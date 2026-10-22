# XAUUSD reversal and continuation research, version 1

Status: research specification. The frozen `zone_absorption_v2` candidate is now connected to live observation and Telegram dispatch; its profitability remains unestablished. The user's manual M5 trade was discretionary. Its screenshot supplied a case-study sequence, not a labelled winning strategy or proof that absorption caused the reversal.

## Question and outcome definition

The question is whether price rejection, subsequent structure and synchronized footprint behaviour distinguish a sustained reversal from a temporary bounce. A candle cannot establish why the market moved. Aggressor volume and price response provide testable observations, not proof of an institution's intentions or of future direction.

Define the initial study horizons as the next 1, 4 and 12 completed H1 trading candles. Measure directional close change, maximum favourable excursion (MFE) and maximum adverse excursion (MAE) in units of the 14-bar ATR computed strictly before the event. Also record whether +2 ATR or −1 ATR was reached first. A candle spanning both is unresolved without intrabar evidence. Horizons use trading candles; elapsed wall-clock time is retained separately because market closures extend the observation period.

These are price-response labels, not trading returns or a promise of a long holding period. A strategy trade additionally requires executable entry/exit rules, spread, fees and slippage. A move that initially reverses can subsequently fail; the state must be allowed to change.

## R1: sweep, reclaim and structure reversal

Initial hypothesis, mirrored for BUY and SELL:

1. **Known context:** freeze the lowest low (BUY) or highest high (SELL) of the preceding 20 completed H1 candles. A separately confirmed historical demand/supply zone can be recorded as an additional feature; it is not reconstructed from future candles. No opposite-colour requirement is imposed on the rejection candle in this research definition.
2. **Rejection candidate:** price trades beyond that known extreme and closes back inside it. In the price-only H1 diagnostic, the relevant wick must cover at least 50% of the candle range and the close must be in the opposing outer 25%. This is a candidate, not an entry.
3. **Structure:** a completed candle closes beyond the rejection candle's opposite extreme within two subsequent H1 candles. For an M5 entry variant, require an M5 close beyond the latest opposing confirmed M5 swing, followed by a pullback that holds the reclaimed reference. A swing uses two bars on either side and becomes available only after the second right-hand bar closes. Do not use the eventual H1 close to justify an earlier M5 entry.
4. **Footprint confirmation at M5 close:** native ask/bid imbalance stack of at least three adjacent levels in the intended direction, using the existing 3:1 diagonal rule and minimum aggressor cell volume of 10. Require same-direction delta/total-volume of at least 10%, and total volume at least the median of the prior 20 completed M5 footprint bars. Missing history means unavailable, not zero volume. Confirm within three M5 candles of the pullback rejection; expire otherwise.
5. **Invalidation:** a close back beyond the swept extreme or a break of the protective pullback swing cancels the candidate. A developing candle alone cannot confirm an entry.

The M5 thresholds above are frozen starting hypotheses, not optimised values. R1 remains separate from the active `zone_absorption_v2` rule and has not been converted into live points. The absolute ±500 delta threshold is retained only as a diagnostic comparison; it is not an entry gate for the active M5 absorption strategy.

## C1: failed rebound and continuation

Test this separately from R1; a rebound does not automatically imply continuation:

1. **Known H1 context:** two confirmed lower highs and lower lows for SELL; invert for BUY. H1 swings use two left and two right completed candles with their confirmation timestamps retained.
2. **Rebound:** after an M5 impulse of at least 1.5 prior M5 ATR, the countertrend rebound travels at least one M5 ATR but does not invalidate the most recent H1 opposing swing. Record the rebound extreme only after its two-right-bar pivot confirmation.
3. **Failure:** price closes through a confirmed M5 swing formed during the rebound, then retests that broken price within six M5 candles. SELL requires a bearish retest close with an upper rejection wick of at least 25% and a close in the bottom 35%; invert for BUY. If no such swing exists, the setup is absent. Do not substitute a retrospective line chosen from the screenshot.
4. **Orderflow:** use the same M5 aligned stack, normalised delta and relative-volume gates as R1. A negative delta reading at the low by itself is insufficient. A small candle body alone is not directional absorption.
5. **Room and invalidation:** reject if the known prior extreme or an opposing confirmed H1 zone lies before the planned 1.5R target. Reject if price already makes a new impulse extreme before the retest entry; that is a late chase. Cancel if the rebound extreme is broken before entry. If R1 and C1 conflict on the same candle, record the conflict and take neither in the baseline experiment.

This specification is deliberately more restrictive than a discretionary short placed by eye. It may reject the user's pictured trade; the missing M5 footprint data means its actual classification is not yet known.

## Price-aware absorption feature

Treat absorption as a separate hypothesis, not a sign copied from delta. A potential BUY absorption event requires aggressive selling near the low with limited additional downward progress and recovery away from that low; potential SELL absorption is symmetric. Record lower/upper wick fraction, close position, native delta fraction and the volume concentration in the outer 20% of the native footprint range. Confirm subsequent price response before using the flag.

Negative delta with a high close can be consistent with sellers failing to hold price down; it does not prove passive absorption. Positive delta with an upper rejection can similarly reflect unsuccessful buying. Level-two order-book or iceberg claims are outside this dataset. The original small-body/delta-sign proxy remains identifiable in the archived original strategy for comparison.

## Executable trade baseline for a future M5 backtest

