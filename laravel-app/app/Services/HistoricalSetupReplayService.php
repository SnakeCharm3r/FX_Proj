<?php

namespace App\Services;

use App\Models\AlertLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class HistoricalSetupReplayService
{
    public function __construct(
        private OrderflowMetricsService $metrics,
        private SetupEngineService $engine,
        private H1TrendRangeStrategyService $strategy,
        private SetupBuilderService $builder,
        private SetupObservationService $observations,
        private AlertDispatchService $alerts,
    ) {}

    /** Replay only candles that have matching archived broker and native footprint totals. */
    public function scan(string $date): array
    {
        $day=CarbonImmutable::parse($date.' 00:00:00','UTC');
        $start=$day->timestamp;$end=min(now()->timestamp,$day->addDay()->timestamp);
        $broker=[];
        for($cursor=$day->subDays(2);$cursor->timestamp<$end;$cursor=$cursor->addDay()){
            $broker=array_replace($broker,$this->archive('broker',$cursor->format('Y-m-d')));
        }
        ksort($broker);$history=array_values($broker);
        $footprints=$this->archive('footprint',$date);
        $candidates=[];$stages=[];$paired=0;
        foreach($history as $index=>$bar){
            $time=(int)$bar['time'];
            if($time<$start||$time>=$end)continue;
            $footprint=$footprints[$time]['bar']??null;
            if(!$footprint||($footprint['source_totals_match']??false)!==true
                ||(int)$footprint['volume']!==(int)$bar['volume']
                ||(int)$footprint['delta']!==(int)$bar['delta'])continue;
            $paired++;
            $prefix=array_slice($history,0,$index+1);
            $metrics=$this->metrics->computeFromBars($prefix,'5m',20,true);
            $previous=$footprints[$time-300]['bar']??null;
            $tick=(float)($footprints[$time]['tick_size']??0.1);
            $metrics=array_merge($metrics,$this->engine->footprintFeatures($footprint,$previous,$tick));
            $gate=$this->strategy->assess($metrics);
            $stages[$gate['stage']]=($stages[$gate['stage']]??0)+1;
            if(!($gate['qualified']??false))continue;
            $metrics['zone_retest']=$gate;
            $candidates[]=['metrics'=>$metrics,'gate'=>$gate,
                'delta_percentage'=>$this->deltaPercentage($metrics)];
        }
        return ['date'=>$date,'start'=>$start,'end'=>$end,'broker_bars'=>count(array_filter($history,
            fn($bar)=>(int)$bar['time']>=$start&&(int)$bar['time']<$end)),
            'footprint_bars'=>count($footprints),'paired_bars'=>$paired,'stages'=>$stages,
            'candidates'=>$candidates];
    }

    public function persist(array $report,bool $send): array
    {
        $saved=[];$sent=0;$original=config('orderflow.external_alerts_enabled');
        config(['orderflow.external_alerts_enabled'=>false]);
        try{
            foreach($report['candidates'] as $candidate){
                $setup=$this->builder->evaluate($candidate['metrics']);
                if(!$setup)continue;
                $created=$setup->wasRecentlyCreated;
                if($created){
                    $setup->status='invalidated';$setup->exit_tracking_state='historical';
                    $setup->closed_at=now();$setup->created_at=CarbonImmutable::createFromTimestampUTC($setup->candle_time);
                    $setup->saveQuietly();
                    $assessment=['signals'=>$candidate['gate']['signals'],'score'=>$candidate['gate']['rule_score'],
                        'bull_score'=>array_sum(array_column(array_filter($candidate['gate']['signals'],fn($s)=>$s['status']==='bullish'),'weight')),
                        'bear_score'=>array_sum(array_column(array_filter($candidate['gate']['signals'],fn($s)=>$s['status']==='bearish'),'weight')),
                        'threshold'=>100,'direction'=>$candidate['gate']['direction']];
                    $observation=$this->observations->record($candidate['metrics'],$assessment);
                    if($observation&&!$observation->setup_id)$observation->update(['setup_id'=>$setup->id]);
                }
                $alreadySent=AlertLog::where('setup_id',$setup->id)->where('channel','telegram')
                    ->whereIn('type',['NEW_SETUP','BACKFILLED_SETUP'])->where('status','sent')->exists();
                $sentNow=false;
                if($send&&!$alreadySent){
                    $this->alerts->dispatch('BACKFILLED_SETUP',app(SetupAlertMessageService::class)->entry($setup),
                        strtolower($setup->direction),$setup->id);
                    $sentNow=AlertLog::where('setup_id',$setup->id)->where('channel','telegram')
                        ->where('type','BACKFILLED_SETUP')->where('status','sent')->exists();
                    if($sentNow)$sent++;
                }
                $saved[]=['id'=>$setup->id,'created'=>$created,'direction'=>$setup->direction,
                    'candle_time'=>$setup->candle_time,'delta_percentage'=>$candidate['delta_percentage'],
                    'score'=>$setup->score,'entry'=>$setup->entry,'stop_loss'=>$setup->stop_loss,
                    'tp1'=>$setup->tp1,'tp2'=>$setup->tp2,'telegram_sent'=>$sentNow];
            }
        }finally{config(['orderflow.external_alerts_enabled'=>$original]);}
        return ['saved'=>$saved,'sent'=>$sent];
    }

    private function archive(string $kind,string $date): array
    {
        $root=Storage::disk('local')->path("research/clusterdelta/GC/5m/{$date}/{$kind}");
        $latest=[];
        foreach(glob($root.'/*.json.gz')?:[] as $file){
            $json=gzdecode((string)file_get_contents($file));$row=$json===false?null:json_decode($json,true);
            if(!is_array($row)||!isset($row['bar']['time']))continue;
            $time=(int)$row['bar']['time'];$observed=(int)($row['first_observed_at']??0);
            if(isset($latest[$time])&&$latest[$time]['observed']>$observed)continue;
            $bar=$row['bar'];
            if($kind==='broker'){
                $volume=(int)$bar['volume'];$delta=(int)$bar['delta'];
                $bar['buy_vol']=($volume+$delta)/2;$bar['sell_vol']=($volume-$delta)/2;$bar['closed']=true;
                $latest[$time]=['observed'=>$observed,'bar'=>$bar];
            }else{
                $latest[$time]=['observed'=>$observed,'bar'=>$bar,
                    'tick_size'=>(float)($row['metadata']['tick_size']??0.1)];
            }
        }
        ksort($latest);
        if($kind==='broker'){
            $bars=[];foreach($latest as $time=>$row)$bars[$time]=$row['bar'];
            return $bars;
        }
        return $latest;
    }

    private function deltaPercentage(array $metrics): float
    {
        $volume=(float)$metrics['buy_volume']+(float)$metrics['sell_volume'];
        return $volume>0?round(100*(float)$metrics['delta']/$volume,1):0.0;
    }
}
