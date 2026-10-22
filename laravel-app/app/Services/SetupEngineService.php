<?php

namespace App\Services;

use App\Models\Setup;
use Illuminate\Support\Facades\Redis;

class SetupEngineService
{
    public function __construct(
        private MarketDataService $market,
        private OrderflowMetricsService $metrics,
        private FootprintService $footprints,
        private SetupBuilderService $builder,
    ) {}

    public function key(string $tf): string
    {
        return 'orderflow:evaluation:'.config('orderflow.connector').':'.config('clusterdelta.instrument', 'GC').':'.$tf;
    }

    public function run(string $tf): array
    {
        $state = ['timeframe' => $tf, 'source' => config('orderflow.connector'),
            'instrument' => config('clusterdelta.instrument', 'GC'), 'checked_at' => now()->timestamp,
            'threshold' => config('orderflow.min_confluence_score', 60), 'signals' => [],
            'bull_score' => null, 'bear_score' => null, 'candle_time' => null, 'setup_id' => null];
        try {
            $state = array_merge($state, $this->evaluate($tf));
        } catch (\Throwable $e) {
            report($e);
            $state['status'] = 'error';
            $state['reason'] = 'Evaluation failed. Check the application log and local services.';
        }
        Redis::setex($this->key($tf), 86400, json_encode($state, JSON_THROW_ON_ERROR));
        Redis::setex('orderflow:engine:heartbeat', 86400, json_encode(['checked_at' => now()->timestamp,
            'error' => $state['status'] === 'error']));
        return $state;
    }

    private function fresh(?int $time): bool
    {
        return $time !== null && $time <= now()->timestamp + 5
            && now()->timestamp - $time < config('orderflow.setup_fresh_seconds', 60);
    }

    private function evaluate(string $tf): array
    {
        $blocked = fn ($reason, $extra = []) => array_merge(['status' => 'blocked', 'reason' => $reason], $extra);
        if (config('orderflow.connector') !== 'clusterdelta') return $blocked('Select the ClusterDelta connector.');
        $raw = $this->market->bars($tf, 1);
        $latest = $raw ? json_decode($raw[0], true) : null;
        if (!$latest) return $blocked('No MT5 bars for this timeframe. Attach its bridge and footprint exporter.');
        if (!$this->fresh($latest['received_at'] ?? null)) return $blocked('MT5 bar feed is stale. Check MT5 and the data forwarder.');
        $seconds = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1d' => 86400][$tf];
        $m = $this->metrics->compute($tf, 20, true);
        $time = $m['bar_time'] ?? null;
        $extra = ['candle_time' => $time, 'bar_count' => $m['bar_count'] ?? 0];
        if (!$time || !($m['closed'] ?? false)) return $blocked('Waiting for a completed candle.', $extra);
        if (!$this->fresh($m['received_at'] ?? null) || $time + $seconds > now()->timestamp
            || now()->timestamp - ($time + $seconds) > $seconds) {
            return $blocked('Latest completed candle is stale or has an invalid clock. Check the chart timeframe and time offset.', $extra);
        }
        if ($m['bar_count'] < config('orderflow.setup_min_bars', 20)) {
            return $blocked('Need at least '.config('orderflow.setup_min_bars').' completed bars for the rolling calculations.', $extra);
        }
        $snapshot = $this->footprints->snapshot(config('clusterdelta.instrument', 'GC'), $tf);
        if (!$snapshot || !$this->fresh($snapshot['received_at'] ?? null)) {
            return $blocked('Waiting for fresh footprint cells on this timeframe. Check FXClusterDeltaFootprint.', $extra);
        }
        $bars = array_column($snapshot['bars'], null, 'time');
        $footprint = $bars[$time] ?? null;
        if (!$footprint || !($footprint['closed'] ?? false)) {
            return $blocked('Waiting for footprint cells matching the completed MT5 candle.', $extra);
        }
        if (($footprint['source_totals_match'] ?? false) !== true
            || $footprint['volume'] != $m['buy_volume'] + $m['sell_volume'] || $footprint['delta'] != $m['delta']) {
            return $blocked('Footprint and bridge volume/delta disagree on the completed candle. Waiting for synchronized exports.', $extra);
        }
        $features = $this->footprintFeatures($footprint, $bars[$time - $seconds] ?? null, (float) $snapshot['tick_size']);
        $m = array_merge($m, $features);
        app(MarketParticipationSnapshotService::class)->record($m);
        $assessment = $this->builder->assess($m);
        $gate = match (config('orderflow.setup_strategy')) {
            'zone_retest_v1' => app(ZoneRetestService::class)->assess($m, $assessment),
            'zone_absorption_v2' => app(ZoneAbsorptionStrategyService::class)->assess($m),
            'h1_trend_range_v3' => app(H1TrendRangeStrategyService::class)->assess($m),
            default => null,
        };
        if ($gate) $m['zone_retest'] = $gate;
        if (($gate['strategy_version'] ?? '') === 'h1_trend_range_v3' && !empty($gate['signals'])) {
            $assessment['signals'] = $gate['signals'];
            $assessment['score'] = (int) ($gate['rule_score'] ?? 0);
            $assessment['bull_score'] = array_sum(array_column(array_filter($gate['signals'],
                fn ($signal) => ($signal['status'] ?? '') === 'bullish'), 'weight'));
            $assessment['bear_score'] = array_sum(array_column(array_filter($gate['signals'],
                fn ($signal) => ($signal['status'] ?? '') === 'bearish'), 'weight'));
        }
        $dedicated = ($gate['qualified'] ?? false)
            && in_array($gate['strategy_version'] ?? '',config('orderflow.dedicated_strategies',[]),true);
        if ($dedicated) {
            $assessment['direction']=$gate['direction'];
            $assessment['score']=(int)($gate['rule_score']??$assessment['score']);
            if (($gate['strategy_version'] ?? '') !== 'h1_trend_range_v3') {
                $assessment['bull_score']=$gate['direction']==='BUY'?$assessment['score']:0;
                $assessment['bear_score']=$gate['direction']==='SELL'?$assessment['score']:0;
            }
            $assessment['signals']=$gate['signals']??$assessment['signals'];
        }
        $observation = app(SetupObservationService::class)->record($m,$assessment);
        $result = array_merge($extra, $assessment, ['footprint_matched' => true,
            'observation_id'=>$observation?->id,
            'delta' => $m['delta'], 'footprint_poc' => $footprint['poc'],
            'status' => 'waiting', 'reason' => 'Best directional score is '.$assessment['score'].' / 100; needs '
                .$assessment['threshold'].'. Conditions are evaluated on the last completed candle.']);
        if ($gate) {
            $result['zone_retest'] = $gate;
            $result['reason'] = $gate['reason'];
            if (!$gate['qualified']) return $result;
        }
        if (!$dedicated && $assessment['direction'] === 'NEUTRAL') {
            $result['reason'] = 'Bullish and bearish scores are tied. Waiting for directional alignment.';
            return $result;
        }
        if (!$dedicated && $assessment['score'] < $assessment['threshold']) return $result;
        $setup = $this->builder->evaluate($m);
        if ($setup && $observation && !$observation->setup_id) $observation->update(['setup_id'=>$setup->id]);
        $result['setup_id'] = $setup?->id;
        $result['status'] = $setup?->wasRecentlyCreated ? 'detected' : 'recorded';
        $result['reason'] = $setup?->wasRecentlyCreated
            ? 'A qualifying setup was saved from this completed candle.'
            : 'This candle already has a saved setup; duplicate suppressed.';
        return $result;
    }

