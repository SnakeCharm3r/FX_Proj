<?php

return [
    'channels'          => array_filter(explode(',', env('ALERT_CHANNELS', 'telegram,email'))),
    'min_score'         => (int) env('ALERT_MIN_SCORE', 65),
    'telegram_token'    => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_chat_id'  => env('TELEGRAM_CHAT_ID', ''),
    'telegram_bot_name' => env('TELEGRAM_BOT_NAME', '@goldflow_bot'),
    'alert_email'       => env('ALERT_EMAIL', ''),
    'cooldown_seconds'  => (int) env('ALERT_COOLDOWN_SECONDS', 300),
    'tp_updates_enabled' => (bool) env('ALERT_TP_UPDATES_ENABLED', true),
    'sl_updates_enabled' => (bool) env('ALERT_SL_UPDATES_ENABLED', true),

    'templates' => [
        'new_setup' =>
            "<b>{opportunity}</b>\n" .
            "Delta: <b>{delta_percentage}</b>\n" .
            "Confluence: <b>{score}%</b>\n" .
            "SL: <code>{stop_loss}</code>\n" .
            "TP1: <code>{tp1}</code>\n" .
            "TP2: <code>{tp2}</code>",
        'tp1_hit' =>
            "🎯1️⃣ <b>TP1 reached</b>\n" .
            "{direction_icon} XAUUSD {direction} · #{setup_id}\n" .
            "📍 Entry: <code>{entry}</code>\n" .
            "🏁 Exit: <code>{tp1}</code>",
        'tp2_hit' =>
            "🎯2️⃣ <b>TP2 reached</b>\n" .
            "{direction_icon} XAUUSD {direction} · #{setup_id}\n" .
            "📍 Entry: <code>{entry}</code>\n" .
            "🏁 Exit: <code>{tp2}</code>",
        'sl_hit' =>
            "🛑 <b>SL PRICE REACHED — EXIT LEVEL</b>\n" .
            "{direction_icon} XAUUSD {direction} · Setup #{setup_id} · {timeframe}\n" .
            "📍 Entry: <code>{entry}</code>\n" .
            "🛑 Stop loss: <code>{stop_loss}</code>\n" .
            "🕐 Observed: {time}\n" .
            "📨 Notification: {sent_time}\n" .
            "Price tracking ends at this stop. Order closure is not confirmed.",
        'exit' =>
            "🚪 <b>XAUUSD — EXIT SIGNAL</b>\n" .
            "{direction_icon} Original {direction} · Setup #{setup_id} · {timeframe}\n" .
            "📍 Entry: <code>{entry}</code>\n" .
            "🏁 Exit reference: <code>{exit_price}</code>\n" .
            "📝 Reason: {reason}\n" .
            "🕐 Signal: {time}\n" .
            "Exit instruction; order closure is not confirmed.",
        'test' =>
            "✅ <b>GoldFlow Pro — Test Alert</b>\n" .
            "Connection is working correctly.\nTime: {time}",
    ],
];
