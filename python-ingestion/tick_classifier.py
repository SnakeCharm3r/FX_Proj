"""
tick_classifier.py
──────────────────
Classifies each incoming tick as BUY or SELL aggressor using the Tick Rule.

Tick Rule:
  price > prev_price  →  BUY  aggressor (market order hit the ASK)
  price < prev_price  →  SELL aggressor (market order hit the BID)
  price == prev_price →  carry forward previous direction
"""


class TickClassifier:
    def __init__(self):
        self._prev_price: float = 0.0
        self._prev_direction: str = "BUY"  # default until first real tick

    def classify(self, bid: float, ask: float) -> dict:
        """
        Returns a classified tick dict.

        mid  = (bid + ask) / 2  — used for direction comparison
        side = 'BUY' | 'SELL'
        """
        mid = (bid + ask) / 2.0

        if mid > self._prev_price:
            direction = "BUY"
        elif mid < self._prev_price:
            direction = "SELL"
        else:
            direction = self._prev_direction  # carry forward (Lee-Ready rule)

        self._prev_price = mid
        self._prev_direction = direction

        return {
            "bid":       round(bid, 5),
            "ask":       round(ask, 5),
            "mid":       round(mid, 5),
            "spread":    round((ask - bid) * 10, 1),   # spread in pips (gold: *10)
            "direction": direction,
        }

    def reset(self):
        self._prev_price = 0.0
        self._prev_direction = "BUY"
