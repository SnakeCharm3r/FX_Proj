<?php

namespace App\Console\Commands;

use App\Services\SetupEngineService;
use Illuminate\Console\Command;

class ProcessOrderflow extends Command
{
    protected $signature = 'orderflow:process {--tf=* : Timeframes to process (default: all configured)}';
    protected $description = 'Evaluate fresh completed ClusterDelta candles and save qualifying setups';

    public function handle(SetupEngineService $engine): int
    {
        $failed = false;
        foreach ($this->option('tf') ?: config('orderflow.timeframes') as $tf) {
            if (!in_array($tf, config('orderflow.timeframes'), true)) {
                $this->error('Unsupported timeframe: '.$tf); $failed = true; continue;
            }
            try {
                $state = $engine->run($tf);
                $this->line($tf.' · '.$state['status'].' · '.$state['reason']);
                if ($state['status'] === 'error') $failed = true;
            } catch (\Throwable $e) {
                report($e); $this->error($tf.' · Local storage unavailable.'); $failed = true;
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
