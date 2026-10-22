<?php

namespace App\Console\Commands;

use App\Models\Setup;
use App\Models\SetupObservation;
use App\Services\SetupBuilderService;
use App\Services\ZoneRetestService;
use Illuminate\Console\Command;

class AuditSetup extends Command
{
    protected $signature = 'setups:audit {setup : Saved setup ID}';
    protected $description = 'Explain a saved setup and dry-run its recorded inputs against the current zone rule; sends no alerts';

    public function handle(SetupBuilderService $builder, ZoneRetestService $zones): int
    {
        $setup = Setup::findOrFail($this->argument('setup'));
        $observation = SetupObservation::where('setup_id', $setup->id)->first();
        $this->line("Setup #{$setup->id}: {$setup->direction} {$setup->timeframe} at {$setup->entry}; saved score {$setup->score}/100.");
        $this->line('Original strategy: '.$setup->strategy_version);
        $this->table(['Condition','Direction','Points'], array_map(fn ($s)=>[$s['name'],$s['status'],$s['weight']], $setup->confluence_detail));
        $m = $observation?->metrics;
        if (!$m || $m['source'] !== config('orderflow.connector') || $m['instrument'] !== config('clusterdelta.instrument')) {
            $this->warn('No matching saved input snapshot. Current-rule replay unavailable; inputs were not reconstructed.');
            return self::SUCCESS;
        }
        $this->line('Completed candle: '.gmdate('Y-m-d H:i:s', $m['bar_time']).' UTC');
        $assessment = $builder->assess($m);
        $gate = $zones->assess($m, $assessment);
        $this->line('Current-rule dry run: '.json_encode($gate, JSON_UNESCAPED_UNICODE));
        $this->line('Uses recorded inputs and currently imported price history. No setup, observation or alert was written.');
        return self::SUCCESS;
    }
}
