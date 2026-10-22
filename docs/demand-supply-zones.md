# H1 demand and supply zones

The dashboard now overlays price zones on broker XAUUSD candles. Select **1H view** beneath the chart to inspect their origin, **Show boxes** to toggle the overlay, and **Include invalidated** to review historical zones. The levels table includes origin and confirmation timestamps in UTC.

The user's selected bounds are the rejection wick up to the candle body:

- Demand: the last bearish origin candle's low to `min(open, close)`.
- Supply: the last bullish origin candle's `max(open, close)` to high.

## Confirmation rules, version wick-reversal-v1

The first version translates “rejection followed by a major H1 reversal” into explicit, reviewable rules. Defaults are in `laravel-app/config/price_zones.php`:

1. The origin is an opposite candle at a three-bar local low for demand or local high for supply.
2. Its relevant wick occupies at least 20% of its range.
3. The next candle reverses direction. Confirmation must follow within three consecutive, closed H1 candles, all in the reversal direction, without breaking the origin's far wick extreme.
4. A confirmation close breaks the origin and preceding three-bar price structure, moves at least 1.5 times the preceding 14-bar average true range from the zone's body edge, and has a body occupying at least 50% of its own range.
5. The zone becomes knowable only at the confirmation candle's close. Its box begins at the origin for chart review; origin and confirmation are stored separately.

A later closed H1 candle strictly below the demand low or above the supply high invalidates it. An invalidated box ends at that candle's close and is dashed. Missing hourly history after confirmation makes a zone unverified and disables its arrival alerts. Zones persist in SQL, with a unique source/instrument/version/type/origin key; scans do not duplicate them. New zones start unverified until their subsequent history has been checked.

This detector is independent of the existing 60-point footprint confluence setup model. It records price areas and arrival alerts; it does not place orders or generate artificial entry/stop/target records.

## Hourly data and chart rendering

`HourlyCandleService` combines existing native H1 broker bars with H1 reconstructed from M15. A completed reconstructed hour requires all four correctly aligned, completed M15 slots. Incomplete or frozen developing snapshots are never promoted to completed candles. The current partial hour can be displayed as developing when all elapsed quarter-hour slots are available. Fresh reconstructions replace older overlapping native snapshots, and retain summed volume/delta. Futures footprint prices are not used as broker-zone bounds.

The XAUUSD chart, feed-status and price metric paths use the same merged H1 data. Its current candle is labelled “H1 built from M15” when applicable. The existing H1 footprint setup still requires actual H1 footprint cells; price-zone detection does not invent them.

The SVG overlay uses chart time/price coordinates and redraws on data changes, horizontal range changes, resize and chart pointer/wheel interactions. Active boxes use a 1-pixel outline and 3.5% fill. Invalidated, unverified or paused boxes use dashed outlines and 1.5% fill. Boxes are clipped to the candle pane and do not alter chart autoscaling.

## Arrival notifications

`price-zones:process` runs after the orderflow scan in `orderflow:watch`. It is also registered with the existing scheduler every minute as an alternative. Keep one watcher running:

```sh
cd /var/www/FX_Proj/laravel-app
php artisan orderflow:watch
```

Every scan checks the freshest current developing broker candle from the available 1m, 5m, M15 or H1 feed. Receipts must be under 60 seconds old, with a valid current candle clock. No stale, historical or future price can trigger a notification. Sampling is approximately every ten seconds; this is not a tick-level arrival recorder.

An observed outside-to-inside transition records one `ZONE_TOUCH` in `alert_logs`, channel `browser`. A first observation already inside is labelled “is inside” rather than claiming an observed crossing. Staying inside does not repeat the alert. A re-entry requires a new visit and at least five minutes since that zone's previous alert. Per-zone row locks, observation timestamps, and a unique zone/entry constraint prevent duplicate records from overlapping checks.

The durable alert status is `recorded`; it does not claim that a browser displayed it. Every app page polls `GET /api/zone-alerts` and displays new events as in-app toasts with a bell badge. A cursor avoids replaying old events and is shared between pages through local storage. Events remain available in Alerts even when no page was open. Email and Telegram are not used by this path. Arrival alerts do not require Reverb or a queue worker.

`GET /api/price-zones` is read-only and returns monitor readiness, stored zones and rule settings. It does not trigger alert generation. The detector's heartbeat is marked stale after 90 seconds without a scan.

## Verification

The full test suite passed (49 tests). Focused tests cover both demand and supply wick bounds, absence of future/developing confirmation, missing hours, insufficient rejection/departure, hourly OHLC/volume aggregation, durable idempotent creation, initialization as unverified, stale-price suppression, first-inside wording, repeated visits/cooldown, close-based invalidation, history-gap suppression, and notification cursors. Persistence fixtures use SQLite in memory.

Live Chromium checks verified the actual historical H1 zone, box visibility controls, reconstruction labels, and agreement of SVG box coordinates with chart price/time coordinates after changing the visible range. Toast rendering was tested with a response intercepted only in an isolated browser; no synthetic zone or alert was inserted into the live database.

The initial live scan found demand 4115.447–4130.555, origin 2026-10-20 01:00 UTC, confirmed at 04:00 UTC, invalidated at 10:00 UTC. It is available for review, with arrival alerts disabled. No active zone was forced from the current sample. These are deterministic chart rules, not validated claims about future price behavior; change the strategy version when changing its rules.
