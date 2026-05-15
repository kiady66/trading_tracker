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

        [Parameter("API Base URL", DefaultValue = "http://localhost:8001")]
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

            Print($"[TradingTracker] ✓ Connecté à {ApiBaseUrl}");
        }

        protected override void OnStop()
        {
            Positions.Opened   -= OnPositionOpened;
            Positions.Modified -= OnPositionModified;
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

            var updatePayload = BuildUpdatePayload(position);
            _ = PatchTradeAsync(position.Id, updatePayload);
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
                // Recherche du trade en DB via ctraderPositionId
                var searchResponse = await _http.GetAsync($"{ApiBaseUrl}/api/trades?ctraderPositionId={positionId}");
                if (!searchResponse.IsSuccessStatusCode)
                {
                    Print($"[TradingTracker] ✗ Trade introuvable pour position #{positionId}");
                    return;
                }

                var searchBody = await searchResponse.Content.ReadAsStringAsync();
                var trades = JsonSerializer.Deserialize<JsonElement[]>(searchBody);

                if (trades == null || trades.Length == 0)
                {
                    Print($"[TradingTracker] Trade non trouvé en DB pour position #{positionId}");
                    return;
                }

                var tradeId = trades[0].GetProperty("id").GetInt32();

                var json = JsonSerializer.Serialize(payload);
                var content = new StringContent(json, Encoding.UTF8, "application/json");
                var request = new HttpRequestMessage(new System.Net.Http.HttpMethod("PATCH"), $"{ApiBaseUrl}/api/trades/{tradeId}")
                {
                    Content = content
                };
                var response = await _http.SendAsync(request);

                if (response.IsSuccessStatusCode)
                    Print($"[TradingTracker] ✓ Trade #{tradeId} mis à jour (position #{positionId})");
                else
                {
                    var body = await response.Content.ReadAsStringAsync();
                    Print($"[TradingTracker] ✗ Erreur mise à jour #{tradeId}: {response.StatusCode} — {body}");
                }
            }
            catch (Exception ex)
            {
                Print($"[TradingTracker] ✗ Erreur réseau (PATCH): {ex.Message}");
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
            };
        }

        private object BuildUpdatePayload(Position position)
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

            return new
            {
                riskPercentage = riskPct,
                initialRR,
            };
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
