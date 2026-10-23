<?php

namespace App\Services;

use App\Events\NewSetupDetected;
use App\Models\Setup;
use App\Models\Signal;
use Illuminate\Support\Facades\Log;

class SetupBuilderService
{
    public function __construct(private readonly AlertDispatchService $alerter) {}

    /** Scores are weights supporting each direction, not probabilities. */
    public function assess(array $metrics): array
    {
        $signals = $this->scoreSignals($metrics);
        $bull = $bear = 0;
        foreach ($signals as $signal) {
            if ($signal['status'] === 'bullish') $bull += $signal['weight'];
            if ($signal['status'] === 'bearish') $bear += $signal['weight'];
        }
        return ['signals' => $signals, 'bull_score' => $bull, 'bear_score' => $bear,
            'score' => max($bull, $bear), 'threshold' => config('orderflow.min_confluence_score', 60),
            'direction' => $bull > $bear ? 'BUY' : ($bear > $bull ? 'SELL' : 'NEUTRAL')];
    }

    /** Called by SetupEngineService only after feed and closed-candle checks. */
    public function evaluate(array $metrics): ?Setup
    {
        $assessment = $this->assess($metrics);
        $gate = $metrics['zone_retest'] ?? null;
        $dedicated = ($gate['qualified'] ?? false)
            && in_array($gate['strategy_version'] ?? '',config('orderflow.dedicated_strategies',[]),true);
        if($dedicated){
            $assessment['direction']=$gate['direction'];
            $assessment['score']=(int)($gate['rule_score']??$assessment['score']);
            $assessment['signals']=$gate['signals']??$assessment['signals'];
        }
        if (!$dedicated && ($assessment['score'] < $assessment['threshold'] || $assessment['direction'] === 'NEUTRAL')) return null;
        $direction = $dedicated ? $gate['direction'] : $assessment['direction'];
        $signals = $assessment['signals'];
        if ($gate && !$gate['qualified']) return null;
        if ($gate && !$dedicated) $signals[] = ['key'=>'zone_retest','name'=>'1H zone retest','status'=>$direction === 'BUY' ? 'bullish' : 'bearish',
            'weight'=>0, 'detail'=>$gate['reason'].' Zone #'.$gate['price_zone_id'].' '.$gate['zone_low'].'–'.$gate['zone_high']];
        [$entry, $sl, $tp1, $tp2] = $this->calculateLevels($metrics, $direction);
        // A database constraint also protects against overlapping scheduler/watch processes.
        $key = hash('sha256', implode(':', [$metrics['source'], $metrics['instrument'],
            $metrics['timeframe'], $metrics['bar_time']]));
        if ($gate) $key = hash('sha256', implode(':', [$metrics['source'], $metrics['instrument'],
            $metrics['timeframe'], $gate['strategy_version'], $gate['price_zone_id'], $gate['rejection_time']]));
        $setup = \Illuminate\Support\Facades\DB::transaction(function () use ($key, $metrics, $assessment, $direction, $signals, $entry, $sl, $tp1, $tp2) {
            $setup = Setup::firstOrCreate(['evaluation_key' => $key], [
                'source' => $metrics['source'], 'instrument' => $metrics['instrument'],
                'candle_time' => $metrics['bar_time'], 'direction' => $direction,
                'strategy_version' => $metrics['zone_retest']['strategy_version'] ?? 'weighted_orderflow_v1',
                'price_zone_id' => $metrics['zone_retest']['price_zone_id'] ?? null,
                'timeframe' => $metrics['timeframe'], 'entry' => $entry, 'stop_loss' => $sl,
                'tp1' => $tp1, 'tp2' => $tp2, 'score' => $assessment['score'], 'status' => 'active',
                'confluence_detail' => $signals, 'risk_pips' => round(abs($entry - $sl) * 10, 1),
                'rr_ratio' => round(abs($tp1 - $entry) / abs($entry - $sl), 2),
                'vwap' => $metrics['vwap'] ?? null,
                // Native futures POC is retained in the signal detail, never stored as a broker price.
                'poc' => null,
            ]);
            if (!$setup->wasRecentlyCreated) return $setup;
            foreach ($signals as $sig) {
                if ($sig['weight'] <= 0 || $sig['status'] !== ($direction === 'BUY' ? 'bullish' : 'bearish')) continue;
                Signal::create(['setup_id' => $setup->id, 'type' => $sig['key'],
                    'direction' => $direction === 'BUY' ? 'BULLISH' : 'BEARISH',
                    'timeframe' => $metrics['timeframe'], 'value' => $sig['raw_value'] ?? null,
                    'price' => $entry, 'weight' => $sig['weight'],
                    'meta' => ['detail' => $sig['detail'], 'candle_time' => $metrics['bar_time'],
                        'source' => $metrics['source'], 'instrument' => $metrics['instrument']]]);
            }
            return $setup;
        });
        if (!$setup->wasRecentlyCreated) return $setup;
        // The polled application works without a queue worker or WebSocket server.
        if (config('orderflow.broadcast_enabled')) {
            try { broadcast(new NewSetupDetected($setup)); }
            catch (\Throwable $e) { Log::warning('Setup saved; broadcast unavailable: '.$e->getMessage()); }
        }
        if (config('orderflow.external_alerts_enabled')) {
            try {
                $this->alerter->dispatch(type: 'NEW_SETUP', message: $this->formatAlertMessage($setup),
                    level: strtolower($direction), setupId: $setup->id);
            } catch (\Throwable $e) { Log::warning('Setup saved; alert unavailable: '.$e->getMessage()); }
        }
        Log::info("Setup #{$setup->id} created: {$direction}, score={$setup->score}, tf={$metrics['timeframe']}");
        return $setup;
    }

