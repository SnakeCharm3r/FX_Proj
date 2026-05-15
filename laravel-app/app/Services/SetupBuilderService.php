<?php

namespace App\Services;

use App\Events\NewSetupDetected;
use App\Models\Setup;
use App\Models\Signal;
use Illuminate\Support\Facades\Log;

class SetupBuilderService
{
    public function __construct(private readonly AlertDispatchService $alerter) {}

    /**
     * Evaluate metrics → calculate score → persist setup if threshold met.
     */
    public function evaluate(array $metrics): ?Setup
    {
        $signals = $this->scoreSignals($metrics);
        $score   = array_sum(array_column($signals, 'weight'));

        // Cache latest confluence state in Redis for the dashboard
        try {
            \Illuminate\Support\Facades\Redis::set(
                'xauusd:confluence:latest',
                json_encode(array_map(fn($s) => [
                    'name'   => $s['name'],
                    'status' => $s['status'],
                    'detail' => $s['detail'],
                ], $signals))
            );
            \Illuminate\Support\Facades\Redis::set('xauusd:metrics:latest', json_encode($metrics));
        } catch (\Exception $e) {
            Log::warning('Redis write failed: ' . $e->getMessage());
        }

        $minScore = config('orderflow.min_confluence_score', 60);
        if ($score < $minScore) {
            return null;
        }

        $direction = $this->resolveDirection($signals);
        if ($direction === 'NEUTRAL') {
            return null;
        }

        // Avoid duplicate active setups on same timeframe + direction within 15 min
        $recent = Setup::where('direction',  $direction)
            ->where('timeframe',  $metrics['timeframe'])
            ->where('status',     'active')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();

        if ($recent) {
            return null;
        }

        [$entry, $sl, $tp1, $tp2] = $this->calculateLevels($metrics, $direction);

        $setup = Setup::create([
            'direction'         => $direction,
            'timeframe'         => $metrics['timeframe'],
            'entry'             => $entry,
            'stop_loss'         => $sl,
            'tp1'               => $tp1,
            'tp2'               => $tp2,
            'score'             => $score,
            'status'            => 'active',
            'confluence_detail' => $signals,
            'risk_pips'         => round(abs($entry - $sl), 1),
            'rr_ratio'          => $sl != $entry ? round(abs($tp1 - $entry) / abs($entry - $sl), 2) : 0,
            'vwap'              => $metrics['vwap'] ?? null,
            'poc'               => $metrics['poc']  ?? null,
        ]);

        // Persist individual signals
        foreach ($signals as $sig) {
            if ($sig['weight'] > 0) {
                Signal::create([
                    'setup_id'  => $setup->id,
                    'type'      => $sig['key'],
                    'direction' => $sig['status'] === 'bullish' ? 'BULLISH' : 'BEARISH',
                    'timeframe' => $metrics['timeframe'],
                    'value'     => $sig['raw_value'] ?? null,
                    'price'     => $metrics['price'],
                    'weight'    => $sig['weight'],
                    'meta'      => ['detail' => $sig['detail']],
                ]);
            }
        }

        // Broadcast to dashboard
        broadcast(new NewSetupDetected($setup));

        // Send alerts
        try {
            $this->alerter->dispatch(
                type:    'NEW_SETUP',
                message: $this->formatAlertMessage($setup),
                level:   $direction === 'BUY' ? 'buy' : 'sell',
                setupId: $setup->id
            );
        } catch (\Exception $e) {
            Log::error('Alert dispatch failed: ' . $e->getMessage());
        }

        Log::info("Setup #{$setup->id} created: {$direction} @ {$entry} score={$score}% tf={$metrics['timeframe']}");

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
            'detail'    => $deltaFired ? "Delta: {$delta}" : "Below threshold ({$threshold})",
            'weight'    => $deltaFired ? $weights['delta_imbalance'] : 0,
            'raw_value' => $delta,
        ];

