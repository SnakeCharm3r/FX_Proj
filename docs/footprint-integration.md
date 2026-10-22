# Real ClusterDelta footprints

The existing dashboard uses broker OHLC paired with ClusterDelta bar volume/delta. Its POC, VWAP and imbalance heuristics are not the vendor Footprint's price-level indicators. The new `/orderflow/footprint` page displays exported GC futures price cells separately and compares them with the existing bar feed at matching UTC candle times.

## MT5 exporter

`FXClusterDeltaFootprint` is a separate indicator generated from `MQL5/Indicators/#Footprint_Data_for_EA.mq5` in the user-supplied archive. It is not the installed commercial `ClusterDelta_#Footprint` chart and does not read chart pixels. The vendor source and attribution are preserved in the generated local file. Generation instructions are in `integrations/clusterdelta/build_footprint_exporter.py`; the added export hook is `FootprintExport.mqh`.

The build fixes include-path casing and uses the program's DLL permission rather than the terminal's default permission flag. It defaults to streaming GC with MetaTrader_GMT=0. The observed vendor clock still required explicit normalization, described below. The supplied footprint source uses gold ID **13**, whereas the separate volume helper uses **24**. Do not reuse the volume helper's enum values here.

Source and compiled binary were added under the local Wine installation's `MQL5/Indicators/FXClusterDeltaFootprint.*`, plus the archive's `footprint_v1x0_x64.dll` in `MQL5/Libraries`. Compilation passed with 0 errors and 0 warnings. Existing commercial indicators and bridge files were not overwritten.

In MT5, refresh Navigator → Indicators, attach **FXClusterDeltaFootprint** to XAUUSDm M15, and allow DLL imports on its Dependencies tab. Keep `ONLINE_STREAM=true`, GC selected, `MetaTrader_GMT=0`, `FX_ExportBars=12` and `FX_ExportSeconds=10`. Leave the existing EA and chart indicator attached. The exporter emits a separate atomic FILE_COMMON JSON snapshot `FXFootprint_GC_15m.json` every ten seconds after vendor clusters become available.

## Data path and display

The Python forwarder now handles both `FXClusterDelta_*.json` bar files and `FXFootprint_*.json` price-cell files. It routes footprint files to authenticated `POST /api/clusterdelta/footprints`, using the same separate bridge token. The updated forwarder was restarted and existing bar ingestion was verified to continue.

Payloads contain instrument, timeframe, broker chart symbol, raw vendor expiration metadata, native tick size, and up to 40 candles. Futures contract identity is not verified; `contract` is null. Legacy exporter snapshots that put the vendor Expiration value in `contract` are accepted and relabeled as `vendor_expiration`. Each candle includes UTC time, futures OHLC, completed/developing flag, bid/ask totals, min/max delta, vendor summary totals, and native price/bid/ask cells. The default exporter sends the latest 12 candles. It derives total bid/ask from cells and retains the original vendor totals to flag disagreements.

The backend validates nonnegative integer volume, unique prices on the tick grid, OHLC ranges and agreement between cells and supplied totals. It computes candle POC from maximum cell volume and native-tick diagonal imbalances at ratio 3:1 with major volume at least 10. Zero opposing volume is excluded. These are explicit app settings, not a claim that they match the user's commercial indicator settings. A price tie selects the first/lowest maximum cell.

Snapshots are stored separately under `clusterdelta:footprint:GC:{tf}:latest`, with seven-day expiry after the last write. This is a current-snapshot cache, not a historical backtesting archive. The read-only app endpoint is `GET /api/orderflow/footprint?instrument=GC&tf=15m`.

The Footprint page refreshes every five seconds. It shows bid × ask cells, delta, volume, POC and min/max delta; groups prices by selected tick multiples; and highlights POC. Grouped displays suppress native-tick imbalance highlights. A comparison table shows volume/delta differences and the futures/broker closing-price basis, without shifting futures prices into the broker price grid. Developing snapshots can differ across independently refreshed feeds. The page reports missing data honestly and never creates synthetic footprint cells.

## UTC alignment and price correlation

Live snapshots showed the vendor footprint clock three hours ahead of the normalized bar feed despite requesting MetaTrader_GMT=0. The local Laravel environment now sets `CLUSTERDELTA_FOOTPRINT_UTC_OFFSET_MINUTES=180`. The backend subtracts that explicit offset, retains each original timestamp in `source_time`, retains the original completion flag in `source_closed`, and recomputes completion after normalization. This setting is exposed as snapshot metadata and on the page; it is not inferred silently from prices or relabeled timestamps. Recheck it if the vendor clock configuration changes.

With that setting, all 12 observed footprint candles matched exact UTC bar timestamps, and all 11 completed candles had identical volume and delta totals to the independent bar helper. The developing candle differed slightly across separately refreshed snapshots, as expected. All 12 source summary totals matched their price-cell sums. GC futures and broker XAUUSD closes differed by about 17.8 price units in the observed samples; those prices remain separate and the comparison table exposes the basis.

## Verification

Live MT5 export, ingestion, reconciliation and browser rendering were verified on 2026-10-21. The exporter produced 12 M15 footprints with approximately 740–800 native price cells across them at tick size 0.1; the exact count changes with the market. The app displayed six selected footprints, POC outlines, bid/ask cells and the comparison table. A real installed Chromium browser check confirmed populated cells, UTC correlation and grouped display with no JavaScript page errors.

19 relevant backend tests (106 assertions) passed. Coverage includes authentication, duplicate/off-grid prices, volume totals, POC, diagonal imbalance, raw vendor-summary disagreements, price-source separation and explicit UTC normalization with retention of the original timestamp. Blade compilation, JavaScript syntax checks and `git diff --check` passed. Earlier browser rendering fixtures were browser-local and were not inserted into the app.

The source is currently GC on the attached M15 chart. Other supported timeframes need their own export or a timeframe change in MT5. Snapshots are a live cache; historical backfill, permanent archival, exact contract/rollover identification and a backtest replay engine are not part of this display integration.
