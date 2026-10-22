<?php

namespace App\Console\Commands;

use App\Services\PriceZoneService;
use Illuminate\Console\Command;

class ProcessPriceZones extends Command
{
    protected $signature = 'price-zones:process';
    protected $description = 'Confirm H1 demand/supply wick zones and record in-app arrival alerts';

    public function handle(PriceZoneService $zones): int
    {
        try {
            $state = $zones->run();
            $this->line('1H zones · '.$state['status'].' · '.$state['reason']);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('1H zones · Evaluation failed. Check local services and the application log.');
            return self::FAILURE;
        }
    }
}
