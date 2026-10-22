<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WatchOrderflow extends Command
{
    protected $signature = 'orderflow:watch {--interval=10 : Seconds between checks, minimum 5}';
    protected $description = 'Keep the local setup detector running without a queue or WebSocket server';

    public function handle(): int
    {
        $interval = max(5, (int) $this->option('interval'));
        $this->info('Setup detector running. Completed candles only. Stop with Ctrl+C.');
        while (true) {
            $this->call('price-zones:process');
            $this->call('price-zones:observe');
            $this->call('orderflow:process');
            $this->call('setups:track-targets');
            sleep($interval);
        }
    }
}
