# H1 reversal study

See [the research protocol](../../docs/xauusd-research-protocol.md) for the two M5 strategy candidates and their data requirements.

This directory contains a reproducible **price-only diagnostic**, not a trading-system backtester. It measures H1 rejection responses at 1, 4 and 12 trading-bar horizons. It does not test the M5 footprint rules, estimate net profitability, or send orders/alerts.

```bash
cd /var/www/FX_Proj/research/reversal-study
python3 -m unittest -v test_study.py
python3 study.py --input data/h1-2026-10-22.json --output outputs/h1-2026-10-22-study.json
```

The input is an actual chart-data snapshot from the application. The report stores its SHA-256 hash, version, period, events, censoring and chronological partition. The later period is exploratory and was already partly inspected; it is not an untouched validation sample. Overlapping events are dependent. Unresolved same-bar excursion order remains unresolved.

Initial data: 499 completed H1 candles, 14 distinct rejection origins. At the 12-bar horizon the later period has only three evaluable raw rejection events, plus one censored event. This cannot establish a durable trading edge.

Research archive verification: the running application saved 49 broker M15 frames and 11 footprint M15 frames when collection was enabled; these were correctly labelled late observations. The live feed stayed healthy. No M5 footprint frames were available at that check. The archive's full source fields and observation timestamps are needed before an as-observed orderflow replay.

Validation: six Python research tests passed; 112 Laravel tests passed (669 assertions), including research archive and ingestion regressions. Live alert criteria were not changed by this study.

M5 follow-up: after the exporters were attached, the integration check found 11 paired completed M5 candles, all matching broker volume/delta and native footprint totals. Collection is now active. See [the integration report](outputs/m5-integration-check.json). This verifies data transport and preservation; it is not an M5 strategy backtest.
