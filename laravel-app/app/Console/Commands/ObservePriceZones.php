<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ZoneObservationService;

class ObservePriceZones extends Command
{
    protected $signature='price-zones:observe';
    protected $description='Record silent H1 range approaches, rejections, breaks and M5 retests for today in EAT';
    public function handle(ZoneObservationService $observer): int
    {
        $result=$observer->run();
        $this->line(sprintf('Zone observations · %d zones · %d M5 bars today · %d events · %d new',
            $result['zones'],$result['broker_bars'],$result['events'],$result['created']));
        return self::SUCCESS;
    }
}
