"""
main.py
───────
GoldFlow Pro — Python ingestion service entry point.

Usage:
  python main.py           → real cTrader feed
  python main.py --sim     → simulated XAUUSD ticks (no API needed)

Environment: copy .env.example to .env and fill in your credentials.
"""

import argparse
import logging
import os
import signal
import sys
import time
import random
import math

from dotenv import load_dotenv

load_dotenv()

# ── Logging setup ─────────────────────────────────────────────────────────────
log_level = getattr(logging, os.getenv("LOG_LEVEL", "INFO").upper(), logging.INFO)
logging.basicConfig(
    level=log_level,
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("goldflow")

# ── Imports ───────────────────────────────────────────────────────────────────
from tick_classifier  import TickClassifier
from bar_builder      import BarBuilder
from redis_publisher  import RedisPublisher


def get_timeframes() -> list[str]:
    raw = os.getenv("TIMEFRAMES", "5m,15m,30m,1h,4h")
    return [t.strip() for t in raw.split(",") if t.strip()]


# ── Bar-closed callback ───────────────────────────────────────────────────────

def make_on_bar_closed(publisher: RedisPublisher):
    def on_bar_closed(bar: dict):
        tf = bar["tf"]
        publisher.publish_closed_bar(bar)
        count = publisher.get_bar_count(tf)
        logger.info(
            f"Bar closed [{tf}] O={bar['open']:.2f} H={bar['high']:.2f} "
            f"L={bar['low']:.2f} C={bar['close']:.2f} "
            f"Δ={bar['delta']:+d} (buy={bar['buy_vol']} sell={bar['sell_vol']}) "
            f"| stored bars: {count}"
        )
    return on_bar_closed


# ── Simulator ─────────────────────────────────────────────────────────────────

class GoldSimulator:
    """
    Realistic XAUUSD tick simulator.
    - Mean-reverting random walk centred near 3320
    - Session-based volatility (London/NY higher, Asia lower)
    - Realistic spread: 0.20–0.45 pips
    - ~3–8 ticks/second during active sessions
    """

    def __init__(self, classifier: TickClassifier, bar_builder: BarBuilder, publisher: RedisPublisher):
        self._classifier  = classifier
        self._bar_builder = bar_builder
        self._publisher   = publisher
        self._price       = 3320.00
        self._running     = False

    def start(self):
        self._running = True
        logger.info("Simulator started — generating XAUUSD ticks (Ctrl+C to stop)")
        try:
            self._run()
        except KeyboardInterrupt:
            self.stop()

    def stop(self):
        self._running = False
        self._bar_builder.flush_all()
        logger.info("Simulator stopped.")

    def _run(self):
        tick_count = 0
        while self._running:
            # Session-based volatility
            hour_utc = time.gmtime().tm_hour
            if 7 <= hour_utc < 17:    # London + NY overlap
                volatility = 0.08
                tick_interval = 0.18
            elif 13 <= hour_utc < 22: # NY session
                volatility = 0.06
                tick_interval = 0.22
            else:                      # Asia/off-session
                volatility = 0.03
                tick_interval = 0.45

            # Mean-reverting random walk
            mean  = 3320.0
            drift = (mean - self._price) * 0.001   # gentle pull toward mean
            move  = drift + random.gauss(0, volatility)
            self._price = max(3200.0, min(3500.0, self._price + move))

            # Realistic spread for gold
            spread = round(random.uniform(0.20, 0.45), 2)
            half   = spread / 2.0
            bid    = round(self._price - half, 2)
            ask    = round(self._price + half, 2)

            tick = self._classifier.classify(bid, ask)
            self._publisher.publish_tick(tick)
            self._bar_builder.process_tick(tick)

            tick_count += 1
            if tick_count % 100 == 0:
                for tf, bar in self._bar_builder.get_current_bars().items():
                    if bar:
                        self._publisher.publish_live_bar(tf, bar)
                logger.debug(f"[SIM] Tick #{tick_count:,} | bid={bid:.2f} ask={ask:.2f} dir={tick['direction']}")

            time.sleep(tick_interval)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="GoldFlow Pro — XAUUSD ingestion service")
    parser.add_argument("--sim", action="store_true", help="Run with simulated ticks (no cTrader API needed)")
    args = parser.parse_args()

    timeframes = get_timeframes()
    logger.info(f"GoldFlow Pro ingestion starting | timeframes: {timeframes}")

    publisher   = RedisPublisher()
    classifier  = TickClassifier()

    # Verify Redis connection
    if not publisher.ping():
        logger.error("Cannot connect to Redis — is it running? Check REDIS_HOST/PORT in .env")
        sys.exit(1)
    logger.info("Redis connection OK ✓")

    on_bar_closed = make_on_bar_closed(publisher)
    bar_builder   = BarBuilder(timeframes=timeframes, on_bar_closed=on_bar_closed)

    # Graceful shutdown handler
    def shutdown(signum, frame):
        logger.info("Shutdown signal received…")
        bar_builder.flush_all()
        sys.exit(0)

    signal.signal(signal.SIGINT,  shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    if args.sim:
        logger.info("Running in SIMULATION mode")
        sim = GoldSimulator(classifier, bar_builder, publisher)
        sim.start()
    else:
        # Validate credentials
        required = ["CLIENT_ID", "CLIENT_SECRET", "ACCOUNT_ID"]
        missing  = [k for k in required if not os.getenv(k)]
        if missing:
            logger.error(f"Missing required env vars: {', '.join(missing)}")
            logger.error("Copy .env.example to .env and fill in your cTrader credentials")
            sys.exit(1)

        logger.info(f"Connecting to cTrader ({'DEMO' if os.getenv('DEMO','true').lower()=='true' else 'LIVE'})")

        from ctrader_connector import CTraderConnector
        connector = CTraderConnector(classifier, bar_builder, publisher)

        # Override shutdown to also stop the Twisted reactor
        def shutdown_live(signum, frame):
            logger.info("Shutdown signal received…")
            connector.stop()

        signal.signal(signal.SIGINT,  shutdown_live)
        signal.signal(signal.SIGTERM, shutdown_live)

        connector.start()   # blocks (Twisted reactor loop)


if __name__ == "__main__":
    main()
