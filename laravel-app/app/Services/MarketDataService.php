<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class MarketDataService
{
    /** A fresh developing broker close can monitor reference levels across chart changes. */
    public function latestBrokerQuote(): ?array
    {
        if (config('orderflow.connector') !== 'clusterdelta') return null;
        $latest = null; $now = now()->timestamp;
        foreach (['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400] as $tf=>$period) {
            $raw = $this->storedBars($tf, 1);
            $bar = $raw ? json_decode($raw[0], true) : null;
            if (!$bar || ($bar['closed'] ?? true) !== false || ($bar['price_source'] ?? '') !== 'mt5_broker'
                || ($bar['source'] ?? '') !== config('orderflow.connector')) continue;
            $received = $bar['received_at'] ?? 0; $time = $bar['time'] ?? 0; $price = $bar['close'] ?? 0;
            if ($received > $now + 5 || $now - $received >= 60 || $time > $now || $time + $period <= $now
                || $received < $time || !is_numeric($price) || !is_finite((float)$price) || $price <= 0) continue;
            if ($latest === null || $received > $latest['received_at']) {
                $latest = array_merge($bar, ['timeframe'=>$tf,'instrument'=>config('clusterdelta.instrument','GC')]);
            }
        }
        return $latest;
    }

    public function activeTimeframe(): string
    {
        if (config('orderflow.connector') !== 'clusterdelta') return '15m';
        $active = '15m';
        $latest = 0;
        foreach (['15m', '5m', '30m', '1h', '4h', '1m', '1d'] as $tf) {
            $raw = $this->bars($tf, 1);
            $bar = $raw ? json_decode($raw[0], true) : null;
            $received = $bar['received_at'] ?? 0;
            if ($received > $latest) {
                $latest = $received;
                $active = $tf;
            }
        }
        return $active;
    }

    public function bars(string $tf, int $limit = 200): array
    {
        if ($tf === '1h' && config('orderflow.connector') === 'clusterdelta') {
            return app(HourlyCandleService::class)->bars($limit);
        }
        return $this->storedBars($tf, $limit);
    }

    public function storedBars(string $tf, int $limit = 200): array
    {
        if (config('orderflow.connector') !== 'clusterdelta') {
            return Redis::lrange("xauusd:bars:{$tf}", 0, $limit - 1);
        }
        $instrument = config('clusterdelta.instrument', 'GC');
        $key = "clusterdelta:{$instrument}:{$tf}";
        $times = Redis::zrevrange($key.':times', 0, $limit - 1);
        if (!$times) return [];
        return array_values(array_filter(Redis::hmget($key.':values', ...$times), function ($value) {
            $bar = json_decode($value ?: '', true);
            return is_array($bar) && isset($bar['open'], $bar['high'], $bar['low'], $bar['close']);
        }));
    }
}
