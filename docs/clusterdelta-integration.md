# ClusterDelta integration

## Findings from the supplied archive

The archive includes MT4/MT5 source indicators and Windows DLLs, plus an MT5 iCustom demo and compiled helper. The sources call vendor DLLs; no documented standalone HTTP authentication/data API was supplied. Keep ClusterDelta credentials in the vendor Authorizer on the terminal. The application's bridge token is a separate secret.

The vendor demo contains CTrade and position-management examples. Do not attach DemoVolumes as an exporter. The new FXClusterDeltaBridge EA contains no trading calls.

There are two distinct data paths:

- `#Volume_Delta_EA_iCustom.ex5`: volume buffer 0 and delta buffer 2 in MT5. The new bridge uses this helper. Buy/ask volume = (volume + delta)/2; sell/bid volume = (volume - delta)/2.
- `#Footprint_Data_for_EA.mq5`: price-level clusters accessed inside its source via `GetClusters`, `ClusterINDEX`, and `ReCalculateCluster`. It is not interchangeable with the helper buffers. Exporting its price-level bid/ask arrays requires an additional adapter; this stage does not supply footprint cells.

`config/clusterdelta.php` records the explicit instrument IDs in the supplied volume source. This is a source-version catalog, not a live list of current subscriptions or confirmed instruments. It covers FX futures, indices, commodities, Brazilian contracts and crypto. GC is the exchange-volume source commonly paired with XAUUSD; exchange futures and broker spot prices must retain distinct identities. Inverted currency pairs need explicit direction and price-mapping verification before combining with broker charts.

Official references:
- https://clusterdelta.com/software/download
- https://www.clusterdelta.com/footprint

## Setup

1. Set `CLUSTERDELTA_BRIDGE_TOKEN` in the Laravel environment to a strong random secret and reload cached configuration if applicable. Never commit this value.
2. On the already-authorized MT5 terminal, copy vendor DLLs into `MQL5/Libraries` and the supplied compiled helper `#Volume_Delta_EA_iCustom.ex5` into `MQL5/Experts`. Preserve a working terminal's existing vendor files until compatibility is checked.
3. Copy `integrations/clusterdelta/FXClusterDeltaBridge.mq5` to `MQL5/Experts`, compile in MetaEditor, and attach it to the matching broker chart. Allow DLL imports for the vendor indicator and allow the application's HTTPS origin under MT5 WebRequest settings.
4. For the local Wine installation, keep `LocalFileTransport=true`. Run `python3 python-ingestion/clusterdelta_ingestion.py` alongside the app; it reads FILE_COMMON snapshots and uses the separate API token from Laravel `.env`. No token or WebRequest allowlist is needed in MT5 for this mode. Set the instrument code and matching numeric ClusterDelta ID. `BrokerUtcOffsetMinutes=10000` detects the broker offset; other values set an explicit offset in minutes. Example: gold uses GC / 24 on an XAUUSD chart. When using an explicit offset, update it when the broker changes DST. Supported periods: M1/M5/M15/M30/H1/H4/D1.
5. Keep vendor Authorizer authentication working. Bridge 1.02 exports the latest 50 bars every 10 seconds, including the developing candle when `ExportOpenBar=true` (default). Resending replaces the same timestamp. The developing candle has `closed=false`; completed bars have `closed=true`. Set `ExportOpenBar=false` for completed candles only.
6. Verify authenticated `GET /api/clusterdelta/bars?instrument=GC&tf=15m` against the terminal. Check timestamps, volumes and deltas over several bars before using the feed for analysis.

All three endpoints require `Authorization: Bearer <bridge token>`:

- GET `/api/clusterdelta/instruments`: source catalog, explicitly unverified availability.
- POST `/api/clusterdelta/bars`: `{ "instrument": "GC", "tf": "15m", "bars": [{ "time": 1700000000, "open": 2000, "high": 2002, "low": 1999, "close": 2001, "volume": 10, "delta": 4 }] }`. Time is UTC Unix seconds.
- GET `/api/clusterdelta/bars?instrument=GC&tf=15m`: up to 500 bars in ascending time order, with receipt timestamps to assess freshness.

Redis uses a timestamp sorted set and hash under `clusterdelta:{instrument}:{tf}`. Updates replace values at the same timestamp. Keys expire seven days after the last ingestion; they are a cache, not durable history. Reads return at most 500 entries, while an actively ingested key retains older entries until expiration. Plan archival/retention before sustained production ingestion.

## Verification and remaining work

Six Laravel feature tests (30 assertions) cover authentication, validation, timestamp-based writes, buy/sell volume calculations, ClusterDelta chart/delta reads, prevention of cTrader fallback and rejection of legacy ingestion. Run `php artisan test --filter=ClusterDelta` inside `laravel-app`.

MetaEditor compilation succeeded on the local Wine installation on 2026-10-20 with 0 errors and 0 warnings. The bridge source/binary, vendor helper and missing DLLs are installed under `/home/simon/.mt5/drive_c/Program Files/MetaTrader 5/MQL5`. Updated bridge files have `.before-app-link` backups. A preset is installed in `MQL5/Presets/FXClusterDeltaBridge.set`.