- Enter at the first available broker quote after the confirming M5 close and after the confirming footprint was received. Where only bars are available, use the next bar open plus explicit execution costs and label the approximation. Never assume an entry before a delayed signal arrived.
- Place the stop beyond the rejection/pullback extreme plus the larger of two observed spreads or 0.1 prior M5 ATR. Calculate risk from that executable entry. Do not widen the stop after entry.
- Start with the existing TP1=1.5R and TP2=3R, half the position at each, with the original stop retained after TP1. Study moving the stop to breakeven or trailing only as separately versioned variants. At 12 H1 trading bars, close the remaining position at the next executable quote.
- Permit only one active research position per instrument. One rejection event may generate at most one entry. Unresolved same-bar SL/TP ordering is reported separately; use lower-timeframe or tick evidence to resolve it.
- Use broker prices for entries/exits and native GC prices for footprint calculations. Align timestamps and record futures/spot basis and contract metadata. Never draw a GC price directly as an XAUUSD execution level. Incomplete or changing contract/basis metadata must be identified in the results.

## Evidence and validation plan

Compare price-only rejection, price follow-through, R1 with footprint confirmation, and C1 separately. Include no-trade candidates and their blocking reasons. First freeze the implementation and parameter manifest, then collect an untouched prospective evaluation period. Historical screenshots and periods already examined are exploratory, even if a chronological split is applied. Preserve an embargo equal to the maximum outcome horizon across development/evaluation boundaries. Overlapping events are dependent; report counts by session/day and do not treat them as independent trials.

Report sample counts, data coverage, entry rejection reasons, net expectancy in R, drawdown, target/stop/timeout/unresolved counts, MFE/MAE, and results by session and volatility regime. Compare a small predeclared neighbourhood of thresholds for sensitivity rather than searching for one best-looking setting. Do not choose a strategy from the current tiny sample or claim a win rate from the screenshots.

Reference context: [CME gold futures/spot example](https://www.cmegroup.com/articles/files/2023/a-golden-opportunity-revisiting-gold-futures-and-options-webinar-slides.pdf) illustrates that gold futures and spot can have different prices. [CFA Institute's backtesting discussion](https://www.cfainstitute.org/insights/professional-learning/refresher-readings/2026/backtesting-and-simulation) describes rolling-window evaluation and simulation limitations. The [Investor.gov performance bulletin](https://www.investor.gov/introduction-investing/general-resources/news-alerts/alerts-bulletins-47) explains that backtested results are hypothetical.

## What is available now

`research/reversal-study/study.py` implements the price-only H1 diagnostic, not the full R1/C1 execution strategies. It tests rejection alone, rejection plus follow-through, and a failed rejection closing beyond the opposite extreme within three subsequent candles. The latter is an exploratory H1 failure label, not an implementation of C1's M5 rebound/retest sequence. ATR uses only prior data; entries are next-open references; final incomplete outcomes are censored. Pattern confirmation does not cross a missing hourly interval. Outcome horizons count available trading bars and retain elapsed time. There are no trading-cost or footprint-performance estimates in this report.

The saved input covers 499 completed H1 bars, 22 September 13:00 UTC–22 October 05:00 UTC. A 70/30 chronological split places the later period from 13 October 17:00 UTC. That period is not an untouched holdout: parts of it were already inspected in this conversation. There were 14 distinct raw rejection origins in the full sample. At the 12-bar horizon the later period has only two evaluable BUY rejections and one SELL rejection, plus one censored recent BUY event. Follow-through also has only three later-period evaluable events and overlaps those origins. Those counts cannot establish an edge. See the [study output](../research/reversal-study/outputs/h1-2026-10-22-study.json).

Run the reproducible diagnostic:

```bash
cd /var/www/FX_Proj/research/reversal-study
python3 -m unittest -v test_study.py
python3 study.py --input data/h1-2026-10-22.json --output outputs/h1-2026-10-22-study.json
```

## Prospective orderflow archive

`OF_RESEARCH_ARCHIVE_ENABLED=true` is enabled locally. Accepted, completed broker and normalised footprint candles are stored as compressed revisions under `laravel-app/storage/app/private/research/clusterdelta/`. Identical revisions are deduplicated. Each file retains its actual first observation time, source clock metadata and a late-observation flag. Historical backfills are not represented as observations available at their candle close. Authentication headers and secrets are excluded. Partial files are staged before publication; archive failures are logged without stopping live ingestion. Disk usage should be reviewed as the archive grows; there is no automatic deletion of research evidence.

Only closed candles are archived. This does not preserve the full intrabar tick/quote path, spread history or developing-candle sequence. It enables closed-candle orderflow research; it cannot settle every intrabar execution question. Footprint revisions after the decision time must be excluded from an as-observed replay. The H1 price-only backfill remains separate and supplies no synthetic delta or footprint cells.

M5 collection was enabled and verified on 22 October after the user attached the exporters. The app received 50 broker bars and 12 footprint snapshots, including the developing candle. The archive contained 11 paired completed M5 candles; every pair matched bridge volume, delta and native footprint totals. The footprints contained 33–160 native price levels per completed candle. A follow-up integration check verifies continued export polling. See [the integration report](../research/reversal-study/outputs/m5-integration-check.json).

Keep a separate XAUUSDm M5 chart in MT5 with FXClusterDeltaBridge 1.03 as the EA and FXClusterDeltaFootprint as the indicator, with DLL imports allowed. The forwarder accepts additional timeframes concurrently. A chart in the user's other trading platform does not feed this MT5 integration. The first 11 pairs are a collection baseline, not enough for the proposed 20-bar footprint-volume warm-up or a reliable performance study. The full M5 R1/C1 backtests remain pending a longer dataset, implementation of those candidate rules and an execution-cost model.
