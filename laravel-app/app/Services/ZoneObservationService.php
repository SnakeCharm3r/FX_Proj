<?php

namespace App\Services;

use App\Models\AlertLog;
use App\Models\ZoneObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ZoneObservationService
{
    /** Price observations are independent from executable setup/risk gates. */
    public function analyse(array $zones,array $h1,array $bars,array $footprints,int $start,int $end): array
    {
        $bars=array_values(array_filter($bars,fn($b)=>($b['closed']??false)&&$b['time']+300<=$end));
        usort($bars,fn($a,$b)=>$a['time']<=>$b['time']);
        $h1=array_values(array_filter($h1,fn($b)=>($b['closed']??false)&&$b['time']+3600<=$end));
        usort($h1,fn($a,$b)=>$a['time']<=>$b['time']);
        $events=[];$evidenceCache=[];
        foreach($zones as $zone){
            $demand=$zone['type']==='DEMAND';$brokenAt=null;$previous=null;
            foreach($h1 as $hour){
                if($hour['time']<$zone['confirmed_time'])continue;
                if($demand?$hour['close']<$zone['low']:$hour['close']>$zone['high']){
                    $brokenAt=(int)$hour['time']+3600;break;
                }
            }
            $breakRecorded=false;
            foreach($bars as $i=>$bar){
                $time=(int)$bar['time'];$decision=$time+300;
                if($time<$zone['confirmed_time'])continue;
                $touch=$bar['low']<=$zone['high']&&$bar['high']>=$zone['low'];
                $contiguous=$previous&&$time===$previous['time']+300;
                $wasTouch=$contiguous&&$previous['low']<=$zone['high']&&$previous['high']>=$zone['low'];
                $distance=$bar['close']<$zone['low']?$zone['low']-$bar['close']:max(0,$bar['close']-$zone['high']);
                $near=max(1,(float)$zone['atr']*(float)config('price_zones.approach_atr_fraction',.25));
                $wasNear=$contiguous&&$previous['close']>=$zone['low']-$near&&$previous['close']<=$zone['high']+$near;
                $types=[];$broken=$brokenAt!==null&&$brokenAt<=$decision;
                if($broken&&!$breakRecorded){
                    // The event is attributed to the H1 close, never to the earlier wick break.
                    $types[]=['BREAK',$demand?'SELL':'BUY'];$breakRecorded=true;
                }
                if(!$broken){
                    $towards=$contiguous&&($demand?$bar['close']<$previous['close']:$bar['close']>$previous['close']);
                    if(!$touch&&$distance<=$near&&!$wasNear&&$towards)$types[]=['APPROACH',$demand?'BUY':'SELL'];
                    if($touch&&!$wasTouch)$types[]=['TOUCH',$demand?'BUY':'SELL'];
                    $reject=$demand?$bar['close']>$zone['high']&&$bar['close']>$bar['open']
                        :$bar['close']<$zone['low']&&$bar['close']<$bar['open'];
                    if(($touch||$wasTouch)&&$reject)$types[]=['REJECTION',$demand?'BUY':'SELL'];
                }elseif($time>=$brokenAt){
                    // Any later visit is a new observation; an old demand range may act as supply (and vice versa).
                    $postBreakWasTouch=$wasTouch&&$previous['time']>=$brokenAt;
                    if($touch&&!$postBreakWasTouch)$types[]=['BROKEN_RETEST',$demand?'SELL':'BUY'];
                    if($touch||$postBreakWasTouch){
                        $holds=$demand?$bar['close']<$zone['low']&&$bar['close']<$bar['open']
                            :$bar['close']>$zone['high']&&$bar['close']>$bar['open'];
                        $reclaims=$demand?$bar['close']>$zone['high']&&$bar['close']>$bar['open']
                            :$bar['close']<$zone['low']&&$bar['close']<$bar['open'];
                        if($holds)$types[]=['CONTINUATION',$demand?'SELL':'BUY'];
                        if($reclaims)$types[]=['COUNTER_BREAKOUT',$demand?'BUY':'SELL'];
                    }
                }
                if($time>=$start){
                    foreach($types as [$type,$direction]){
                        $eventTime=$type==='BREAK'?$brokenAt-300:$time;
                        if($eventTime<$start||($type==='BREAK'&&$eventTime!==$time))continue;
                        if(!isset($evidenceCache[$time])){
                            $evidenceCache[$time]=$this->evidence(array_slice($bars,max(0,$i-299),min(300,$i+1)),
                                $footprints[$time]??null,$footprints[$time-300]??null,$h1,$decision);
                        }
                        $events[]=['price_zone_id'=>$zone['id'],'event_type'=>$type,'direction'=>$direction,
                            'candle_time'=>$eventTime,'decision_time'=>$type==='BREAK'?$brokenAt:$decision,
                            'price'=>(float)$bar['close'],'evidence'=>$evidenceCache[$time]+[
                                'zone_type'=>$zone['type'],'zone_low'=>$zone['low'],'zone_high'=>$zone['high'],
                                'broken_at'=>$brokenAt,'role'=>$broken?($demand?'potential_supply':'potential_demand'):strtolower($zone['type']),
                                'classification'=>'price_observation','entry_approved'=>false]];
                    }
                }
                $previous=$bar;
            }
        }
        usort($events,fn($a,$b)=>($a['decision_time']<=>$b['decision_time'])?:($a['price_zone_id']<=>$b['price_zone_id']));
        return $events;
    }

    private function evidence(array $history,?array $frame,?array $previous,array $h1,int $decision): array
    {
        $metrics=app(OrderflowMetricsService::class)->computeFromBars($history,'5m',20,true);
        $bar=end($history);$volume=(float)($bar['buy_vol']??0)+(float)($bar['sell_vol']??0);
        $fp=$frame['bar']??null;
        $matched=$fp&&($fp['source_totals_match']??false)===true&&($fp['closed']??false)
            &&$fp['volume']==$volume&&$fp['delta']==$bar['delta'];
        $features=$matched?app(SetupEngineService::class)->footprintFeatures($fp,$previous['bar']??null,
            (float)($frame['tick_size']??.1)):[];
        return ['volume'=>$volume,'delta'=>$bar['delta']??null,
            'delta_pct'=>$volume>0?round(100*(float)$bar['delta']/$volume,2):null,
            'cvd'=>$metrics['cvd'],'cvd_divergence'=>$metrics['cvd_divergence'],
            'participation'=>$metrics['participation'],'absorption'=>$metrics['absorption_evidence'],
            'h1_trend'=>app(H1TrendService::class)->assess($decision,$h1)['direction'],
            'footprint_status'=>$matched?'matched':($fp?'totals_mismatch':'unavailable'),
            'footprint'=>$features,'footprint_price_source'=>'GC_futures','zone_price_source'=>'MT5_XAUUSD',
            'retrospective'=>true];
    }

    public function run(): array
    {
        $day=CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfDay();
        $start=$day->timestamp;$end=now()->timestamp;
        $bars=[];$footprints=[];
        for($date=$day->utc()->subDay()->startOfDay();$date->timestamp<=$end;$date=$date->addDay()){
            $bars=array_replace($bars,$this->archive('broker',$date->format('Y-m-d')));
            $footprints=array_replace($footprints,$this->archive('footprint',$date->format('Y-m-d')));
        }
        foreach(app(MarketDataService::class)->storedBars('5m',500) as $raw){
            $bar=json_decode($raw,true);if(($bar['closed']??false)&&$bar['time']+300<=$end)$bars[$bar['time']]=$bar;
        }
        $snapshot=app(FootprintService::class)->snapshot(config('clusterdelta.instrument','GC'),'5m');
        foreach($snapshot['bars']??[] as $bar){
            if(($bar['closed']??false)&&$bar['time']+300<=$end)$footprints[$bar['time']]=['bar'=>$bar,'tick_size'=>$snapshot['tick_size']];
        }
        $h1=app(HourlyPriceHistoryService::class)->bars(500);
        $zones=app(PriceZoneService::class)->scope()->get()->toArray();
        $events=$this->analyse($zones,$h1,array_values($bars),$footprints,$start,$end);$created=0;
        foreach($events as $event){
            $key=hash('sha256',implode(':',[$event['price_zone_id'],$event['event_type'],$event['candle_time']]));
            $event['historical']=$end-$event['decision_time']>90;
            $saved=ZoneObservation::firstOrCreate(['event_key'=>$key],$event);
            if(!$saved->wasRecentlyCreated)continue;$created++;
            if(!$event['historical'])AlertLog::create(['price_zone_id'=>$event['price_zone_id'],
                'channel'=>'browser','type'=>'ZONE_'.$event['event_type'],'status'=>'recorded',
                'message'=>sprintf('H1 %s %.2f–%.2f: %s at %.2f; M5 delta %s.',
                    strtolower($event['evidence']['zone_type']),$event['evidence']['zone_low'],$event['evidence']['zone_high'],
                    strtolower(str_replace('_',' ',$event['event_type'])),$event['price'],
                    $event['evidence']['delta_pct']===null?'unavailable':sprintf('%+.1f%%',$event['evidence']['delta_pct']))]);
        }
        return ['created'=>$created,'events'=>count($events),'broker_bars'=>count(array_filter($bars,
            fn($b)=>$b['time']>=$start&&($b['closed']??false))), 'zones'=>count($zones)];
    }

    private function archive(string $kind,string $date): array
    {
        $instrument=config('clusterdelta.instrument','GC');
        $root=Storage::disk('local')->path("research/clusterdelta/{$instrument}/5m/{$date}/{$kind}");$rows=[];
        foreach(glob($root.'/*.json.gz')?:[] as $file){
            $json=gzdecode((string)file_get_contents($file));$row=$json===false?null:json_decode($json,true);
            if(!isset($row['bar']['time']))continue;$time=(int)$row['bar']['time'];
            if(isset($rows[$time])&&$rows[$time]['first_observed_at']>($row['first_observed_at']??0))continue;
            $rows[$time]=$row;
        }
        $result=[];
        foreach($rows as $time=>$row){
            $bar=$row['bar'];
            if($kind==='broker'){
                $bar['buy_vol']=($bar['volume']+$bar['delta'])/2;$bar['sell_vol']=($bar['volume']-$bar['delta'])/2;
                $result[$time]=$bar;
            }else $result[$time]=['bar'=>$bar,'tick_size'=>$row['metadata']['tick_size']??.1];
        }
        return $result;
    }
}
