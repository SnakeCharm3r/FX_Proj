"""
ctrader_connector.py
────────────────────
Connects to Pepperstone via cTrader Open API (Spotware).
Streams live XAUUSD ticks and feeds them to TickClassifier → BarBuilder → RedisPublisher.

Protocol:  Google Protobuf over TCP (TLS)
Library:   ctrader-open-api  (pip install ctrader-open-api)
Auth flow: Client Credentials (no browser redirect needed for personal use)
"""

import logging
import os
import time

from twisted.internet import reactor
from ctrader_open_api import Client, Protobuf, TcpProtocol, EndPoints
from ctrader_open_api.messages.OpenApiMessages_pb2 import (
    ProtoOAApplicationAuthReq,
    ProtoOAAccountAuthReq,
    ProtoOASubscribeSpotsReq,
    ProtoOASymbolsListReq,
)

from tick_classifier  import TickClassifier
from bar_builder      import BarBuilder
from redis_publisher  import RedisPublisher

logger = logging.getLogger(__name__)


class CTraderConnector:
    def __init__(
        self,
        classifier:  TickClassifier,
        bar_builder: BarBuilder,
        publisher:   RedisPublisher,
    ):
        self._classifier  = classifier
        self._bar_builder = bar_builder
        self._publisher   = publisher

        self._client_id     = os.getenv("CLIENT_ID",     "")
        self._client_secret = os.getenv("CLIENT_SECRET", "")
        self._account_id    = int(os.getenv("ACCOUNT_ID", "0"))
        self._demo          = os.getenv("DEMO", "true").lower() == "true"
        self._symbol_name   = os.getenv("SYMBOL", "XAUUSD")

        self._symbol_id: int = 0
        self._client:    Client = None
        self._connected: bool   = False
        self._tick_count: int   = 0
        self._reconnect_delay   = 5   # seconds, doubles on each failure

    # ── Public entry point ────────────────────────────────────────────────────

    def start(self):
        """Start the Twisted reactor loop (blocking)."""
        self._connect()
        reactor.run()

    def stop(self):
        """Graceful shutdown — flush bars then stop reactor."""
        logger.info("Shutting down connector…")
        self._bar_builder.flush_all()
        if reactor.running:
            reactor.callFromThread(reactor.stop)

    # ── Connection ────────────────────────────────────────────────────────────

    def _connect(self):
        host = EndPoints.PROTOBUF_DEMO_HOST if self._demo else EndPoints.PROTOBUF_LIVE_HOST
        port = EndPoints.PROTOBUF_PORT

        logger.info(f"Connecting to {'DEMO' if self._demo else 'LIVE'} {host}:{port}")

        self._client = Client(host, port, TcpProtocol)
        self._client.setConnectedCallback(self._on_connected)
        self._client.setDisconnectedCallback(self._on_disconnected)
        self._client.setMessageReceivedCallback(self._on_message)
        self._client.startService()

    def _on_connected(self, client):
        logger.info("TCP connected — authenticating application…")
        self._connected = True
        self._reconnect_delay = 5

        req = ProtoOAApplicationAuthReq()
        req.clientId     = self._client_id
        req.clientSecret = self._client_secret
        deferred = client.send(req)
        deferred.addErrback(self._on_error)

    def _on_disconnected(self, client, reason):
        self._connected = False
        logger.warning(f"Disconnected: {reason} — reconnecting in {self._reconnect_delay}s")
        reactor.callLater(self._reconnect_delay, self._connect)
        self._reconnect_delay = min(self._reconnect_delay * 2, 60)

    # ── Message routing ───────────────────────────────────────────────────────

    def _on_message(self, client, message):
        msg_type = message.payloadType

        # Application authenticated → now authenticate the trading account
        if msg_type == 2101:   # ProtoOAApplicationAuthRes
            logger.info("Application authenticated — authenticating account…")
            req = ProtoOAAccountAuthReq()
            req.ctidTraderAccountId = self._account_id
            req.accessToken = ""   # not needed for client-credentials flow
            d = client.send(req)
            d.addErrback(self._on_error)

        # Account authenticated → request symbol list to find XAUUSD ID
        elif msg_type == 2103:  # ProtoOAAccountAuthRes
            logger.info(f"Account {self._account_id} authenticated — fetching symbol list…")
            req = ProtoOASymbolsListReq()
            req.ctidTraderAccountId = self._account_id
            req.includeArchivedSymbols = False
            d = client.send(req)
            d.addErrback(self._on_error)

        # Symbol list received → find XAUUSD and subscribe to spots
        elif msg_type == 2115:  # ProtoOASymbolsListRes
            symbols = Protobuf.extract(message)
            for sym in symbols.symbol:
                if sym.symbolName.upper() == self._symbol_name.upper():
                    self._symbol_id = sym.symbolId
                    logger.info(f"Found {self._symbol_name} → symbolId={self._symbol_id}")
                    self._subscribe_spots()
                    return
            logger.error(f"Symbol '{self._symbol_name}' not found in account symbol list!")

        # Live spot tick received
        elif msg_type == 2131:  # ProtoOASpotEvent
            self._handle_spot(Protobuf.extract(message))

        # Error response
        elif msg_type == 50:    # ProtoOAErrorRes
            err = Protobuf.extract(message)
            logger.error(f"API error: {err.errorCode} — {err.description}")

    # ── Spot subscription ─────────────────────────────────────────────────────

    def _subscribe_spots(self):
        req = ProtoOASubscribeSpotsReq()
        req.ctidTraderAccountId = self._account_id
        req.symbolId.append(self._symbol_id)
        d = self._client.send(req)
        d.addCallback(lambda _: logger.info(f"Subscribed to {self._symbol_name} spot feed ✓"))
        d.addErrback(self._on_error)

    # ── Tick handling ─────────────────────────────────────────────────────────

    def _handle_spot(self, event):
        """
        cTrader sends prices as integers (1/100000 of price unit).
        XAUUSD: divide by 100 to get USD price (5 decimal precision → 2 decimal for gold)
        """
        # Prices arrive as integers — divide by 100000 for raw price, then /100 for gold pips
        divisor = 100_000.0

        if not event.HasField("bid") or not event.HasField("ask"):
            return  # incomplete tick (sometimes only bid or ask updates)

        bid = event.bid / divisor
        ask = event.ask / divisor

        if bid <= 0 or ask <= 0:
            return

        # Classify the tick
        tick = self._classifier.classify(bid, ask)
        self._tick_count += 1

        # Publish latest tick to Redis (for live price display)
        self._publisher.publish_tick(tick)

        # Feed into bar builder (all timeframes)
        self._bar_builder.process_tick(tick)

        # Publish live (unclosed) bar snapshots periodically
        if self._tick_count % 10 == 0:
            for tf, bar in self._bar_builder.get_current_bars().items():
                if bar:
                    self._publisher.publish_live_bar(tf, bar)

        if self._tick_count % 500 == 0:
            logger.info(f"Tick #{self._tick_count:,} | {self._symbol_name} bid={bid:.2f} ask={ask:.2f}")

    def _on_error(self, failure):
        logger.error(f"Deferred error: {failure.getErrorMessage()}")
