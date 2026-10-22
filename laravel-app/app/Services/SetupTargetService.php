<?php

namespace App\Services;

use App\Models\Setup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SetupTargetService
{
    public function __construct(private MarketDataService $market) {}

    public function run(): int
    {
        $changed = 0; $cache = []; $quote = null; $quoteRead = false;
        $setups = Setup::where('source', config('orderflow.connector'))
            ->where('instrument', config('clusterdelta.instrument', 'GC'))
            ->whereIn('status', ['active', 'triggered'])
            ->whereNull('tp2_hit_at')->whereNull('sl_hit_at')
            ->where('exit_tracking_state', 'watching')->get();
        foreach ($setups as $setup) {
            $tf = $setup->timeframe;
            if (!isset($cache[$tf])) {
                $cache[$tf] = array_map(fn ($v) => json_decode($v, true), $this->market->bars($tf, 500));
            }
            $hits = $this->observedHits($setup, $cache[$tf], now()->timestamp);
            if (!$hits) {
                if (!$quoteRead) { $quote = $this->market->latestBrokerQuote(); $quoteRead = true; }
                $hits = $this->observedQuoteHits($setup, $quote, now()->timestamp);
            }
            if (!$hits) continue;
            $saved = DB::transaction(function () use ($setup, $hits) {
                $locked = Setup::lockForUpdate()->findOrFail($setup->id);
                if (!in_array($locked->status, ['active', 'triggered'], true)) return [];
                if ($locked->tp2_hit_at || $locked->sl_hit_at || $locked->exit_tracking_state !== 'watching') return [];
                $details = $locked->target_hit_details ?? []; $newTargets = [];
                foreach ($hits as $target => $evidence) {
                    if ($target === 'path_ambiguous') {
                        $details[$target] = $evidence;
                        $locked->exit_tracking_state = 'ambiguous';
                        continue;
                    }
                    $field = $target.'_hit_at';
                    if ($locked->$field) continue;
                    $locked->$field = Carbon::createFromTimestampUTC($evidence['observed_at']);
                    $details[$target] = $evidence; $newTargets[] = $target;
                    if ($target === 'sl' || $target === 'tp2') $locked->exit_tracking_state = $target;
                }
                $locked->target_hit_details = $details;
                $locked->save();
                return ['targets' => $newTargets];
            });
            if ($saved) {
                $changed++;
                if (config('orderflow.external_alerts_enabled')) {
                    $setup->refresh();
                    foreach ($saved['targets'] as $target) {
                        if (!config($target === 'sl' ? 'alerts.sl_updates_enabled' : 'alerts.tp_updates_enabled')) continue;
                        try {
                            app(AlertDispatchService::class)->dispatch(
                                type: strtoupper($target).'_HIT',
                                message: $target === 'sl' ? app(SetupAlertMessageService::class)->stopReached($setup)
                                    : app(SetupAlertMessageService::class)->targetReached($setup, $target),
                                level: 'info', setupId: $setup->id);
                        } catch (\Throwable) {
                            \Illuminate\Support\Facades\Log::warning('Target touch saved; alert unavailable.', [
                                'setup_id' => $setup->id, 'target' => $target,
                            ]);
                        }
                    }
                }
            }
        }
        return $changed;
    }

    /** Current price evidence only; never import another timeframe's high/low into a setup. */
    public function observedQuoteHits(Setup $setup, ?array $quote, int $now): array
    {
        if (!$quote || $setup->sl_hit_at || $setup->tp2_hit_at
            || ($setup->exit_tracking_state && $setup->exit_tracking_state !== 'watching')
            || !in_array($setup->direction, ['BUY','SELL'], true)
            || ($quote['source'] ?? '') !== $setup->source
            || ($quote['instrument'] ?? '') !== $setup->instrument
            || ($quote['price_source'] ?? '') !== 'mt5_broker' || ($quote['closed'] ?? true) !== false) return [];
        $period = ['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400][$quote['timeframe'] ?? ''] ?? null;
        $received = $quote['received_at'] ?? 0; $time = $quote['time'] ?? 0; $price = $quote['close'] ?? 0;
        if (!$period || $received < $setup->created_at->timestamp || $received > $now+5
            || $now-$received >= 60 || $time > $now || $time+$period <= $now || $received < $time
            || !is_numeric($price) || !is_finite((float)$price) || $price <= 0) return [];
        $hits = [];
        foreach (['sl'=>'stop_loss','tp1'=>'tp1','tp2'=>'tp2'] as $event=>$level) {
            if ($setup->{$event.'_hit_at'} || $setup->$level <= 0) continue;
            $up = ($setup->direction === 'BUY') !== ($event === 'sl');
            if ($up ? $price < $setup->$level : $price > $setup->$level) continue;
            $hits[$event] = ['observed_at'=>$received,'candle_time'=>$time,'timeframe'=>$quote['timeframe'],
                'setup_timeframe'=>$setup->timeframe,'target_price'=>$setup->$level,
                'basis'=>'sampled_broker_quote','observed_price'=>$price];
            if ($event === 'sl') break;
        }
        return $hits;
    }

    /** Records price touches, not order fills. Evidence timestamps are observation times. */
    public function observedHits(Setup $setup, array $bars, int $now): array
    {
        $period = ['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400][$setup->timeframe] ?? null;
        if (!$period || !in_array($setup->direction, ['BUY', 'SELL'], true)
            || $setup->tp2_hit_at || $setup->sl_hit_at || ($setup->exit_tracking_state && $setup->exit_tracking_state !== 'watching')) return [];
        $created = $setup->created_at->timestamp;
        usort($bars, fn ($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));
        $hits = [];
        foreach ($bars as $bar) {
            if (($bar['source'] ?? '') !== $setup->source || ($bar['price_source'] ?? '') !== 'mt5_broker') continue;
            if (!isset($bar['time'], $bar['close'], $bar['high'], $bar['low'], $bar['received_at'])) continue;
            $start = (int) $bar['time']; $end = $start + $period; $received = (int) $bar['received_at'];
            if ($start > $now || $received > $now + 5 || $received < $start) continue;
            $closed = ($bar['closed'] ?? false) === true;
            if ($closed) {
                if ($end <= $created || $end > $now || $received < $end) continue;
                $observed = $end;
            } else {
                // Frozen/old developing snapshots and stale live quotes do not create hits.
                if ($end <= $now || $received < $created || $now - $received >= 60) continue;
                $observed = $received;
            }
            // Never use the full high/low of a candle that began before this setup existed.
            $fullRange = $start >= $created;
            $reached = $fullRange ? ($setup->direction === 'BUY' ? $bar['high'] : $bar['low']) : $bar['close'];
            $stopPrice = $fullRange ? ($setup->direction === 'BUY' ? $bar['low'] : $bar['high']) : $bar['close'];
            $stopHit = $setup->stop_loss > 0 && ($setup->direction === 'BUY'
                ? $stopPrice <= $setup->stop_loss : $stopPrice >= $setup->stop_loss);
            $targetHit = $setup->direction === 'BUY' ? $reached >= $setup->tp1 : $reached <= $setup->tp1;
            $secondTargetHit = $setup->direction === 'BUY' ? $reached >= $setup->tp2 : $reached <= $setup->tp2;
            $priorTp1 = $setup->tp1_hit_at?->timestamp ?? ($hits['tp1']['observed_at'] ?? null);
            // With both sides inside a candle, OHLC cannot establish the first exit.
            // A TP1 observed in an earlier candle is safe; a same-candle range is not.
            if ($fullRange && $stopHit && ($secondTargetHit || ($targetHit && ($priorTp1 === null || $priorTp1 > $start)))) {
                $hits['path_ambiguous'] = ['observed_at' => $observed, 'candle_time' => $start,
                    'timeframe' => $setup->timeframe, 'basis' => 'both_stop_and_target_in_candle',
                    'reason' => 'Stop and target crossed in one candle; first touch requires tick replay.'];
                break;
            }
            if ($stopHit) {
                $hits['sl'] = ['observed_at' => $observed, 'candle_time' => $start,
                    'timeframe' => $setup->timeframe, 'target_price' => $setup->stop_loss,
                    'basis' => $fullRange ? 'post_setup_candle_range' : ($closed ? 'post_setup_candle_close' : 'sampled_close'),
                    'observed_price' => $stopPrice];
                break;
            }
            foreach (['tp1', 'tp2'] as $target) {
                $field = $target.'_hit_at';
                if ($setup->$field || isset($hits[$target]) || $setup->$target <= 0) continue;
                $hit = $setup->direction === 'BUY' ? $reached >= $setup->$target : $reached <= $setup->$target;
                if (!$hit) continue;
                $hits[$target] = ['observed_at' => $observed, 'candle_time' => $start,
                    'timeframe' => $setup->timeframe, 'target_price' => $setup->$target,
                    'basis' => $fullRange ? 'post_setup_candle_range' : ($closed ? 'post_setup_candle_close' : 'sampled_close'),
                    'observed_price' => $reached];
            }
            if (isset($hits['tp2'])) break;
        }
        return $hits;
    }
}
