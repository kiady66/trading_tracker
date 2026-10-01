"""Rollover guard : porte RolloverStopLossGuard.cs.

Retire les stop loss juste avant le rollover quotidien (17h00 New York, où le
spread s'élargit) et les restaure juste après. Le niveau est TOUJOURS persisté
en base (historique `stopLosses` du trade) AVANT le retrait — si l'API échoue,
le SL est laissé en place.

Seule écriture broker de tout le démon : ProtoOAAmendPositionSLTPReq (amender
le SL/TP d'une position existante). Ne touche qu'aux positions liées à un trade
tracké en base ; jamais d'ouverture, de clôture ni de changement de volume.

ProtoOAReconcileReq est utilisé en LECTURE SEULE pour lister les positions
ouvertes — jamais pour créer des trades (pas de rattrapage, choix assumé).
"""

import logging
from datetime import datetime
from zoneinfo import ZoneInfo

from ctrader_open_api import Protobuf
from ctrader_open_api.messages.OpenApiMessages_pb2 import (
    ProtoOAAmendPositionSLTPReq,
    ProtoOAReconcileReq,
)
from twisted.internet import defer
from twisted.internet.task import LoopingCall

log = logging.getLogger("ctrader-daemon.guard")

NEW_YORK = ZoneInfo("America/New_York")
ROLLOVER_HOUR = 17


class RolloverGuard:
    def __init__(self, client, api, account_id: int, enabled: bool,
                 minutes_before: int = 5, minutes_after: int = 10):
        self.client = client
        self.api = api
        self.account_id = account_id
        self.enabled = enabled
        self.minutes_before = minutes_before
        self.minutes_after = minutes_after
        self._in_window = False
        self._loop = None

    def start(self) -> None:
        if not self.enabled:
            log.info("Rollover guard DÉSACTIVÉ (GUARD_ENABLED=false) — aucune écriture broker")
            return
        if self._loop is not None:
            return
        log.info("Rollover guard actif : retrait des SL %d min avant 17h00 NY, remise %d min après",
                 self.minutes_before, self.minutes_after)
        self._loop = LoopingCall(self._tick)
        self._loop.start(10, now=False)
        # Récupération : démon arrêté pendant la fenêtre → SL toujours en DB,
        # on restaure au démarrage (uniquement les positions trackées).
        if not self._is_in_window(self._now_ny()):
            self.restore_stop_losses()

    @staticmethod
    def _now_ny() -> datetime:
        return datetime.now(tz=NEW_YORK)

    def _is_in_window(self, now: datetime) -> bool:
        rollover = now.replace(hour=ROLLOVER_HOUR, minute=0, second=0, microsecond=0)
        seconds = (now - rollover).total_seconds()
        return -self.minutes_before * 60 <= seconds < self.minutes_after * 60

    def _tick(self):
        if self._is_in_window(self._now_ny()):
            if not self._in_window:
                log.info("Fenêtre de rollover ouverte — retrait des stop loss")
            self._in_window = True
            # Couvre aussi les positions ouvertes (ou re-modifiées) pendant la fenêtre.
            return self.remove_stop_losses()
        if self._in_window:
            self._in_window = False
            log.info("Fenêtre de rollover terminée — remise des stop loss")
            return self.restore_stop_losses()

    # --- Lecture seule : positions ouvertes du compte -----------------------

    @defer.inlineCallbacks
    def _open_positions(self):
        res = yield self.client.send(ProtoOAReconcileReq(ctidTraderAccountId=self.account_id))
        return list(Protobuf.extract(res).position)

    # --- Retrait (SL sauvegardé en DB au préalable, sinon laissé en place) ---

    @defer.inlineCallbacks
    def remove_stop_losses(self):
        positions = yield self._open_positions()
        for position in positions:
            if not position.HasField("stopLoss"):
                continue
            trade = yield self.api.find_trade(position.positionId)
            if trade is None:
                log.info("Position #%s non trackée — SL laissé en place", position.positionId)
                continue
            saved = yield self.api.patch_trade(trade["id"], {"stopLoss": position.stopLoss})
            if not saved:
                log.error("✗ Échec de sauvegarde du SL du trade #%s — SL laissé en place", trade["id"])
                continue
            ok = yield self._amend(position, stop_loss=None)
            if ok:
                log.info("✓ SL %s retiré sur la position #%s (sauvé dans le trade #%s)",
                         position.stopLoss, position.positionId, trade["id"])

    # --- Restauration : dernier SL de l'historique du trade ------------------

    @defer.inlineCallbacks
    def restore_stop_losses(self):
        positions = yield self._open_positions()
        for position in positions:
            if position.HasField("stopLoss"):
                continue
            trade = yield self.api.find_trade(position.positionId)
            if trade is None:
                continue  # Position non trackée — on n'y touche pas.
            last_stop_loss = trade.get("lastStopLoss")
            if last_stop_loss is None:
                log.warning("⚠ Trade #%s sans historique de SL — rien à restaurer sur #%s",
                            trade["id"], position.positionId)
                continue
            ok = yield self._amend(position, stop_loss=last_stop_loss)
            if ok:
                log.info("✓ SL restauré à %s sur la position #%s", last_stop_loss, position.positionId)
            else:
                # Cas typique : le prix a traversé le niveau pendant la fenêtre.
                log.warning("⚠ Impossible de restaurer le SL à %s sur #%s — à replacer manuellement",
                            last_stop_loss, position.positionId)

    # --- Seule écriture broker du démon --------------------------------------

    @defer.inlineCallbacks
    def _amend(self, position, stop_loss: float | None):
        """Amende le SL d'une position en préservant son TP (les champs omis
        de ProtoOAAmendPositionSLTPReq sont RETIRÉS de la position)."""
        req = ProtoOAAmendPositionSLTPReq(
            ctidTraderAccountId=self.account_id,
            positionId=position.positionId,
        )
        if stop_loss is not None:
            req.stopLoss = stop_loss
        if position.HasField("takeProfit"):
            req.takeProfit = position.takeProfit
        try:
            res = yield self.client.send(req)
        except Exception as exc:
            log.error("✗ Amendement SL refusé sur #%s : %s", position.positionId, exc)
            return False
        extracted = Protobuf.extract(res)
        if extracted.DESCRIPTOR.name in ("ProtoOAOrderErrorEvent", "ProtoOAErrorRes"):
            log.error("✗ Amendement SL refusé sur #%s : %s — %s", position.positionId,
                      extracted.errorCode, getattr(extracted, "description", ""))
            return False
        return True
