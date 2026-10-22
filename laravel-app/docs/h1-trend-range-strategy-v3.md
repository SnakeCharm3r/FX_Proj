# H1 trend and range strategy v3

This is the live setup rule for XAUUSD. It evaluates completed M5 candles and uses only H1 candles that had already closed at the M5 decision time. A setup score is a checklist completion score, not an estimated win probability.

## Trend continuation

A continuation setup needs all of the following:

1. The H1 close and the latest five-H1-bar mean are both on the same side of the 20-H1-bar mean.
2. Price reaches a pre-existing H1 demand zone during a BUY trend, or an H1 supply zone during a SELL trend.
3. A completed M5 absorption sequence confirms failed opposing effort, a weaker-delta rejection, and an opposite-delta break.
4. Rolling CVD agrees with the setup direction.
5. Native GC footprint data has either a matching three-level stacked imbalance or a matching rejection of the previous GC POC.
6. The reference stop is no wider than 5.0 XAUUSD price points.

## Countertrend reversal

A reversal uses the same H1 range, M5 absorption, footprint and capital checks. It additionally requires CVD divergence in the reversal direction. A demand reaction during an H1 SELL trend can therefore become a BUY only after bullish absorption, bullish CVD divergence and bullish footprint confirmation. The inverse applies at supply during an H1 BUY trend.

## Setup output

Each qualified setup is stored with its source candle time, strategy version, direction, entry, stop, TP1, TP2 and the five rule checks. The Dashboard plots the arrow on that source candle. The Setup page lists the strategy and lets the selected setup drive the detail card.

When Telegram alerts are enabled, a newly stored setup produces one short BUY or SELL opportunity message containing signed delta as a percentage of total bar volume, rule confluence, SL, TP1 and TP2. Existing target tracking sends separate TP1, TP2 and stop-loss updates. Duplicate evaluation of the same range reaction is suppressed.

`php artisan setups:replay-missed YYYY-MM-DD --send` audits archived M5 broker and footprint data with the same live rule. It sends only synchronized candles that pass every rule and records them as historical so live TP/SL tracking does not treat an old opportunity as a new open position. Repeating the command does not resend an already delivered setup.

## Risk interpretation

The 5-point stop gate matches the current $100 / 0.01-lot research profile. It is a research constraint and does not place or manage a broker order. Any change to capital or lot size should be tested before changing `OF_LIVE_MAX_STOP_POINTS`.
