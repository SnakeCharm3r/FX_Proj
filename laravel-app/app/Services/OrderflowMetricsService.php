<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class OrderflowMetricsService
{
    /**
     * Compute all orderflow metrics from the last N bars in Redis.
     * Returns a structured array consumed by SetupBuilderService.
     */
    public function compute(string $timeframe, int $lookback = 20, bool $closedOnly = false): array
    {
        $historyLimit = max($lookback + ($closedOnly ? 1 : 0), (int) config('orderflow.participation_history_bars', 300));
        $raw  = app(\App\Services\MarketDataService::class)->bars($timeframe, $historyLimit);
        $bars = array_map(function ($b) {
            $decoded = json_decode($b, true);
            return is_string($decoded) ? json_decode($decoded, true) : $decoded;
        }, $raw);

        if ($closedOnly) {
            $bars = array_values(array_filter($bars, fn ($b) => ($b['closed'] ?? false) === true));
        }
        return $this->computeFromBars(array_reverse($bars), $timeframe, $lookback);
    }

    /** Metrics for user-facing charts, sourced solely from validated native footprint bars. */
    public function computeFootprint(string $timeframe,int $lookback=20,bool $closedOnly=false): array
    {
        $limit=max($lookback+($closedOnly?1:0),(int)config('orderflow.participation_history_bars',300));
        return $this->computeFromBars(app(FootprintService::class)->bars($timeframe,$limit),$timeframe,$lookback,$closedOnly);
    }

    /** Compute the live metrics against an explicit chronological history for reproducible replay. */
    public function computeFromBars(array $history, string $timeframe = '5m', int $lookback = 20,
        bool $closedOnly = false): array
    {
        if ($closedOnly) {
            $history = array_values(array_filter($history, fn ($bar) => ($bar['closed'] ?? false) === true));
        }
        usort($history, fn ($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));
        if (empty($history)) return $this->emptyMetrics();

        $latest = $history[array_key_last($history)];
        $participation = app(MarketParticipationService::class)->analyse($history, $timeframe);
        $absorptionEvidence = app(AbsorptionSequenceService::class)->analyse($history, $timeframe);
        $bars = array_slice($history, -$lookback); // chronological calculation window
        $previous = count($bars) > 1 ? $bars[count($bars) - 2] : null;
        $range = $latest['high'] - $latest['low'];
        $references = app(BrokerReferenceService::class)->summarize($bars);

        return [
            'timeframe'        => $timeframe,
            'bar_time'         => $latest['time'] ?? null,
            'closed'           => $latest['closed'] ?? false,
            'received_at'      => $latest['received_at'] ?? null,
            'bar_count'        => count($bars),
            'body_range_ratio' => $range > 0 ? abs($latest['close'] - $latest['open']) / $range : null,
            'exhaustion_bar_count' => min(count($bars), config('orderflow.exhaustion_lookback', 10)),
            'previous_close'   => $previous['close'] ?? null,
            'previous_delta'   => $previous['delta'] ?? null,
            'source'           => config('orderflow.connector'),
            'instrument'       => config('clusterdelta.instrument', 'GC'),
            'price'            => (float) ($latest['close'] ?? 0),
            'open'             => (float) ($latest['open']  ?? 0),
            'high'             => (float) ($latest['high']  ?? 0),
            'low'              => (float) ($latest['low']   ?? 0),
            'close'            => (float) ($latest['close'] ?? 0),
            'delta'            => (int)   ($latest['delta'] ?? 0),
            'buy_volume'       => (float) ($latest['buy_vol']  ?? 0),
            'sell_volume'      => (float) ($latest['sell_vol'] ?? 0),
            'cvd'              => $this->calculateCVD($bars),
            'vwap'             => $references['vwap'] ?? 0.0,
            'poc'              => $references['poc'] ?? 0.0,
            'reference_lookback' => $lookback,
            'absorption'       => $absorptionEvidence['type'],
            'absorption_evidence' => $absorptionEvidence,
            'imbalance'        => $this->detectImbalance($latest),
            'stacked_imbalance'=> $this->detectStackedImbalance($bars),
            'exhaustion'       => $this->detectExhaustion($bars),
            'cvd_divergence'   => $this->detectCVDDivergence($bars),
            'buy_pct'          => $this->volumePercent($latest, 'buy'),
            'sell_pct'         => $this->volumePercent($latest, 'sell'),
            'participation'    => $participation,
            'participation_state' => $participation['state'],
            'participation_ratio' => $participation['ratio'],
            'active_flow'      => $participation['flow'],
            'delta_share'      => $participation['delta_share'],
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
            'vwap' => 0, 'poc' => 0, 'absorption' => 'NONE',
            'absorption_evidence' => ['type'=>'NONE','direction'=>'NEUTRAL','stage'=>'waiting_for_effort',
                'qualified'=>false,'reason'=>'Waiting for completed M5 bars.'], 'imbalance' => 'NEUTRAL',
            'stacked_imbalance' => 'NONE', 'exhaustion' => 'NONE', 'cvd_divergence' => 'NONE',
            'buy_pct' => 50, 'sell_pct' => 50, 'participation' => app(MarketParticipationService::class)->analyse([], '5m'),
            'participation_state' => 'UNAVAILABLE', 'participation_ratio' => null,
            'active_flow' => 'UNAVAILABLE', 'delta_share' => null,
        ];
    }
}
