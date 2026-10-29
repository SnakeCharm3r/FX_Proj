<?php

namespace App\Services;

use App\Models\AlertLog;
use App\Models\Setup;
use App\Models\VariableOpportunity;
use Illuminate\Support\Facades\DB;

class VariableOpportunityService
{
    public const VERSION='footprint_primary_v2';
    public const LEGACY_VERSION='variable_orderflow_v1';

    public function __construct(private StrategyDryRunService $replay,private SetupBuilderService $levels,
        private AlertDispatchService $alerts) {}

    public function scope()
    {
        return VariableOpportunity::where('source',config('orderflow.connector'))
            ->where('instrument',config('clusterdelta.instrument','GC'))
            ->whereIn('strategy_version',[self::VERSION,self::LEGACY_VERSION]);
    }

    /** Store the exact replay candidates, independently of strict setup approval. */
    public function record(array $brokerBars,array $footprints,array $h1,bool $historical): array
    {
        $footprints=array_values(array_filter($footprints,fn($bar)=>($bar['source_totals_match']??false)===true));
        usort($footprints,fn($a,$b)=>$a['time']<=>$b['time']);
        $brokerBars=array_column($brokerBars,null,'time');$frames=array_column($footprints,null,'time');$recorded=[];
        $zones=app(PriceZoneDetector::class)->detect($h1,now()->timestamp);
        foreach($footprints as $index=>$bar){
            $time=(int)$bar['time'];$end=$time+300;$now=now()->timestamp;
            if(!($bar['closed']??false)||$end>$now)continue;
            if(!$historical&&($now-$end>300||!$this->fresh($bar['received_at']??0)))continue;
            $prefix=array_slice($footprints,0,$index+1);
            $candidate=$this->replay->potential($prefix,$zones,$h1);
            if(!$candidate||!$candidate['notable']||$candidate['rule_completion']<config('orderflow.variable_min_rules',25))continue;
            $metrics=app(OrderflowMetricsService::class)->computeFromBars($prefix,'5m',20,true);
            $features=app(SetupEngineService::class)->footprintFeatures($bar,$frames[$time-300]??null,
                (float)($bar['tick_size']??.1));
            $trend=app(H1TrendService::class)->assess($end,$h1);
            [$entry,$stop,$tp1,$tp2]=$this->levels->referenceLevels($metrics,$candidate['direction']);
            $risk=round(abs($entry-$stop),2);
            $broker=$brokerBars[$time]??null;
            $comparison=$broker?['status'=>'available','broker_close'=>(float)$broker['close'],
                'gc_close'=>(float)$bar['close'],'price_basis'=>round((float)$bar['close']-(float)$broker['close'],8),
                'broker_received_at'=>$broker['received_at']??null,
                'broker_fresh'=>$this->fresh((int)($broker['received_at']??0))]:['status'=>'unavailable'];
            $key=hash('sha256',implode(':',[config('orderflow.connector'),config('clusterdelta.instrument','GC'),
                '5m',self::VERSION,$time]));
            $opportunity=VariableOpportunity::firstOrCreate(['evaluation_key'=>$key],[
                'source'=>config('orderflow.connector'),'instrument'=>config('clusterdelta.instrument','GC'),
                'timeframe'=>'5m','strategy_version'=>self::VERSION,'candle_time'=>$time,
                'direction'=>$candidate['direction'],'score'=>$candidate['rule_completion'],'entry'=>$entry,
                'stop_loss'=>$stop,'tp1'=>$tp1,'tp2'=>$tp2,'risk_points'=>$risk,'delta_pct'=>$candidate['delta_pct'],
                'historical'=>$historical,'telegram_status'=>$historical?'historical':'pending',
                'evidence'=>$candidate+['cvd'=>$metrics['cvd'],'cvd_divergence'=>$metrics['cvd_divergence'],
                    'absorption'=>$metrics['absorption_evidence'],'footprint'=>$features,
                    'footprint_status'=>'authoritative','price_source'=>'GC_futures','broker_comparison'=>$comparison,
                    'h1_trend'=>$trend['direction'],'with_h1_trend'=>$trend['direction']===$candidate['direction'],
                    'strict_risk_limit'=>config('orderflow.live_max_stop_points',5),
                    'strict_risk_passed'=>$risk<=config('orderflow.live_max_stop_points',5),
                    'levels_basis'=>'GC futures footprint close; stop beyond the GC signal candle plus configured buffer',
                    'entry_approved'=>false,'observed_at'=>$now]]);
            $recorded[$opportunity->id]=$opportunity;
        }
        return array_values($recorded);
    }

    public function historical(string $date,bool $send=false): array
    {
        $footprints=array_map(fn($bar)=>app(FootprintService::class)->analysisBar($bar),
            $this->replay->archive('footprint',$date));
        $rows=$this->record($this->replay->archive('broker',$date),$footprints,$this->footprintH1(),true);
        $sent=0;
        if($send)foreach($rows as $row)if($this->notify($row,true))$sent++;
        return ['recorded'=>count($rows),'sent'=>$sent,'ids'=>array_map(fn($r)=>$r->id,$rows)];
    }

    public function live(): array
    {
        if(!config('orderflow.variable_opportunities_enabled'))return ['status'=>'disabled','recorded'=>0,'sent'=>0];
        $footprints=app(FootprintService::class);
        $snapshot=$footprints->snapshot(config('clusterdelta.instrument','GC'),'5m');
        if(!$snapshot||!$this->fresh($snapshot['received_at']??0))
            return ['status'=>'waiting_for_fresh_footprint','recorded'=>0,'sent'=>0];
        $frames=$footprints->bars('5m',300);
        $minimum=(int)config('orderflow.participation_min_samples',10)+1;
        if(count(array_filter($frames,fn($b)=>($b['closed']??false)))<$minimum)
            return ['status'=>'waiting_for_'.$minimum.'_footprint_bars','recorded'=>0,'sent'=>0];
        $broker=array_map(fn($raw)=>json_decode($raw,true),app(MarketDataService::class)->storedBars('5m',300));
        $rows=$this->record($broker,$frames,$this->footprintH1(),false);
        $sent=0;
        foreach($rows as $row){
            if(!$row->historical&&$this->notify($row))$sent++;
        }
        return ['status'=>'watching','recorded'=>count($rows),'sent'=>$sent];
    }

    private function footprintH1(): array
    {
        return array_values(array_filter(app(FootprintService::class)->bars('1h',500),
            fn($bar)=>($bar['closed']??false)===true));
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

        return sprintf("<b>%s · %s</b>\n%s %s: <b>%+.1f%%</b>\n📊 Volume: <b>%s</b>\nConfluence: <b>%d%%</b>\nEntry: <code>%.2f</code>\nSL: <code>%.2f</code>\nTP1: <code>%.2f</code>\nTP2: <code>%.2f</code>",
            $instrument,$title,$deltaMarker,$deltaLabel,$row->delta_pct,number_format($volume,0,'.',','),
            $row->score,$row->entry,$row->stop_loss,$row->tp1,$row->tp2);
    }

    private function fresh(int $received): bool
    {
        return $received<=now()->timestamp+5&&now()->timestamp-$received<60;
    }
}
