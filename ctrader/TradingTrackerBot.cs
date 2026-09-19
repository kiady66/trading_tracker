using System;
using System.Collections.Generic;
using System.Text.Json;
using cAlgo.API;

namespace cAlgo.Robots
{
    // AccessRights.None + API Http de cAlgo (et non System.Net.Http) : requis
    // pour l'exécution cloud de cTrader, qui interdit FullAccess.
    // Réf : https://help.ctrader.com/ctrader-algo/guides/network-access/
    [Robot(TimeZone = TimeZones.UTC, AccessRights = AccessRights.None)]
    public class TradingTrackerBot : Robot
    {
        // ── Paramètres configurables dans cTrader ────────────────────────────

        // Prod : https://trading-tracker.freeddns.org — pour tester en local, remplacer par http://localhost:8001
        [Parameter("API Base URL", DefaultValue = "https://trading-tracker.freeddns.org")]
        public string ApiBaseUrl { get; set; }

        [Parameter("API Token", DefaultValue = "")]
        public string ApiToken { get; set; }

        [Parameter("Max Risk (€)", DefaultValue = 500.0, MinValue = 1)]
        public double MaxRiskEuro { get; set; }

        // ── Constantes : assets supportés par Trading Tracker ─────────────────

        private static readonly HashSet<string> SupportedAssets = new(StringComparer.OrdinalIgnoreCase)
        {
            "EUR/USD", "GBP/USD", "USD/JPY", "USD/CHF", "AUD/USD", "USD/CAD", "NZD/USD",
            "EUR/GBP", "EUR/JPY", "GBP/JPY", "GBP/CHF", "NZD/JPY", "AUD/GBP", "AUD/NZD",
            "AUD/CAD", "NZD/CAD", "AUD/CHF", "AUD/JPY", "GBP/CAD", "GBP/AUD", "CAD/CHF",
            "CAD/JPY", "CHF/JPY", "BTC/USD", "ETH/USD", "XAU/USD", "SP500"
        };

        // Deals de clôture déjà transmis à l'API (cache mémoire ; l'API dédoublonne
        // de toute façon par dealId, donc un redémarrage du bot est sans risque).
        private readonly HashSet<long> _sentDealIds = new();

        // ── Lifecycle ─────────────────────────────────────────────────────────

        protected override void OnStart()
        {
            if (string.IsNullOrWhiteSpace(ApiToken))
            {
                Print("[TradingTracker] ⚠ API Token non configuré. Le bot ne fonctionnera pas.");
                return;
            }

            Positions.Opened   += OnPositionOpened;
            Positions.Modified += OnPositionModified;
            Positions.Closed   += OnPositionClosed;

            Print($"[TradingTracker] ✓ Connecté à {ApiBaseUrl}");
        }

        protected override void OnStop()
        {
            Positions.Opened   -= OnPositionOpened;
            Positions.Modified -= OnPositionModified;
            Positions.Closed   -= OnPositionClosed;
        }

        // ── Événement : ouverture de position ─────────────────────────────────

        private void OnPositionOpened(PositionOpenedEventArgs args)
        {
            var position = args.Position;
            var asset = MapSymbol(position.SymbolName);

            if (asset == null)
            {
                Print($"[TradingTracker] Symbole '{position.SymbolName}' non supporté — trade ignoré.");
                return;
            }

            PostTrade(BuildPayload(position, asset), position.Id);
        }

        // ── Événement : modification de position (SL/TP) ──────────────────────

        private void OnPositionModified(PositionModifiedEventArgs args)
        {
            var position = args.Position;
            var asset = MapSymbol(position.SymbolName);

            if (asset == null) return;

            // Une clôture partielle réduit le volume de la position (même Id) et
            // enregistre un deal dans History — on synchronise avant le reste.
            SyncExits(position.Id, closed: false);

            var updatePayload = BuildUpdatePayload(position);
            if (updatePayload.Count == 0) return; // SL retiré (ex: rollover) — rien à mettre à jour

            PatchTrade(position.Id, updatePayload);
        }

        // ── Événement : clôture de position (SL, TP ou fermeture manuelle) ────

        private void OnPositionClosed(PositionClosedEventArgs args)
        {
            var position = args.Position;
            if (MapSymbol(position.SymbolName) == null) return;

            SyncExits(position.Id, closed: true);
        }

        // ── Appels API ────────────────────────────────────────────────────────

