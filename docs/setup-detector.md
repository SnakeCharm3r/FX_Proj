# Live setup detector

The current alert rule is [H1 zone + M5 absorption](zone-absorption-strategy-v2.md). It requires a pre-existing H1 zone, unusually high M5 effort that fails to move price, a weaker rejected retest, and an opposite-delta break. The score-only behavior remains available as `weighted_orderflow_v1`, and the previous live gate remains preserved as `zone_retest_v1`; saved setups keep their original provenance.

Open http://localhost:8003/setups. The page shows the last evaluation, separate diagnostic BUY/SELL scores, eight signal observations, missing inputs, and saved setup history. It polls every 10 seconds; history refreshes on new records and every 30 seconds. WebSockets are optional. System Monitor includes an evaluation heartbeat. Selecting a timeframe does not create or aggregate data.

The page also includes [spotted setup history](setup-observation-history.md): durable first evaluations scoring at least 45, independently of the live setup threshold of 60. Lower scores are not retained. Previously saved setups can be imported; missing older subthreshold evaluations cannot be recovered.

## Run locally

Keep the application, MT5 and the Python forwarder running. In a separate terminal:

```sh
cd /var/www/FX_Proj/laravel-app
php artisan orderflow:watch
```

This checks all seven supported timeframes every 10 seconds. Use one watcher. The existing scheduler can alternatively run `orderflow:process` every minute. One check: `php artisan orderflow:process --tf=15m`. Evaluation/storage errors produce a failure exit code and a readable error state when Redis is available. No queue worker is needed. Restart the watcher after PHP strategy/configuration changes.

## Eligibility and diagnostics

A setup requires fresh bridge and footprint receipts (under 60 seconds), at least 20 completed broker candles, a recent completed candle, footprint cells at the same UTC opening timestamp, matching bridge/cell volume and delta, and internally consistent vendor/cell totals. Newly closed candles may be blocked briefly while independently sampled exports synchronize. Developing candles stay visible in charts but cannot trigger setups.

Under `zone_absorption_v2`, the mandatory sequence in the strategy document creates the entry. The weighted readings remain visible for comparison; their total does not approve or veto the entry. Under the archived `weighted_orderflow_v1` and `zone_retest_v1` modes, the higher directional score must reach the configured threshold. Scores describe rule weights, not estimated success probabilities.

| Condition | Weight | Implementation |
| --- | ---: | --- |
| CVD divergence | 20 | Broker close movement versus cumulative exchange delta over the rolling closed-bar window |
| Delta imbalance | 20 | Absolute closed-bar delta at least 500; direction follows delta |
| Absorption sequence | 15 | M5 high-volume failed effort, weaker rejected retest, then opposite-delta break |
| Stacked imbalance | 20 | At least 3 consecutive native GC price levels with diagonal imbalance; default 3:1 ratio, major volume at least 10 and nonzero opposing volume; gaps break runs; stacks on both sides are neutral |
| Exhaustion proxy | 10 | Existing close-versus-delta change heuristic, not a vendor exhaustion output |
| Rolling VWAP deviation proxy | 10 | Broker typical-price volume-weighted mean over 20 closed bars; threshold 150 gold pips (price difference ×10); direction follows deviation |
| GC POC rejection | 5 | Native GC candle tests the immediately previous GC POC, then closes away on a same-direction candle; proximity alone is insufficient |

GC prices stay separate from broker XAUUSD prices. Entry is the rounded broker confirmation-candle close; stop uses the farther of the H1 zone and M5 rejection extreme plus the configured buffer; targets retain the 1.5R and 3R rules. These are reference levels, not order fills. Detection does not execute trades. The separate tracker records observed target/stop price touches.

## Persistence and visibility

The additive migration adds source, instrument, UTC candle time, and a unique evaluation key. One source/instrument/timeframe/candle can create one setup, even across overlapping workers or after a previous result was invalidated. Setup and contributing signal writes share a database transaction. JSON details, weights and metadata now persist through model casts/fillable fields. The selected setup dashboard shows provenance and all saved condition observations; only supporting conditions contribute to the directional score.

`GET /api/setups/status` exposes source/instrument/timeframe evaluation snapshots and marks an evaluator stale after 90 seconds. `GET /api/setups/export` now resolves as CSV instead of a setup ID.

External notifications are disabled by default (`OF_EXTERNAL_ALERTS_ENABLED=false`). Optional queued broadcasts are controlled separately by `OF_BROADCAST_ENABLED=false`. A missing Reverb server or queue worker cannot block the polled setup path.

## Verification and limits

Persistence tests use SQLite in memory, never synthetic live-database rows. Coverage includes opposing scores, qualifying creation, details, rollback, duplicates, stale/developing/future candles, missing/mismatched footprints, tick-adjacent stacks, and stopped-detector reporting. A live Chromium check verified scores, seven conditions, missing-timeframe blockers, and HTTP history refresh without JavaScript exceptions.

First observed live evaluation after startup: M15 BUY 30/100, SELL 0/100, below 60. No setup was forced. Other periods need their own matching exports; old H1 cache is not a live H1 feed.

These rules have not been performance validated. Redis bar/footprint caches are not a durable backtesting dataset. Historical archival, strategy versioning, outcome/fill modeling, costs, and out-of-sample replay are separate work before evidence-based threshold tuning.

