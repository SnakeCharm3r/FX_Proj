# Simple zone retest: baseline for testing

**Three entry conditions: H1 location, M5 rejection, one footprint confirmation.**

Status: proposed research baseline, `simple_zone_retest_v1`. It has not been implemented or activated. The app still runs [the original zone-retest rule](zone-retest-strategy-v1.md), including its 60-point threshold and absolute delta filter. This document simplifies the entry/exit proposal; it does not change live alerts.

## Entry

| Condition | BUY | SELL |
|---|---|---|
| H1 location | Retest a previously confirmed, valid demand zone | Retest a previously confirmed, valid supply zone |
| M5 rejection | Price approaches from above, touches the zone and closes back above its upper edge | Price approaches from below, touches the zone and closes back below its lower edge |
| Footprint confirmation | The same completed M5 candle has a bullish imbalance stack | The same completed M5 candle has a bearish imbalance stack |

An approach from above means the preceding completed M5 close was above the demand zone; from below means it was below the supply zone. A touch means the candle's high–low range overlaps the zone. The zone must already be confirmed when the M5 candle starts and must remain valid through its close.

Use the existing footprint stack definition: at least three adjacent native price levels meeting the 3:1 diagonal imbalance rule, with minimum aggressor volume of 10 per level. If both directions have qualifying stacks, skip the entry. This is the only footprint entry filter.

All three conditions must occur together. No later confirmation window, candle-colour requirement or minimum wick percentage is added. Signal at the M5 close; test entry at the first executable quote after the signal and confirming data arrive. A bar-only backtest uses the next M5 open with explicit costs. The signal close is a reference, not an assumed fill.

## Exit

- **Stop:** beyond the farther of the zone's outside edge and the rejection candle's extreme, plus the existing 1.50-price-unit buffer.
- **TP1:** 1.5R from entry; close half the test position.
- **TP2:** 3R from entry; close the remainder.
- Retain the original stop after TP1. Each return to the zone may create one entry; allow only one open test position at a time. There is no automatic opposite-signal exit in this baseline.

R is the distance between the executable entry and stop. Both BUY and SELL use the same rules in opposite directions. Position splitting above is a backtest rule; the current app does not execute partial closes or place orders.

## What is removed from the proposed entry

No weighted score, CVD divergence, VWAP deviation, POC rejection, absorption/exhaustion proxy or absolute ±500 delta requirement. These may remain visible as diagnostics, but none can approve or veto this proposed entry. Keep them as separate comparisons rather than adding them back by default.

The H1 zone formation algorithm itself is unchanged in this proposal. Its existing pivot, candle-colour, wick and ATR filters are described in [the original rule](zone-retest-strategy-v1.md). Simplifying those is a separate experiment; changing zone formation and entry filters together would make the comparison harder to interpret.

## Test it fairly

Compare this baseline with the same H1-zone/M5-rejection rule **without** footprint confirmation, using identical execution and exit assumptions. Keep the original live strategy as another labelled benchmark. Freeze these rules before evaluating new data.

Fresh, synchronized inputs and completed candles remain data checks. Missing footprint data is unavailable, not a failed signal. Include spread, fees and slippage. If SL and TP cross in the same bar and their order is unknown, retain an unresolved result. Keep end-of-data open positions censored or separately marked to market. Report trade count, expectancy in R, drawdown and unresolved outcomes; do not choose a winner from a handful of cases.

The M5 archive is collecting data. The existing H1 price-only study does not test this baseline, and its profitability is unestablished. Research implementation and a comparable backtest are the next steps. See [the broader research protocol](xauusd-research-protocol.md) for archived evidence and data requirements; its more elaborate candidates are separate hypotheses, not extra conditions for this baseline.

Method reference: [CFA Institute on backtesting](https://www.cfainstitute.org/insights/professional-learning/refresher-readings/2026/backtesting-and-simulation) discusses defining rules before evaluating performance and avoiding look-ahead bias.