        private void PostTrade(object payload, long positionId)
        {
            var response = SendJson(HttpMethod.Post, $"{ApiBaseUrl}/api/trades", payload);

            if (response != null && response.IsSuccessful)
                Print($"[TradingTracker] ✓ Trade créé (position #{positionId})");
            else
                Print($"[TradingTracker] ✗ Erreur création trade #{positionId}: {response?.StatusCode} — {response?.Body}");
        }

        private void PatchTrade(long positionId, object payload)
        {
            var tradeId = FindTradeId(positionId);
            if (tradeId == null) return;

            SendPatch(tradeId.Value, payload, positionId);
        }

        /// <summary>
        /// Transmet à l'API les deals de clôture (History) pas encore envoyés pour
        /// cette position. Chaque deal devient une sortie {dealId, price, volume, date} ;
        /// l'API dédoublonne par dealId et calcule le RR final à la clôture totale.
        /// Réf : https://help.ctrader.com/ctrader-algo/references/Trading/History/HistoricalTrade/
        /// </summary>
        private void SyncExits(long positionId, bool closed)
        {
            var deals = new List<HistoricalTrade>();
            foreach (var historicalTrade in History)
            {
                if (historicalTrade.PositionId == positionId && !_sentDealIds.Contains(historicalTrade.ClosingDealId))
                    deals.Add(historicalTrade);
            }

            if (deals.Count == 0 && !closed) return; // Modified sans clôture partielle (ex: changement de SL)

            var tradeId = FindTradeId(positionId);
            if (tradeId == null) return;

            for (var i = 0; i < deals.Count; i++)
            {
                var deal = deals[i];
                var payload = new Dictionary<string, object>
                {
                    ["exit"] = new
                    {
                        dealId = deal.ClosingDealId.ToString(),
                        price  = deal.ClosingPrice,
                        volume = deal.VolumeInUnits,
                        date   = deal.ClosingTime.ToString("o"),
                    },
                };
                // Le flag closed part avec le dernier deal : l'API fixe alors
                // exitDate et calcule le RR final pondéré.
                if (closed && i == deals.Count - 1)
                    payload["closed"] = true;

                if (SendPatch(tradeId.Value, payload, positionId))
                    _sentDealIds.Add(deal.ClosingDealId);
            }

            // Clôture reçue mais deal pas encore visible dans History : signaler quand même
            if (closed && deals.Count == 0)
                SendPatch(tradeId.Value, new Dictionary<string, object> { ["closed"] = true }, positionId);
        }

        /// <summary>Retrouve l'id du trade en DB via ctraderPositionId (null si absent).</summary>
        private int? FindTradeId(long positionId)
        {
            var response = SendJson(HttpMethod.Get, $"{ApiBaseUrl}/api/trades?ctraderPositionId={positionId}");
            if (response == null || !response.IsSuccessful)
            {
                Print($"[TradingTracker] ✗ Trade introuvable pour position #{positionId}");
                return null;
            }

            var trades = JsonSerializer.Deserialize<JsonElement[]>(response.Body);
            if (trades == null || trades.Length == 0)
            {
                Print($"[TradingTracker] Trade non trouvé en DB pour position #{positionId}");
                return null;
            }

            return trades[0].GetProperty("id").GetInt32();
        }

        private bool SendPatch(int tradeId, object payload, long positionId)
        {
            var response = SendJson(HttpMethod.Patch, $"{ApiBaseUrl}/api/trades/{tradeId}", payload);

            if (response != null && response.IsSuccessful)
            {
                Print($"[TradingTracker] ✓ Trade #{tradeId} mis à jour (position #{positionId})");
                return true;
            }

            Print($"[TradingTracker] ✗ Erreur mise à jour #{tradeId}: {response?.StatusCode} — {response?.Body}");
            return false;
        }

        /// <summary>Requête via l'API Http de cAlgo (compatible cloud). Null en cas d'échec réseau.</summary>
        private HttpResponse SendJson(HttpMethod method, string url, object payload = null)
        {
            try
            {
                var request = new HttpRequest(new Uri(url))
                {
                    Method  = method,
                    Timeout = TimeSpan.FromSeconds(5),
                };
                request.Headers.Add("Authorization", "Bearer " + ApiToken);

                if (payload != null)
                {
                    request.Headers.Add("Content-Type", "application/json");
                    request.Body = JsonSerializer.Serialize(payload);
                }

                var response = Http.Send(request);
                if (response.Exception != null)
                {
                    Print($"[TradingTracker] ✗ Erreur réseau ({method} {url}): {response.Exception.Message}");
                    return null;
                }

                return response;
            }
            catch (Exception ex)
            {
                Print($"[TradingTracker] ✗ Erreur réseau ({method} {url}): {ex.Message}");
                return null;
            }
        }

