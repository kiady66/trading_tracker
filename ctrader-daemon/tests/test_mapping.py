"""Tests des fonctions pures (sans SDK) : python3 -m unittest discover tests"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from mapping import (
    compute_initial_rr,
    compute_net_profit,
    compute_risk_percentage,
    normalize_symbol,
)


class NormalizeSymbolTest(unittest.TestCase):
    def test_forex_pair(self):
        self.assertEqual(normalize_symbol("EURUSD"), "EUR/USD")
        self.assertEqual(normalize_symbol("GBPNZD"), "GBP/NZD")
        self.assertEqual(normalize_symbol("NZDCHF"), "NZD/CHF")
        self.assertEqual(normalize_symbol("EURAUD"), "EUR/AUD")
        self.assertEqual(normalize_symbol("EURCAD"), "EUR/CAD")

    def test_broker_suffix_stripped(self):
        self.assertEqual(normalize_symbol("EURUSD.i"), "EUR/USD")
        self.assertEqual(normalize_symbol("usdjpy.s"), "USD/JPY")

    def test_special_symbols(self):
        self.assertEqual(normalize_symbol("SP500"), "SP500")
        self.assertEqual(normalize_symbol("NAS100"), "NAS100")
        self.assertEqual(normalize_symbol("USWTI"), "USOIL")
        self.assertEqual(normalize_symbol("USWTI.p"), "USOIL")

    def test_already_formatted(self):
        self.assertEqual(normalize_symbol("XAU/USD"), "XAU/USD")

    def test_unsupported(self):
        self.assertIsNone(normalize_symbol("USDMXN"))   # paire hors liste
        self.assertIsNone(normalize_symbol("GER40"))    # indice non supporté
        self.assertIsNone(normalize_symbol("XRPUSDT"))  # longueur inattendue


class RiskComputationTest(unittest.TestCase):
    def test_risk_percentage_quote_is_deposit(self):
        # Long EUR/USD 10 000 unités, SL à 50 pips : perte = 0.0050 × 10 000 = 50 $
        self.assertEqual(
            compute_risk_percentage(1.1000, 1.0950, 10_000, 1.0, 500.0), 10.0)

    def test_risk_percentage_with_conversion(self):
        # USD/JPY : perte en JPY convertie en devise du compte (taux 1/150)
        self.assertEqual(
            compute_risk_percentage(150.00, 149.50, 10_000, 1 / 150.0, 500.0), 6.67)

    def test_initial_rr(self):
        self.assertEqual(compute_initial_rr(1.1000, 1.0950, 1.1150), 3.0)
        self.assertEqual(compute_initial_rr(1.1000, 1.1050, 1.0900), 2.0)  # short

    def test_initial_rr_zero_distance(self):
        self.assertIsNone(compute_initial_rr(1.1000, 1.1000, 1.1150))


class NetProfitTest(unittest.TestCase):
    def test_loss_with_commission(self):
        # Trade #319 (02/10/2026) : perte brute 162.74 $ + 31.34 $ de commissions
        self.assertEqual(compute_net_profit(-16274, 0, -3134, 0, 2), -194.08)

    def test_positive_swap_credits_the_result(self):
        self.assertEqual(compute_net_profit(10000, 250, -300, 0, 2), 99.50)

    def test_conversion_fee_is_always_a_cost(self):
        # Quel que soit le signe renvoyé par le broker
        self.assertEqual(compute_net_profit(10000, 0, 0, 120, 2), 98.80)
        self.assertEqual(compute_net_profit(10000, 0, 0, -120, 2), 98.80)

    def test_money_digits_scaling(self):
        self.assertEqual(compute_net_profit(-194080, 0, 0, 0, 3), -194.08)


if __name__ == "__main__":
    unittest.main()
