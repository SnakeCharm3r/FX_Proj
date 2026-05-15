<?php

return [
    'channels'          => array_filter(explode(',', env('ALERT_CHANNELS', 'telegram,email'))),
    'min_score'         => (int) env('ALERT_MIN_SCORE', 65),
    'telegram_token'    => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_chat_id'  => env('TELEGRAM_CHAT_ID', ''),
    'telegram_bot_name' => env('TELEGRAM_BOT_NAME', '@goldflow_bot'),
    'alert_email'       => env('ALERT_EMAIL', ''),
    'cooldown_seconds'  => (int) env('ALERT_COOLDOWN_SECONDS', 300),

    'templates' => [
        'new_setup' =>
            "🥇 <b>GoldFlow Pro — XAUUSD {direction} Setup</b>\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "📍 Entry:  <code>{entry}</code>\n" .
            "🛑 Stop:   <code>{stop_loss}</code>\n" .
            "🎯 TP1:    <code>{tp1}</code>\n" .
            "🎯 TP2:    <code>{tp2}</code>\n" .
            "⚡ Score:  <b>{score}%</b>\n" .
            "📊 TF:     {timeframe}\n" .
            "🕐 Time:   {time}",
        'test' =>
            "✅ <b>GoldFlow Pro — Test Alert</b>\n" .
            "Connection is working correctly.\nTime: {time}",
    ],
];
