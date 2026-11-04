"""Cycle de vie de l'authentification du démon (daemon.py).

Reproduit les deux pannes de novembre 2026 :
- 01/11 : ProtoOAAccountDisconnectEvent seulement loggé → démon sourd 3 jours
  (la connexion TCP et son heartbeat gardaient le conteneur « healthy ») ;
- 04/11 : boucle infinie de refresh — chaque ProtoOARefreshTokenReq invalide
  l'ancien access token, le serveur pousse un AccountsTokenInvalidatedEvent,
  auquel le démon répondait par… un nouveau refresh.
"""

import os
import sys
import tempfile
import time
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from twisted.internet import defer  # noqa: E402

from ctrader_open_api.messages.OpenApiCommonMessages_pb2 import (  # noqa: E402
    ProtoHeartbeatEvent,
    ProtoMessage,
)
from ctrader_open_api.messages.OpenApiMessages_pb2 import (  # noqa: E402
    ProtoOAAccountDisconnectEvent,
    ProtoOAAccountsTokenInvalidatedEvent,
    ProtoOARefreshTokenReq,
)


def wrap(event) -> ProtoMessage:
    """Enveloppe un payload comme le fait le serveur (Protobuf.extract l'attend)."""
    return ProtoMessage(payloadType=event.payloadType, payload=event.SerializeToString())


class FakeClient:
    def __init__(self):
        self.sent = []

    def send(self, req):
        self.sent.append(req)
        return defer.Deferred()


class DaemonAuthTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        os.environ.update({
            "CTRADER_ENV": "demo",
            "CTRADER_CLIENT_ID": "cid",
            "CTRADER_CLIENT_SECRET": "secret",
            "CTRADER_ACCOUNT_ID": "1",
            "CTRADER_ACCESS_TOKEN": "access",
            "CTRADER_REFRESH_TOKEN": "refresh",
            "CTRADER_TOKENS_FILE": f"{self.tmp.name}/tokens.json",
            "HEALTH_FILE": f"{self.tmp.name}/health",
            "TRADING_TRACKER_API_URL": "http://localhost",
            "TRADING_TRACKER_API_TOKEN": "token",
        })
        from daemon import Daemon
        self.daemon = Daemon()
        self.daemon.client = FakeClient()

    def refresh_requests(self):
        return [r for r in self.daemon.client.sent if isinstance(r, ProtoOARefreshTokenReq)]

    def test_refresh_is_serialized(self):
        """Un seul ProtoOARefreshTokenReq en vol, quel que soit le nombre de
        déclencheurs simultanés (réponse expirée + événement d'invalidation)."""
        self.daemon.refresh_tokens()
        self.daemon.refresh_tokens()
        self.assertEqual(len(self.refresh_requests()), 1)

    def test_invalidation_echo_is_ignored(self):
        """L'invalidation qui suit immédiatement NOTRE refresh est un écho :
        y répondre par un refresh créait la boucle infinie du 04/11/2026."""
        self.daemon._last_refresh_at = time.monotonic()
        self.daemon.on_message(None, wrap(ProtoOAAccountsTokenInvalidatedEvent()))
        self.assertEqual(len(self.refresh_requests()), 0)

    def test_stale_invalidation_triggers_refresh(self):
        """Hors fenêtre d'écho, une invalidation est réelle : refresh."""
        self.daemon._last_refresh_at = time.monotonic() - 3600
        self.daemon.on_message(None, wrap(ProtoOAAccountsTokenInvalidatedEvent()))
        self.assertEqual(len(self.refresh_requests()), 1)
        self.assertFalse(self.daemon.account_alive)

    def test_account_disconnect_schedules_reauth(self):
        """Le serveur peut déconnecter le compte en laissant la connexion TCP
        vivante (01/11/2026) : il faut ré-authentifier, pas seulement logger."""
        reauths = []
        self.daemon._schedule_reauth = lambda: reauths.append(True)
        self.daemon.account_alive = True
        self.daemon.on_message(None, wrap(ProtoOAAccountDisconnectEvent(ctidTraderAccountId=1)))
        self.assertEqual(reauths, [True])
        self.assertFalse(self.daemon.account_alive)

    def test_healthcheck_requires_live_account_session(self):
        """Le fichier de santé n'est touché que si la session compte est
        authentifiée : un heartbeat TCP seul ne doit plus masquer une panne."""
        heartbeat = wrap(ProtoHeartbeatEvent())
        os.utime(self.daemon.health_file, (1, 1))

        self.daemon.account_alive = False
        self.daemon.on_message(None, heartbeat)
        self.assertEqual(self.daemon.health_file.stat().st_mtime, 1)

        self.daemon.account_alive = True
        self.daemon.on_message(None, heartbeat)
        self.assertGreater(self.daemon.health_file.stat().st_mtime, 1)


if __name__ == "__main__":
    unittest.main()
