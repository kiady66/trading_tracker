"""Sérialisation des événements d'exécution (tracker.on_execution_event).

Reproduit la course du 02/10/2026 (trade #319) : un ordre limite avec SL
pré-programmé produit un fill puis un événement SL quasi simultanés — le second
doit attendre la fin du traitement du premier, sinon le SL est perdu.
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from twisted.internet import defer  # noqa: E402

from tracker import Tracker  # noqa: E402


class TrackerQueueTest(unittest.TestCase):
    def setUp(self):
        self.tracker = Tracker(api=None, catalog=None, max_risk=500.0)
        self.calls = []
        self.pending = {}

        def fake_process(event):
            self.calls.append(f"start {event}")
            d = defer.Deferred()
            self.pending[event] = d
            return d.addCallback(lambda _: self.calls.append(f"end {event}"))

        self.tracker._process_event = fake_process

    def test_second_event_waits_for_first(self):
        self.tracker.on_execution_event("fill")
        self.tracker.on_execution_event("sltp")
        # Le fill (POST du trade) n'est pas terminé : l'événement SL attend.
        self.assertEqual(self.calls, ["start fill"])
        self.pending["fill"].callback(None)
        self.assertEqual(self.calls, ["start fill", "end fill", "start sltp"])
        self.pending["sltp"].callback(None)
        self.assertEqual(self.calls, ["start fill", "end fill", "start sltp", "end sltp"])

    def test_failure_does_not_block_queue(self):
        # Comme daemon.py : un errback de log est posé sur chaque événement.
        self.tracker.on_execution_event("boom").addErrback(lambda f: self.calls.append("logged"))
        self.tracker.on_execution_event("next")
        self.assertEqual(self.calls, ["start boom"])
        self.pending["boom"].errback(RuntimeError("API down"))
        self.assertEqual(self.calls, ["start boom", "logged", "start next"])
        self.pending["next"].callback(None)
        self.assertIn("end next", self.calls)


if __name__ == "__main__":
    unittest.main()
