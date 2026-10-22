<?php

namespace App\Services;

/** Broker-price estimates weighted by exported exchange volume, not native footprint levels. */
class BrokerReferenceService
{
    public function summarize(array $bars): array
    {
        $weighted = 0; $volume = 0; $buckets = [];
        foreach ($bars as $bar) {
            $weight = ($bar['buy_vol'] ?? 0) + ($bar['sell_vol'] ?? 0);
            if ($weight <= 0) continue;
            $weighted += (($bar['high'] + $bar['low'] + $bar['close']) / 3) * $weight;
            $volume += $weight;
            // Integer half-point indices retain .5 prices; float array keys truncate in PHP.
            $index = (int) round($bar['close'] * 2);
            $buckets[$index] = ($buckets[$index] ?? 0) + $weight;
        }
        arsort($buckets);
        return ['vwap' => $volume > 0 ? round($weighted / $volume, 2) : null,
            'poc' => $buckets ? array_key_first($buckets) / 2 : null];
    }

    /** Chronological rolling values; each point only uses candles available at that time. */
    public function series(array $bars, int $lookback = 20): array
    {
        $vwap = []; $poc = []; $latest = ['vwap'=>null,'poc'=>null];
        foreach ($bars as $i => $bar) {
            $start = max(0, $i + 1 - $lookback);
            $latest = $this->summarize(array_slice($bars, $start, $i + 1 - $start));
            if ($latest['vwap'] !== null) $vwap[] = ['time'=>$bar['time'],'value'=>$latest['vwap']];
            if ($latest['poc'] !== null) $poc[] = ['time'=>$bar['time'],'value'=>$latest['poc']];
        }
        return ['vwap'=>$vwap,'poc'=>$poc,'latest'=>$latest,'lookback'=>$lookback,
            'window_bars'=>min($lookback,count($bars))];
    }
}
