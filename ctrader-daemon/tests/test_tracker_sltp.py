"""Mise à jour SL/TP (tracker._handle_sltp_change) : le risque et le RR initial
sont figés au PREMIER SL — un déplacement ultérieur (breakeven, trailing)
n'ajoute que le niveau à l'historique, sans toucher au risque pris à l'entrée
(bug du 07/10/2026, trade #326 : risque recalculé sur le dernier SL trainé).
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from ctrader_open_api.messages.OpenApiModelMessages_pb2 import (  # noqa: E402
    ProtoOAPosition,
    ProtoOATradeSide,
)
from twisted.internet import defer  # noqa: E402

from tracker import Tracker  # noqa: E402


class FakeApi:
    def __init__(self, trade):
        self.trade = trade
        self.patches = []

    def find_trade(self, position_id):
        return defer.succeed(self.trade)

    def patch_trade(self, trade_id, payload):
        self.patches.append(payload)
        return defer.succeed(True)


class FakeCatalog:
    loaded = True

    def quote_currency(self, symbol_id):
        return "CHF"

    def conversion_rate(self, currency):
        return defer.succeed(1.2)


def make_position(stop_loss, take_profit=None):
    position = ProtoOAPosition()
    position.positionId = 326
    position.price = 1.10000
    position.tradeData.symbolId = 1
    position.tradeData.tradeSide = ProtoOATradeSide.BUY
    position.tradeData.volume = 14_100_000  # 141 000 unités (centièmes)
    position.stopLoss = stop_loss
    if take_profit is not None:
        position.takeProfit = take_profit
    return position


class SltpChangeTest(unittest.TestCase):
    def _run(self, trade, position):
        api = FakeApi(trade)
        tracker = Tracker(api=api, catalog=FakeCatalog(), max_risk=500.0)
        tracker._handle_sltp_change(position)  # tous les Deferreds sont déjà résolus
        return api.patches

    def test_first_sl_sets_risk_and_initial_rr(self):
        patches = self._run({"id": 1, "lastStopLoss": None},
                            make_position(stop_loss=1.0950, take_profit=1.1150))
        # |1.10 − 1.095| × 141 000 × 1.2 = 846 $ → 169.2 % de 500
        self.assertEqual(patches, [{
            "stopLoss": 1.0950, "riskPercentage": 169.2, "initialRR": 3.0}])

    def test_moved_sl_only_appends_to_history(self):
        patches = self._run({"id": 1, "lastStopLoss": 1.0950},
                            make_position(stop_loss=1.1050, take_profit=1.1150))
        self.assertEqual(patches, [{"stopLoss": 1.1050}])


if __name__ == "__main__":
    unittest.main()
