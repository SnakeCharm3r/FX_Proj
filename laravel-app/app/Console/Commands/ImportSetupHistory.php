<?php

namespace App\Console\Commands;

use App\Models\Setup;
use App\Services\SetupObservationService;
use Illuminate\Console\Command;

class ImportSetupHistory extends Command
{
    protected $signature='setups:import-history';
    protected $description='Import existing saved setup snapshots into observation history without sending alerts';

    public function handle(SetupObservationService $history): int
    {
        $created=0;
        Setup::orderBy('id')->chunkById(100,function ($setups) use ($history,&$created) {
            foreach ($setups as $setup) {
                $record=$history->import($setup);
                if ($record?->wasRecentlyCreated) $created++;
            }
        });
        $this->info('Imported '.$created.' saved setup snapshot(s). Missing past evaluations were not recreated.');
        return self::SUCCESS;
    }
}
