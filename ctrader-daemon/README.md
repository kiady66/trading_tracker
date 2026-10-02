# ctrader-daemon

Démon Python qui remplace les cBots (`ctrader/`) : connecté en permanence à la
**cTrader Open API** (TCP+SSL, `live.ctraderapi.com:5035`), il reçoit les
événements d'exécution du compte et alimente l'API Trading Tracker
(`/api/trades`). Plan d'origine : [docs/plans/ctrader-open-api-daemon.html](../docs/plans/ctrader-open-api-daemon.html).

**En prod depuis le 01/10/2026** sur le droplet (service `ctrader-daemon` de
`compose.prod.yaml`) : tracker actif, rollover guard **désactivé**
(`GUARD_ENABLED=false`) — voir [État et bascule](#état-et-bascule-du-guard).

**Garde-fou financier : ce démon ne passe jamais d'ordre.** Aucun code
d'ouverture, de clôture ou de changement de volume n'existe ici, par
construction. La seule écriture broker existante est l'amendement de SL du
rollover guard (`ProtoOAAmendPositionSLTPReq`), inactive tant que
`GUARD_ENABLED=false`.

## Modules

| Fichier | Rôle |
|---|---|
| `daemon.py` | Connexion, auth app + compte, keepalive (SDK), refresh des tokens, dispatch des événements |
| `tracker.py` | Réplique les trades : POST à l'ouverture, PATCH aux changements de SL/TP et aux clôtures (partielles/totales). Purement événementiel — **pas de rattrapage** (choix assumé) |
| `guard.py` | Rollover guard : SL sauvé en base **avant** retrait à 17h00 NY −5 min, restauré +10 min après — uniquement sur les positions trackées. `ProtoOAReconcileReq` sert en lecture seule à lister les positions |
| `symbols.py` | Catalogue symbolId → asset ; taux de conversion devise de cotation → devise du compte (spot éphémère, cache 30 min) pour le calcul du risque |
| `mapping.py` | Fonctions pures : normalisation des symboles (`EURUSD.i → EUR/USD`, `USWTI → USOIL`), % de risque, RR initial. **À resynchroniser avec `Trade::ALLOWED_ASSETS`** |
| `api.py` | Client REST `/api/trades` (requests dans le threadpool Twisted), chaque appel loggé avec son code HTTP |

Tests : `python3 -m unittest discover tests` (fonctions pures, sans SDK).

## Cycle de vie d'un trade (tracker)

Le serveur Spotware **pousse** un `ProtoOAExecutionEvent` à chaque exécution sur
le compte (ordre passé depuis n'importe quel appareil) ; le démon ne fait qu'y
réagir :

| Événement cTrader | Action côté API |
|---|---|
| **Ouverture** (fill sans `closePositionDetail`) | `POST /api/trades` : asset normalisé, `buy/sell market`, date et prix d'entrée, volume, `ctraderPositionId`, et si définis SL (+ `riskPercentage`) et TP (+ `initialRR`). **Sans SL : `riskPercentage = 100` + warning ⚠.** Position déjà trackée (renforcement, événement reçu deux fois) → bascule sur la mise à jour SL/risque, jamais de doublon |
| **SL/TP déplacé** (ordre `STOP_LOSS_TAKE_PROFIT`) | `PATCH` : nouveau SL (ajouté à l'historique `stopLosses` côté API), risque et RR recalculés sur le volume courant. SL retiré (ex. par le guard) → rien n'est envoyé |
| **Clôture** partielle ou totale (fill avec `closePositionDetail`) | `PATCH {exit: {dealId, price, volume, date}}`, idempotent par `dealId` ; + `closed: true` si la position est totalement fermée — l'API fixe alors `exitDate` et calcule `finalRR`/gains |

Symbole non supporté ou position inconnue en base → ignoré avec un log
(« pas de rattrapage » : un trade ouvert pendant une coupure du démon est à
créer manuellement dans l'app).

## Calcul du risque

```
riskPercentage = |entrée − SL| × volume en unités × taux / MAX_RISK_EURO × 100
```

(arrondi à 2 décimales — `mapping.compute_risk_percentage` ; `entrée` = prix
moyen de la position, volumes Open API en centièmes d'unités ÷ 100, prix spot
en 1/100000.)

Le `taux` convertit la devise de cotation vers la devise du compte (USD) :
1.0 si identiques (EUR/USD, XAU/USD, indices…) ; sinon bid de la paire directe
ou 1/bid de la paire inverse (ex. JPY → USD via `USDJPY`), obtenu par
souscription spot éphémère et mis en cache 30 min (`symbols.py`). Paire ou tick
introuvable → **taux 1.0 + warning** : le risque est alors approximatif mais
jamais absent — ce warning dans les logs est le signal d'un vrai écart.
`initialRR = |TP − entrée| / |SL − entrée|`.

## État et bascule du guard

Tant que `GUARD_ENABLED=false` (état actuel), le démon n'écrit **rien** chez le
broker : le cBot local `RolloverStopLossGuard` doit continuer à tourner autour
de 17h00 NY. Le `TradingTrackerBot` local est, lui, déjà remplacé.

Pour activer le guard (après validation du tracker sur des trades réels, dont
le `riskPercentage` vs cBot) : passer `GUARD_ENABLED=true` dans le `.env` du
droplet puis recréer le service —
`docker compose -f compose.prod.yaml up -d --force-recreate ctrader-daemon`
(un simple `restart` ne relit pas le `.env`). **Ensuite seulement**, arrêter le
cBot guard local : jamais les deux guards en même temps.

## Configuration (env)

| Variable | Rôle |
|---|---|
| `CTRADER_ENV` | `live` ou `demo` (choisit l'endpoint) |
| `CTRADER_CLIENT_ID` / `CTRADER_CLIENT_SECRET` | Identifiants de l'app Open API |
| `CTRADER_ACCESS_TOKEN` / `CTRADER_REFRESH_TOKEN` | Tokens OAuth initiaux (flow décrit dans le plan) |
| `CTRADER_ACCOUNT_ID` | `ctidTraderAccountId` du compte à suivre |
| `TRADING_TRACKER_API_URL` | Base de l'API (`https://trading-tracker.freeddns.org` en prod) |
| `TRADING_TRACKER_API_TOKEN` | Token personnel (profil → Token API cTrader), le même que les cBots |
| `MAX_RISK_EURO` | Base du `riskPercentage`, comme le paramètre « Max Risk » des cBots (défaut 500) |
| `GUARD_ENABLED` | `true` active le rollover guard (défaut **false** : zéro écriture broker) |
| `GUARD_MINUTES_BEFORE` / `GUARD_MINUTES_AFTER` | Fenêtre autour de 17h00 NY (défauts 5 / 10) |
| `CTRADER_TOKENS_FILE` | Persistance des tokens rafraîchis (défaut `tokens.json`, `/data/tokens.json` en conteneur — volume requis) |
| `HEALTH_FILE` | Fichier touché à chaque message reçu (healthcheck Docker) |
| `LOG_LEVEL` | `DEBUG` pour le détail complet des événements (défaut `INFO`) |

## Ré-authentification OAuth (si le refresh token est révoqué/perdu)

1. Ouvrir `https://id.ctrader.com/my/settings/openapi/grantingaccess/?client_id=<CTRADER_CLIENT_ID>&redirect_uri=https%3A%2F%2Ftrading-tracker.freeddns.org%2Fctrader%2Foauth%2Fcallback&scope=trading`, autoriser, copier le `code` dans l'URL de redirection (page 404 attendue).
2. `GET https://openapi.ctrader.com/apps/token?grant_type=authorization_code&code=...&redirect_uri=...&client_id=...&client_secret=...` → `accessToken`/`refreshToken`.
3. Mettre à jour le `.env` du droplet, supprimer le volume de tokens
   (`docker compose -f compose.prod.yaml down ctrader-daemon && docker volume rm trading-tracker_ctrader_tokens`)
   puis redémarrer le service (le fichier de tokens primerait sinon sur l'env).

## Lancer en local

```bash
python3.13 -m venv .venv && .venv/bin/pip install -r requirements.txt \
  && .venv/bin/pip install -U pyOpenSSL service_identity requests tzdata
set -a && source ../.env && set +a   # les CTRADER_* sont dans le .env racine
.venv/bin/python daemon.py
```

Les tokens rafraîchis sont écrits dans `tokens.json` (gitignoré) ; au
démarrage ce fichier prime sur les variables d'env.
