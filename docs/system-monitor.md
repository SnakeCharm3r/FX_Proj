# System Monitor

Open http://localhost:8003/monitor or select System Monitor in the sidebar.

The application and feed services now run under systemd, with startup and automatic restart configured. See [background-startup.md](background-startup.md) for controls and the separate MT5 desktop login requirement.

The page checks `/api/system-status` every five seconds and shows:
- Application server responsiveness.
- MT5, the ClusterDelta data forwarder and optional Reverb WebSocket process status, with detected PIDs.
- Redis connectivity.
- Export modification time and application receipt time for each detected ClusterDelta timeframe, including M15 even before its first export.

Process names are matched from Linux `/proc`. The monitor does not return full command lines or credentials. If process visibility is restricted, fresh exports/arrivals provide an `Active via data` state; otherwise a process is shown as not detected or not visible. A process being running does not establish that its data feed is fresh. Timestamps older than 60 seconds count as stale. The summary describes where the data flow appears to have stopped.

If the app becomes unreachable while the page remains open, the browser clears the previous green states and retries automatically. This is a local monitor, not an independent uptime service; it cannot load from scratch while the app server is stopped. It does not restart programs.

MT5 can remain running while its bridge is removed. On 21 October 2026, its Journal recorded `FXClusterDeltaBridge (XAUUSDm,M15) removed` at 14:09:35 EAT. The footprint exporter subsequently continued writing cells while broker candles stopped. Both the bridge and footprint were unloaded together; these logs do not identify the actor or exact chart action. Restore both components: `FXClusterDeltaBridge` under Expert Advisors and `FXClusterDeltaFootprint` under Indicators. Keep local-file transport and open-bar exports enabled on the bridge, plus its DLL permission. Fresh footprint cells alone cannot replace broker prices for charts or TP/SL tracking.

Reattachment was verified first on H1 and later M15: application health became healthy, fresh broker prices and footprints arrived, and matching completed-candle evaluations resumed. Feed freshness is per timeframe. Changing MT5's timeframe can leave the former timeframe stale while the new one is live; the dashboard defaults to the active feed unless a setup or chart timeframe has been explicitly selected. TP/SL tracking can use a validated fresh broker close across timeframe changes while preserving the setup's strategy timeframe.

The export directory can be configured through `MT5_EXPORT_DIRECTORY` in the Laravel environment. Its default matches the local Wine prefix. The source instrument follows `CLUSTERDELTA_INSTRUMENT`.

Validation: 13 monitor/ClusterDelta tests (79 assertions) passed, Blade templates compiled, and monitor JavaScript passed a syntax check. Live page/API checks passed; MT5, forwarder, app and Redis were observed running, with a fresh M15 export/receipt. Reverb was not detected and is optional for the polling dashboard.

## Setup detector

The monitor also shows a Setup detector card based on its recent evaluation heartbeat (90 seconds). The `goldflow-engine.service` background service runs `orderflow:watch`; no additional terminal instance is needed. A recent heartbeat means evaluations are running; it does not mean a trade condition has qualified. Setups explains per-timeframe data blockers and directional scores. See [setup-detector.md](setup-detector.md).

## H1 price-zone monitoring

`orderflow:watch` now also calls `price-zones:process`. The dashboard's demand/supply panel shows that monitor's recent heartbeat, active/unverified counts, and input readiness. It checks broker-price arrivals independently of the footprint confluence threshold. H1 candles can be reconstructed from complete M15 groups when the separate H1 export is stale; the dashboard labels that source. See [demand-supply-zones.md](demand-supply-zones.md).
