<?php

namespace App\Services;

class HourlyCandleService
{
    public function __construct(private MarketDataService $market) {}

    /** Newest first; synthesize closed H1 only from all four closed M15 slots. */
    public function bars(int $limit = 500): array
    {
        $native = array_map(fn ($v) => json_decode($v, true), $this->market->storedBars('1h', 500));
        $quarters = array_map(fn ($v) => json_decode($v, true), $this->market->storedBars('15m', 500));
        return array_map(fn ($bar) => json_encode($bar), array_slice($this->merge($native, $quarters, now()->timestamp), 0, $limit));
    }

    public function merge(array $native, array $quarters, int $now): array
    {
        $hours = []; $groups = [];
        foreach ($native as $bar) {
            if ($bar['time'] % 3600 !== 0 || $bar['time'] > $now) continue;
            // A frozen developing snapshot is not promoted to a completed candle.
            if (!($bar['closed'] ?? false) && $bar['time'] + 3600 <= $now) continue;
            $bar['candle_source'] = 'native_1h'; $hours[$bar['time']] = $bar;
        }
        foreach ($quarters as $bar) {
            if ($bar['time'] % 900 !== 0 || $bar['time'] > $now || ($bar['price_source'] ?? 'mt5_broker') !== 'mt5_broker') continue;
            $hour = intdiv($bar['time'], 3600) * 3600;
            $groups[$hour][$bar['time']] = $bar;
        }
        foreach ($groups as $hour => $group) {
            $closed = $hour + 3600 <= $now;
            $slots = $closed ? 4 : intdiv($now - $hour, 900) + 1;
            $parts = [];
            for ($i = 0; $i < $slots; $i++) {
                $part = $group[$hour + $i * 900] ?? null;
                if (!$part || (($closed || $i < $slots - 1) && !($part['closed'] ?? false))) { $parts = []; break; }
                $parts[] = $part;
            }
            if (!$parts) continue;
            $last = $parts[count($parts) - 1];
            $bar = ['time' => $hour, 'open' => $parts[0]['open'], 'high' => max(array_column($parts, 'high')),
                'low' => min(array_column($parts, 'low')), 'close' => $last['close'], 'closed' => $closed,
                'buy_vol' => array_sum(array_column($parts, 'buy_vol')), 'sell_vol' => array_sum(array_column($parts, 'sell_vol')),
                'delta' => array_sum(array_column($parts, 'delta')), 'volume' => array_sum(array_column($parts, 'volume')),
                'received_at' => $last['received_at'] ?? 0, 'source' => 'clusterdelta', 'price_source' => 'mt5_broker',
                'candle_source' => 'aggregated_15m'];
            if (!isset($hours[$hour]) || $bar['received_at'] > ($hours[$hour]['received_at'] ?? 0)) $hours[$hour] = $bar;
        }
        krsort($hours);
        return array_values($hours);
    }
}
