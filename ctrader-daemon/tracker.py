"""Tracker : réplique les trades du compte dans Trading Tracker depuis les
événements d'exécution Open API (reprend l'ex-cBot TradingTrackerBot.cs, supprimé).

Purement événementiel (pas de rattrapage, choix assumé) :
- fill d'ouverture        → POST /api/trades (si la position n'est pas déjà trackée)
- SL/TP modifié           → PATCH {riskPercentage, stopLoss, initialRR}
- fill de clôture (reçu)  → PATCH {exit: {...}} (+ closed sur la clôture totale)

Aucune écriture broker ici : ce module ne fait que réagir à ce que le trader
a déjà exécuté lui-même.
"""

import logging
from datetime import datetime, timezone

from ctrader_open_api.messages.OpenApiModelMessages_pb2 import (
    ProtoOAExecutionType,
    ProtoOAOrderType,
    ProtoOAPositionStatus,
    ProtoOATradeSide,
)
from twisted.internet import defer

from mapping import compute_initial_rr, compute_risk_percentage

log = logging.getLogger("ctrader-daemon.tracker")

VOLUME_SCALE = 100.0  # volumes Open API en centièmes d'unités


def iso_from_ms(timestamp_ms: int) -> str:
    return datetime.fromtimestamp(timestamp_ms / 1000, tz=timezone.utc).isoformat()


class Tracker:
    def __init__(self, api, catalog, max_risk: float):
        self.api = api
        self.catalog = catalog
        self.max_risk = max_risk
        self._sent_deal_ids = set()  # l'API dédoublonne par dealId de toute façon

    @defer.inlineCallbacks
    def on_execution_event(self, event):
        if not self.catalog.loaded or not event.HasField("position"):
            return
        position = event.position
        exec_type = event.executionType

        is_fill = exec_type in (ProtoOAExecutionType.ORDER_FILLED,
                                ProtoOAExecutionType.ORDER_PARTIAL_FILL) and event.HasField("deal")
        is_sltp_change = exec_type in (ProtoOAExecutionType.ORDER_ACCEPTED,
                                       ProtoOAExecutionType.ORDER_REPLACED) \
            and event.HasField("order") \
            and event.order.orderType == ProtoOAOrderType.STOP_LOSS_TAKE_PROFIT
        if not is_fill and not is_sltp_change:
            log.debug("Événement %s ignoré (position #%s)",
                      ProtoOAExecutionType.Name(exec_type), position.positionId)
            return

        symbol_id = position.tradeData.symbolId
        asset = self.catalog.asset_of(symbol_id)
        if asset is None:
            if is_fill:
                log.info("Symbole '%s' non supporté — position #%s ignorée",
                         self.catalog.raw_name(symbol_id), position.positionId)
            return

        if is_fill and event.deal.HasField("closePositionDetail"):
            yield self._handle_close(position, event.deal)
        elif is_fill:
            yield self._handle_open(position, asset)
        else:
            yield self._handle_sltp_change(position)

    # --- Ouverture ----------------------------------------------------------

    @defer.inlineCallbacks
    def _handle_open(self, position, asset: str):
        position_id = position.positionId
        existing = yield self.api.find_trade(position_id)
        if existing is not None:
            # Renforcement de position (même id) : on rafraîchit risque et SL.
            yield self._handle_sltp_change(position)
            return

        entry = position.price
        volume_units = position.tradeData.volume / VOLUME_SCALE
        payload = {
            "asset": asset,
            "orderType": "buy market" if position.tradeData.tradeSide == ProtoOATradeSide.BUY else "sell market",
            "entryDate": iso_from_ms(position.tradeData.openTimestamp),
            "maxRiskEuro": self.max_risk,
            "ctraderPositionId": position_id,
            "entryPrice": entry,
            "volumeInUnits": volume_units,
        }

        if position.HasField("stopLoss"):
            rate = yield self.catalog.conversion_rate(self.catalog.quote_currency(position.tradeData.symbolId))
            payload["stopLoss"] = position.stopLoss
            payload["riskPercentage"] = compute_risk_percentage(
                entry, position.stopLoss, volume_units, rate, self.max_risk)
            if position.HasField("takeProfit"):
                payload["targetPrice"] = position.takeProfit
                payload["initialRR"] = compute_initial_rr(entry, position.stopLoss, position.takeProfit)
        else:
            log.warning("⚠ Pas de SL défini pour la position #%s — riskPercentage = 100%%", position_id)
            payload["riskPercentage"] = 100.0
            if position.HasField("takeProfit"):
                payload["targetPrice"] = position.takeProfit

        created = yield self.api.post_trade(payload)
        if created is not None:
            log.info("✓ Trade #%s créé (%s, position #%s)", created.get("id"), asset, position_id)
        else:
            log.error("✗ Échec de création du trade pour la position #%s", position_id)

    # --- Modification SL/TP ---------------------------------------------------

    @defer.inlineCallbacks
    def _handle_sltp_change(self, position):
        position_id = position.positionId
        trade = yield self.api.find_trade(position_id)
        if trade is None:
            log.info("Trade non trouvé en DB pour la position #%s — modification ignorée (pas de rattrapage)",
                     position_id)
            return

        if not position.HasField("stopLoss"):
            log.debug("SL retiré sur la position #%s (ex: rollover) — rien à mettre à jour", position_id)
            return

        entry = position.price
        rate = yield self.catalog.conversion_rate(self.catalog.quote_currency(position.tradeData.symbolId))
        payload = {
            "stopLoss": position.stopLoss,
            "riskPercentage": compute_risk_percentage(
                entry, position.stopLoss, position.tradeData.volume / VOLUME_SCALE, rate, self.max_risk),
        }
        if position.HasField("takeProfit"):
            rr = compute_initial_rr(entry, position.stopLoss, position.takeProfit)
            if rr is not None:
                payload["initialRR"] = rr

        ok = yield self.api.patch_trade(trade["id"], payload)
        if ok:
            log.info("✓ Trade #%s mis à jour (SL %s, position #%s)", trade["id"], position.stopLoss, position_id)

    # --- Clôture (partielle ou totale, exécutée par le trader/broker) ---------

    @defer.inlineCallbacks
    def _handle_close(self, position, deal):
        if deal.dealId in self._sent_deal_ids:
            return
        position_id = position.positionId
        trade = yield self.api.find_trade(position_id)
        if trade is None:
            log.info("Trade non trouvé en DB pour la position #%s — clôture ignorée (pas de rattrapage)",
                     position_id)
            return

        volume = (deal.filledVolume or deal.volume) / VOLUME_SCALE
        payload = {
            "exit": {
                "dealId": str(deal.dealId),
                "price": deal.executionPrice,
                "volume": volume,
                "date": iso_from_ms(deal.executionTimestamp),
            },
        }
        fully_closed = position.positionStatus == ProtoOAPositionStatus.POSITION_STATUS_CLOSED
        if fully_closed:
            payload["closed"] = True

        ok = yield self.api.patch_trade(trade["id"], payload)
        if ok:
            self._sent_deal_ids.add(deal.dealId)
            log.info("✓ Trade #%s : sortie %s @ %s (deal #%s%s)", trade["id"], volume,
                     deal.executionPrice, deal.dealId, ", clôture totale" if fully_closed else "")