The app defaults to ClusterDelta with GC selected; local `.env` has the separate bridge token configured. Dashboard charts, delta/CVD endpoints and metrics calculations read the ClusterDelta namespace. cTrader connection controls are commented out and its old ingestion endpoints reject requests while ClusterDelta is selected. Python `main.py` also defaults to leaving cTrader disabled. Error handling no longer inserts simulated candles into the ClusterDelta dashboard.

Local HTTP health, dashboard, settings and feed-status checks passed. After the interrupted session the managed web server and forwarder were restarted; the feed-status endpoint confirms the source is ClusterDelta but reports `connected=false` and `last_received=null`. There is no MT5 snapshot yet. The terminal journal confirms the vendor Footprint indicator loaded on EURUSDm; it does not confirm that FXClusterDeltaBridge is attached. Broker symbols use suffixes, so the automatic launch configuration using XAUUSD may not match the available gold symbol. Attach the bridge to the actual broker gold M15 chart and allow DLL imports.

Live vendor data and price-level footprints remain unverified. This stage exports bar-level ClusterDelta volume/delta paired with MT5 broker OHLC, not exchange-price footprint cells. No subscription credentials were read or copied. Keep the app server and forwarder running during the terminal test.

### DLL permission troubleshooting

The expert log showed `#Volume_Delta_EA_iCustom: DLL loading is not allowed` after the bridge was attached. The bridge now includes a read-only Windows process-ID diagnostic, making a DLL dependency explicit on the EA, and logs terminal/program DLL permission, the export folder, helper initialization failures and buffer readiness. Compilation passed with 0 errors and 0 warnings. After installation, remove and reattach the bridge and enable **Allow DLL imports** on its **Dependencies** tab. Footprint's permission is separate. A terminal-wide default change alone does not establish that an already-attached program was reinitialized with permission.

Reference: https://www.metatrader5.com/en/terminal/help/algotrading/trade_robots_indicators

Live verification remains pending until the updated bridge is reattached with its DLL permission enabled.

### Bridge 1.01 buffer loading

After per-EA DLL imports were enabled, the journal confirmed startup on XAUUSDm M15 with EA DLL permission 1, a valid helper handle and the expected FILE_COMMON path. It then repeatedly reported waiting for vendor indicator history. Version 1.01 removes the early BarsCalculated gate and requests CopyBuffer first, allowing MT5 to initiate missing indicator/history calculation. This is a suspected loading blockage addressed in bridge code; vendor buffer availability still requires live verification after reattaching the updated binary. Compilation passed with 0 errors and 0 warnings.

Reference: https://www.mql5.com/en/docs/series/copybuffer

### First verified market data

The 1.01 bridge produced real GC snapshots containing 50 completed M15 bars and 50 H1 bars. The forwarder accepted both through the authenticated API. Live HTTP checks returned `connected=true`, `has_data=true`, `status=receiving`, 50 M15 candles, 50 delta points and 50 CVD points. One observed M15 bar had delta -133, ask/buy volume 949 and bid/sell volume 1082. These are transient observations, not fixed expectations. The earlier continuous forwarding session had stopped and was restarted; keep both the app server and forwarder running alongside MT5.

### Developing candle support (bridge 1.02)

The first browser investigation verified that real candles rendered and new completed bars reached the chart. Version 1.01 excluded the current bar, so the candle and price were static between M15 closes. Version 1.02 includes the open candle by default, marks it `closed=false`, checks for a candle boundary during sampling and upserts developing snapshots by timestamp. It is compiled and installed with 0 errors and 0 warnings; MT5 must reload 1.02 before this mode becomes active.

The dashboard independently refreshes chart data every ten seconds, labels developing/completed candles, ignores responses for an obsolete timeframe, fits the first load, follows the latest data while the user is at the right edge and resizes with its container. Area mode maps OHLC to closing values. Browser tests confirmed automatic same-timestamp developing-candle updates even when the feed-status request was blocked; these fixture values stayed inside the test browser and were not stored by the app. A separate live browser check rendered 52 real completed candles. Live open-candle verification remains pending reattachment of version 1.02.

Validation: 12 backend monitor/ClusterDelta tests, 68 assertions; successful Blade compilation; Chromium checks of automatic refresh, developing candles, area mode and live rendering with no JavaScript page errors.

### Verified developing candles and active timeframes

Live version 1.02 exports were verified with `closed=false` and changing real OHLC/volume. The active terminal initially exported H1 while the page was still showing M15. The app now chooses the freshest stored timeframe on initial load and follows the active feed until the user explicitly selects a timeframe. `/api/feed-status?tf=...` returns metrics for the requested timeframe; omitting `tf` chooses the active feed. The dashboard supports every bridge timeframe (M1/M5/M15/M30/H1/H4/D1).

The terminal subsequently exported M15 developing candles. A real browser observed one candle's close change from 4122.788 to 4123.094 and volume rise from 392 to 397 at the same timestamp, without reloading the page. Those values are transient observations. This supersedes the earlier pending open-candle verification. Backend validation now passes 13 relevant tests with 79 assertions, including automatic timeframe selection and explicit user selection.