        // ── Construction des payloads ─────────────────────────────────────────

        private object BuildPayload(Position position, string asset)
        {
            double? riskPct = null;
            double? initialRR = null;

            if (position.StopLoss.HasValue)
            {
                double slPips = Math.Abs(position.EntryPrice - position.StopLoss.Value) / Symbol.PipSize;
                double lossAtSl = slPips * Symbol.PipValue * position.VolumeInUnits;
                riskPct = Math.Round((lossAtSl / MaxRiskEuro) * 100.0, 2);

                if (position.TakeProfit.HasValue)
                {
                    double tpDistance = Math.Abs(position.TakeProfit.Value - position.EntryPrice);
                    double slDistance = Math.Abs(position.EntryPrice - position.StopLoss.Value);
                    initialRR = slDistance > 0 ? Math.Round(tpDistance / slDistance, 2) : null;
                }
            }
            else
            {
                Print($"[TradingTracker] ⚠ Pas de SL défini pour position #{position.Id} — riskPercentage = 100%");
                riskPct = 100.0;
            }

            return new
            {
                asset,
                orderType         = MapOrderType(position.TradeType),
                entryDate         = position.EntryTime.ToString("o"),
                riskPercentage    = riskPct ?? 100.0,
                maxRiskEuro       = MaxRiskEuro,
                initialRR,
                ctraderPositionId = position.Id,
                stopLoss          = position.StopLoss,
                entryPrice        = position.EntryPrice,
                targetPrice       = position.TakeProfit,
                volumeInUnits     = position.VolumeInUnits,
            };
        }

        private Dictionary<string, object> BuildUpdatePayload(Position position)
        {
            // PATCH partiel : on n'envoie que les champs réellement calculables,
            // sinon l'API rejette un riskPercentage null (422).
            var payload = new Dictionary<string, object>();

            if (position.StopLoss.HasValue)
            {
                double slPips = Math.Abs(position.EntryPrice - position.StopLoss.Value) / Symbol.PipSize;
                double lossAtSl = slPips * Symbol.PipValue * position.VolumeInUnits;
                payload["riskPercentage"] = Math.Round((lossAtSl / MaxRiskEuro) * 100.0, 2);

                // L'API ajoute ce SL en fin d'historique (trade.stopLosses)
                payload["stopLoss"] = position.StopLoss.Value;

                if (position.TakeProfit.HasValue)
                {
                    double tpDistance = Math.Abs(position.TakeProfit.Value - position.EntryPrice);
                    double slDistance = Math.Abs(position.EntryPrice - position.StopLoss.Value);
                    if (slDistance > 0)
                        payload["initialRR"] = Math.Round(tpDistance / slDistance, 2);
                }
            }

            return payload;
        }

        // ── Helpers ───────────────────────────────────────────────────────────

        /// <summary>
        /// Convertit un symbole cTrader (ex: "EURUSD") vers le format API (ex: "EUR/USD").
        /// Retourne null si le symbole n'est pas supporté.
        /// </summary>
        private string MapSymbol(string symbolName)
        {
            // Supprimer les suffixes broker (.i, .s, .p, etc.)
            var dotIndex = symbolName.IndexOf('.');
            var name = dotIndex >= 0 ? symbolName.Substring(0, dotIndex) : symbolName;
            name = name.ToUpper();

            if (name == "SP500")
                return SupportedAssets.Contains("SP500") ? "SP500" : null;

            // Déjà au bon format (ex: "XAU/USD")
            if (name.Contains('/'))
                return SupportedAssets.Contains(name) ? name : null;

            // Format 6 caractères : insérer "/" après les 3 premiers (ex: "EURUSD" → "EUR/USD")
            if (name.Length == 6)
            {
                var formatted = name.Substring(0, 3) + "/" + name.Substring(3);
                return SupportedAssets.Contains(formatted) ? formatted : null;
            }

            return null;
        }

        private static string MapOrderType(TradeType tradeType)
        {
            // cTrader ne distingue pas market/limit/stop après exécution.
            // On utilise "market" par défaut — l'utilisateur peut affiner manuellement.
            return tradeType == TradeType.Buy ? "buy market" : "sell market";
        }
    }
}
