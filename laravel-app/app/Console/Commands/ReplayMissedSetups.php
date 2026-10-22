<?php

namespace App\Console\Commands;

use App\Services\HistoricalSetupReplayService;
use Illuminate\Console\Command;

class ReplayMissedSetups extends Command
{
    protected $signature='setups:replay-missed {date? : UTC date, defaults to today} {--send : Send unsent qualifying setups to Telegram}';
    protected $description='Replay synchronized archived M5 broker and footprint data through the live H1 trend/range strategy';

    public function handle(HistoricalSetupReplayService $replay): int
    {
        $date=$this->argument('date')?:now('UTC')->format('Y-m-d');
        try{\Carbon\CarbonImmutable::createFromFormat('!Y-m-d',$date,'UTC');}
        catch(\Throwable){$this->error('Date must use YYYY-MM-DD.');return self::FAILURE;}
        $report=$replay->scan($date);
        $this->line(sprintf('%s · broker %d · footprint %d · synchronized %d · qualified %d',
            $date,$report['broker_bars'],$report['footprint_bars'],$report['paired_bars'],count($report['candidates'])));
        foreach($report['stages'] as $stage=>$count)$this->line("{$stage}: {$count}");
        if(!$report['candidates'])return self::SUCCESS;
        $result=$replay->persist($report,(bool)$this->option('send'));
        $this->table(['ID','UTC candle','Direction','Delta %','Rules','SL','TP1','TP2','Telegram'],
            array_map(fn($row)=>[$row['id'],gmdate('Y-m-d H:i',$row['candle_time']),$row['direction'],
                sprintf('%+.1f',$row['delta_percentage']),$row['score'].'%',number_format($row['stop_loss'],2),
                number_format($row['tp1'],2),number_format($row['tp2'],2),$row['telegram_sent']?'sent':'not sent'],
                $result['saved']));
        $this->info('Telegram messages sent: '.$result['sent']);
        return self::SUCCESS;
    }
}
