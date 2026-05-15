<?php

namespace App\Console\Commands;

use App\Events\MetricsUpdated;
use App\Services\OrderflowMetricsService;
use App\Services\SetupBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessOrderflow extends Command
{
    protected $signature   = 'orderflow:process {--tf=* : Timeframes to process (default: all configured)}';
    protected $description = 'Compute orderflow metrics, evaluate signals, and emit trade setups';

    public function handle(
        OrderflowMetricsService $metricsService,
        SetupBuilderService     $setupBuilder
    ): int {
        $timeframes = $this->option('tf') ?: config('orderflow.timeframes', ['15m', '1h']);

        foreach ($timeframes as $tf) {
            try {
                $metrics = $metricsService->compute($tf);

                if ($metrics['price'] === 0.0) {
                    $this->line("  <fg=yellow>⚠ No data for {$tf}</>");
                    continue;
                }

                // Broadcast metrics update to dashboard
                broadcast(new MetricsUpdated($metrics));

                // Attempt to build a setup
                $setup = $setupBuilder->evaluate($metrics);

                if ($setup) {
                    $this->line("  <fg=green>✓ {$tf}: {$setup->direction} setup #{$setup->id} score={$setup->score}%</>");
                } else {
                    $this->line("  <fg=gray>  {$tf}: score below threshold or no signal alignment</>");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ {$tf}: " . $e->getMessage());
                Log::error("ProcessOrderflow [{$tf}]: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        }

        return self::SUCCESS;
    }
}
