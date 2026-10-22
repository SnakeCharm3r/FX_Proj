<?php

namespace App\Console\Commands;

use App\Services\SetupTargetService;
use Illuminate\Console\Command;

class TrackSetupTargets extends Command
{
    protected $signature = 'setups:track-targets';
    protected $description = 'Track TP1, TP2 and SL price touches and dispatch enabled alerts';

    public function handle(SetupTargetService $targets): int
    {
        try {
            $changed = $targets->run();
            $this->line('Targets · '.$changed.' setup(s) updated.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e); $this->error('Target tracking failed. Check local services and the application log.');
            return self::FAILURE;
        }
    }
}
