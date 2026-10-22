# Zone absorption strategy v2

Status: implemented and active for live observation on 2026-10-22. It is a research strategy; profitability has not been established.

## Full rule

The strategy creates entries on completed M5 candles. Every item below is mandatory.

1. **Location:** a confirmed H1 wick-to-body demand or supply zone existed before the M5 sequence began and remained valid. Demand is eligible for BUY; supply is eligible for SELL.
2. **Effort into the zone:** within the previous four M5 bars, total GC volume was at least 1.5 times its prior 20-bar median, absolute total delta was at least its own prior 20-bar median, and aggressive orders pushed into the zone.
3. **Failure:** that effort candle left a rejection wick of at least 40% of its range and closed away from the attempted extreme. For a SELL, this means positive delta failed near the high. For a BUY, negative delta failed near the low.
4. **Rejected retest:** the next bars retested or exceeded the effort extreme, closed back away with at least a 20% rejection wick, and total delta weakened relative to the effort bar.
5. **Confirmation:** the next completed M5 candle broke the rejection candle's low with negative delta for a SELL, or its high with positive delta for a BUY.

The alert is created only after step 5 closes. A small candle, a long wick, positive/negative delta, high volume, or a footprint imbalance by itself is not absorption.

## What the order flow means

For a SELL, unusually high total volume and positive delta show aggressive buyers lifting offers. If price cannot hold the high, passive sellers have absorbed enough of that buying to stop progress. A later weaker retest suggests the buyers are losing effect. Negative delta breaking the rejection low confirms that sellers have taken control. BUY is the exact inverse.

The rule measures executed volume and total delta. It does not interpret buy volume and sell volume as counts of people. It also does not claim to identify who traded or why.

## Supporting readings

CVD divergence, the fixed ±500 delta reading, native stacked imbalance, exhaustion, rolling VWAP and GC POC remain visible in the setup table. They are diagnostics for research and do not approve or veto a `zone_absorption_v2` entry. The weighted strategy and `zone_retest_v1` remain preserved by version for comparison.

## Entry and exit

- Reference entry: broker XAUUSD close of the completed M5 confirmation candle. A backtest should use the next executable quote or next M5 open with spread and slippage.
- Stop: beyond the farther of the H1 zone edge and M5 rejection extreme, plus the configured 1.50 price-unit buffer.
- TP1: 1.5R.
- TP2: 3R.
- The current tracker records TP1, TP2 and SL price touches. It does not place or manage broker orders.
- One sequence and zone combination creates one setup; repeat evaluation is deduplicated.

## Case-study result

The 2026-10-22 08:55-09:25 EAT sequence satisfies the order-flow portion: volume expanded, positive delta stopped producing durable upward progress, the 09:20 bar rejected the high, and 09:25 broke lower with delta -194. It does **not** satisfy the complete live rule because 4097-4103 was not a pre-existing confirmed H1 supply zone in the application's zone set. It is therefore retained as a trigger example, not counted as a valid strategy trade.

The sell near the newly created H1 low is also blocked unless price is at a pre-existing H1 supply zone and the full bearish M5 sequence completes. This directly prevents a high score or a single red delta bar from authorizing a sell at demand/new lows.

## Backtest protocol

Freeze this version before measuring it. Test all eligible H1 zones in chronological order, use only data known at each M5 close, include costs, and retain failed and neutral cases. Compare expectancy in R, win rate, maximum drawdown, trade count, TP1/TP2/SL outcomes and unresolved same-bar exits. Do not retune the thresholds from the selected successful case and then report that same case as validation.
