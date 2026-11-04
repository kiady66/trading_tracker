"""Résilience des ticks du rollover guard.

Reproduit la panne du 01–04/11/2026 : un reconcile en erreur faisait échouer le
deferred du tick, ce qui arrêtait DÉFINITIVEMENT le LoopingCall — guard mort en
silence. Les erreurs doivent être avalées (tick suivant = nouvelle tentative)
et une remise de SL échouée ne doit jamais être abandonnée.
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from twisted.internet import defer  # noqa: E402

from guard import RolloverGuard  # noqa: E402


class GuardTickTest(unittest.TestCase):
    def setUp(self):
        self.guard = RolloverGuard(client=None, api=None, account_id=1, enabled=True)

    def test_remove_failure_is_swallowed(self):
        """Un échec du retrait ne doit pas tuer le LoopingCall : le tick
        consomme l'erreur et le tick suivant retente."""
        self.guard._is_in_window = lambda now: True
        calls = []

        def failing_remove():
            calls.append("remove")
            return defer.fail(RuntimeError("reconcile refusé"))

        self.guard.remove_stop_losses = failing_remove
        self.guard._tick()
        self.guard._tick()
        self.assertEqual(calls, ["remove", "remove"])

    def test_restore_failure_is_retried_next_tick(self):
        """_in_window ne tombe qu'après une remise SANS erreur : un échec
        transitoire à la fin de fenêtre est retenté, jamais abandonné."""
        self.guard._in_window = True
        self.guard._is_in_window = lambda now: False

        self.guard.restore_stop_losses = lambda: defer.fail(RuntimeError("boom"))
        self.guard._tick()
        self.assertTrue(self.guard._in_window)

        self.guard.restore_stop_losses = lambda: defer.succeed(None)
        self.guard._tick()
        self.assertFalse(self.guard._in_window)

    def test_start_outside_window_forces_restore_pass(self):
        """Au démarrage hors fenêtre, la branche « remise » du premier tick est
        forcée : les SL encore en base (démon mort pendant la fenêtre) sont
        restaurés avec la même logique de retry que le reste."""
        self.guard._is_in_window = lambda now: False
        self.guard.start()
        try:
            self.assertTrue(self.guard._in_window)
        finally:
            self.guard._loop.stop()


if __name__ == "__main__":
    unittest.main()