        // 3. Absorption
        $abs = $m['absorption'] ?? 'NONE';
        $signals[] = [
            'key'       => 'absorption',
            'name'      => 'Absorption Pattern',
            'status'    => $abs === 'BULL_ABSORPTION' ? 'bullish' : ($abs === 'BEAR_ABSORPTION' ? 'bearish' : 'neutral'),
            'detail'    => $abs !== 'NONE' ? ucfirst(strtolower(str_replace('_', ' ', $abs))) : 'No absorption',
            'weight'    => $abs !== 'NONE' ? $weights['absorption'] : 0,
            'raw_value' => null,
        ];

        // 4. Stacked Imbalance
        $stacked = $m['stacked_imbalance'] ?? 'NONE';
        $signals[] = [
            'key'       => 'stacked_imbalance',
            'name'      => 'Stacked Imbalance',
            'status'    => $stacked === 'BULLISH' ? 'bullish' : ($stacked === 'BEARISH' ? 'bearish' : 'neutral'),
            'detail'    => $stacked !== 'NONE' ? "{$stacked} stack detected" : 'No stack',
            'weight'    => $stacked !== 'NONE' ? $weights['stacked_imbalance'] : 0,
            'raw_value' => null,
        ];

        // 5. Exhaustion
        $exhaustion = $m['exhaustion'] ?? 'NONE';
        $signals[] = [
            'key'       => 'exhaustion',
            'name'      => 'Exhaustion Signal',
            'status'    => str_contains($exhaustion, 'BULLISH') ? 'bullish' : (str_contains($exhaustion, 'BEARISH') ? 'bearish' : 'neutral'),
            'detail'    => $exhaustion !== 'NONE' ? ucfirst(strtolower(str_replace('_', ' ', $exhaustion))) : 'No exhaustion',
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
            'name'      => 'VWAP Deviation',
            'status'    => $vwapFired ? ($price > $vwap ? 'bullish' : 'bearish') : 'neutral',
            'detail'    => $vwapFired ? sprintf('%.1f pips from VWAP %.2f', $vwapDiff, $vwap) : 'Within VWAP range',
            'weight'    => $vwapFired ? $weights['vwap_deviation'] : 0,
            'raw_value' => $vwapDiff,
        ];

        // 7. POC Rejection
        $poc      = $m['poc']  ?? 0;
        $pocRange = $poc > 0 ? abs($price - $poc) * 10 : 999;
        $pocFired = $poc > 0 && $pocRange <= 50; // within 50 pips of POC = rejection zone
        $signals[] = [
            'key'       => 'poc_rejection',
            'name'      => 'POC Rejection',
            'status'    => $pocFired ? ($price > $poc ? 'bullish' : 'bearish') : 'neutral',
            'detail'    => $pocFired ? sprintf('Price near POC %.2f (%.1f pips)', $poc, $pocRange) : 'No POC rejection',
            'weight'    => $pocFired ? $weights['poc_rejection'] : 0,
            'raw_value' => $pocRange,
        ];

        return $signals;
    }

    private function resolveDirection(array $signals): string
    {
        $bull = 0;
        $bear = 0;

        foreach ($signals as $sig) {
            if ($sig['status'] === 'bullish') $bull += $sig['weight'];
            if ($sig['status'] === 'bearish') $bear += $sig['weight'];
        }

        if ($bull > 0 && $bull > $bear) return 'BUY';
        if ($bear > 0 && $bear > $bull) return 'SELL';

        return 'NEUTRAL';
    }

    private function calculateLevels(array $m, string $direction): array
    {
        $price  = $m['price'];
        $high   = $m['high'];
        $low    = $m['low'];
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
        $template = config('alerts.templates.new_setup', '');
        return str_replace(
            ['{direction}','{entry}','{stop_loss}','{tp1}','{tp2}','{score}','{timeframe}','{time}'],
            [$setup->direction, $setup->entry, $setup->stop_loss, $setup->tp1, $setup->tp2,
             $setup->score, strtoupper($setup->timeframe), now()->format('H:i:s T')],
            $template
        );
    }
}
