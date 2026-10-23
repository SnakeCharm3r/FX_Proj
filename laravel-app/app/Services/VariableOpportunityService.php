<?php

namespace App\Services;

use App\Models\AlertLog;
use App\Models\Setup;
use App\Models\VariableOpportunity;
use Illuminate\Support\Facades\DB;

class VariableOpportunityService
{
    public const VERSION='variable_orderflow_v1';

    public function __construct(private StrategyDryRunService $replay,private SetupBuilderService $levels,
        private AlertDispatchService $alerts) {}

    public function scope()
    {
        return VariableOpportunity::where('source',config('orderflow.connector'))
            ->where('instrument',config('clusterdelta.instrument','GC'))->where('strategy_version',self::VERSION);
    }

    /** Store the exact replay candidates, independently of strict setup approval. */
    public function record(array $history,array $footprints,array $h1,bool $historical): array
    {
        usort($history,fn($a,$b)=>$a['time']<=>$b['time']);
        $footprints=array_column($footprints,null,'time');$recorded=[];
        $zones=app(PriceZoneDetector::class)->detect($h1,now()->timestamp);
        foreach($history as $index=>$bar){
            $time=(int)$bar['time'];$end=$time+300;$now=now()->timestamp;
            if(!($bar['closed']??false)||$end>$now)continue;
            if(!$historical&&($now-$end>300||!$this->fresh($bar['received_at']??0)))continue;
            $fp=$footprints[$time]??null;
            if(!$fp||!($fp['closed']??false)||($fp['source_totals_match']??false)!==true
                ||$fp['volume']!=($bar['buy_vol']+$bar['sell_vol'])||$fp['delta']!=$bar['delta'])continue;
            $prefix=array_slice($history,0,$index+1);
            $candidate=$this->replay->potential($prefix,$zones,$h1);
            if(!$candidate||!$candidate['notable']||$candidate['rule_completion']<config('orderflow.variable_min_rules',25))continue;
            $metrics=app(OrderflowMetricsService::class)->computeFromBars($prefix,'5m',20,true);
            $features=app(SetupEngineService::class)->footprintFeatures($fp,$footprints[$time-300]??null,
                (float)($fp['tick_size']??.1));
            $trend=app(H1TrendService::class)->assess($end,$h1);
            [$entry,$stop,$tp1,$tp2]=$this->levels->referenceLevels($metrics,$candidate['direction']);
            $risk=round(abs($entry-$stop),2);
            $key=hash('sha256',implode(':',[config('orderflow.connector'),config('clusterdelta.instrument','GC'),
                '5m',self::VERSION,$time]));
            $opportunity=VariableOpportunity::firstOrCreate(['evaluation_key'=>$key],[
                'source'=>config('orderflow.connector'),'instrument'=>config('clusterdelta.instrument','GC'),
                'timeframe'=>'5m','strategy_version'=>self::VERSION,'candle_time'=>$time,
                'direction'=>$candidate['direction'],'score'=>$candidate['rule_completion'],'entry'=>$entry,
                'stop_loss'=>$stop,'tp1'=>$tp1,'tp2'=>$tp2,'risk_points'=>$risk,'delta_pct'=>$candidate['delta_pct'],
                'historical'=>$historical,'telegram_status'=>$historical?'historical':'pending',
                'evidence'=>$candidate+['cvd'=>$metrics['cvd'],'cvd_divergence'=>$metrics['cvd_divergence'],
                    'absorption'=>$metrics['absorption_evidence'],'footprint'=>$features,'footprint_status'=>'matched',
                    'h1_trend'=>$trend['direction'],'with_h1_trend'=>$trend['direction']===$candidate['direction'],
                    'strict_risk_limit'=>config('orderflow.live_max_stop_points',5),
                    'strict_risk_passed'=>$risk<=config('orderflow.live_max_stop_points',5),
                    'levels_basis'=>'Signal M5 close; stop beyond signal candle plus configured buffer',
                    'entry_approved'=>false,'observed_at'=>$now]]);
            $recorded[$opportunity->id]=$opportunity;
        }
        return array_values($recorded);
    }

    public function historical(string $date,bool $send=false): array
    {
        $rows=$this->record($this->replay->archive('broker',$date),$this->replay->archive('footprint',$date),
            app(HourlyPriceHistoryService::class)->bars(500),true);
        $sent=0;
        if($send)foreach($rows as $row)if($this->notify($row,true))$sent++;
        return ['recorded'=>count($rows),'sent'=>$sent,'ids'=>array_map(fn($r)=>$r->id,$rows)];
    }

