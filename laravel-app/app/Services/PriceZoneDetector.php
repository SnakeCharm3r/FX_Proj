<?php

namespace App\Services;

class PriceZoneDetector
{
    /** A zone becomes knowable only at the confirmation candle's close. */
    public function detect(array $bars, int $now): array
    {
        if(config('price_zones.version')==='rejection-swing-v2'){
            return app(RejectionRangeDetector::class)->detect($bars,$now);
        }
        $bars = array_values(array_filter($bars, fn ($b) => ($b['closed'] ?? false) && $b['time'] + 3600 <= $now));
        usort($bars, fn ($a, $b) => $a['time'] <=> $b['time']);
        $period = config('price_zones.atr_period', 14);
        $pivot = config('price_zones.pivot_lookback', 3);
        $zones = [];
        for ($i = max($period, $pivot); $i < count($bars) - 1; $i++) {
            $origin = $bars[$i]; $range = $origin['high'] - $origin['low'];
            if ($range <= 0 || $origin['close'] == $origin['open']) continue;
            $demand = $origin['close'] < $origin['open'];
            $low = $demand ? $origin['low'] : max($origin['open'], $origin['close']);
            $high = $demand ? min($origin['open'], $origin['close']) : $origin['high'];
            if ($high <= $low || ($high - $low) / $range < config('price_zones.rejection_wick_ratio', 0.2)) continue;
            $context = array_slice($bars, $i - $pivot, $pivot);
            if ($demand && $origin['low'] > min(array_column($context, 'low'))) continue;
            if (!$demand && $origin['high'] < max(array_column($context, 'high'))) continue;
            $tr = [];
            for ($k = $i - $period; $k < $i; $k++) {
                $prevClose = $bars[max(0, $k - 1)]['close'];
                $tr[] = max($bars[$k]['high'] - $bars[$k]['low'], abs($bars[$k]['high'] - $prevClose), abs($bars[$k]['low'] - $prevClose));
            }
            $atr = array_sum($tr) / $period;
            if ($atr <= 0) continue;
            for ($j = 1; $j <= config('price_zones.departure_bars', 3) && isset($bars[$i + $j]); $j++) {
                $bar = $bars[$i + $j]; $previous = $bars[$i + $j - 1];
                // The reversal must follow immediately, without an unseen hour or another opposite candle.
                if ($bar['time'] !== $previous['time'] + 3600) break;
                if ($demand ? ($bar['close'] <= $bar['open'] || $bar['low'] < $origin['low'])
                    : ($bar['close'] >= $bar['open'] || $bar['high'] > $origin['high'])) break;
                $move = $demand ? $bar['close'] - $high : $low - $bar['close'];
                $breakLevel = $demand ? max($origin['high'], ...array_column($context, 'high'))
                    : min($origin['low'], ...array_column($context, 'low'));
                if ($demand ? $bar['close'] <= $breakLevel : $bar['close'] >= $breakLevel) continue;
                $barRange = $bar['high'] - $bar['low'];
                if ($barRange <= 0 || abs($bar['close'] - $bar['open']) / $barRange < config('price_zones.departure_body_ratio', 0.5)) continue;
                if ($move < $atr * config('price_zones.departure_atr', 1.5)) continue;
                $zones[] = ['type' => $demand ? 'DEMAND' : 'SUPPLY', 'timeframe' => '1h',
                    'low' => $low, 'high' => $high, 'atr' => $atr, 'origin_time' => $origin['time'],
                    'confirmed_time' => $bar['time'] + 3600, 'metadata' => ['origin' => $origin,
                        'confirmation' => $bar, 'wick_ratio' => ($high - $low) / $range,
                        'departure_atr' => $move / $atr, 'bounds' => 'rejection_wick_to_body']];
                break;
            }
        }
        return $zones;
    }
}
