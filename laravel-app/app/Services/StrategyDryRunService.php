<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class StrategyDryRunService
{
    public function run(string $date): array
    {
        $start=CarbonImmutable::parse($date.' 00:00:00','UTC')->timestamp;
        $requestedEnd=min(now()->timestamp,$start+86400);
        $broker=$this->archive('broker',$date);
        $footprints=$this->archive('footprint',$date);
        $bars=array_values(array_filter($broker,fn($bar)=>$bar['time'] >= $start && $bar['time'] < $requestedEnd));
        $end=$bars ? min($requestedEnd,end($bars)['time']+300) : $requestedEnd;
        $h1=app(HourlyPriceHistoryService::class)->bars(500);
        $zones=app(PriceZoneDetector::class)->detect($h1,$end);
        return $this->analyse($bars,$footprints,$h1,$zones,$start,$end,$date);
    }

    public function analyse(array $bars,array $footprints,array $h1,array $zones,int $start,int $end,string $date='2026-10-22'): array
    {
        usort($bars,fn($a,$b)=>$a['time']<=>$b['time']);
        usort($h1,fn($a,$b)=>$a['time']<=>$b['time']);
        $footprints=array_column($footprints,null,'time');
        $paired=[];
        foreach($bars as $bar){
            $fp=$footprints[$bar['time']]??null;
            if($fp && (int)$fp['volume']===(int)$bar['volume'] && (int)$fp['delta']===(int)$bar['delta']) $paired[$bar['time']]=true;
        }

        $potentials=[];$potentialMarkers=[];
        foreach($bars as $index=>$bar){
            if(!isset($paired[$bar['time']])) continue;
            $prefix=array_slice($bars,0,$index+1);
            $potential=$this->potential($prefix,$zones,$h1);
            if(!$potential)continue;
            $potentials[]=$potential;
            if($potential['notable']){
                $direction=$potential['direction'];$ratio=$potential['volume_ratio'];
                $potentialMarkers[]=['time'=>$bar['time'],'position'=>$direction==='BUY'?'belowBar':'aboveBar',
                    'color'=>$direction==='BUY'?'#00c076':'#ff3b5c',
                    'shape'=>$direction==='BUY'?'arrowUp':'arrowDown',
                    'text'=>sprintf('%s %d%% rules · Δ%+.1f%% · %s', $direction,$potential['rule_completion'],$potential['delta_pct'],
                        $ratio===null?'—':number_format($ratio,2).'x')];
            }
        }

        $setups=[];$blocked=[];
        foreach($bars as $index=>$bar){
            $evidence=app(AbsorptionSequenceService::class)->analyse(array_slice($bars,0,$index+1),'5m');
            if(!($evidence['qualified']??false)) continue;
            $candidate=['time'=>$bar['time'],'direction'=>$evidence['direction'],'evidence'=>$evidence];
            if(!isset($paired[$bar['time']])){
                $blocked[]=$candidate+['reason'=>'No archived footprint matching this confirmation candle.'];
                continue;
            }
            $zone=$this->zoneAt($zones,$h1,$evidence,$bar['time']);
            if(!$zone){
                $blocked[]=$candidate+['reason'=>'Absorption sequence completed away from a pre-existing valid H1 '.
                    ($evidence['direction']==='BUY'?'demand':'supply').' zone.'];
                continue;
            }
            $next=$bars[$index+1]??null;
            if(!$next){
                $blocked[]=$candidate+['reason'=>'No later M5 bar is available for a next-open test entry.'];
                continue;
            }
            $setup=$this->levels($evidence,$zone,$next);
            $setup['signal_time']=$bar['time'];$setup['zone']=$zone;$setup['evidence']=$evidence;
            $setup['rule_match']=100;$setup['context_score']=15;
            $setup=array_merge($setup,$this->outcome($setup,array_slice($bars,$index+1)));
            $setups[]=$setup;
        }

        $accepted=[];
        foreach($setups as $setup){
            $prior=end($accepted);
            if($prior && (!$prior['exit_time'] || $setup['signal_time'] <= $prior['exit_time'])){
                $blocked[]=['time'=>$setup['signal_time'],'direction'=>$setup['direction'],'evidence'=>$setup['evidence'],
                    'reason'=>'A prior strategy position was still open.'];
                continue;
            }
            $accepted[]=$setup;
        }
        $setups=$accepted;

        $worked=count(array_filter($setups,fn($s)=>$s['worked']));
        $failed=count(array_filter($setups,fn($s)=>$s['status']==='FAILED'));
        $ambiguous=count(array_filter($setups,fn($s)=>$s['status']==='AMBIGUOUS'));
        $open=count($setups)-$worked-$failed-$ambiguous;
        $resolved=$worked+$failed;
        $success=$resolved?round(100*$worked/$resolved,1):null;
        $expectancy=$resolved?round(array_sum(array_map(fn($s)=>$s['result_r'],$setups))/$resolved,2):null;
        $expected=max(0,intdiv($end-$start,300));
        $pairedCount=count(array_filter(array_keys($paired),fn($time)=>$time >= $start && $time < $end));
        $footprintTimes=array_keys($paired);sort($footprintTimes);
        $coverage=['requested_start'=>$start,'tested_end'=>$end,'expected_bars'=>$expected,
            'broker_bars'=>count($bars),'paired_bars'=>$pairedCount,
            'coverage_pct'=>$expected?round(100*$pairedCount/$expected,1):0,
            'broker_start'=>$bars[0]['time']??null,'paired_start'=>$footprintTimes[0]??null,'retrospective'=>true];
        $summary=['setups'=>count($setups),'worked'=>$worked,'failed'=>$failed,'open'=>$open,
            'ambiguous'=>$ambiguous,'resolved'=>$resolved,'success_rate'=>$success,'expectancy_r'=>$expectancy,
            'blocked_sequences'=>count($blocked),'verdict'=>$this->verdict($resolved,$success,$expectancy)];
        $markers=[];
        foreach($setups as $setup){
            $markers[]=['time'=>$setup['signal_time'],'position'=>$setup['direction']==='BUY'?'belowBar':'aboveBar',
                'color'=>$setup['direction']==='BUY'?'#00c076':'#ff3b5c','shape'=>$setup['direction']==='BUY'?'arrowUp':'arrowDown',
                'text'=>$setup['direction'].' · '.$setup['rule_match'].'% rules'];
            if($setup['exit_time']) $markers[]=['time'=>$setup['exit_time'],'position'=>$setup['direction']==='BUY'?'aboveBar':'belowBar',
                'color'=>$setup['worked']?'#00c076':'#ff3b5c','shape'=>'circle','text'=>$setup['exit_label']];
        }
        usort($markers,fn($a,$b)=>$a['time']<=>$b['time']);
        $potentialSummary=['buy'=>count(array_filter($potentials,fn($row)=>$row['direction']==='BUY')),
            'sell'=>count(array_filter($potentials,fn($row)=>$row['direction']==='SELL')),
            'balanced'=>$pairedCount-count($potentials)];
        $notableFindings=array_values(array_filter($potentials,fn($row)=>
            $row['participation']==='INCREASED'||$row['sequence']!==null
        ));
        return compact('date','start','end','bars','setups','blocked','summary','coverage','markers',
            'potentials','potentialMarkers','potentialSummary','notableFindings');
    }

    /** Shared by the research chart and the live variable-opportunity watcher. */
    public function potential(array $prefix,array $zones,array $h1): ?array
    {
        $bar=end($prefix);if(!$bar)return null;
        $participation=app(MarketParticipationService::class)->analyse($prefix,'5m');
        $flow=$participation['flow']??'BALANCED';
        if(!in_array($flow,['BUY_DOMINANT','SELL_DOMINANT'],true))return null;
        $direction=$flow==='BUY_DOMINANT'?'BUY':'SELL';
        $absorption=app(AbsorptionSequenceService::class)->analyse($prefix,'5m');
        $sequence=($absorption['qualified']??false)?$absorption['direction']:null;
        $zone=$sequence===$direction?$this->zoneAt($zones,$h1,$absorption,$bar['time'])
            :$this->barZoneAt($zones,$h1,$bar,$direction);
        $score=$sequence===$direction?75:($participation['state']==='INCREASED'?25:0);
        if($zone)$score=min(100,$score+25);
        return ['time'=>$bar['time'],'direction'=>$direction,'close'=>(float)$bar['close'],
            'volume'=>(int)(($bar['buy_vol']??0)+($bar['sell_vol']??0)),
            'delta'=>(int)$bar['delta'],'delta_pct'=>round(100*(float)($participation['delta_share']??0),1),
            'participation'=>$participation['state'],
            'volume_ratio'=>$participation['ratio']!==null?round((float)$participation['ratio'],2):null,
            'sequence'=>$sequence,'zone_type'=>$zone['type']??null,'zone'=>$zone,
            'rule_completion'=>$score,'notable'=>$participation['state']==='INCREASED'||$sequence!==null,
            'finding'=>$this->potentialFinding($direction,$participation['state'],$sequence,$zone)];
    }

    public function archive(string $kind,string $date): array
    {
        $instrument=config('clusterdelta.instrument','GC');
        $root=Storage::disk('local')->path("research/clusterdelta/{$instrument}/5m/{$date}/{$kind}");
        if(!is_dir($root)) return [];
        $latest=[];
        foreach(glob($root.'/*.json.gz')?:[] as $file){
            $bytes=file_get_contents($file);$json=$bytes===false?false:gzdecode($bytes);
            $decoded=$json===false?null:json_decode($json,true);
            if(!is_array($decoded)||!isset($decoded['bar']['time'])) continue;
            $bar=$decoded['bar'];
            // Re-normalize archived footprint source timestamps with the current
            // configured source clock. This avoids preserving an earlier offset
            // mistake in research charts and replays.
            if($kind==='footprint'&&isset($bar['source_time']))
                $bar['time']=(int)$bar['source_time']-(int)config('clusterdelta.footprint_utc_offset_minutes',0)*60;
            $time=(int)$bar['time'];$observed=(int)($decoded['first_observed_at']??0);
            if(isset($latest[$time]) && $latest[$time]['observed']>$observed) continue;
            if($kind==='broker'){
                $volume=(int)$bar['volume'];$delta=(int)$bar['delta'];
                $bar['buy_vol']=($volume+$delta)/2;$bar['sell_vol']=($volume-$delta)/2;$bar['closed']=true;
            }else{
                $bar['volume']=(int)$bar['ask']+(int)$bar['bid'];$bar['delta']=(int)$bar['ask']-(int)$bar['bid'];
                $bar['tick_size']=(float)($decoded['metadata']['tick_size']??.1);
            }
            $latest[$time]=['observed'=>$observed,'bar'=>$bar];
        }
        ksort($latest);
        return array_values(array_map(fn($row)=>$row['bar'],$latest));
    }

    private function zoneAt(array $zones,array $h1,array $evidence,int $signalTime): ?array
    {
        $type=$evidence['direction']==='BUY'?'DEMAND':'SUPPLY';
        $eligible=array_values(array_filter($zones,function($zone)use($type,$evidence,$h1,$signalTime){
            if($zone['type']!==$type || $zone['confirmed_time']>$evidence['effort_time']) return false;
            $touch=($evidence['effort_low']<=$zone['high']&&$evidence['effort_high']>=$zone['low'])
                ||($evidence['rejection_low']<=$zone['high']&&$evidence['rejection_high']>=$zone['low']);
            if(!$touch) return false;
            foreach($h1 as $bar){
                if($bar['time']<$zone['confirmed_time']||$bar['time']+3600>$signalTime) continue;
                if($type==='DEMAND'?$bar['close']<$zone['low']:$bar['close']>$zone['high']) return false;
            }
            return true;
        }));
        usort($eligible,fn($a,$b)=>$b['origin_time']<=>$a['origin_time']);
        return $eligible[0]??null;
    }

    private function barZoneAt(array $zones,array $h1,array $bar,string $direction): ?array
    {
        $type=$direction==='BUY'?'DEMAND':'SUPPLY';
        $eligible=array_values(array_filter($zones,function($zone)use($type,$bar,$h1){
            if($zone['type']!==$type||$zone['confirmed_time']>$bar['time']
                ||$bar['low']>$zone['high']||$bar['high']<$zone['low']) return false;
            foreach($h1 as $hour){
                if($hour['time']<$zone['confirmed_time']||$hour['time']+3600>$bar['time']) continue;
                if($type==='DEMAND'?$hour['close']<$zone['low']:$hour['close']>$zone['high']) return false;
            }
            return true;
        }));
        usort($eligible,fn($a,$b)=>$b['origin_time']<=>$a['origin_time']);
        return $eligible[0]??null;
    }

    private function potentialFinding(string $direction,string $participation,?string $sequence,?array $zone): string
    {
        if($sequence===$direction&&$zone) return 'All four zone-absorption rules completed.';
        if($sequence===$direction) return 'Absorption completed, but no pre-existing matching H1 zone was present.';
        if($participation==='INCREASED'&&$zone) return 'Participation increased at an H1 zone, but the M5 rejection sequence did not complete.';
        if($participation==='INCREASED') return 'Strong volume and directional delta, but no completed absorption sequence at a matching H1 zone.';
        return ucfirst(strtolower($participation)).' participation and directional delta only; no confirmed absorption sequence.';
    }

    private function levels(array $evidence,array $zone,array $entryBar): array
    {
        $direction=$evidence['direction'];$entry=round((float)$entryBar['open'],2);
        $buffer=(float)config('orderflow.sl_buffer_pips',15)/10;
        if($direction==='BUY'){
            $stop=round(min($zone['low'],$evidence['rejection_low'])-$buffer,2);$risk=$entry-$stop;
            return ['direction'=>$direction,'entry_time'=>$entryBar['time'],'entry'=>$entry,'stop'=>$stop,
                'tp1'=>round($entry+$risk*1.5,2),'tp2'=>round($entry+$risk*3,2),'risk'=>$risk];
        }
        $stop=round(max($zone['high'],$evidence['rejection_high'])+$buffer,2);$risk=$stop-$entry;
        return ['direction'=>$direction,'entry_time'=>$entryBar['time'],'entry'=>$entry,'stop'=>$stop,
            'tp1'=>round($entry-$risk*1.5,2),'tp2'=>round($entry-$risk*3,2),'risk'=>$risk];
    }

    private function outcome(array $setup,array $bars): array
    {
        $tp1=false;
        foreach($bars as $bar){
            $stop=$setup['direction']==='BUY'?$bar['low']<=$setup['stop']:$bar['high']>=$setup['stop'];
            $one=$setup['direction']==='BUY'?$bar['high']>=$setup['tp1']:$bar['low']<=$setup['tp1'];
            $two=$setup['direction']==='BUY'?$bar['high']>=$setup['tp2']:$bar['low']<=$setup['tp2'];
            if($stop && ($one||$two)) return ['status'=>'AMBIGUOUS','worked'=>false,'result_r'=>0,
                'exit_time'=>$bar['time'],'exit_price'=>null,'exit_label'=>'SL/TP order unknown','tp1_hit'=>$tp1];
            if($stop) return ['status'=>$tp1?'WORKED_TP1_THEN_SL':'FAILED','worked'=>$tp1,
                'result_r'=>$tp1?.25:-1.0,'exit_time'=>$bar['time'],'exit_price'=>$setup['stop'],
                'exit_label'=>$tp1?'TP1 then SL · +0.25R':'SL · -1R','tp1_hit'=>$tp1];
            if($two) return ['status'=>'WORKED_TP2','worked'=>true,'result_r'=>2.25,
                'exit_time'=>$bar['time'],'exit_price'=>$setup['tp2'],'exit_label'=>'TP2 · +2.25R','tp1_hit'=>true];
            if($one) $tp1=true;
        }
        return ['status'=>$tp1?'OPEN_AFTER_TP1':'OPEN','worked'=>$tp1,'result_r'=>$tp1?.75:0,
            'exit_time'=>null,'exit_price'=>null,'exit_label'=>null,'tp1_hit'=>$tp1];
    }

    private function verdict(int $resolved,?float $success,?float $expectancy): string
    {
        if($resolved<20) return 'Insufficient sample: fewer than 20 resolved setups.';
        if($expectancy!==null&&$expectancy>0&&$success!==null&&$success>=40) return 'Positive in this sample; prospective validation is still required.';
        return 'No positive edge demonstrated in this sample.';
    }
}
