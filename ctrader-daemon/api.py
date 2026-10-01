"""Client REST Trading Tracker (/api/trades) — mêmes appels que les cBots.

Les requêtes (bibliothèque requests, synchrone) tournent dans le threadpool de
Twisted pour ne jamais bloquer le réacteur. Chaque appel est loggé avec sa
méthode, son URL et son code HTTP.
"""

import logging

import requests
from twisted.internet.threads import deferToThread

log = logging.getLogger("ctrader-daemon.api")


class TradingTrackerApi:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url.rstrip("/")
        self.headers = {"Authorization": f"Bearer {token}"}

    # --- API publique (deferred) -------------------------------------------

    def find_trade(self, position_id: int):
        """Deferred → trade (dict) lié à cette position cTrader, ou None."""
        return deferToThread(self._find_trade, position_id)

    def post_trade(self, payload: dict):
        """Deferred → trade créé (dict), ou None en cas d'échec."""
        return deferToThread(self._post_trade, payload)

    def patch_trade(self, trade_id: int, payload: dict):
        """Deferred → True si la mise à jour a réussi."""
        return deferToThread(self._patch_trade, trade_id, payload)

    # --- Implémentation synchrone (threadpool) -----------------------------

    def _request(self, method: str, path: str, payload: dict | None = None):
        url = f"{self.base_url}{path}"
        try:
            response = requests.request(method, url, json=payload, headers=self.headers, timeout=10)
        except requests.RequestException as exc:
            log.error("✗ %s %s — erreur réseau : %s", method, url, exc)
            return None
        level = logging.DEBUG if response.ok else logging.ERROR
        log.log(level, "%s %s — HTTP %s%s", method, url, response.status_code,
                "" if response.ok else f" — {response.text[:300]}")
        return response

    def _find_trade(self, position_id: int):
        response = self._request("GET", f"/api/trades?ctraderPositionId={position_id}")
        if response is None or not response.ok:
            return None
        trades = response.json()
        return trades[0] if trades else None

    def _post_trade(self, payload: dict):
        response = self._request("POST", "/api/trades", payload)
        if response is None or not response.ok:
            return None
        return response.json()

    def _patch_trade(self, trade_id: int, payload: dict) -> bool:
        response = self._request("PATCH", f"/api/trades/{trade_id}", payload)
        return response is not None and response.ok
