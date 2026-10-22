# H1 zone retest strategy

This is the preserved previous strategy version. The live configuration now uses `OF_SETUP_STRATEGY=zone_absorption_v2`. Existing setup and observation records retain their original strategy, and `zone_retest_v1` remains available for comparisons.

## Setup sequence

1. The existing zone detector confirms a 1H demand or supply origin using completed broker candles. A zone cannot be used before its confirmation close.
2. A later completed execution-timeframe candle overlaps demand for BUY, or supply for SELL.
3. That candle rejects the zone: the rejection wick is at least 20% of its range, its body points away from the zone, and its close is beyond the near edge.
4. On the rejection candle or either of the following two consecutive candles, delta must meet the existing ±500 threshold in the same direction, the native footprint must have the same unique directional stack (at least three adjacent imbalances), and the existing weighted score must meet the unchanged live threshold (currently 60). All intervening closes must remain beyond the near edge. Missing execution candles interrupt the sequence.
5. The engine still requires fresh broker and footprint inputs, a completed candle, and matching candle volume/delta totals. A zone alone cannot create a signal.

H1 closes between zone confirmation and entry must be available and must not invalidate the far edge. Full consecutive MT5 CopyRates backfills record coverage windows: hours for which the broker returned no candle inside such a window are distinguished from gaps in ordinary live exports. Missing hours outside verified backfills block the rule. These windows reflect the broker's available history; they do not prove that the broker's historical data is complete at the exchange.

Entry is the confirming candle close. The stop includes the zone's far edge and rejection wick, plus the existing stop buffer. TP1/TP2 retain the configured risk multiples. One rejection event creates at most one setup, even when subsequent candles still confirm it. A later distinct rejection may create a separate setup. Existing TP/SL monitoring continues for previously saved setups.

Scores remain rule weights, not win probabilities. The 45-point observation floor remains unchanged. Observations include the zone rule stage/reason in their saved input snapshot; the Setups detail page displays it. A qualifying score without a confirmed retest remains an observation and produces no new setup alert. Existing Telegram configuration handles genuinely qualifying new setups; tests and audit commands do not post replay alerts.

## Deeper history

Bridge 1.03 independently exports up to 500 completed H1 broker candles, even if the attached execution chart uses another timeframe or the vendor volume buffer is not ready. `H1HistoryBars=500`, `LocalFileTransport=true` and the existing DLL permission are required. The original 50-candle orderflow export is unchanged.

`FXBrokerHistory_GC_1h.json` is forwarded to the authenticated `/api/clusterdelta/history` endpoint. This endpoint accepts a full consecutive CopyRates batch; it is not an endpoint for sparse candle patches. OHLC prices are validated and upserted into the durable `broker_hourly_prices` table. No historical delta, footprint cells or trade volume is inferred from those prices. H1 chart data and zone detection use the deeper price history; orderflow calculations continue using actual orderflow exports. Older candles without volume have no volume histogram sample.

The strict zone formation rules are unchanged: an opposite-color origin candle, ≥20% rejection wick, a three-bar pivot context, an immediate same-direction departure within three H1 candles, a structure break, a departure candle with ≥50% body, and ≥1.5 ATR displacement. Consequently, deeper history does not label every visually plausible base. In the imported data, the 14 October 16:00 UTC low at 4073.195 belongs to a bullish rejection candle; the existing detector's bearish-origin requirement excludes it. This is a formation-rule limitation, separate from the retest gate. It should be compared against broader zone definitions in historical tests before relaxing it.

## SELL #2 audit

Created 22 October 2026 at 06:00:38 UTC (09:00:38 EAT), from the 05:00 UTC completed H1 candle. Entry 4089.69, SL 4094.35, TP1 4082.70, TP2 4075.71.

The original score was SELL 65 versus BUY 20: negative delta −603 gave 20 SELL points, bearish native stack 20, bearish absorption proxy 15, and below-VWAP deviation 10. Bullish CVD divergence gave 20 BUY points but was not a veto. The absorption condition is a small-body/delta proxy, not confirmed price-level absorption. The candle swept to 4066.745 and closed at 4089.694; its recovery did not veto the score-only rule. A claim that this was an all-time low cannot be established from these imports.

The new rule blocks that assessment without a confirmed supply retest. Its historical setup and Telegram message are preserved, and its TP/SL tracking remains active. To inspect the original evidence and dry-run its saved inputs against the current rule:

```bash
cd /var/www/FX_Proj/laravel-app
/opt/lampp/bin/php artisan setups:audit 2
```

This is a rule audit, not a full backtest: older footprint snapshots and all intervening tick paths were not retained. No performance or win-rate conclusion follows from the dry run.

## Validation on 22 October 2026

### SELL #2 outcome review

The application recorded the SL reference crossing on 22 October at 06:08:39 UTC (09:08:39 EAT): sampled broker close 4095.962 versus the saved stop 4094.35. Neither TP1 nor TP2 had a recorded touch before that event. This is observed price evidence, not a broker execution report; it cannot establish realized fills, slippage or P&L.

The signal candle closed 87.9% of the way from its low to high, with a lower wick covering 87.9% of its range and a body covering only 7.0%. Nevertheless, `detectAbsorption()` classified the small body as bearish solely because delta was negative. That calculation does not distinguish aggressive selling that continues downward from selling that fails to sustain a lower price. Its 15 points were decisive: removing this proxy leaves SELL 50 versus BUY 20, below the 60-point threshold. This is an arithmetic sensitivity check, not evidence of how a replacement absorption rule performs.

The original score also counted being far below VWAP as 10 bearish points and allowed bullish CVD divergence to coexist with the winning SELL score. Those choices combine momentum and reversal interpretations without a location veto. The zone-retest gate now supplies a location/rejection requirement, but it has not yet been shown to improve expectancy. Unit-test success establishes implementation behavior, not profitability.

For strategy comparison, freeze the original score-only definition, the new zone-retest definition, and any proposed price-aware absorption variant separately. Replay with only zones confirmed before entry, chronologically split development and held-out periods, and account for spread, fees and slippage. Report signal counts, rejected candidates, win/loss/ambiguous counts, expectancy in R, drawdown and adverse/favorable excursions. Where one candle crosses both SL and TP without intrabar evidence, record the result as unresolved rather than choosing the favorable path. The current 500-candle price backfill cannot reconstruct missing historical footprint stacks or exchange delta; those full-strategy results remain unavailable until matching historical data or a prospective archive exists.

Bridge 1.03 compiled with zero errors and warnings. After reattachment, MT5 exported 500 closed H1 candles spanning 22 September–22 October, which reached the database through the forwarder. The H1 chart API returned 500 candles, 85 actual volume samples and 415 price-only candles. The live health endpoint was healthy. Nine zones were detected; most had subsequently been invalidated.

The saved SELL #2 dry run retained its 65-point score but failed the supply-retest gate. Headless browser checks confirmed the live zone stage/blocker and preserved original SELL conditions without JavaScript errors. The complete Laravel suite passed: 109 tests, 657 assertions. Tests cover both directions, delayed confirmation, one alert per rejection, unchanged score threshold, missing/future/invalidated zones, authenticated history imports and price-only chart data.