    /** Native adjacent levels, not whole-candle buy/sell ratios. */
    public function footprintFeatures(array $bar, ?array $previous, float $tick): array
    {
        $runs = $this->footprints->imbalanceRuns($bar, $tick);
        $maxBull = $maxBear = 0;
        foreach ($runs as $run) {
            if ($run['type'] === 'BULLISH') $maxBull = max($maxBull, $run['count']);
            if ($run['type'] === 'BEARISH') $maxBear = max($maxBear, $run['count']);
        }
        $needed = config('orderflow.stacked_imbalance_count', 3);
        $isBull = $maxBull >= $needed; $isBear = $maxBear >= $needed;
        $poc = $previous['poc'] ?? null;
        $rejection = 'NONE';
        if ($poc && $bar['low'] <= $poc && $bar['high'] >= $poc) {
            if ($bar['close'] > $poc && $bar['close'] > $bar['open']) $rejection = 'BULLISH';
            if ($bar['close'] < $poc && $bar['close'] < $bar['open']) $rejection = 'BEARISH';
        }
        return ['stacked_imbalance' => $isBull && !$isBear ? 'BULLISH' : ($isBear && !$isBull ? 'BEARISH' : 'NONE'),
            'footprint_bull_stack' => $maxBull, 'footprint_bear_stack' => $maxBear,
            'poc_rejection' => $rejection, 'footprint_previous_poc' => $poc];
    }

    public function status(): array
    {
        $rows = [];
        foreach (config('orderflow.timeframes') as $tf) {
            $raw = Redis::get($this->key($tf));
            $row = $raw ? json_decode($raw, true) : null;
            $row ??= ['timeframe' => $tf, 'status' => 'not_running', 'reason' => 'No detector evaluation recorded yet.', 'signals' => []];
            $row['engine_fresh'] = isset($row['checked_at']) && $row['checked_at'] <= now()->timestamp + 5
                && now()->timestamp - $row['checked_at'] < 90;
            if (!$row['engine_fresh']) {
                $row['status'] = 'not_running';
                $row['reason'] = 'Detector has not checked this timeframe recently. Start the setup detector terminal.';
            }
            $rows[] = $row;
        }
        return ['checked_at' => now()->timestamp, 'timeframes' => $rows,
            'latest_setup_id' => Setup::max('id'),
            'setup_count' => Setup::count()];
    }
}
