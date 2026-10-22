# GC market participation

This layer records how much executed GC volume is present without treating volume as a trade direction or an entry condition.

## Recorded measures

- `volume`: executed ask plus bid volume in a completed bar.
- `delta`: executed ask minus bid volume.
- `delta`: the bar's total ask-minus-bid delta, recorded beside participation rather than used as a participant count.
- `baseline_volume`: median volume of the previous 20 completed bars on the same timeframe.
- `participation_ratio`: current volume divided by that rolling median.
- `session_volume` and `session_delta`: totals observed since the official GC session start.
- `session_coverage`: `COMPLETE` only when stored data reaches the session open; otherwise `PARTIAL`.

Footprint volume does not identify unique traders and does not expose historical unexecuted or cancelled limit orders.

## Participation states

| State | Rule |
|---|---|
| `INCREASED` | volume is at least 1.50 times the prior 20-bar median |
| `NORMAL` | volume is between 0.70 and 1.50 times the median |
| `DECREASED` | volume is at most 0.70 times the median |
| `UNAVAILABLE` | fewer than 10 valid prior completed bars |

Threshold equality belongs to the outer state: exactly 1.50 is increased and exactly 0.70 is decreased.

Participation is classified from total volume only. Total delta remains its signed raw value. The application does not translate ask volume, bid volume, or delta into a number of individual traders.

## Use with absorption

Participation supplies context and zero setup points. An absorption deduction still needs location and price response:

- potential bearish absorption: buying remains active near a high, but price closes away from that high;
- potential bullish absorption: selling remains active near a low, but price closes away from that low.

Increased participation strengthens the evidence that meaningful market activity met opposition. Normal or decreased participation makes the conclusion weaker or more consistent with exhaustion. Total delta shows the net aggressive pressure during that activity. Passive liquidity remains an inference because the feed contains executed footprint cells rather than historical order-book depth.

## Session handling

The GC session anchor is 18:00 in `America/New_York`, with daylight-saving conversion handled by the timezone database. The application retains a coverage flag because data collected after MT5 starts cannot reconstruct the missed part of the session.

The dashboard shows the last completed bar's participation. Records are available from `/api/orderflow/participation?instrument=GC&tf=5m`.
