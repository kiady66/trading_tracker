using System;
using System.Collections.Generic;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using cAlgo.API;

namespace cAlgo.Robots
{
    /// <summary>
    /// Retire temporairement les stop loss avant le rollover quotidien (swap) et les
    /// restaure juste après, pour éviter d'être sorti par l'élargissement du spread.
    ///
    /// Le rollover a lieu à 17h00 heure de New York, soit 23h00 à Paris la plupart de
    /// l'année — mais 22h00 pendant les quelques semaines où les États-Unis ont changé
    /// d'heure et pas encore l'Europe (et inversement). Le bot se cale directement sur
    /// le fuseau de New York, donc l'heure française est toujours la bonne sans réglage.
    ///
    /// Les niveaux de SL sont persistés dans Trading Tracker : chaque trade (retrouvé
    /// via sa référence cTrader `ctraderPositionId`) porte un historique `stopLosses`.
    /// Avant le rollover, le SL courant est ajouté à cet historique via l'API puis
    /// retiré de la position ; après le rollover, le DERNIER élément de la liste est
    /// remis en place. Un redémarrage de cTrader pendant la fenêtre ne perd donc rien.
    /// </summary>
    [Robot(TimeZone = TimeZones.UTC, AccessRights = AccessRights.FullAccess)]
    public class RolloverStopLossGuard : Robot
    {
        // ── Paramètres configurables dans cTrader ────────────────────────────

        // Prod : https://trading-tracker.freeddns.org — pour tester en local, remplacer par http://localhost:8001
        [Parameter("API Base URL", DefaultValue = "https://trading-tracker.freeddns.org")]
        public string ApiBaseUrl { get; set; }

        [Parameter("API Token", DefaultValue = "")]
        public string ApiToken { get; set; }

        [Parameter("Minutes avant rollover (retrait SL)", DefaultValue = 5, MinValue = 1, MaxValue = 60)]
        public int MinutesBefore { get; set; }

        [Parameter("Minutes après rollover (remise SL)", DefaultValue = 10, MinValue = 1, MaxValue = 120)]
        public int MinutesAfter { get; set; }

        // ── État ──────────────────────────────────────────────────────────────

        private HttpClient _http;
        private TimeZoneInfo _newYork;
        private bool _inWindow;

        // ── Lifecycle ─────────────────────────────────────────────────────────

        protected override void OnStart()
        {
            if (string.IsNullOrWhiteSpace(ApiToken))
            {
                Print("[RolloverGuard] ⚠ API Token non configuré. Le bot ne fonctionnera pas.");
                Stop();
                return;
            }

            _newYork = FindTimeZone("Eastern Standard Time", "America/New_York");
            if (_newYork == null)
            {
                Print("[RolloverGuard] ✗ Fuseau horaire de New York introuvable — bot inactif.");
                Stop();
                return;
            }

            _http = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
            _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", ApiToken);

            Timer.Start(TimeSpan.FromSeconds(10));

            var ny = TimeZoneInfo.ConvertTimeFromUtc(Server.TimeInUtc, _newYork);
            Print($"[RolloverGuard] ✓ Démarré. Rollover à 17h00 New York (actuellement {ny:HH\\:mm} à NY). " +
                  $"Retrait des SL {MinutesBefore} min avant, remise {MinutesAfter} min après. API: {ApiBaseUrl}");

            // Récupération : si le bot a été arrêté pendant la fenêtre de rollover,
            // les SL retirés sont toujours en DB — on les restaure au démarrage.
            if (!IsInRolloverWindow(ny) && Positions.Any(p => !p.StopLoss.HasValue))
            {
                Print("[RolloverGuard] Positions sans SL détectées au démarrage — tentative de restauration depuis la DB.");
                RestoreStopLosses();
            }
        }

        protected override void OnStop()
        {
            Timer.Stop();
            _http?.Dispose();
        }

        protected override void OnTimer()
        {
            var ny = TimeZoneInfo.ConvertTimeFromUtc(Server.TimeInUtc, _newYork);

            if (IsInRolloverWindow(ny))
            {
                if (!_inWindow)
                    Print("[RolloverGuard] Fenêtre de rollover ouverte — retrait des stop loss.");
                _inWindow = true;

                // Couvre aussi les positions ouvertes (ou re-modifiées) pendant la fenêtre.
                RemoveStopLosses();
            }
            else if (_inWindow)
            {
                _inWindow = false;
                Print("[RolloverGuard] Fenêtre de rollover terminée — remise des stop loss.");
                RestoreStopLosses();
            }
        }

        private bool IsInRolloverWindow(DateTime newYorkTime)
        {
            var rollover = newYorkTime.Date.AddHours(17);
            return newYorkTime >= rollover.AddMinutes(-MinutesBefore)
                && newYorkTime < rollover.AddMinutes(MinutesAfter);
        }

