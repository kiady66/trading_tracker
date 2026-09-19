# cBots cTrader — Trading Tracker

Deux robots cTrader qui relient le compte de trading à l'application via
l'[API REST](../docs/API.md) (`/api/trades`), authentifiés par le token
personnel (**Mon profil → Token API cTrader**).

| Fichier | Rôle |
|---|---|
| [`TradingTrackerBot.cs`](TradingTrackerBot.cs) | Enregistre automatiquement chaque position ouverte dans Trading Tracker et suit ses modifications (SL/TP) |
| [`RolloverStopLossGuard.cs`](RolloverStopLossGuard.cs) | Retire les stop loss quelques minutes avant le rollover quotidien et les restaure juste après |

## Installation

1. Dans cTrader Desktop : **Automate → New cBot**, remplacer le code généré par
   le contenu du fichier `.cs`, puis **Build** (aucune dépendance externe).
2. Attacher le cBot à un graphique quelconque — **une seule instance suffit**
   pour tout le compte, les deux bots surveillent toutes les positions.
3. Renseigner les paramètres (voir ci-dessous) et démarrer.

Les deux bots pointent par défaut sur la prod
(`https://trading-tracker.freeddns.org`). Pour tester en local, remplacer le
paramètre *API Base URL* par `http://localhost:8001`.

> ⚠ Une instance déjà installée garde les valeurs de paramètres enregistrées :
> après une mise à jour du code, vérifier que l'URL et le token de l'instance
> sont corrects.

## TradingTrackerBot

À chaque **ouverture de position** : crée le trade via `POST /api/trades` avec
l'asset (symbole cTrader converti, ex. `EURUSD` → `EUR/USD`), la date d'entrée,
le risque calculé depuis la distance du SL, le RR initial, le SL courant, les
prix d'exécution (`entryPrice`, `targetPrice`, `volumeInUnits`) et la
référence `ctraderPositionId` qui lie la position au trade.

À chaque **modification de position** (SL/TP déplacé) : retrouve le trade par
`ctraderPositionId` et le met à jour via `PATCH` — risque recalculé, RR, et le
nouveau SL ajouté en fin d'historique `stopLosses` (l'API ignore un SL identique
au dernier, pas de doublons). Un retrait de SL n'envoie rien.

À chaque **clôture (partielle ou totale)** : une clôture partielle conserve la
position (même Id, volume réduit) et chaque exécution crée un *deal* dans
`History`. Sur `Positions.Modified` et `Positions.Closed`, le bot envoie à l'API
les deals de clôture pas encore transmis (`exit: {dealId, price, volume, date}`) ;
l'API dédoublonne par `dealId` (un redémarrage du bot est donc sans risque) et,
à la clôture totale (`closed: true` ou volume initial atteint), fixe `exitDate`
et calcule le `finalRR` pondéré par volume, rapporté au risque initial. Limite
connue : une clôture survenue pendant que le bot est arrêté n'est pas rattrapée.

Paramètres :

| Paramètre | Défaut | Description |
|---|---|---|
| API Base URL | `https://trading-tracker.freeddns.org` | URL de l'application |
| API Token | *(vide)* | Token personnel — obligatoire |
| Max Risk (€) | 500 | Risque max servant au calcul du `riskPercentage` |

Les symboles non supportés par l'application (voir `Trade::ALLOWED_ASSETS`)
sont ignorés avec un message dans le journal.

## RolloverStopLossGuard

Le spread s'élargit fortement au **rollover quotidien (swap)** et peut sortir
des positions sur leur stop loss. Ce bot :

1. **Avant le rollover** (5 min par défaut) : pour chaque position avec SL,
   pousse d'abord le niveau courant dans l'historique `stopLosses` du trade via
   l'API, **puis** retire le SL de la position. Si l'API est injoignable, le SL
   est laissé en place (le niveau ne doit jamais être perdu).
2. **Après le rollover** (10 min par défaut) : remet sur chaque position sans SL
   le **dernier élément** de la liste `stopLosses` de son trade.

### Pourquoi 17h00 New York et pas « 23h en France »

Le rollover est fixé à **17h00, heure de New York**. Ça correspond à 23h00 à
Paris la majeure partie de l'année, mais à **22h00 pendant les quelques
semaines** où les États-Unis ont déjà changé d'heure et pas encore l'Europe (et
inversement). Le bot se cale directement sur le fuseau `America/New_York` :
l'heure française est toujours la bonne, sans aucun réglage aux changements
d'heure.

Paramètres :

| Paramètre | Défaut | Description |
|---|---|---|
| API Base URL | `https://trading-tracker.freeddns.org` | URL de l'application |
| API Token | *(vide)* | Token personnel — obligatoire |
| Minutes avant rollover (retrait SL) | 5 | Ouverture de la fenêtre avant 17h00 NY |
| Minutes après rollover (remise SL) | 10 | Fermeture de la fenêtre après 17h00 NY |

### Comportement en cas d'incident

- **Crash / redémarrage de cTrader pendant la fenêtre** : les niveaux sont en
  base, pas en RAM. Au démarrage hors fenêtre, le bot détecte les positions
  sans SL et restaure depuis la base.
- **Prix ayant traversé le niveau du SL pendant la fenêtre** : le broker refuse
  la remise du SL — le bot logge un avertissement, le SL est à replacer
  manuellement (la position n'est **pas** fermée automatiquement).
- **Position non trackée** (pas de trade avec ce `ctraderPositionId`) : le bot
  n'y touche pas, ni au retrait ni à la restauration.

⚠ Pendant la fenêtre (~15 min par défaut), les positions tournent **sans filet** :
un mouvement violent ne serait pas coupé. C'est le compromis assumé contre les
sorties sur spread de rollover.

## Interaction entre les deux bots

Quand le guard retire un SL, `TradingTrackerBot` voit une modification sans SL
et n'envoie rien (le PATCH serait vide). Quand le guard restaure, le PATCH du
tracker renvoie le même SL, que l'API déduplique. Les deux bots peuvent donc
tourner ensemble sans se marcher dessus.

## Références API cAlgo

- [Position](https://help.ctrader.com/ctrader-algo/references/Trading/Positions/Position/) — `EntryPrice`, `TakeProfit`, `VolumeInUnits`, `Id`
- [PositionClosedEventArgs](https://help.ctrader.com/ctrader-algo/references/EventArgs/PositionClosedEventArgs/) / [PositionModifiedEventArgs](https://help.ctrader.com/ctrader-algo/references/EventArgs/PositionModifiedEventArgs/) — la doc ne précise pas quel événement se déclenche sur une clôture partielle, d'où la synchronisation depuis `History` sur les deux
- [History / HistoricalTrade](https://help.ctrader.com/ctrader-algo/references/Trading/History/HistoricalTrade/) — un deal de clôture par exécution : `PositionId`, `ClosingDealId`, `ClosingPrice`, `ClosingTime`, `VolumeInUnits`