## Empty-section audit (2026-10-21)

The screenshot's M15 candle at 04:45 UTC was evaluated, not missing data. Its delta 22 was below 500; its longest buy stack was 2 adjacent levels rather than 3; body/range was 49.2% versus the absorption proxy limit below 30%; divergence and exhaustion contributed 20 and 10 points. Later candles can have different scores. A neutral condition and a missing input are separate states; the page now shows earned/possible weights and the matching-input status.

Absorption observations now include measured body/range and delta. Zero delta cannot fire that proxy. Exhaustion observations include the latest close change and delta change. These remain application heuristics, not imported vendor absorption/exhaustion indicator outputs. Missing previous native POC is explicitly unavailable rather than an unexplained neutral observation.

The Imbalance Zones endpoint previously returned `zones: []` unconditionally; its page also generated simulated candles/zones on request errors. It now derives observed adjacent price ranges from exported native GC footprint cells, using the same diagonal/run calculations as the setup engine. A range's count is price levels in one candle, not a number of bars. Historical/developing ranges are not asserted to be active support/resistance. Inconsistent candles cannot supply ranges; stale/missing snapshots have explicit states. The chart uses GC candles to match GC ranges, refreshes every five seconds, supports all seven timeframes and a stacks-only filter, and highlights a selected observed range. No missing-feed fallback creates data.

Dashboard confluence now polls the same completed-candle evaluations as Setups. Its stack metric uses that evaluation rather than the old across-candle volume-ratio heuristic. Whole-bar volume ratio is labelled separately from native diagonal imbalance. Recent-bar setup cells join saved results by source, instrument, timeframe and UTC candle time; missing results explicitly say No setup or Developing. Signal History describes supporting signals saved with qualifying setups rather than claiming to be an archive of all raw observations.

Verification: 38 tests / 235 assertions passed, including in-memory persistence checks, exact screenshot proxy measurements, native-price ranges, missing/stale/inconsistent inputs, and source-aware dashboard joins. Live browser checks cover the zones list, stacks filter, empty timeframes, numerical setup observations and the polling dashboard.

## TP card price-touch tracking

The active setup's TP1 and TP2 cards now pulse green with a blinking green indicator and a PRICE REACHED label after that target is observed. Each target is stored independently in `tp1_hit_at` / `tp2_hit_at`, with evidence in `target_hit_details`. Hit timestamps are observation times, not exact tick/fill times. Reloads and price retracements do not clear confirmed hits. Reduced-motion users see a steady green indicator instead of animation.

`setups:track-targets` runs in the existing `orderflow:watch` loop, approximately every ten seconds, and is also available through the minute scheduler. It tracks active/triggered setups matching the configured connector and instrument. BUY targets use later highs, SELL targets use later lows. Historical closed candles can recover a prior touch only when they occurred after setup creation. For the candle overlapping setup creation, its entire high/low is excluded; only its post-creation close or a fresh sampled live close can qualify. Old/future developing snapshots and futures-price cells do not create broker-target results. Locked writes preserve the first recorded hit for each target.

The dashboard polls its displayed setup ID every ten seconds through the uncached setup-detail API. A response for a different setup ID cannot update those cards. This records reference-price touches; it does not claim a broker order filled, change setup status, or implement stop-loss/order lifecycle management.

Live verification found setup #1's TP1 4122.62 within the post-creation M15 candle starting 07:15 UTC on 2026-10-21 (high 4124.572), recorded at that candle's 07:30 UTC close. TP2 4129.74 remained pending at that check. The full suite passed: 57 tests, 346 assertions. Browser checks verified real TP1 animation, pending TP2, reload persistence, reduced-motion behavior, and an isolated TP2 transition using an intercepted response without changing live data.

## Selecting among multiple setups

The Setups page now includes the shared dashboard card. Choose a saved setup from the dropdown or click View in its history row. Its ID is pinned in `?setup_id=...`, so reloads, history filters, pagination and incoming signals preserve the selection. Recent active/triggered records appear first in the dropdown; older records remain accessible through their history rows. An explicitly selected record remains available even if it falls outside the dropdown's recent 100 records.

The selected card shows that record's direction, entry, stop, targets, score, timeframe, status and independent TP lights. Beside it, saved conditions describe the setup at creation; the live detector below continues evaluating current candles independently. A new SELL does not replace a selected BUY or cancel it. Target tracking continues for all eligible active/triggered setups, regardless of which card is visible. This change adds no order execution or stop-loss lifecycle management.

`GET /api/setups/{id}/focus` returns the uncached selected card and saved conditions. Polling every ten seconds updates only the selected ID; cancelled or delayed responses cannot display another setup's variables. Unknown selections return 404 rather than falling back to a different setup. Open this setup on chart loads its timeframe and draws labelled entry, stop, TP1 and TP2 lines. The chart requires the saved source/instrument to match the configured feed.

Verification: 65 tests / 394 assertions passed. Browser checks confirmed real setup #1's saved TP1 state, reload persistence, isolated BUY/SELL switching, delayed-response isolation, selection preservation on new signals, and the selected chart's levels/timeframe without JavaScript exceptions. Browser fixtures used SQLite in memory and intercepted browser responses; no synthetic setup records were added to the live database.