    private function scoreSignals(array $m): array
    {
        $weights = config('orderflow.signal_weights');
        $signals = [];

        // 1. CVD Divergence
        $cvdDiv = $m['cvd_divergence'] ?? 'NONE';
        $signals[] = [
            'key'       => 'cvd_divergence',
            'name'      => 'CVD Divergence',
            'status'    => $cvdDiv === 'BULLISH_DIVERGENCE' ? 'bullish' : ($cvdDiv === 'BEARISH_DIVERGENCE' ? 'bearish' : 'neutral'),
            'detail'    => $cvdDiv !== 'NONE' ? ucfirst(strtolower(str_replace('_', ' ', $cvdDiv))) : 'No divergence',
            'weight'    => $cvdDiv !== 'NONE' ? $weights['cvd_divergence'] : 0,
            'raw_value' => $m['cvd'] ?? 0,
        ];

        // 2. Delta Imbalance
        $delta     = $m['delta'] ?? 0;
        $threshold = config('orderflow.delta_threshold', 500);
        $deltaFired = abs($delta) >= $threshold;
        $signals[] = [
            'key'       => 'delta_imbalance',
            'name'      => 'Delta Imbalance',
            'status'    => $deltaFired ? ($delta > 0 ? 'bullish' : 'bearish') : 'neutral',
            'detail'    => "Delta {$delta}; needs absolute delta ≥ {$threshold}",
            'weight'    => $deltaFired ? $weights['delta_imbalance'] : 0,
            'raw_value' => $delta,
        ];

        // 3. Absorption
        $abs = $m['absorption'] ?? 'NONE';
        $evidence = $m['absorption_evidence'] ?? null;
        $bodyRatio = $m['body_range_ratio'] ?? null;
        $absDetail = $evidence
            ? $evidence['reason']
            : ($bodyRatio === null ? 'Absorption sequence evidence unavailable.' : sprintf(
                'Legacy body/range %.1f%% with delta %d; this older proxy is retained only for archived/manual inputs.',
                100 * $bodyRatio, $delta));
        $participation = $m['participation'] ?? [];
        if (($participation['state'] ?? 'UNAVAILABLE') !== 'UNAVAILABLE') {
            $absDetail .= sprintf(' Confirmation volume %.0f; participation %s at %.2fx rolling median; total delta %+.0f.',
                $participation['volume'], strtolower($participation['state']), $participation['ratio'], $delta);
        }
        $signals[] = [
            'key'       => 'absorption',
            'name'      => 'Absorption sequence',
            'status'    => $abs === 'BULL_ABSORPTION' ? 'bullish' : ($abs === 'BEAR_ABSORPTION' ? 'bearish' : 'neutral'),
            'detail'    => $absDetail,
            'weight'    => $abs !== 'NONE' ? $weights['absorption'] : 0,
            'raw_value' => null,
        ];

        // 4. Stacked Imbalance
        $stacked = $m['stacked_imbalance'] ?? 'NONE';
        $signals[] = [
            'key'       => 'stacked_imbalance',
            'name'      => 'Stacked Imbalance',
            'status'    => $stacked === 'BULLISH' ? 'bullish' : ($stacked === 'BEARISH' ? 'bearish' : 'neutral'),
            'detail'    => $stacked !== 'NONE' ? "{$stacked} native price-level stack detected"
                : 'No unique directional stack; buy run '.($m['footprint_bull_stack'] ?? 0)
                    .', sell run '.($m['footprint_bear_stack'] ?? 0).'; needs '.config('orderflow.stacked_imbalance_count', 3),
            'weight'    => $stacked !== 'NONE' ? $weights['stacked_imbalance'] : 0,
            'raw_value' => null,
        ];

        // 5. Exhaustion
        $exhaustion = $m['exhaustion'] ?? 'NONE';
        $hasPrevious = isset($m['previous_close'], $m['previous_delta']);
        $exhaustionDetail = $hasPrevious ? sprintf(
            'Close change %+.3f; delta change %+d. Needs opposing changes over ≥4 bars; reversal proxy.',
            $m['price'] - $m['previous_close'], $delta - $m['previous_delta'])
            : 'Waiting for previous close/delta measurements; needs ≥4 bars.';
        $signals[] = [
            'key'       => 'exhaustion',
            'name'      => 'Exhaustion proxy',
            'status'    => str_contains($exhaustion, 'BULLISH') ? 'bullish' : (str_contains($exhaustion, 'BEARISH') ? 'bearish' : 'neutral'),
            'detail'    => $exhaustionDetail,
            'weight'    => $exhaustion !== 'NONE' ? $weights['exhaustion'] : 0,
            'raw_value' => null,
        ];

        // 6. VWAP Deviation
        $price       = $m['price']            ?? 0;
        $vwap        = $m['vwap']             ?? 0;
        $vwapPips    = config('orderflow.vwap_deviation_pips', 150);
        $vwapDiff    = $vwap > 0 ? abs($price - $vwap) * 10 : 0; // * 10 for pips on gold
        $vwapFired   = $vwapDiff >= $vwapPips;
        $signals[] = [
            'key'       => 'vwap_deviation',
            'name'      => 'Rolling VWAP deviation (proxy)',
            'status'    => $vwapFired ? ($price > $vwap ? 'bullish' : 'bearish') : 'neutral',
            'detail'    => sprintf('%.1f pips from rolling VWAP %.2f; needs %.1f pips', $vwapDiff, $vwap, $vwapPips),
            'weight'    => $vwapFired ? $weights['vwap_deviation'] : 0,
            'raw_value' => $vwapDiff,
        ];

        // 7. Rejection of the previous candle's native GC POC, in futures prices only.
        $rejection = $m['poc_rejection'] ?? 'NONE';
        $signals[] = [
            'key' => 'poc_rejection', 'name' => 'GC POC rejection',
            'status' => $rejection === 'BULLISH' ? 'bullish' : ($rejection === 'BEARISH' ? 'bearish' : 'neutral'),
            'detail' => !isset($m['footprint_previous_poc']) ? 'Previous native GC POC is unavailable'
                : ($rejection === 'NONE' ? 'Previous GC POC '.$m['footprint_previous_poc'].' was not tested and rejected by the close'
                : "{$rejection} rejection of previous GC POC ".($m['footprint_previous_poc'] ?? '')),
            'weight' => $rejection === 'NONE' ? 0 : $weights['poc_rejection'],
            'raw_value' => $m['footprint_previous_poc'] ?? null,
        ];

        // Context only: participation never supplies direction or entry points.
        $state = $participation['state'] ?? 'UNAVAILABLE';
        $ratio = $participation['ratio'] ?? null;
        $signals[] = [
            'key' => 'market_participation', 'name' => 'GC Market Participation', 'status' => 'neutral',
            'detail' => $state === 'UNAVAILABLE'
                ? 'Waiting for enough completed GC bars to establish normal participation.'
                : sprintf('%s at %.2fx rolling median (%d prior bars); total volume %.0f, total delta %+.0f, session coverage %s.',
                    $state, $ratio, $participation['baseline_sample_size'],
                    $participation['volume'], $delta, $participation['session_coverage']),
            'weight' => 0, 'raw_value' => $ratio, 'total_volume' => $participation['volume'] ?? null,
        ];

        foreach ($signals as &$signal) {
            $signal['max_weight'] = $weights[$signal['key']];
            $signal['available'] = $signal['key'] !== 'poc_rejection' || isset($m['footprint_previous_poc']);
        }
        unset($signal);
        return $signals;
    }

