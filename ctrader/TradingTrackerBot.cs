using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using cAlgo.API;

namespace cAlgo.Robots
{
    [Robot(TimeZone = TimeZones.UTC, AccessRights = AccessRights.FullAccess)]
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

        private HttpClient _http;

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

            _http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", ApiToken);

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
            _http?.Dispose();
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

            var payload = BuildPayload(position, asset);
            _ = PostTradeAsync(payload, position.Id);
        }

        // ── Événement : modification de position (SL/TP) ──────────────────────

        private void OnPositionModified(PositionModifiedEventArgs args)
        {
            var position = args.Position;
            var asset = MapSymbol(position.SymbolName);

            if (asset == null) return;

            // Une clôture partielle réduit le volume de la position (même Id) et
            // enregistre un deal dans History — on synchronise avant le reste.
            _ = SyncExitsAsync(position.Id, closed: false);

            var updatePayload = BuildUpdatePayload(position);
            if (updatePayload.Count == 0) return; // SL retiré (ex: rollover) — rien à mettre à jour

            _ = PatchTradeAsync(position.Id, updatePayload);
        }

        // ── Événement : clôture de position (SL, TP ou fermeture manuelle) ────

        private void OnPositionClosed(PositionClosedEventArgs args)
        {
            var position = args.Position;
            if (MapSymbol(position.SymbolName) == null) return;

            _ = SyncExitsAsync(position.Id, closed: true);
        }

        // ── Appels API ────────────────────────────────────────────────────────

        private async Task PostTradeAsync(object payload, long positionId)
        {
            try
            {
                var json = JsonSerializer.Serialize(payload);
                var content = new StringContent(json, Encoding.UTF8, "application/json");
                var response = await _http.PostAsync($"{ApiBaseUrl}/api/trades", content);
                var body = await response.Content.ReadAsStringAsync();

                if (response.IsSuccessStatusCode)
                    Print($"[TradingTracker] ✓ Trade créé (position #{positionId})");
                else
                    Print($"[TradingTracker] ✗ Erreur création trade #{positionId}: {response.StatusCode} — {body}");
            }
            catch (Exception ex)
            {
                Print($"[TradingTracker] ✗ Erreur réseau (POST): {ex.Message}");
            }
        }

        private async Task PatchTradeAsync(long positionId, object payload)
        {
            try
            {
                var tradeId = await FindTradeIdAsync(positionId);
                if (tradeId == null) return;

                await SendPatchAsync(tradeId.Value, payload, positionId);
            }
            catch (Exception ex)
            {
                Print($"[TradingTracker] ✗ Erreur réseau (PATCH): {ex.Message}");
            }
        }

        /// <summary>
        /// Transmet à l'API les deals de clôture (History) pas encore envoyés pour
        /// cette position. Chaque deal devient une sortie {dealId, price, volume, date} ;
        /// l'API dédoublonne par dealId et calcule le RR final à la clôture totale.
        /// Réf : https://help.ctrader.com/ctrader-algo/references/Trading/History/HistoricalTrade/
        /// </summary>
        private async Task SyncExitsAsync(long positionId, bool closed)
        {
            try
            {
                var deals = new List<HistoricalTrade>();
                foreach (var historicalTrade in History)
                {
                    if (historicalTrade.PositionId == positionId && !_sentDealIds.Contains(historicalTrade.ClosingDealId))
                        deals.Add(historicalTrade);
                }

                if (deals.Count == 0 && !closed) return; // Modified sans clôture partielle (ex: changement de SL)

                var tradeId = await FindTradeIdAsync(positionId);
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

                    if (await SendPatchAsync(tradeId.Value, payload, positionId))
                        _sentDealIds.Add(deal.ClosingDealId);
                }

                // Clôture reçue mais deal pas encore visible dans History : signaler quand même
                if (closed && deals.Count == 0)
                    await SendPatchAsync(tradeId.Value, new Dictionary<string, object> { ["closed"] = true }, positionId);
            }
            catch (Exception ex)
            {
                Print($"[TradingTracker] ✗ Erreur sync sorties #{positionId}: {ex.Message}");
            }
        }

        /// <summary>Retrouve l'id du trade en DB via ctraderPositionId (null si absent).</summary>
        private async Task<int?> FindTradeIdAsync(long positionId)
        {
            var searchResponse = await _http.GetAsync($"{ApiBaseUrl}/api/trades?ctraderPositionId={positionId}");
            if (!searchResponse.IsSuccessStatusCode)
            {
                Print($"[TradingTracker] ✗ Trade introuvable pour position #{positionId}");
                return null;
            }

            var searchBody = await searchResponse.Content.ReadAsStringAsync();
            var trades = JsonSerializer.Deserialize<JsonElement[]>(searchBody);

            if (trades == null || trades.Length == 0)
            {
                Print($"[TradingTracker] Trade non trouvé en DB pour position #{positionId}");
                return null;
            }

            return trades[0].GetProperty("id").GetInt32();
        }

        private async Task<bool> SendPatchAsync(int tradeId, object payload, long positionId)
        {
            var json = JsonSerializer.Serialize(payload);
            var content = new StringContent(json, Encoding.UTF8, "application/json");
            var request = new HttpRequestMessage(new System.Net.Http.HttpMethod("PATCH"), $"{ApiBaseUrl}/api/trades/{tradeId}")
            {
                Content = content
            };
            var response = await _http.SendAsync(request);

            if (response.IsSuccessStatusCode)
            {
                Print($"[TradingTracker] ✓ Trade #{tradeId} mis à jour (position #{positionId})");
                return true;
            }

            var body = await response.Content.ReadAsStringAsync();
            Print($"[TradingTracker] ✗ Erreur mise à jour #{tradeId}: {response.StatusCode} — {body}");
            return false;
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
