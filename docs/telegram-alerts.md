# Telegram setup messages

Configure `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` in `laravel-app/.env`. For a public channel the destination can be `@channel_username`; a private channel needs its numeric chat ID. Add the bot as a channel administrator with permission to post messages. Keep the token private.

`ALERT_CHANNELS=telegram` selects Telegram only. `OF_EXTERNAL_ALERTS_ENABLED=false` keeps automatic entry and target notifications off while configuring. After checking delivery, set it to true, run `php artisan config:clear`, and restart the existing `orderflow:watch` process so it reloads the configuration and PHP code. Keep a single watcher running. Inspect the Alerts page's sent/failed records to verify delivery; a test-request success toast alone is not proof of Telegram delivery.

## Entry

Templates live in `laravel-app/config/alerts.php`; `SetupAlertMessageService` formats prices to two decimals and escapes substitutions for Telegram HTML. BUY uses 🟢⬆️ and SELL uses 🔴⬇️; the colored circle carries the color because Telegram controls arrow rendering.

```text
🟢⬆️ XAUUSD BUY — ENTRY SIGNAL
━━━━━━━━━━━━━━━━
🆔 Setup #{setup_id} · 📊 {timeframe}
📍 Entry: {entry}
🛑 Stop: {stop_loss}
🎯1️⃣ TP1: {tp1}
🎯2️⃣ TP2: {tp2}
🧭 Basis: H1 zone + confirmed M5 absorption
🕐 Signal: {setup_created_at} UTC
Reference levels · GoldFlow Pro
```

The creation hook formats the newly saved setup once; repeat evaluation of the same sequence and zone does not send another entry notification. Current alerts name the mandatory strategy basis. Older weighted strategy versions show their weighted confluence instead; that score is not a success probability.

## Targets and exit template

Newly persisted target touches emit separate `TP1_HIT` / `TP2_HIT` notifications when external alerts are enabled. Messages use a compact four-line format:

```text
🎯1️⃣ TP1 reached
🟢⬆️ XAUUSD BUY · #{setup_id}
📍 Entry: {entry}
🏁 Exit: {tp1}
```

TP2 uses `🎯2️⃣ TP2 reached` and its own target as Exit; SELL uses `🔴⬇️`. Exit is the configured target level, not a measured broker fill. Observation timestamps remain in the setup evidence, while alert logs retain notification creation time. Repeated scans and already recorded hits do not resend notifications. Target touches can be recovered from later historical candles, so observation time may precede notification time.

Target messages describe price observations, not broker fills or confirmed order closures. They do not alter position size, move a stop, or change setup status. Dispatch occurs after price-touch persistence, so delivery failure does not undo tracking. Failed dispatches are not automatically retried; there is no durable notification outbox yet.

A generic `exit` template is available through `SetupAlertMessageService::exitSignal`, requiring an explicit price, reason and timestamp. It retains the original setup direction. Expiry or an opposite signal is not reported as a filled exit.

## TP and SL controls

The local installation has automatic alerts enabled after successful historical-entry and TP1 posts. These two `.env` switches control exit-level messages independently of entry alerts:

```dotenv
ALERT_TP_UPDATES_ENABLED=true
ALERT_SL_UPDATES_ENABLED=true
```

The first covers TP1 and TP2; the second covers SL. Both require `OF_EXTERNAL_ALERTS_ENABLED=true`. Disabling a message switch does not disable price tracking. Clear cached configuration and restart the single watcher after changing runtime configuration.

The additive migration `2026_10_21_120000_add_setup_stop_tracking` adds `sl_hit_at` and `exit_tracking_state`. BUY stops use later broker lows; SELL stops use later broker highs. Creation-candle wicks, stale developing data and wrong-source prices are excluded. An observed stop emits `SL_HIT` with the setup ID, original direction, stop and observation time. The selected setup card and dashboard show a red SL indicator.

TP1 is intermediate. Reference-price tracking ends at the first unambiguous SL or TP2. A later retracement cannot send SL after TP2, and a rally cannot send TP2 after SL. These states do not claim a broker position was closed.

If one candle spans both the stop and an unresolved target, OHLC cannot establish first touch. Tracking pauses as `exit_tracking_state=ambiguous`, stores evidence in `target_hit_details.path_ambiguous`, and displays a warning. That candle sends neither a fabricated SL nor a fabricated TP update; an earlier separately established TP1 is preserved. Resolving the path requires finer historical data.

Reference-level tracking can also use a fresh developing broker close from any exported timeframe when the setup's own candle feed has no new event. It checks source, instrument, receipt freshness and current candle time, and uses only the sampled close. Another timeframe's high/low is never imported into the setup. Its strategy timeframe and saved levels stay fixed. Evidence records `basis=sampled_broker_quote`, both timeframes and the observation timestamp. This keeps target checks working when the user changes the MT5 chart timeframe; new setup detection still requires matching candle/footprint exports for each strategy timeframe.

Verification: 87 tests / 526 assertions passed, covering BUY/SELL stops, TP/SL switches, duplicate suppression, terminal levels, ambiguous paths, invalid samples, message formatting and cross-timeframe broker quotes. Notifications are mocked in tests. The attempted additional browser check could not start its headless browser; no browser verification of the new SL state is claimed. With the restored live M15 feed, setup #1's TP2 was recorded and its `TP2_HIT` Telegram log shows sent.