        // ── Retrait des SL (persistés en DB au préalable) ─────────────────────

        private void RemoveStopLosses()
        {
            foreach (var position in Positions.Where(p => p.StopLoss.HasValue).ToArray())
            {
                var stopLoss = position.StopLoss.Value;

                var trade = FindTradeByPositionId(position.Id);
                if (trade == null)
                {
                    Print($"[RolloverGuard] ⚠ Aucun trade en DB pour la position #{position.Id} — SL laissé en place.");
                    continue;
                }

                // Persiste le SL courant en fin d'historique AVANT de le retirer.
                // Si l'API échoue, on ne retire pas : le niveau serait perdu.
                if (!AppendStopLoss(trade.Value.Id, stopLoss))
                {
                    Print($"[RolloverGuard] ✗ Échec de la sauvegarde du SL pour le trade #{trade.Value.Id} — SL laissé en place.");
                    continue;
                }

                var result = position.ModifyStopLossPrice(null);
                if (result.IsSuccessful)
                    Print($"[RolloverGuard] ✓ SL {stopLoss} retiré sur {position.SymbolName} #{position.Id} (sauvé dans trade #{trade.Value.Id}).");
                else
                    Print($"[RolloverGuard] ✗ Échec du retrait du SL sur #{position.Id}: {result.Error}");
            }
        }

        // ── Restauration : dernier SL de l'historique du trade ────────────────

        private void RestoreStopLosses()
        {
            foreach (var position in Positions.Where(p => !p.StopLoss.HasValue).ToArray())
            {
                var trade = FindTradeByPositionId(position.Id);
                if (trade == null)
                    continue; // Position non trackée — on n'y touche pas.

                if (trade.Value.LastStopLoss == null)
                {
                    Print($"[RolloverGuard] ⚠ Trade #{trade.Value.Id} sans historique de SL — rien à restaurer sur #{position.Id}.");
                    continue;
                }

                var stopLoss = trade.Value.LastStopLoss.Value;
                var result = position.ModifyStopLossPrice(stopLoss);

                if (result.IsSuccessful)
                    Print($"[RolloverGuard] ✓ SL restauré à {stopLoss} sur {position.SymbolName} #{position.Id}.");
                else
                    // Cas typique : le prix a traversé le niveau du SL pendant la fenêtre.
                    Print($"[RolloverGuard] ⚠ Impossible de restaurer le SL à {stopLoss} sur #{position.Id} " +
                          $"({result.Error}) — à replacer manuellement.");
            }
        }

        // ── Appels API (synchrones : quelques appels par jour seulement) ──────

        private (int Id, double? LastStopLoss)? FindTradeByPositionId(long positionId)
        {
            try
            {
                var response = _http.GetAsync($"{ApiBaseUrl}/api/trades?ctraderPositionId={positionId}")
                    .GetAwaiter().GetResult();
                if (!response.IsSuccessStatusCode)
                    return null;

                var body = response.Content.ReadAsStringAsync().GetAwaiter().GetResult();
                var trades = JsonSerializer.Deserialize<JsonElement[]>(body);
                if (trades == null || trades.Length == 0)
                    return null;

                var trade = trades[0];
                double? lastStopLoss = null;
                if (trade.TryGetProperty("lastStopLoss", out var sl) && sl.ValueKind == JsonValueKind.Number)
                    lastStopLoss = sl.GetDouble();

                return (trade.GetProperty("id").GetInt32(), lastStopLoss);
            }
            catch (Exception ex)
            {
                Print($"[RolloverGuard] ✗ Erreur réseau (GET trade #{positionId}): {ex.Message}");
                return null;
            }
        }

        private bool AppendStopLoss(int tradeId, double stopLoss)
        {
            try
            {
                var json = JsonSerializer.Serialize(new { stopLoss });
                var request = new HttpRequestMessage(new HttpMethod("PATCH"), $"{ApiBaseUrl}/api/trades/{tradeId}")
                {
                    Content = new StringContent(json, Encoding.UTF8, "application/json"),
                };
                var response = _http.SendAsync(request).GetAwaiter().GetResult();
                return response.IsSuccessStatusCode;
            }
            catch (Exception ex)
            {
                Print($"[RolloverGuard] ✗ Erreur réseau (PATCH trade #{tradeId}): {ex.Message}");
                return false;
            }
        }

        // ── Helpers fuseaux horaires ──────────────────────────────────────────

        private static TimeZoneInfo FindTimeZone(string windowsId, string ianaId)
        {
            foreach (var id in new[] { windowsId, ianaId })
            {
                try
                {
                    return TimeZoneInfo.FindSystemTimeZoneById(id);
                }
                catch (TimeZoneNotFoundException)
                {
                }
                catch (InvalidTimeZoneException)
                {
                }
            }

            return null;
        }
    }
}
