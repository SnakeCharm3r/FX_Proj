<?php

namespace App\Console\Commands;

use App\Services\VariableOpportunityService;
use Illuminate\Console\Command;

class ProcessVariableOpportunities extends Command
{
    protected $signature='opportunities:process {--date= : Import a historical UTC trading date without sending live alerts} {--notify-historical : Send imported candidates with an explicit historical label}';
    protected $description='Record M5 variable-strength opportunities and notify Telegram once per completed signal candle';
    public function handle(VariableOpportunityService $opportunities): int
    {
        $date=$this->option('date');
        if($date&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){$this->error('Date must be YYYY-MM-DD.');return self::FAILURE;}
        $state=$date?$opportunities->historical($date,(bool)$this->option('notify-historical')):$opportunities->live();
        $this->line('Variable opportunities · '.json_encode($state));return self::SUCCESS;
    }
}
