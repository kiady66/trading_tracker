"""Fenêtre du rollover guard — fonction pure, testable sans SDK.

Le rollover (swap) a lieu à 17h00 New York, mais le spread reste large bien
au-delà : la remise des SL attend 18h15. Le week-end est traité comme une seule
grande fenêtre : le marché ferme vendredi 17h00 et rouvre dimanche 17h00 sur un
spread d'ouverture élargi (et d'éventuels gaps) — les SL sont retirés vendredi
16h45 et remis dimanche 18h15. Entre les deux le marché est fermé : les
positions ne risquent rien sans SL.

Toutes les heures sont en temps de New York (le fuseau absorbe les décalages
été/hiver entre les États-Unis et l'Europe).

NB : pendant une fenêtre, un SL remis à la main est retiré de nouveau par le
guard (après sauvegarde en base, comme toujours).
"""

from datetime import datetime, time

REMOVE_WEEKDAY = time(16, 55)  # lundi–jeudi
REMOVE_FRIDAY = time(16, 45)   # vendredi, avant la fermeture du marché à 17h00
RESTORE = time(18, 15)         # lundi–jeudi et dimanche, une fois le spread calmé


def is_in_rollover_window(now_ny: datetime) -> bool:
    """True si les SL doivent être retirés à cet instant (heure de New York)."""
    weekday = now_ny.weekday()  # lundi = 0 … dimanche = 6
    now = now_ny.time()

    if weekday <= 3:  # lundi–jeudi : fenêtre quotidienne
        return REMOVE_WEEKDAY <= now < RESTORE
    if weekday == 4:  # vendredi : entrée dans la fenêtre de week-end
        return now >= REMOVE_FRIDAY
    if weekday == 5:  # samedi : marché fermé, SL laissés retirés
        return True
    return now < RESTORE  # dimanche : remise après l'ouverture de 17h00
