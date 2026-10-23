<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Setup;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* ── Orderflow engine — runs every minute ── */
Schedule::command('orderflow:process')->everyMinute()->withoutOverlapping(2);
Schedule::command('opportunities:process')->everyMinute()->withoutOverlapping(2);
Schedule::command('price-zones:process')->everyMinute()->withoutOverlapping(2);
Schedule::command('price-zones:observe')->everyMinute()->withoutOverlapping(2);
Schedule::command('setups:track-targets')->everyMinute()->withoutOverlapping(2);

/* ── Auto-invalidate setups older than 4h with no trigger ── */
Schedule::call(function () {
    Setup::where('status', 'active')
        ->where('created_at', '<', now()->subHours(4))
        ->update(['status' => 'invalidated', 'closed_at' => now()]);
})->hourly()->name('invalidate-stale-setups')->withoutOverlapping();
