<?php

namespace App\Services;

use App\Models\AlertLog;
use App\Models\PriceZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PriceZoneService
{
    public function __construct(private MarketDataService $market, private PriceZoneDetector $detector) {}

    public function scope()
    {
        return PriceZone::where('source', config('orderflow.connector'))
            ->where('instrument', config('clusterdelta.instrument', 'GC'))
            ->where('version', config('price_zones.version'));
    }

    public function statusKey(): string
    {
        return 'price-zones:'.config('orderflow.connector').':'.config('clusterdelta.instrument', 'GC').':status';
    }

    public function run(): array
    {
        $now = now()->timestamp;
        $bars = app(HourlyPriceHistoryService::class)->bars(500, $this->market->bars('1h', 500));
        $bars = array_values(array_filter($bars, fn ($b) => ($b['closed'] ?? false) && $b['time'] + 3600 <= $now));
        usort($bars, fn ($a, $b) => $a['time'] <=> $b['time']);
        foreach ($this->detector->detect($bars, $now) as $candidate) {
            $key = hash('sha256', implode(':', [config('orderflow.connector'), config('clusterdelta.instrument'),
                config('price_zones.version'), $candidate['type'], $candidate['origin_time']]));
            PriceZone::firstOrCreate(['zone_key' => $key], $candidate + ['source' => config('orderflow.connector'),
                'instrument' => config('clusterdelta.instrument', 'GC'), 'version' => config('price_zones.version'),
                'status' => 'unverified']);
        }
        $byTime = array_column($bars, null, 'time');
        $coverage = app(HourlyPriceHistoryService::class)->coverage();
        $coverageCache=[];
        $hour = intdiv($now, 3600) * 3600;
        foreach ($this->scope()->whereIn('status', ['active', 'unverified'])->get() as $zone) {
            DB::transaction(function () use ($zone, $byTime, $hour, $coverage, &$coverageCache) {
                $locked = PriceZone::lockForUpdate()->findOrFail($zone->id);
                if ($locked->status === 'invalidated') return;
                $gap = false; $broken = null;
                for ($t = $locked->confirmed_time; $t < $hour; $t += 3600) {
                    $bar = $byTime[$t] ?? null;
                    if (!$bar) {
                        // No candle exists during broker session closures in a complete CopyRates window.
                        $coverageCache[$t]??=app(HourlyPriceHistoryService::class)->covered($t, $coverage);
                        if (!$coverageCache[$t]) $gap = true;
                        continue;
                    }
                    if ($locked->type === 'DEMAND' ? $bar['close'] < $locked->low : $bar['close'] > $locked->high) {
                        $broken = $t + 3600; break;
                    }
                }
                $locked->status = $broken ? 'invalidated' : ($gap ? 'unverified' : 'active');
                $locked->invalidated_time = $broken;
                $locked->save();
            });
        }
        $live = $this->livePrice($now);
        if ($live) $this->checkTouches($live);
        $state = ['checked_at' => $now, 'closed_h1_bars' => count($bars), 'live_price' => $live['close'] ?? null,
            'price_received_at' => $live['received_at'] ?? null,
            'status' => !$bars ? 'waiting_for_h1' : (!$live ? 'stale_price' : 'watching'),
            'reason' => !$bars ? 'Waiting for native H1 history or complete sets of four M15 candles.'
                : (!$live ? 'Zones computed; waiting for a fresh developing broker candle before sending arrival alerts.'
                : 'Watching broker price for entry into confirmed 1H wick-to-body zones.')];
        Redis::setex($this->statusKey(), 86400, json_encode($state));
        return $state;
    }

    private function livePrice(int $now): ?array
    {
        $candidates = [];
        foreach (['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600] as $tf => $period) {
            $raw = $this->market->storedBars($tf, 1);
            $bar = $raw ? json_decode($raw[0], true) : null;
            if (!$bar || ($bar['closed'] ?? true) || ($bar['price_source'] ?? '') !== 'mt5_broker') continue;
            $received = $bar['received_at'] ?? 0;
            if ($received > $now + 5 || $now - $received >= config('price_zones.feed_fresh_seconds', 60)) continue;
            if ($bar['time'] > $now || $bar['time'] + $period <= $now) continue;
            $candidates[] = $bar;
        }
        usort($candidates, fn ($a, $b) => ($b['received_at'] <=> $a['received_at']) ?: ($b['time'] <=> $a['time']));
        return $candidates[0] ?? null;
    }

    public function checkTouches(array $live): void
    {
        foreach ($this->scope()->where('status', 'active')->get() as $zone) {
            DB::transaction(function () use ($zone, $live) {
                $zone = PriceZone::lockForUpdate()->findOrFail($zone->id);
                if ($zone->status !== 'active' || $live['received_at'] <= ($zone->last_price_received ?? 0)
                    || $live['received_at'] < $zone->confirmed_time) return;
                $inside = $live['close'] >= $zone->low && $live['close'] <= $zone->high;
                $metadata=$zone->metadata??[];
                $distance=max(0,$zone->low-$live['close'],$live['close']-$zone->high);
                $near=$distance<=max(1,$zone->atr*(float)config('price_zones.approach_atr_fraction',.25));
                $prior=$metadata['monitor_last_price']??null;
                $towards=$prior!==null&&($zone->type==='DEMAND'?$live['close']<$prior:$live['close']>$prior);
                if(!$inside&&$near&&$towards&&!($metadata['monitor_was_near']??false)){
                    AlertLog::create(['price_zone_id'=>$zone->id,'channel'=>'browser','type'=>'ZONE_APPROACH',
                        'message'=>sprintf('Broker price %.3f approaching H1 %s %.3f–%.3f.',
                            $live['close'],strtolower($zone->type),$zone->low,$zone->high),'status'=>'recorded']);
                }
                $metadata['monitor_last_price']=$live['close'];$metadata['monitor_was_near']=$near;
                $zone->metadata=$metadata;
                $entry = $inside && $zone->was_inside !== true;
                $cooldownOver = !$zone->last_alerted_at
                    || $live['received_at'] - $zone->last_alerted_at->timestamp >= config('price_zones.alert_cooldown_seconds', 300);
                if ($entry && $cooldownOver) {
                    $zone->entry_count++;
                    $message = sprintf('Broker price %.3f %s 1H %s zone %.3f–%.3f.', $live['close'],
                        $zone->was_inside === null ? 'is inside' : 'entered', strtolower($zone->type), $zone->low, $zone->high);
                    AlertLog::create(['price_zone_id' => $zone->id, 'zone_entry' => $zone->entry_count,
                        'channel' => 'browser', 'type' => 'ZONE_TOUCH', 'message' => $message, 'status' => 'recorded']);
                    $zone->last_alerted_at = \Carbon\Carbon::createFromTimestampUTC($live['received_at']);
                }
                $zone->was_inside = $inside; $zone->last_price_received = $live['received_at']; $zone->save();
            });
        }
    }

    public function status(): array
    {
        $state = json_decode(Redis::get($this->statusKey()) ?: 'null', true)
            ?? ['checked_at' => null, 'reason' => 'Waiting for the zone detector.', 'status' => 'not_running'];
        $running = $state['checked_at'] && now()->timestamp - $state['checked_at'] < 90;
        return array_merge($state, ['monitor_running' => (bool) $running,
            'reason' => $running ? $state['reason'] : 'Zone detector has not checked recently. Start the detector terminal.',
            'zones' => $this->scope()->orderByDesc('origin_time')->limit(100)->get(),
            'rules' => config('price_zones')]);
    }
}
