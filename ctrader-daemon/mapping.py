"""Normalisation des symboles broker et calculs de risque — fonctions pures.

Sans dépendance au SDK pour rester testables avec un simple `python -m unittest`.
"""

# Doit rester synchronisé avec Trade::ALLOWED_ASSETS (src/Entity/Trade.php).
SUPPORTED_ASSETS = {
    "EUR/USD", "GBP/USD", "USD/JPY", "USD/CHF", "AUD/USD", "USD/CAD", "NZD/USD",
    "EUR/GBP", "EUR/JPY", "EUR/CHF", "EUR/NZD", "GBP/JPY", "GBP/CHF", "NZD/JPY",
    "AUD/GBP", "AUD/NZD", "AUD/CAD", "NZD/CAD", "AUD/CHF", "AUD/JPY", "GBP/CAD",
    "GBP/AUD", "GBP/NZD", "CAD/CHF", "CAD/JPY", "CHF/JPY",
    "BTC/USD", "ETH/USD", "XAU/USD", "SP500", "NAS100", "USOIL",
}

# Symboles broker dont le nom Trading Tracker n'est pas déductible mécaniquement.
SPECIAL_SYMBOLS = {
    "SP500": "SP500",
    "NAS100": "NAS100",
    "USWTI": "USOIL",
}


def normalize_symbol(symbol_name: str) -> str | None:
    """Convertit un symbole broker (ex: "EURUSD.i") vers le format Trading
    Tracker (ex: "EUR/USD"). None si le symbole n'est pas supporté."""
    name = symbol_name.split(".")[0].upper()

    if name in SPECIAL_SYMBOLS:
        candidate = SPECIAL_SYMBOLS[name]
    elif "/" in name:
        candidate = name
    elif len(name) == 6:
        candidate = f"{name[:3]}/{name[3:]}"
    else:
        return None

    return candidate if candidate in SUPPORTED_ASSETS else None


def compute_risk_percentage(entry: float, stop_loss: float, volume_units: float,
                            quote_to_deposit_rate: float, max_risk: float) -> float:
    """Perte au SL en devise du compte, en % du risque max — même formule que le
    cBot (|entrée − SL| × volume = perte en devise de cotation, convertie)."""
    loss = abs(entry - stop_loss) * volume_units * quote_to_deposit_rate
    return round(loss / max_risk * 100.0, 2)


def compute_initial_rr(entry: float, stop_loss: float, take_profit: float) -> float | None:
    sl_distance = abs(entry - stop_loss)
    if sl_distance <= 0:
        return None
    return round(abs(take_profit - entry) / sl_distance, 2)
