"""Catalogue des symboles du compte et conversion de devises (lecture seule).

- symbolId → nom broker et asset Trading Tracker (via mapping.normalize_symbol) ;
- taux de conversion devise de cotation → devise du compte pour le calcul du
  risque : obtenu par une souscription spot éphémère sur la paire de conversion
  (ex: USDJPY pour JPY → USD), mis en cache 30 minutes.
"""

import logging
import time

from ctrader_open_api import Protobuf
from ctrader_open_api.messages.OpenApiMessages_pb2 import (
    ProtoOAAssetListReq,
    ProtoOASubscribeSpotsReq,
    ProtoOASymbolsListReq,
    ProtoOATraderReq,
    ProtoOAUnsubscribeSpotsReq,
)
from twisted.internet import defer, reactor

from mapping import normalize_symbol

log = logging.getLogger("ctrader-daemon.symbols")

SPOT_PRICE_SCALE = 100000.0  # prix des ProtoOASpotEvent en 1/100000
RATE_CACHE_SECONDS = 1800


class SymbolCatalog:
    def __init__(self, client, account_id: int):
        self.client = client
        self.account_id = account_id
        self.loaded = False
        self._symbols = {}       # symbolId → (nom broker, asset normalisé|None, devise de cotation)
        self._by_clean_name = {}  # nom broker sans suffixe → symbolId
        self.deposit_currency = None
        self._rates = {}          # devise → (taux, timestamp)
        self._spot_waiters = {}   # symbolId → [Deferred]

    @defer.inlineCallbacks
    def load(self):
        res = yield self.client.send(ProtoOATraderReq(ctidTraderAccountId=self.account_id))
        trader = Protobuf.extract(res).trader

        res = yield self.client.send(ProtoOAAssetListReq(ctidTraderAccountId=self.account_id))
        assets = {a.assetId: a.name for a in Protobuf.extract(res).asset}
        self.deposit_currency = assets.get(trader.depositAssetId)

        res = yield self.client.send(ProtoOASymbolsListReq(ctidTraderAccountId=self.account_id))
        supported = 0
        for symbol in Protobuf.extract(res).symbol:
            asset = normalize_symbol(symbol.symbolName)
            quote = assets.get(symbol.quoteAssetId)
            self._symbols[symbol.symbolId] = (symbol.symbolName, asset, quote)
            self._by_clean_name[symbol.symbolName.split(".")[0].upper()] = symbol.symbolId
            supported += asset is not None

        self.loaded = True
        log.info("Catalogue chargé : %d symboles (%d supportés), devise du compte %s",
                 len(self._symbols), supported, self.deposit_currency)

    def raw_name(self, symbol_id: int) -> str:
        return self._symbols.get(symbol_id, (f"symbolId {symbol_id}", None, None))[0]

    def asset_of(self, symbol_id: int) -> str | None:
        return self._symbols.get(symbol_id, (None, None, None))[1]

    def quote_currency(self, symbol_id: int) -> str | None:
        return self._symbols.get(symbol_id, (None, None, None))[2]

    # --- Conversion devise de cotation → devise du compte -------------------

    @defer.inlineCallbacks
    def conversion_rate(self, quote: str | None):
        """Taux devise de cotation → devise du compte. 1.0 (avec warning) si
        introuvable : le riskPercentage sera alors approximatif, jamais absent."""
        if quote is None or quote == self.deposit_currency:
            return 1.0

        cached = self._rates.get(quote)
        if cached and time.time() - cached[1] < RATE_CACHE_SECONDS:
            return cached[0]

        direct = self._by_clean_name.get(f"{quote}{self.deposit_currency}")
        inverse = self._by_clean_name.get(f"{self.deposit_currency}{quote}")
        symbol_id = direct if direct is not None else inverse
        if symbol_id is None:
            log.warning("Aucune paire de conversion %s → %s — taux 1.0 utilisé",
                        quote, self.deposit_currency)
            return 1.0

        try:
            price = yield self._spot_price(symbol_id)
        except Exception as exc:
            log.warning("Prix de conversion %s indisponible (%s) — taux 1.0 utilisé",
                        self.raw_name(symbol_id), exc)
            return 1.0

        rate = price if direct is not None else 1.0 / price
        self._rates[quote] = (rate, time.time())
        log.debug("Taux %s → %s : %s", quote, self.deposit_currency, rate)
        return rate

    @defer.inlineCallbacks
    def _spot_price(self, symbol_id: int):
        waiter = defer.Deferred()
        self._spot_waiters.setdefault(symbol_id, []).append(waiter)
        waiter.addTimeout(10, reactor)

        req = ProtoOASubscribeSpotsReq(ctidTraderAccountId=self.account_id)
        req.symbolId.append(symbol_id)
        yield self.client.send(req)
        try:
            price = yield waiter
        finally:
            unsub = ProtoOAUnsubscribeSpotsReq(ctidTraderAccountId=self.account_id)
            unsub.symbolId.append(symbol_id)
            self.client.send(unsub).addErrback(lambda f: None)
        return price

    def on_spot(self, event) -> None:
        """À brancher sur les ProtoOASpotEvent reçus par le démon."""
        waiters = self._spot_waiters.pop(event.symbolId, [])
        if not waiters or not event.HasField("bid"):
            if waiters:
                self._spot_waiters[event.symbolId] = waiters
            return
        price = event.bid / SPOT_PRICE_SCALE
        for waiter in waiters:
            if not waiter.called:
                waiter.callback(price)
