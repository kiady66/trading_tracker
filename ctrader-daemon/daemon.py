#!/usr/bin/env python3
"""Démon Trading Tracker ↔ cTrader Open API.

Connexion TCP+SSL, authentification application + compte, keepalive (géré par
le SDK), rafraîchissement automatique des tokens, puis :
- tracker (tracker.py)  : réplique les trades du compte dans Trading Tracker ;
- rollover guard (guard.py) : retire/restaure les SL autour de 17h00 New York
  (désactivé par défaut — GUARD_ENABLED).

GARDE-FOU FINANCIER : ce démon ne passe JAMAIS d'ordre. Aucune requête
d'ouverture, de clôture ou de modification de volume n'est implémentée. La
seule écriture broker est l'amendement de SL du rollover guard
(ProtoOAAmendPositionSLTPReq).
"""

import json
import logging
import os
import sys
from pathlib import Path

from ctrader_open_api import Client, EndPoints, Protobuf, TcpProtocol
from ctrader_open_api.messages.OpenApiMessages_pb2 import (
    ProtoOAAccountAuthReq,
    ProtoOAApplicationAuthReq,
    ProtoOARefreshTokenReq,
)
from twisted.internet import reactor

from api import TradingTrackerApi
from guard import RolloverGuard
from symbols import SymbolCatalog
from tracker import Tracker

logging.basicConfig(
    stream=sys.stdout,
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
log = logging.getLogger("ctrader-daemon")

# Codes d'erreur Open API signalant un access token à rafraîchir.
EXPIRED_TOKEN_ERRORS = {"CH_ACCESS_TOKEN_INVALID", "ACCESS_TOKEN_EXPIRED", "INVALID_TOKEN"}


def require_env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        log.critical("Variable d'environnement %s manquante", name)
        sys.exit(1)
    return value


class TokenStore:
    """Tokens initialisés depuis l'env, puis persistés dans un fichier (volume
    en prod) : un token rafraîchi doit survivre aux redémarrages, car l'ancien
    access token devient invalide."""

    def __init__(self, path: str, access: str, refresh: str):
        self.path = Path(path)
        self.access = access
        self.refresh = refresh
        if self.path.exists():
            data = json.loads(self.path.read_text())
            self.access = data["accessToken"]
            self.refresh = data["refreshToken"]
            log.info("Tokens rechargés depuis %s", self.path)

    def save(self, access: str, refresh: str) -> None:
        self.access = access
        self.refresh = refresh
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps({"accessToken": access, "refreshToken": refresh}))
        tmp.replace(self.path)
        log.info("Tokens rafraîchis et persistés dans %s", self.path)


