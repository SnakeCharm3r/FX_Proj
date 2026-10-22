<?php

namespace App\Services;

class ZoneRetestService
{
    public function __construct(private PriceZoneService $zones, private HourlyPriceHistoryService $history) {}

    /** The engine has already checked freshness and matching native footprint totals. */
    public function assess(array $m, array $assessment): array
    {
        $direction = $assessment['direction'];
        $base = ['qualified' => false, 'strategy_version' => 'zone_retest_v1', 'stage' => 'waiting_for_zone'];
        if (!in_array($direction, ['BUY','SELL'], true)) return $base + ['reason' => 'Waiting for a unique orderflow direction.'];
        $seconds = ['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400][$m['timeframe']];
        $end = $m['bar_time'] + $seconds;
        $type = $direction === 'BUY' ? 'DEMAND' : 'SUPPLY';
        // Assess validity at the evaluated candle, not using later invalidation information.
        $zones = $this->zones->scope()->where('type', $type)->where('confirmed_time', '<=', $m['bar_time'])
            ->where(fn ($q) => $q->whereNull('invalidated_time')->orWhere('invalidated_time', '>', $end))
            ->orderByDesc('origin_time')->get();
        $history = array_column($this->history->bars(), null, 'time');
        $coverage = $this->history->coverage();
        $recent = [];
        foreach (app(MarketDataService::class)->bars($m['timeframe'], 4) as $raw) {
            $bar = json_decode($raw, true);
            if (($bar['closed'] ?? false) && $bar['time'] >= $m['bar_time'] - 2*$seconds && $bar['time'] <= $m['bar_time']) $recent[$bar['time']] = $bar;
        }
        $recent[$m['bar_time']] = $m + ['time'=>$m['bar_time']];
        krsort($recent);
        $result = $base + ['reason' => "No confirmed 1H {$type} zone was retested by this candle. No {$direction} alert."];
        foreach ($zones as $zone) {
            $rejection = null; $touch = null;
            foreach ($recent as $bar) {
                if ($bar['time'] < $zone->confirmed_time || $bar['low'] > $zone->high || $bar['high'] < $zone->low) continue;
                $touch ??= $bar['time'];
                $range = $bar['high']-$bar['low'];
                $wick = $direction === 'BUY' ? min($bar['open'],$bar['close'])-$bar['low'] : $bar['high']-max($bar['open'],$bar['close']);
                $away = $direction === 'BUY' ? $bar['close'] > $zone->high && $bar['close'] > $bar['open']
                    : $bar['close'] < $zone->low && $bar['close'] < $bar['open'];
                if ($away && $range>0 && $wick/$range >= config('price_zones.rejection_wick_ratio',.2)) { $rejection = $bar; break; }
            }
            if ($touch === null) continue;
            $result = array_merge($base, ['price_zone_id'=>$zone->id, 'zone_low'=>$zone->low, 'zone_high'=>$zone->high,
                'arrival_time'=>$touch, 'stage'=>'arrival', 'reason'=>'Zone touched; waiting for a completed rejection candle.']);
            for ($t = $zone->confirmed_time; $t + 3600 <= $m['bar_time']; $t += 3600) {
                $bar = $history[$t] ?? null;
                if (!$bar && $this->history->covered($t, $coverage)) continue;
                if (!$bar || !($bar['closed'] ?? false)) {
                    $result['reason'] = 'Zone history has a missing H1 candle; validity cannot be verified.';
                    continue 2;
                }
                if ($direction === 'BUY' ? $bar['close'] < $zone->low : $bar['close'] > $zone->high) {
                    $result['reason'] = 'A completed H1 candle already invalidated this zone.';
                    continue 2;
                }
            }
            if (!$rejection) continue;
            // Confirmation can occur on the rejection or either of the next two candles.
            for ($t=$rejection['time']; $t<=$m['bar_time']; $t+=$seconds) {
                $bar=$recent[$t] ?? null;
                if (!$bar || ($direction === 'BUY' ? $bar['close'] <= $zone->high : $bar['close'] >= $zone->low)) {
                    $result['reason']='Rejection sequence was interrupted or returned inside the zone.';
                    continue 2;
                }
            }
            $result['rejection_time'] = $rejection['time'];
            $result['rejection_low'] = $rejection['low'];
            $result['rejection_high'] = $rejection['high'];
            $result['stage'] = 'rejection';
            $result['reason'] = 'Zone rejected; waiting for aligned delta, native footprint stack and qualifying score.';
            $alignedDelta = $direction === 'BUY' ? $m['delta'] >= config('orderflow.delta_threshold') : $m['delta'] <= -config('orderflow.delta_threshold');
            $stack = $direction === 'BUY' ? 'BULLISH' : 'BEARISH';
            if (!$alignedDelta || ($m['stacked_imbalance'] ?? 'NONE') !== $stack || $assessment['score'] < $assessment['threshold']) continue;
            $result['qualified'] = true;
            $result['stage'] = 'confirmed';
            $result['reason'] = "Confirmed 1H {$type} retest, rejection and aligned footprint orderflow.";
            return $result;
        }
        return $result;
    }
}
