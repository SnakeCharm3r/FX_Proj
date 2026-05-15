<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class OrderflowMetricsService
{
    /**
     * Compute all orderflow metrics from the last N bars in Redis.
     * Returns a structured array consumed by SetupBuilderService.
     */
    public function compute(string $timeframe, int $lookback = 20): array
    {
        $raw  = Redis::lrange("xauusd:bars:{$timeframe}", 0, $lookback - 1);
        $bars = array_map(function ($b) {
            $decoded = json_decode($b, true);
            return is_string($decoded) ? json_decode($decoded, true) : $decoded;
        }, $raw);

        if (empty($bars)) {
            return $this->emptyMetrics();
        }

        $latest = $bars[0];  // most recent bar first (lpush order)
        $bars   = array_reverse($bars); // chronological for calculations

        return [
            'timeframe'        => $timeframe,
            'price'            => (float) ($latest['close'] ?? 0),
            'open'             => (float) ($latest['open']  ?? 0),
            'high'             => (float) ($latest['high']  ?? 0),
            'low'              => (float) ($latest['low']   ?? 0),
            'close'            => (float) ($latest['close'] ?? 0),
            'delta'            => (int)   ($latest['delta'] ?? 0),
            'buy_volume'       => (float) ($latest['buy_vol']  ?? 0),
            'sell_volume'      => (float) ($latest['sell_vol'] ?? 0),
            'cvd'              => $this->calculateCVD($bars),
            'vwap'             => $this->calculateVWAP($bars),
            'poc'              => $this->calculatePOC($bars),
            'absorption'       => $this->detectAbsorption($latest),
            'imbalance'        => $this->detectImbalance($latest),
            'stacked_imbalance'=> $this->detectStackedImbalance($bars),
            'exhaustion'       => $this->detectExhaustion($bars),
            'cvd_divergence'   => $this->detectCVDDivergence($bars),
            'buy_pct'          => $this->volumePercent($latest, 'buy'),
            'sell_pct'         => $this->volumePercent($latest, 'sell'),
        ];
    }

    private function calculateCVD(array $bars): float
    {
        $cvd = 0;
        foreach ($bars as $b) {
            $cvd += ($b['delta'] ?? 0);
        }
        return $cvd;
    }

    private function calculateVWAP(array $bars): float
    {
        $cumTPV = 0;
        $cumVol = 0;
        foreach ($bars as $b) {
            $tp  = (($b['high'] ?? 0) + ($b['low'] ?? 0) + ($b['close'] ?? 0)) / 3;
            $vol = ($b['buy_vol'] ?? 0) + ($b['sell_vol'] ?? 0);
            $cumTPV += $tp * $vol;
            $cumVol += $vol;
        }
        return $cumVol > 0 ? round($cumTPV / $cumVol, 2) : 0.0;
    }

    private function calculatePOC(array $bars): float
    {
        $volumeByPrice = [];
        foreach ($bars as $b) {
            $price = round(($b['close'] ?? 0) * 2) / 2; // round to 0.5 pip
            $vol   = ($b['buy_vol'] ?? 0) + ($b['sell_vol'] ?? 0);
            $volumeByPrice[(string) $price] = ($volumeByPrice[(string) $price] ?? 0) + $vol;
        }
        if (empty($volumeByPrice)) return 0.0;
        arsort($volumeByPrice);
        return (float) array_key_first($volumeByPrice);
    }

    private function detectAbsorption(array $bar): string
    {
        $open  = $bar['open']  ?? 0;
        $close = $bar['close'] ?? 0;
        $high  = $bar['high']  ?? 0;
        $low   = $bar['low']   ?? 0;
        $body  = abs($close - $open);
        $range = $high - $low;

        if ($range < 0.01) return 'NONE';

        $threshold = config('orderflow.absorption_threshold', 0.3);
        $ratio     = $body / $range;

        if ($ratio < $threshold) {
            return ($bar['delta'] ?? 0) >= 0 ? 'BULL_ABSORPTION' : 'BEAR_ABSORPTION';
        }

        return 'NONE';
    }

    private function detectImbalance(array $bar): string
    {
        $buy  = $bar['buy_vol']  ?? 0;
        $sell = $bar['sell_vol'] ?? 0;
        $ratio = config('orderflow.imbalance_ratio', 3.0);

        if ($sell > 0 && ($buy / $sell) >= $ratio) return 'BULLISH';
        if ($buy  > 0 && ($sell / $buy) >= $ratio)  return 'BEARISH';

        return 'NEUTRAL';
    }

    private function detectStackedImbalance(array $bars): string
    {
        $needed  = config('orderflow.stacked_imbalance_count', 3);
        $ratio   = config('orderflow.imbalance_ratio', 3.0);
        $bullRun = 0;
        $bearRun = 0;

        foreach (array_reverse($bars) as $b) { // most recent last → iterate from newest
            $buy  = $b['buy_vol']  ?? 0;
            $sell = $b['sell_vol'] ?? 0;

            if ($sell > 0 && ($buy / $sell) >= $ratio) { $bullRun++; $bearRun = 0; }
            elseif ($buy > 0 && ($sell / $buy) >= $ratio) { $bearRun++; $bullRun = 0; }
            else { $bullRun = 0; $bearRun = 0; }

            if ($bullRun >= $needed) return 'BULLISH';
            if ($bearRun >= $needed) return 'BEARISH';
        }

        return 'NONE';
    }

    private function detectExhaustion(array $bars): string
    {
        $lookback = config('orderflow.exhaustion_lookback', 10);
        $slice    = array_slice($bars, -$lookback);

        if (count($slice) < 4) return 'NONE';

        $closes  = array_column($slice, 'close');
        $deltas  = array_column($slice, 'delta');
        $last    = end($slice);
        $prev    = prev($slice);

        if (!$last || !$prev) return 'NONE';

        $priceUp   = ($last['close'] ?? 0) > ($prev['close'] ?? 0);
        $deltaFall = ($last['delta'] ?? 0) < ($prev['delta'] ?? 0);
        $priceDn   = ($last['close'] ?? 0) < ($prev['close'] ?? 0);
        $deltaRise = ($last['delta'] ?? 0) > ($prev['delta'] ?? 0);

        if ($priceUp && $deltaFall) return 'BEARISH_EXHAUSTION';
        if ($priceDn && $deltaRise) return 'BULLISH_EXHAUSTION';

        return 'NONE';
    }

    private function detectCVDDivergence(array $bars): string
    {
        if (count($bars) < 4) return 'NONE';

        $threshold = config('orderflow.cvd_divergence_threshold', 200);
        $last  = end($bars);
        $first = reset($bars);

        $priceChange = ($last['close'] ?? 0) - ($first['close'] ?? 0);

        $cvdStart = 0;
        $cvdEnd   = 0;
        $cum      = 0;
        foreach ($bars as $i => $b) {
            $cum += ($b['delta'] ?? 0);
            if ($i === 0) $cvdStart = $cum;
        }
        $cvdEnd = $cum;

        $cvdChange = $cvdEnd - $cvdStart;

        if ($priceChange > 0 && $cvdChange < -$threshold) return 'BEARISH_DIVERGENCE';
        if ($priceChange < 0 && $cvdChange > $threshold)  return 'BULLISH_DIVERGENCE';

        return 'NONE';
    }

    private function volumePercent(array $bar, string $side): float
    {
        $buy  = $bar['buy_vol']  ?? 0;
        $sell = $bar['sell_vol'] ?? 0;
        $total = $buy + $sell;
        if ($total <= 0) return 50.0;
        return round($side === 'buy' ? ($buy / $total * 100) : ($sell / $total * 100), 1);
    }

    private function emptyMetrics(): array
    {
        return [
            'timeframe' => '', 'price' => 0, 'open' => 0, 'high' => 0, 'low' => 0, 'close' => 0,
            'delta' => 0, 'buy_volume' => 0, 'sell_volume' => 0, 'cvd' => 0,
            'vwap' => 0, 'poc' => 0, 'absorption' => 'NONE', 'imbalance' => 'NEUTRAL',
            'stacked_imbalance' => 'NONE', 'exhaustion' => 'NONE', 'cvd_divergence' => 'NONE',
            'buy_pct' => 50, 'sell_pct' => 50,
        ];
    }
}
