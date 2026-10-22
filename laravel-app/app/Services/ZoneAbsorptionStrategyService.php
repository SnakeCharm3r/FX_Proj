<?php

namespace App\Services;

class ZoneAbsorptionStrategyService
{
    public function __construct(private PriceZoneService $zones, private HourlyPriceHistoryService $history) {}

    public function assess(array $metrics): array
    {
        $base = ['qualified' => false, 'strategy_version' => 'zone_absorption_v2',
            'stage' => 'waiting_for_absorption', 'direction' => 'NEUTRAL'];
        if (($metrics['timeframe'] ?? '') !== '5m') {
            return array_merge($base, ['reason' => 'zone_absorption_v2 creates entries on M5 only.']);
        }
        $evidence = $metrics['absorption_evidence'] ?? [];
        if (!($evidence['qualified'] ?? false)) {
            return array_merge($base, ['stage' => $evidence['stage'] ?? 'waiting_for_absorption',
                'reason' => $evidence['reason'] ?? 'Waiting for a confirmed absorption sequence.']);
        }

        $direction = $evidence['direction'];
        $type = $direction === 'BUY' ? 'DEMAND' : 'SUPPLY';
        $end = (int) $metrics['bar_time'] + 300;
        $zones = $this->zones->scope()->where('type', $type)
            ->where('confirmed_time', '<=', $evidence['effort_time'])
            ->where(fn ($query) => $query->whereNull('invalidated_time')->orWhere('invalidated_time', '>', $end))
            ->orderByDesc('origin_time')->get();
        $history = array_column($this->history->bars(), null, 'time');
        $coverage = $this->history->coverage();

        foreach ($zones as $zone) {
            $touches = $evidence['effort_low'] <= $zone->high && $evidence['effort_high'] >= $zone->low;
            $retests = $evidence['rejection_low'] <= $zone->high && $evidence['rejection_high'] >= $zone->low;
            if (!$touches && !$retests) continue;
            for ($time = $zone->confirmed_time; $time + 3600 <= $metrics['bar_time']; $time += 3600) {
                $bar = $history[$time] ?? null;
                if (!$bar && $this->history->covered($time, $coverage)) continue;
                if (!$bar || !($bar['closed'] ?? false)) {
                    return array_merge($base, ['direction' => $direction, 'stage' => 'zone_unverified',
                        'reason' => 'Absorption confirmed, but H1 zone history has a missing candle.']);
                }
                if ($direction === 'BUY' ? $bar['close'] < $zone->low : $bar['close'] > $zone->high) {
                    continue 2;
                }
            }
            return array_merge($base, ['qualified' => true, 'direction' => $direction, 'stage' => 'confirmed',
                'price_zone_id' => $zone->id, 'zone_low' => $zone->low, 'zone_high' => $zone->high,
                'arrival_time' => $touches ? $evidence['effort_time'] : $evidence['rejection_time'],
                'rejection_time' => $evidence['rejection_time'], 'rejection_low' => $evidence['rejection_low'],
                'rejection_high' => $evidence['rejection_high'],
                'reason' => "Confirmed H1 {$type} arrival, failed M5 effort/retest and opposite-delta break."]);
        }

        return array_merge($base, ['direction' => $direction, 'stage' => 'waiting_for_zone',
            'reason' => "M5 {$direction} absorption confirmed, but it did not occur at a pre-existing valid H1 {$type} zone. No alert."]);
    }
}