    public function live(): array
    {
        if(!config('orderflow.variable_opportunities_enabled'))return ['status'=>'disabled','recorded'=>0,'sent'=>0];
        $market=app(MarketDataService::class);
        $raw=$market->bars('5m',300);
        $history=array_map(fn($b)=>json_decode($b,true),$raw);
        $latest=$history[0]??null;
        $snapshot=app(FootprintService::class)->snapshot(config('clusterdelta.instrument','GC'),'5m');
        if(!$latest||!$this->fresh($latest['received_at']??0)||!$snapshot||!$this->fresh($snapshot['received_at']??0)){
            return ['status'=>'waiting_for_fresh_m5_and_footprint','recorded'=>0,'sent'=>0];
        }
        $history=array_values(array_filter($history,fn($b)=>($b['closed']??false)&&$b['time']+300<=now()->timestamp));
        if(count($history)<20)return ['status'=>'waiting_for_20_m5_bars','recorded'=>0,'sent'=>0];
        $frames=array_map(fn($b)=>$b+['tick_size'=>$snapshot['tick_size']],$snapshot['bars']);
        $rows=$this->record($history,$frames,app(HourlyPriceHistoryService::class)->bars(500),false);
        $sent=0;
        foreach($rows as $row){
            if(!$row->historical&&$this->notify($row))$sent++;
        }
        return ['status'=>'watching','recorded'=>count($rows),'sent'=>$sent];
    }

    /** Database claim plus delivery log prevents overlapping watcher processes sending twice. */
    public function notify(VariableOpportunity $row,bool $historical=false): bool
    {
        if(!config('orderflow.external_alerts_enabled')||($row->historical&&!$historical))return false;
        $claimed=DB::transaction(function()use($row,$historical){
            $locked=VariableOpportunity::lockForUpdate()->findOrFail($row->id);
            if(AlertLog::where('variable_opportunity_id',$row->id)->where('channel','telegram')->where('status','sent')->exists()){
                $locked->update(['telegram_status'=>'sent']);return false;
            }
            if(!in_array($locked->telegram_status,$historical?['historical','pending']:['pending'],true))return false;
            $strict=Setup::where('source',$locked->source)->where('instrument',$locked->instrument)
                ->where('timeframe','5m')->where('candle_time',$locked->candle_time)->pluck('id');
            if(AlertLog::whereIn('setup_id',$strict)->where('type','NEW_SETUP')->where('channel','telegram')->where('status','sent')->exists()){
                $locked->update(['telegram_status'=>'covered_by_setup']);return false;
            }
            $locked->update(['telegram_status'=>'sending','telegram_attempted_at'=>now()]);return true;
        });
        if(!$claimed)return false;
        $channels=config('alerts.channels');config(['alerts.channels'=>['telegram']]);
        try{
            $this->alerts->dispatch('VARIABLE_OPPORTUNITY',$this->message($row,$historical),
                strtolower($row->direction),null,$row->id);
        }catch(\Throwable $e){
            $row->update(['telegram_status'=>'failed']);report($e);return false;
        }finally{config(['alerts.channels'=>$channels]);}
        $sent=AlertLog::where('variable_opportunity_id',$row->id)->where('channel','telegram')->where('status','sent')->exists();
        $row->update(['telegram_status'=>$sent?'sent':'failed']);return $sent;
    }

    public function message(VariableOpportunity $row,bool $historical=false): string
    {
        $instrument=htmlspecialchars($row->instrument,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title=($historical?'Historical ':'').ucfirst(strtolower($row->direction)).' opportunity';
        $positive=$row->delta_pct>=0;
        $deltaLabel=$positive?'Positive Delta':'Negative Delta';
        $deltaMarker=$positive?'🟢 ▲':'🔴 ▼';
        $volume=(float)($row->evidence['volume']??0);

        return sprintf("<b>%s · %s</b>\n%s %s: <b>%+.1f%%</b>\n📊 Volume: <b>%s</b>\nConfluence: <b>%d%%</b>\nSL: <code>%.2f</code>\nTP1: <code>%.2f</code>\nTP2: <code>%.2f</code>",
            $instrument,$title,$deltaMarker,$deltaLabel,$row->delta_pct,number_format($volume,0,'.',','),
            $row->score,$row->stop_loss,$row->tp1,$row->tp2);
    }

    private function fresh(int $received): bool
    {
        return $received<=now()->timestamp+5&&now()->timestamp-$received<60;
    }
}