    public function referenceLevels(array $m, string $direction): array
    {
        if (!in_array($direction,['BUY','SELL'],true)) throw new \InvalidArgumentException('Directional levels require BUY or SELL.');
        return $this->calculateLevels($m,$direction);
    }

    private function calculateLevels(array $m, string $direction): array
    {
        $price  = round($m['price'], 2);
        $high   = $m['high'];
        $low    = $m['low'];
        if ($m['zone_retest']['qualified'] ?? false) {
            $low = min($low, $m['zone_retest']['zone_low']);
            $high = max($high, $m['zone_retest']['zone_high']);
            $low = min($low, $m['zone_retest']['rejection_low']);
            $high = max($high, $m['zone_retest']['rejection_high']);
        }
        $buffer = config('orderflow.sl_buffer_pips', 15) / 10; // pips → price units
        $rr1    = config('orderflow.tp1_rr_ratio', 1.5);
        $rr2    = config('orderflow.tp2_rr_ratio', 3.0);

        if ($direction === 'BUY') {
            $entry = $price;
            $sl    = round($low - $buffer, 2);
            $risk  = $entry - $sl;
            $tp1   = round($entry + $risk * $rr1, 2);
            $tp2   = round($entry + $risk * $rr2, 2);
        } else {
            $entry = $price;
            $sl    = round($high + $buffer, 2);
            $risk  = $sl - $entry;
            $tp1   = round($entry - $risk * $rr1, 2);
            $tp2   = round($entry - $risk * $rr2, 2);
        }

        return [$entry, $sl, $tp1, $tp2];
    }

    private function formatAlertMessage(Setup $setup): string
    {
        return app(SetupAlertMessageService::class)->entry($setup);
    }
}
