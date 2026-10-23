# Variable opportunity chart and Telegram stream

The sidebar's Opportunity Chart (`/opportunities`) displays the same notable M5 candidates as the zone-absorption replay. A candidate needs matched closed MT5/GC footprint volume and delta, directional delta of at least ±10% of total volume, and either increased participation or a completed absorption sequence.

The shared `StrategyDryRunService::potential` method assigns the chart score:

| Score | Evidence |
| --- | --- |
| 25% | Increased participation with directional delta |
| 50% | Increased participation at a matching pre-existing H1 zone |
| 75% | Completed directional absorption without a matching H1 zone |
| 100% | Completed directional absorption at a matching H1 zone |

Scores are rule completion, not estimated win rates. These are candidate notifications rather than automatic broker trades or strict v3 setup approvals. The independent variable stream is enabled by `OF_VARIABLE_OPPORTUNITIES_ENABLED=true`, with minimum score `OF_VARIABLE_MIN_RULES=25`.

Selecting a chart arrow or sidebar candidate shows its reference entry, SL, TP1, TP2, delta percentage, total volume, participation ratio, H1 trend/range, CVD, footprint features and Telegram delivery status. Filters control direction and score tiers. All chart labels use EAT. The trading-date selector follows the replay's UTC date, corresponding to 03:00 EAT through 03:00 EAT the following day.

Reference entry is the signal candle close. SL uses the signal candle's low for BUY or high for SELL, plus the existing 1.50-point buffer. TP1 uses 1.5R and TP2 uses 3R. The strict strategy's 5-point stop gate is shown as context, but does not suppress variable candidate notifications. Variable candidates do not enter the strict setup's TP/SL tracker.

`opportunities:process` runs inside `orderflow:watch` and is also scheduled once per minute with overlap prevention. Live evaluation needs at least 20 closed M5 bars, fresh broker and footprint receipts under 60 seconds, a confirmation candle no more than one M5 period old, and matching footprint totals. Developing, future, stale and mismatched inputs do not create live candidates.

Each candidate is stored once per source, instrument, timeframe, candle and version. Telegram delivery uses a row lock, a sending claim and a delivery log to prevent repeat notifications. A strict setup already delivered for that same candle suppresses the second candidate notification. Failed sends remain visible in the sidebar; they are not automatically retried because ambiguous network failures can conceal successful delivery.

The reusable Telegram format includes the stored instrument, signed delta percentage and
the associated bar's total volume. Positive readings use `🟢 ▲ Positive Delta`; negative
readings use `🔴 ▼ Negative Delta`. Telegram HTML does not support arbitrary text colours,
so the coloured emoji is the portable colour cue beside the signed number. The remaining
lines retain confluence, SL, TP1 and TP2.

Historical import is explicit and does not automatically post to Telegram:

```sh
php artisan opportunities:process --date=2026-10-22
```

An authorized historical delivery uses `--notify-historical`. Every such message starts with “Historical Buy opportunity” or “Historical Sell opportunity”. Repeating the command skips already-delivered candidates. Production imports for 22 October recovered the screenshot's eight candidates (three BUY and five SELL) and all eight historical messages were delivered once.
