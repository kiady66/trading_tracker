# ctrader-daemon

Démon Python qui remplace les cBots (`ctrader/`) : connecté en permanence à la
**cTrader Open API** (TCP+SSL, `live.ctraderapi.com:5035`), il reçoit les
événements d'exécution du compte et alimentera l'API Trading Tracker
(`/api/trades`). Plan complet : [docs/plans/ctrader-open-api-daemon.html](../docs/plans/ctrader-open-api-daemon.html).

**Garde-fou financier : ce démon ne passe jamais d'ordre.** Aucun code
d'ouverture, de clôture ou de changement de volume n'existe ici, par
construction. La seule écriture broker prévue (phase 3) est l'amendement de SL
du rollover guard (`ProtoOAAmendPositionSLTPReq`).

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
