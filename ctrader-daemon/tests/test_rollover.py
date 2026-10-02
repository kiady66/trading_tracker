import unittest
from datetime import datetime, timedelta

from rollover import is_in_rollover_window


def ny(day: int, hour: int, minute: int) -> datetime:
    """Heure de New York du jour donné (0 = lundi) de la semaine du 2026-09-28."""
    return datetime(2026, 9, 28, hour, minute) + timedelta(days=day)


class IsInRolloverWindowTest(unittest.TestCase):
    def test_semaine_fenetre_quotidienne(self):
        for day in range(4):  # lundi → jeudi
            self.assertFalse(is_in_rollover_window(ny(day, 16, 54)))
            self.assertTrue(is_in_rollover_window(ny(day, 16, 55)))
            self.assertTrue(is_in_rollover_window(ny(day, 17, 0)))
            self.assertTrue(is_in_rollover_window(ny(day, 18, 14)))
            self.assertFalse(is_in_rollover_window(ny(day, 18, 15)))
            self.assertFalse(is_in_rollover_window(ny(day, 12, 0)))

    def test_vendredi_retrait_avance_puis_fenetre_ouverte(self):
        friday = 4
        self.assertFalse(is_in_rollover_window(ny(friday, 16, 44)))
        self.assertTrue(is_in_rollover_window(ny(friday, 16, 45)))
        self.assertTrue(is_in_rollover_window(ny(friday, 17, 30)))
        # Pas de remise le vendredi soir : la fenêtre court jusqu'au dimanche.
        self.assertTrue(is_in_rollover_window(ny(friday, 23, 59)))

    def test_samedi_toujours_en_fenetre(self):
        saturday = 5
        self.assertTrue(is_in_rollover_window(ny(saturday, 0, 0)))
        self.assertTrue(is_in_rollover_window(ny(saturday, 12, 0)))
        self.assertTrue(is_in_rollover_window(ny(saturday, 23, 59)))

    def test_dimanche_remise_apres_ouverture(self):
        sunday = 6
        self.assertTrue(is_in_rollover_window(ny(sunday, 0, 0)))
        self.assertTrue(is_in_rollover_window(ny(sunday, 17, 0)))  # ouverture du marché
        self.assertTrue(is_in_rollover_window(ny(sunday, 18, 14)))
        self.assertFalse(is_in_rollover_window(ny(sunday, 18, 15)))
        self.assertFalse(is_in_rollover_window(ny(sunday, 23, 0)))


if __name__ == "__main__":
    unittest.main()
