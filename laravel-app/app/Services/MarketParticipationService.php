<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class MarketParticipationService
{
    public function analyse(array $chronologicalBars, string $timeframe): array
    {
        $bars = array_values(array_filter($chronologicalBars, fn ($bar) =>
            ($bar['closed'] ?? false) === true
            && isset($bar['time'], $bar['buy_vol'], $bar['sell_vol'], $bar['delta'])
        ));

        if (!$bars) return $this->unavailable();

        $current = end($bars);
        $prior = array_slice($bars, 0, -1);
        $lookback = (int) config('orderflow.participation_lookback', 20);
        $sample = array_slice($prior, -$lookback);
        $volumes = array_values(array_filter(array_map(fn ($bar) => $this->volume($bar), $sample), fn ($v) => $v > 0));
        $baseline = $this->median($volumes);
        $volume = $this->volume($current);
        $ratio = $baseline > 0 ? $volume / $baseline : null;
        $minimum = (int) config('orderflow.participation_min_samples', 10);

        $state = 'UNAVAILABLE';
        if (count($volumes) >= $minimum && $ratio !== null) {
            $state = $ratio >= (float) config('orderflow.participation_increased_ratio', 1.5) ? 'INCREASED'
                : ($ratio <= (float) config('orderflow.participation_decreased_ratio', 0.7) ? 'DECREASED' : 'NORMAL');
        }

        $delta = (float) $current['delta'];
        $deltaShare = $volume > 0 ? $delta / $volume : 0.0;
        $dominance = (float) config('orderflow.participation_delta_share', 0.1);
        $flow = $deltaShare >= $dominance ? 'BUY_DOMINANT'
            : ($deltaShare <= -$dominance ? 'SELL_DOMINANT' : 'BALANCED');

        $period = $this->period($timeframe);
        $sessionStart = $this->sessionStart((int) $current['time']);
        $sessionBars = array_values(array_filter($bars, fn ($bar) => (int) $bar['time'] >= $sessionStart));
        $firstObserved = $sessionBars ? (int) $sessionBars[0]['time'] : null;
        $coverage = $firstObserved !== null && $firstObserved <= $sessionStart + $period ? 'COMPLETE' : 'PARTIAL';

        return [
            'bar_time' => (int) $current['time'],
            'state' => $state,
            'volume' => $volume,
            'baseline_volume' => $baseline > 0 ? round($baseline, 2) : null,
            'ratio' => $ratio !== null ? round($ratio, 4) : null,
            'baseline_kind' => 'rolling_median',
            'baseline_sample_size' => count($volumes),
            'baseline_lookback' => $lookback,
            'flow' => $flow,
            'delta_share' => round($deltaShare, 4),
            'session_start' => $sessionStart,
            'session_timezone' => config('orderflow.gc_session_timezone', 'America/New_York'),
            'session_first_observed' => $firstObserved,
            'session_coverage' => $coverage,
            'session_bar_count' => count($sessionBars),
            'session_volume' => array_sum(array_map(fn ($bar) => $this->volume($bar), $sessionBars)),
            'session_delta' => array_sum(array_map(fn ($bar) => (float) $bar['delta'], $sessionBars)),
        ];
    }

    private function volume(array $bar): float
    {
        return (float) ($bar['buy_vol'] ?? 0) + (float) ($bar['sell_vol'] ?? 0);
    }

    private function median(array $values): float
    {
        if (!$values) return 0.0;
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        return count($values) % 2 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function sessionStart(int $timestamp): int
    {
        $timezone = config('orderflow.gc_session_timezone', 'America/New_York');
        $open = config('orderflow.gc_session_open', '18:00');
        [$hour, $minute] = array_map('intval', explode(':', $open));
        $local = CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($timezone);
        $start = $local->setTime($hour, $minute);
        if ($local->lt($start)) $start = $start->subDay();
        return $start->utc()->timestamp;
    }

    private function period(string $timeframe): int
    {
        return ['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400][$timeframe] ?? 300;
    }

    private function unavailable(): array
    {
        return ['bar_time'=>null,'state'=>'UNAVAILABLE','volume'=>0,'baseline_volume'=>null,'ratio'=>null,
            'baseline_kind'=>'rolling_median','baseline_sample_size'=>0,
            'baseline_lookback'=>(int)config('orderflow.participation_lookback',20),
            'flow'=>'UNAVAILABLE','delta_share'=>null,'session_start'=>null,
            'session_timezone'=>config('orderflow.gc_session_timezone','America/New_York'),
            'session_first_observed'=>null,'session_coverage'=>'PARTIAL','session_bar_count'=>0,
            'session_volume'=>0,'session_delta'=>0];
    }
}
