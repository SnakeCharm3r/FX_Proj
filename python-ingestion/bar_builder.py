"""
bar_builder.py
──────────────
Aggregates classified ticks into OHLCV + delta bars for multiple timeframes.

Each completed bar:
  {
    "time":     unix_timestamp (bar open time),
    "open":     float,
    "high":     float,
    "low":      float,
    "close":    float,
    "buy_vol":  int,   (buy-aggressor tick count)
    "sell_vol": int,   (sell-aggressor tick count)
    "delta":    int,   (buy_vol - sell_vol)
    "tf":       str    ("5m", "15m", etc.)
  }
"""

import time
from collections import defaultdict
from typing import Callable, Optional


# Timeframe → seconds
TF_SECONDS = {
    "1m":  60,
    "5m":  300,
    "15m": 900,
    "30m": 1800,
    "1h":  3600,
    "4h":  14400,
}


class Bar:
    __slots__ = ("tf", "open_time", "open", "high", "low", "close",
                 "buy_vol", "sell_vol", "tick_count")

    def __init__(self, tf: str, open_time: int, first_price: float):
        self.tf         = tf
        self.open_time  = open_time
        self.open       = first_price
        self.high       = first_price
        self.low        = first_price
        self.close      = first_price
        self.buy_vol    = 0
        self.sell_vol   = 0
        self.tick_count = 0

    def update(self, price: float, direction: str):
        if price > self.high:  self.high  = price
        if price < self.low:   self.low   = price
        self.close = price
        self.tick_count += 1
        if direction == "BUY":
            self.buy_vol += 1
        else:
            self.sell_vol += 1

    @property
    def delta(self) -> int:
        return self.buy_vol - self.sell_vol

    def to_dict(self) -> dict:
        return {
            "time":      self.open_time,
            "open":      round(self.open,  2),
            "high":      round(self.high,  2),
            "low":       round(self.low,   2),
            "close":     round(self.close, 2),
            "buy_vol":   self.buy_vol,
            "sell_vol":  self.sell_vol,
            "delta":     self.delta,
            "tick_count": self.tick_count,
            "tf":        self.tf,
        }


class BarBuilder:
    def __init__(
        self,
        timeframes: list[str],
        on_bar_closed: Callable[[dict], None],
    ):
        """
        timeframes     : list of TF strings e.g. ["5m", "15m", "1h"]
        on_bar_closed  : callback(bar_dict) called every time a bar closes
        """
        self._timeframes   = timeframes
        self._on_bar_closed = on_bar_closed
        self._bars: dict[str, Optional[Bar]] = {tf: None for tf in timeframes}

    def process_tick(self, tick: dict):
        """
        Feed a classified tick into all timeframe bar builders.
        tick = { "mid": float, "direction": "BUY"|"SELL", ... }
        """
        price     = tick["mid"]
        direction = tick["direction"]
        now_ts    = int(time.time())

        for tf in self._timeframes:
            period   = TF_SECONDS.get(tf, 900)
            bar_open = (now_ts // period) * period   # floor to bar boundary

            bar = self._bars[tf]

            if bar is None:
                # First tick ever — open new bar
                self._bars[tf] = Bar(tf, bar_open, price)
                self._bars[tf].update(price, direction)

            elif bar_open > bar.open_time:
                # New bar period started — close current bar, open new one
                closed = bar.to_dict()
                self._on_bar_closed(closed)

                new_bar = Bar(tf, bar_open, price)
                new_bar.update(price, direction)
                self._bars[tf] = new_bar

            else:
                # Same bar — just update
                bar.update(price, direction)

    def get_current_bars(self) -> dict[str, Optional[dict]]:
        """Returns live (unclosed) bar snapshots for all timeframes."""
        return {
            tf: (bar.to_dict() if bar else None)
            for tf, bar in self._bars.items()
        }

    def flush_all(self):
        """Force-close all open bars (on shutdown). Emits them as closed bars."""
        for tf, bar in self._bars.items():
            if bar and bar.tick_count > 0:
                self._on_bar_closed(bar.to_dict())
                self._bars[tf] = None
