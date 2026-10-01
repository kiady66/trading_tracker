# ctrader-daemon

Démon Python qui remplace les cBots (`ctrader/`) : connecté en permanence à la
**cTrader Open API** (TCP+SSL, `live.ctraderapi.com:5035`), il reçoit les
événements d'exécution du compte et alimentera l'API Trading Tracker
(`/api/trades`). Plan complet : [docs/plans/ctrader-open-api-daemon.html](../docs/plans/ctrader-open-api-daemon.html).

**Garde-fou financier : ce démon ne passe jamais d'ordre.** Aucun code
d'ouverture, de clôture ou de changement de volume n'existe ici, par
construction. La seule écriture broker prévue (phase 3) est l'amendement de SL
du rollover guard (`ProtoOAAmendPositionSLTPReq`).

## État : phase 1 (squelette)

Connexion, authentification app + compte, keepalive (géré par le SDK),
rafraîchissement automatique des tokens, log de tous les événements.
Pas de rattrapage au démarrage (choix assumé) : purement événementiel.

## Configuration (env)

| Variable | Rôle |
|---|---|
| `CTRADER_ENV` | `live` ou `demo` (choisit l'endpoint) |
| `CTRADER_CLIENT_ID` / `CTRADER_CLIENT_SECRET` | Identifiants de l'app Open API |
| `CTRADER_ACCESS_TOKEN` / `CTRADER_REFRESH_TOKEN` | Tokens OAuth initiaux (flow décrit dans le plan) |
| `CTRADER_ACCOUNT_ID` | `ctidTraderAccountId` du compte à suivre |
| `CTRADER_TOKENS_FILE` | Persistance des tokens rafraîchis (défaut `tokens.json`, `/data/tokens.json` en conteneur — volume requis) |
| `HEALTH_FILE` | Fichier touché à chaque message reçu (healthcheck Docker) |
| `LOG_LEVEL` | `DEBUG` pour le détail complet des événements (défaut `INFO`) |

## Lancer en local

```bash
python3.13 -m venv .venv && .venv/bin/pip install -r requirements.txt
set -a && source ../.env && set +a   # les CTRADER_* sont dans le .env racine
.venv/bin/python daemon.py
```

Les tokens rafraîchis sont écrits dans `tokens.json` (gitignoré) ; au
démarrage ce fichier prime sur les variables d'env.
