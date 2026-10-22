<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HourlyPriceHistoryService
{
    public function coverage(): array
    {
        return DB::table('broker_history_windows')->where('instrument', config('clusterdelta.instrument','GC'))
            ->get(['first_time','last_time'])->map(fn ($row)=>(array)$row)->all();
    }

    public function covered(int $time, array $windows): bool
    {
        foreach ($windows as $window) if ($time >= $window['first_time'] && $time <= $window['last_time']) {
            // A real candle outside the loaded 500-bar slice is missing input, not a closure.
            return !DB::table('broker_hourly_prices')->where('instrument',config('clusterdelta.instrument','GC'))
                ->where('time',$time)->exists();
        }
        return false;
    }

    /** Price-only backfill is never used as delta/volume/footprint data. Newest first. */
    public function bars(int $limit = 500, ?array $native = null): array
    {
        $instrument = config('clusterdelta.instrument', 'GC');
        $bars = [];
        foreach (DB::table('broker_hourly_prices')->where('instrument', $instrument)
            ->orderByDesc('time')->limit($limit)->get() as $row) {
            $bar = (array) $row;
            $bar['time'] = (int) $bar['time'];
            foreach (['open','high','low','close'] as $field) $bar[$field] = (float) $bar[$field];
            $bar['closed'] = true;
            $bar['candle_source'] = 'broker_h1_backfill';
            $bars[$bar['time']] = $bar;
        }
        foreach ($native ?? app(MarketDataService::class)->bars('1h', $limit) as $raw) {
            $bar = json_decode($raw, true);
            $bars[$bar['time']] = $bar;
        }
        krsort($bars);
        return array_slice(array_values($bars), 0, $limit);
    }
}