class Daemon:
    def __init__(self):
        env = require_env("CTRADER_ENV")
        if env not in ("live", "demo"):
            log.critical("CTRADER_ENV doit être 'live' ou 'demo', reçu : %r", env)
            sys.exit(1)
        self.client_id = require_env("CTRADER_CLIENT_ID")
        self.client_secret = require_env("CTRADER_CLIENT_SECRET")
        self.account_id = int(require_env("CTRADER_ACCOUNT_ID"))
        self.tokens = TokenStore(
            os.environ.get("CTRADER_TOKENS_FILE", "tokens.json"),
            require_env("CTRADER_ACCESS_TOKEN"),
            require_env("CTRADER_REFRESH_TOKEN"),
        )
        self.health_file = Path(os.environ.get("HEALTH_FILE", "/tmp/ctrader-daemon-health"))

        host = EndPoints.PROTOBUF_LIVE_HOST if env == "live" else EndPoints.PROTOBUF_DEMO_HOST
        log.info("Démon cTrader — %s (%s:%s), compte %s", env, host, EndPoints.PROTOBUF_PORT, self.account_id)
        self.client = Client(host, EndPoints.PROTOBUF_PORT, TcpProtocol)
        self.client.setConnectedCallback(self.on_connected)
        self.client.setDisconnectedCallback(self.on_disconnected)
        self.client.setMessageReceivedCallback(self.on_message)

        api = TradingTrackerApi(
            require_env("TRADING_TRACKER_API_URL"),
            require_env("TRADING_TRACKER_API_TOKEN"),
        )
        self.catalog = SymbolCatalog(self.client, self.account_id)
        self.tracker = Tracker(api, self.catalog, float(os.environ.get("MAX_RISK_EURO", "500")))
        self.guard = RolloverGuard(
            self.client, api, self.account_id,
            enabled=os.environ.get("GUARD_ENABLED", "false").lower() in ("1", "true", "yes"),
            minutes_before=int(os.environ.get("GUARD_MINUTES_BEFORE", "5")),
            minutes_after=int(os.environ.get("GUARD_MINUTES_AFTER", "10")),
        )

    def run(self) -> None:
        self.client.startService()
        reactor.run()

    # --- Authentification -------------------------------------------------

    def on_connected(self, client) -> None:
        log.info("Connecté — authentification de l'application…")
        req = ProtoOAApplicationAuthReq()
        req.clientId = self.client_id
        req.clientSecret = self.client_secret
        client.send(req).addCallbacks(self.on_app_auth, self.fail)

    def on_app_auth(self, message) -> None:
        if self.is_error(message, "authentification application"):
            return
        log.info("Application authentifiée — authentification du compte %s…", self.account_id)
        self.auth_account()

    def auth_account(self) -> None:
        req = ProtoOAAccountAuthReq()
        req.ctidTraderAccountId = self.account_id
        req.accessToken = self.tokens.access
        self.client.send(req).addCallbacks(self.on_account_auth, self.fail)

    def on_account_auth(self, message) -> None:
        if self.is_error(message, "authentification compte"):
            return
        log.info("✓ Compte %s authentifié — chargement du catalogue de symboles…", self.account_id)
        d = self.catalog.load()
        d.addCallbacks(self.on_ready, self.fail)

    def on_ready(self, _=None) -> None:
        log.info("✓ Démon prêt — tracker en écoute des événements d'exécution")
        self.guard.start()

    def refresh_tokens(self) -> None:
        log.info("Access token expiré — rafraîchissement…")
        req = ProtoOARefreshTokenReq()
        req.refreshToken = self.tokens.refresh
        self.client.send(req).addCallbacks(self.on_tokens_refreshed, self.fail)

    def on_tokens_refreshed(self, message) -> None:
        if self.is_error(message, "rafraîchissement des tokens"):
            return
        res = Protobuf.extract(message)
        # Spotware peut renvoyer un refreshToken vide : on garde alors l'actuel.
        self.tokens.save(res.accessToken, res.refreshToken or self.tokens.refresh)
        self.auth_account()

    # --- Réception --------------------------------------------------------

    def on_message(self, client, message) -> None:
        self.health_file.touch()
        event = Protobuf.extract(message)
        name = event.DESCRIPTOR.name

        if name == "ProtoHeartbeatEvent":
            log.debug("Heartbeat serveur")
        elif name == "ProtoOAExecutionEvent":
            d = self.tracker.on_execution_event(event)
            d.addErrback(lambda f: log.error("Erreur du tracker : %s", f.getTraceback()))
        elif name == "ProtoOASpotEvent":
            self.catalog.on_spot(event)
        elif name == "ProtoOAAccountsTokenInvalidatedEvent":
            log.warning("Token invalidé par le serveur — rafraîchissement")
            self.refresh_tokens()
        elif name in ("ProtoOAClientDisconnectEvent", "ProtoOAAccountDisconnectEvent"):
            log.warning("Déconnexion signalée par le serveur : %s", event)
        elif name.endswith("Res"):
            log.debug("Réponse %s (traitée par son callback)", name)
        else:
            log.info("Message %s :\n%s", name, event)

    def on_disconnected(self, client, reason) -> None:
        # ClientService (Twisted) retente automatiquement avec backoff exponentiel,
        # puis on_connected ré-authentifie tout.
        log.warning("Déconnecté : %s — reconnexion automatique…", reason.getErrorMessage())

    # --- Erreurs ----------------------------------------------------------

    def is_error(self, message, context: str) -> bool:
        """True si la réponse est un ProtoOAErrorRes (loggé, token rafraîchi si expiré)."""
        event = Protobuf.extract(message)
        if event.DESCRIPTOR.name != "ProtoOAErrorRes":
            return False
        if event.errorCode in EXPIRED_TOKEN_ERRORS:
            self.refresh_tokens()
        else:
            log.error("Erreur Open API (%s) : %s — %s", context, event.errorCode, event.description)
        return True

    def fail(self, failure) -> None:
        log.error("Échec d'envoi : %s", failure.getErrorMessage())


if __name__ == "__main__":
    Daemon().run()
