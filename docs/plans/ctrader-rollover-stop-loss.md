> **Statut : implémenté (2026-09-18), avec un design différent de ce plan.**
> Le bot livré est `ctrader/RolloverStopLossGuard.cs` : fenêtre calée sur
> 17h00 America/New_York (et non des heures françaises fixes), et SL persistés
> dans l'historique `trade.stopLosses` via l'API (et non en RAM).
> Voir [ctrader/README.md](../../ctrader/README.md). Ce plan est conservé pour
> l'historique du raisonnement.

# Plan : Protection spread nocturne — TradingTrackerBot.cs

## Contexte

Les brokers appliquent des spreads élargis autour de 23h00 UTC (fin de session américaine). En heure française (CET/CEST) :

- **Hiver (UTC+1)** : le spread survient vers 23h00 CET
- **Été (UTC+2)** : le spread survient vers 00h00 CEST

Pour éviter que le stop loss soit touché artificiellement, le bot doit :

1. À **22h45 heure française** : sauvegarder le SL courant de chaque position ouverte, puis le supprimer
2. À **00h05 heure française** : restaurer chaque SL sauvegardé

La valeur sauvegardée est le SL **courant** (potentiellement trailé, différent du SL initial). Le SL initial en base de données ne doit **jamais** être modifié — il sert au calcul du risque (`riskPercentage`, `initialRR`).

---

## Fichier à modifier

`ctrader/TradingTrackerBot.cs` — seul fichier du bot.

---

## Implémentation

### 1. Nouveaux champs

```csharp
private readonly Dictionary<long, double> _savedStopLosses = new();
private bool _isSpreadProtectionActive = false;
private DateOnly _slRemovedDate = DateOnly.MinValue;
private static readonly TimeZoneInfo FrenchTz = GetFrenchTimezone();
```

### 2. Méthode statique GetFrenchTimezone()

Gère Windows (cTrader desktop) et Linux (serveur) :

```csharp
private static TimeZoneInfo GetFrenchTimezone()
{
    try { return TimeZoneInfo.FindSystemTimeZoneById("Europe/Paris"); }
    catch { return TimeZoneInfo.FindSystemTimeZoneById("Romance Standard Time"); }
}
```

### 3. OnStart — démarrer le timer

```csharp
Timer.Start(30000); // vérifie toutes les 30 secondes
```

### 4. OnStop — arrêter le timer

```csharp
Timer.Stop();
```

### 5. Nouvelle méthode OnTimer()

```csharp
protected override void OnTimer()
{
    var utcTime = DateTime.SpecifyKind(Server.Time, DateTimeKind.Utc);
    var frenchNow = TimeZoneInfo.ConvertTimeFromUtc(utcTime, FrenchTz);
    var frenchToday = DateOnly.FromDateTime(frenchNow);
    var t = frenchNow.TimeOfDay;

    bool inRemoveWindow  = t >= new TimeSpan(22, 45, 0) && t < new TimeSpan(22, 46, 0);
    bool inRestoreWindow = t >= new TimeSpan(0, 5, 0)   && t < new TimeSpan(0, 6, 0);

    if (inRemoveWindow && _slRemovedDate != frenchToday)
    {
        _slRemovedDate = frenchToday;
        _ = RemoveStopLossesForSpreadAsync();
    }
    else if (inRestoreWindow && _savedStopLosses.Count > 0)
    {
        _ = RestoreStopLossesAfterSpreadAsync();
    }
}
```

### 6. RemoveStopLossesForSpreadAsync()

```csharp
private async Task RemoveStopLossesForSpreadAsync()
{
    _isSpreadProtectionActive = true;
    _savedStopLosses.Clear();

    foreach (var pos in Positions)
    {
        if (pos.StopLoss.HasValue)
            _savedStopLosses[pos.Id] = pos.StopLoss.Value;
    }

    foreach (var pos in Positions)
    {
        if (!_savedStopLosses.ContainsKey(pos.Id)) continue;
        var result = await ModifyPositionAsync(pos, null, pos.TakeProfit);
        if (result.IsSuccessful)
            Print($"[TradingTracker] ✓ SL supprimé #{pos.Id} (sauvegardé: {_savedStopLosses[pos.Id]:F5})");
        else
            Print($"[TradingTracker] ✗ Échec suppression SL #{pos.Id}: {result.Error}");
    }

    Print($"[TradingTracker] 🛡 Protection spread activée — {_savedStopLosses.Count} SL(s) supprimé(s)");
}
```

### 7. RestoreStopLossesAfterSpreadAsync()

```csharp
private async Task RestoreStopLossesAfterSpreadAsync()
{
    try
    {
        foreach (var pos in Positions)
        {
            if (!_savedStopLosses.TryGetValue(pos.Id, out var savedSL)) continue;
            var result = await ModifyPositionAsync(pos, savedSL, pos.TakeProfit);
            if (result.IsSuccessful)
                Print($"[TradingTracker] ✓ SL restauré #{pos.Id}: {savedSL:F5}");
            else
                Print($"[TradingTracker] ✗ Échec restauration SL #{pos.Id}: {result.Error}");
        }

        foreach (var posId in _savedStopLosses.Keys)
        {
            if (Positions.FirstOrDefault(p => p.Id == posId) == null)
                Print($"[TradingTracker] ⓘ Position #{posId} fermée pendant la protection spread");
        }
    }
    finally
    {
        _savedStopLosses.Clear();
        _isSpreadProtectionActive = false;
        Print("[TradingTracker] ✓ Protection spread désactivée");
    }
}
```

### 8. Modifier OnPositionModified — supprimer le PATCH pendant la protection

```csharp
private void OnPositionModified(PositionModifiedEventArgs args)
{
    if (_isSpreadProtectionActive) return; // suppression/restauration en cours

    var position = args.Position;
    var asset = MapSymbol(position.SymbolName);
    if (asset == null) return;

    var updatePayload = BuildUpdatePayload(position);
    _ = PatchTradeAsync(position.Id, updatePayload);
}
```

---

## Points clés de conception

| Sujet | Décision |
|---|---|
| Sauvegarde SL | `Dictionary<long, double>` en mémoire (SL courant, potentiellement trailé) |
| SL initial en DB | Jamais modifié — `riskPercentage` et `initialRR` restent intacts |
| DST française | `TimeZoneInfo.ConvertTimeFromUtc` gère automatiquement CET/CEST |
| Redémarrage entre 22h45 et 00h05 | SLs perdus en mémoire (edge case acceptable) |
| Positions ouvertes pendant la fenêtre | Non traitées (complexité non justifiée) |
| Positions fermées pendant la fenêtre | Log `ⓘ` à la restauration, ignorées silencieusement |

---

## Vérification

1. Compiler le bot dans l'IDE cTrader
2. Tester en changeant temporairement les horaires de déclenchement à `Server.Time + 1 minute`
3. Vérifier dans les logs cTrader que les SL sont bien supprimés puis restaurés
4. Vérifier en DB que `riskPercentage` et `initialRR` restent inchangés après la fenêtre