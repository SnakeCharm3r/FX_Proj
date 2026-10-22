<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ScalpConfluenceDryRunService
{
    public function run(string $from,string $to): array
    {
        $start=CarbonImmutable::parse($from.' 00:00:00','UTC')->timestamp;
        $end=CarbonImmutable::parse($to.' 00:00:00','UTC')->addDay()->timestamp;
        $loadStart=CarbonImmutable::createFromTimestampUTC($start)->subDays(4);
        $bars=[];
        for($day=$loadStart;$day->timestamp<$end;$day=$day->addDay()){
            $bars=array_merge($bars,$this->archiveDay($day->format('Y-m-d')));
        }
        $latest=[];
        foreach($bars as $bar) $latest[$bar['time']]=$bar;
        ksort($latest);$bars=array_values($latest);
        $h1=app(HourlyPriceHistoryService::class)->bars(500);
        $ranges=$this->ranges($h1,$end);
        return $this->analyse($bars,$h1,$ranges,$start,$end,$from,$to);
    }

    public function analyse(array $bars,array $h1,array $ranges,int $start,int $end,string $from='',string $to=''): array
    {
        usort($bars,fn($a,$b)=>$a['time']<=>$b['time']);
        $candidates=[];
        foreach($bars as $index=>$bar){
            if($bar['time']<$start||$bar['time']>=$end) continue;
            $prefix=array_slice($bars,0,$index+1);
            $participation=app(MarketParticipationService::class)->analyse($prefix,'5m');
            $volume=(float)$bar['buy_vol']+(float)$bar['sell_vol'];
            $deltaShare=$volume>0?(float)$bar['delta']/$volume:0;
            $absoluteDeltaShare=abs($deltaShare);
            if($absoluteDeltaShare<(float)config('orderflow.scalp_min_delta_share',.05)) continue;
            $direction=$deltaShare>0?'BUY':'SELL';
            $zone=$this->rangeAt($ranges,$bar,$direction);
            $increased=$participation['state']==='INCREASED';
            $confirmed=$this->priceConfirmed($bar,$direction);
            $aligned=$this->priceAligned($bar,$direction);
            $points=[
                'h1_range'=>$zone?25:0,
                'participation'=>$increased?25:($participation['state']==='NORMAL'?10:0),
                'directional_delta'=>$absoluteDeltaShare>=(float)config('orderflow.scalp_delta_share',.10)?25:15,
                'price_confirmation'=>$confirmed?25:($aligned?10:0),
            ];
            $score=array_sum($points);
            $components=['h1_range'=>(bool)$zone,'increased_participation'=>$increased,
                'directional_delta'=>$absoluteDeltaShare>=(float)config('orderflow.scalp_delta_share',.10),
                'price_confirmation'=>$confirmed,'price_aligned'=>$aligned,'points'=>$points];
            if($score<(int)config('orderflow.scalp_min_confluence',75)) continue;
            $next=$bars[$index+1]??null;
            if(!$next||$next['time']!==$bar['time']+300||$next['time']>=$end) continue;
            $setup=$this->levels($direction,$bar,$next,$zone,$this->atr($bars,$index));
            if(!$setup) continue;
            $setup+=['signal_time'=>$bar['time'],'score'=>$score,'delta_pct'=>round(100*$deltaShare,1),
                'volume'=>(int)$volume,'volume_ratio'=>$participation['ratio'],
                'participation'=>$participation['state'],'components'=>$components,'zone'=>$zone,
                'confidence_tier'=>$score>=75?'HIGH':'EXPANDED'];
            $setup=array_merge($setup,$this->outcome($setup,array_slice($bars,$index+1)));
            $candidates[]=$setup;
        }

        $setups=[];$skipped=0;
        foreach($candidates as $setup){
            $prior=end($setups);
            if($prior&&(!$prior['exit_time']||$setup['signal_time']<=$prior['exit_time'])){$skipped++;continue;}
            $setups[]=$setup;
        }
        $worked=count(array_filter($setups,fn($s)=>in_array($s['status'],['WORKED_TP2','WORKED_TP1_THEN_SL'],true)));
        $failed=count(array_filter($setups,fn($s)=>$s['status']==='FAILED'));
        $ambiguous=count(array_filter($setups,fn($s)=>$s['status']==='AMBIGUOUS'));
        $open=count(array_filter($setups,fn($s)=>in_array($s['status'],['OPEN','OPEN_AFTER_TP1'],true)));
        $resolved=$worked+$failed;
        $resolvedSetups=array_values(array_filter($setups,fn($s)=>
            in_array($s['status'],['WORKED_TP2','WORKED_TP1_THEN_SL','FAILED'],true)
        ));
        $resultR=array_sum(array_map(fn($s)=>$s['result_r'],$resolvedSetups));
        $summary=['setups'=>count($setups),'buy'=>count(array_filter($setups,fn($s)=>$s['direction']==='BUY')),
            'sell'=>count(array_filter($setups,fn($s)=>$s['direction']==='SELL')),'worked'=>$worked,
            'failed'=>$failed,'open'=>$open,'ambiguous'=>$ambiguous,'resolved'=>$resolved,
            'success_rate'=>$resolved?round(100*$worked/$resolved,1):null,
            'net_r'=>round($resultR,2),'expectancy_r'=>$resolved?round($resultR/$resolved,2):null,
            'overlap_skipped'=>$skipped];
        $outcomeCounts=[
            'loss'=>count(array_filter($setups,fn($s)=>$s['status']==='FAILED')),
            'tp1_only'=>count(array_filter($setups,fn($s)=>in_array($s['status'],['WORKED_TP1_THEN_SL','OPEN_AFTER_TP1'],true))),
            'tp2'=>count(array_filter($setups,fn($s)=>$s['status']==='WORKED_TP2')),
            'open'=>count(array_filter($setups,fn($s)=>$s['status']==='OPEN')),
            'ambiguous'=>count(array_filter($setups,fn($s)=>$s['status']==='AMBIGUOUS')),
        ];
        $outcomeCounts['total']=count($setups);
        $breakdowns=[
            'confluence'=>$this->breakdown($setups,fn($setup)=>$setup['score'].'%'),
            'tier'=>$this->breakdown($setups,fn($setup)=>$setup['confidence_tier']==='HIGH'?'75–100% high':'60–74% expanded'),
            'direction'=>$this->breakdown($setups,fn($setup)=>$setup['direction']),
            'day'=>$this->breakdown($setups,fn($setup)=>
                CarbonImmutable::createFromTimestampUTC($setup['signal_time'])
                    ->setTimezone('Africa/Dar_es_Salaam')->format('D d M')
            ),
        ];
        $periodBars=array_values(array_filter($bars,fn($bar)=>$bar['time']>=$start&&$bar['time']<$end));
        $capital=$this->capitalSimulation($setups,$periodBars);
        $diagnostics=$this->tradeDiagnostics($setups);
        $coverage=['bars'=>count($periodBars),'first'=>$periodBars[0]['time']??null,
            'last'=>$periodBars?end($periodBars)['time']:null,'available'=>count($periodBars)>=20,
            'reason'=>count($periodBars)>=20?'Historical M5 volume/delta is available for replay.'
                :'Historical M5 volume/delta is missing for this period; no performance claim can be calculated.'];
        $markers=[];
        foreach($setups as $setup){
            $markers[]=['time'=>$setup['signal_time'],'position'=>$setup['direction']==='BUY'?'belowBar':'aboveBar',
                'color'=>$setup['direction']==='BUY'?'#00c076':'#ff3b5c',
                'shape'=>$setup['direction']==='BUY'?'arrowUp':'arrowDown',
                'text'=>$setup['direction'].' '.$setup['score'].'% · Δ'.sprintf('%+.1f%%',$setup['delta_pct'])];
            if($setup['exit_time'])$markers[]=['time'=>$setup['exit_time'],
                'position'=>$setup['direction']==='BUY'?'aboveBar':'belowBar',
                'color'=>$setup['worked']?'#00c076':'#ff3b5c','shape'=>'circle','text'=>$setup['exit_label']];
        }
        usort($markers,fn($a,$b)=>$a['time']<=>$b['time']);
        $visibleRanges=array_values(array_filter($ranges,fn($zone)=>
            $zone['confirmed_time']<$end&&(!$zone['invalidated_time']||$zone['invalidated_time']>$start)
        ));
        return ['from'=>$from,'to'=>$to,'start'=>$start,'end'=>$end,'bars'=>$periodBars,'ranges'=>$visibleRanges,
            'setups'=>$setups,'summary'=>$summary,'outcome_counts'=>$outcomeCounts,
            'capital'=>$capital,'diagnostics'=>$diagnostics,'coverage'=>$coverage,
            'markers'=>$markers,'breakdowns'=>$breakdowns];
    }

    private function tradeDiagnostics(array $setups): array
    {
        $worst=array_values(array_filter($setups,fn($setup)=>$setup['account_pnl']<0));
        usort($worst,fn($a,$b)=>$a['account_pnl']<=>$b['account_pnl']);
        $profitable=array_values(array_filter($setups,fn($setup)=>$setup['account_pnl']>0));
        usort($profitable,fn($a,$b)=>$b['account_pnl']<=>$a['account_pnl']);
        foreach($worst as &$setup)$setup['diagnostic_tags']=$this->diagnosticTags($setup);
        unset($setup);
        foreach($profitable as &$setup)$setup['diagnostic_tags']=$this->diagnosticTags($setup);
        unset($setup);

        $lossTotal=abs(array_sum(array_column($worst,'account_pnl')));
        $topThreeLoss=abs(array_sum(array_column(array_slice($worst,0,3),'account_pnl')));
        $riskAudits=[];
        foreach([5.0,7.5] as $cap){
            $items=array_values(array_filter($setups,fn($setup)=>$setup['risk']<=$cap));
            $worked=count(array_filter($items,fn($setup)=>in_array($setup['status'],['WORKED_TP2','WORKED_TP1_THEN_SL'],true)));
            $failed=count(array_filter($items,fn($setup)=>$setup['status']==='FAILED'));
            $resolved=$worked+$failed;
            $riskAudits[]=['cap'=>$cap,'setups'=>count($items),'worked'=>$worked,'failed'=>$failed,
                'open'=>count($items)-$resolved,'success_rate'=>$resolved?round(100*$worked/$resolved,1):null,
                'pnl'=>round(array_sum(array_column($items,'account_pnl')),2),
                'result_r'=>round(array_sum(array_column($items,'result_r')),2)];
        }
        return ['worst'=>$worst,'profitable'=>$profitable,'risk_audits'=>$riskAudits,
            'gross_loss'=>round($lossTotal,2),'top_three_loss'=>round($topThreeLoss,2),
            'top_three_share'=>$lossTotal>0?round(100*$topThreeLoss/$lossTotal,1):null];
    }

    private function diagnosticTags(array $setup): array
    {
        $tags=[];
        if($setup['risk']>7.5)$tags[]='wide stop >7.5';
        if(!$setup['zone'])$tags[]='no H1 zone';
        if(abs($setup['delta_pct'])<10)$tags[]='partial delta';
        if($setup['participation']!=='INCREASED')$tags[]='volume not increased';
        if(!$setup['components']['price_confirmation'])$tags[]='partial/no price confirmation';
        return $tags?:['all four reasons full'];
    }

    private function capitalSimulation(array &$setups,array $periodBars): array
    {
        $starting=(float)config('orderflow.scalp_test_capital',100);
        $lot=(float)config('orderflow.scalp_test_lot',.01);
        $contract=(float)config('orderflow.xau_contract_size',100);
        $dollarsPerPrice=$lot*$contract;
        $balance=$starting;$peak=$starting;$lowest=$starting;$maxDrawdown=0;$maxDrawdownPct=0;$maxRiskPct=0;
        foreach($setups as &$setup){
            $before=$balance;
            $riskDollars=(float)$setup['risk']*$dollarsPerPrice;
            $pnl=(float)$setup['result_r']*$riskDollars;
            $balance+=$pnl;
            $peak=max($peak,$balance);$lowest=min($lowest,$balance);
            $drawdown=$peak-$balance;
            $maxDrawdown=max($maxDrawdown,$drawdown);
            $maxDrawdownPct=max($maxDrawdownPct,$peak>0?100*$drawdown/$peak:0);
            $maxRiskPct=max($maxRiskPct,$before>0?100*$riskDollars/$before:INF);
            $setup['account_balance_before']=round($before,2);
            $setup['account_risk_dollars']=round($riskDollars,2);
            $setup['account_pnl']=round($pnl,2);
            $setup['account_balance_after']=round($balance,2);
        }
        unset($setup);
        $lastClose=$periodBars?(float)end($periodBars)['close']:null;
        $floating=0;
        if($lastClose!==null){
            foreach($setups as $setup){
                if($setup['exit_time']!==null)continue;
                $remaining=$setup['status']==='OPEN_AFTER_TP1'?.5:1;
                $move=$setup['direction']==='BUY'?$lastClose-$setup['entry']:$setup['entry']-$lastClose;
                $floating+=$move*$dollarsPerPrice*$remaining;
            }
        }
        $equity=$balance+$floating;
        return ['starting_balance'=>round($starting,2),'lot'=>$lot,'contract_size'=>$contract,
            'dollars_per_price'=>round($dollarsPerPrice,2),'ending_balance'=>round($balance,2),
            'floating_pnl'=>round($floating,2),'ending_equity'=>round($equity,2),
            'return_pct'=>$starting>0?round(100*($equity-$starting)/$starting,1):null,
            'lowest_balance'=>round($lowest,2),'peak_balance'=>round($peak,2),
            'max_drawdown'=>round($maxDrawdown,2),'max_drawdown_pct'=>round($maxDrawdownPct,1),
            'max_risk_pct'=>is_finite($maxRiskPct)?round($maxRiskPct,1):null,
            'survived_before_costs'=>$lowest>0];
    }

    private function archiveDay(string $date): array
    {
        $root=Storage::disk('local')->path("research/clusterdelta/GC/5m/{$date}/broker");
        if(!is_dir($root)) return [];
        $latest=[];
        foreach(glob($root.'/*.json.gz')?:[] as $file){
            $json=gzdecode((string)file_get_contents($file));$decoded=$json===false?null:json_decode($json,true);
            if(!is_array($decoded)||!isset($decoded['bar']['time'])) continue;
            $bar=$decoded['bar'];$time=(int)$bar['time'];$observed=(int)($decoded['first_observed_at']??0);
            if(isset($latest[$time])&&$latest[$time]['observed']>$observed)continue;
            $volume=(int)$bar['volume'];$delta=(int)$bar['delta'];
            $bar['buy_vol']=($volume+$delta)/2;$bar['sell_vol']=($volume-$delta)/2;$bar['closed']=true;
            $latest[$time]=['observed'=>$observed,'bar'=>$bar];
        }
        ksort($latest);
        return array_values(array_map(fn($row)=>$row['bar'],$latest));
    }

    /** Broad, chronologically confirmed H1 swing ranges: rejection wick to candle body. */
    private function ranges(array $h1,int $end): array
    {
        $bars=array_values(array_filter($h1,fn($bar)=>($bar['closed']??false)&&$bar['time']+3600<=$end));
        usort($bars,fn($a,$b)=>$a['time']<=>$b['time']);$ranges=[];
        foreach(app(RejectionRangeDetector::class)->detect($bars,$end) as $zone){
            $ranges[]=$this->rangeRow($zone['type'],$zone['low'],$zone['high'],
                $zone['origin_time'],$zone['confirmed_time'],$bars);
        }
        return $ranges;
    }

    private function rangeRow(string $type,float $low,float $high,int $origin,int $confirmed,array $bars): array
    {
        $invalidated=null;
        foreach($bars as $bar){
            if($bar['time']<$confirmed)continue;
            if($type==='DEMAND'?$bar['close']<$low:$bar['close']>$high){$invalidated=$bar['time']+3600;break;}
        }
        return ['type'=>$type,'low'=>$low,'high'=>$high,'origin_time'=>$origin,
            'confirmed_time'=>$confirmed,'invalidated_time'=>$invalidated];
    }

    private function rangeAt(array $ranges,array $bar,string $direction): ?array
    {
        $type=$direction==='BUY'?'DEMAND':'SUPPLY';
        $eligible=array_values(array_filter($ranges,fn($zone)=>$zone['type']===$type
            &&$zone['confirmed_time']<=$bar['time']
            &&(!$zone['invalidated_time']||$zone['invalidated_time']>$bar['time'])
            &&$bar['low']<=$zone['high']&&$bar['high']>=$zone['low']));
        usort($eligible,fn($a,$b)=>$b['origin_time']<=>$a['origin_time']);
        return $eligible[0]??null;
    }

    private function priceConfirmed(array $bar,string $direction): bool
    {
        $range=$bar['high']-$bar['low'];if($range<=0)return false;
        $location=($bar['close']-$bar['low'])/$range;
        return $direction==='BUY'
            ?$bar['close']>$bar['open']&&$location>=.65
            :$bar['close']<$bar['open']&&$location<=.35;
    }

    private function priceAligned(array $bar,string $direction): bool
    {
        return $direction==='BUY'?$bar['close']>$bar['open']:$bar['close']<$bar['open'];
    }

    private function atr(array $bars,int $index): float
    {
        $values=[];
        for($i=max(1,$index-14);$i<$index;$i++){
            $values[]=max($bars[$i]['high']-$bars[$i]['low'],
                abs($bars[$i]['high']-$bars[$i-1]['close']),abs($bars[$i]['low']-$bars[$i-1]['close']));
        }
        return $values?array_sum($values)/count($values):0;
    }

    private function levels(string $direction,array $signal,array $entryBar,?array $zone,float $atr): ?array
    {
        $entry=round((float)$entryBar['open'],2);
        $buffer=max((float)config('orderflow.sl_buffer_pips',15)/10,.1*$atr);
        if($direction==='BUY'){
            $stop=round(min($signal['low'],$zone['low']??$signal['low'])-$buffer,2);$risk=$entry-$stop;
            if($risk<=0)return null;
            return ['direction'=>$direction,'entry_time'=>$entryBar['time'],'entry'=>$entry,'stop'=>$stop,
                'tp1'=>round($entry+1.5*$risk,2),'tp2'=>round($entry+3*$risk,2),'risk'=>$risk];
        }
        $stop=round(max($signal['high'],$zone['high']??$signal['high'])+$buffer,2);$risk=$stop-$entry;
        if($risk<=0)return null;
        return ['direction'=>$direction,'entry_time'=>$entryBar['time'],'entry'=>$entry,'stop'=>$stop,
            'tp1'=>round($entry-1.5*$risk,2),'tp2'=>round($entry-3*$risk,2),'risk'=>$risk];
    }

    private function outcome(array $setup,array $bars): array
    {
        $tp1=false;$tp1Time=null;
        foreach($bars as $bar){
            $stop=$setup['direction']==='BUY'?$bar['low']<=$setup['stop']:$bar['high']>=$setup['stop'];
            $one=$setup['direction']==='BUY'?$bar['high']>=$setup['tp1']:$bar['low']<=$setup['tp1'];
            $two=$setup['direction']==='BUY'?$bar['high']>=$setup['tp2']:$bar['low']<=$setup['tp2'];
            if($stop&&($one||$two))return ['status'=>'AMBIGUOUS','worked'=>false,'result_r'=>0,
                'exit_time'=>$bar['time'],'exit_price'=>null,'exit_label'=>'SL/TP order unknown',
                'tp1_hit'=>$tp1,'tp1_time'=>$tp1Time,'outcome_bucket'=>'AMBIGUOUS'];
            if($stop)return ['status'=>$tp1?'WORKED_TP1_THEN_SL':'FAILED','worked'=>$tp1,
                'result_r'=>$tp1?.25:-1.0,'exit_time'=>$bar['time'],'exit_price'=>$setup['stop'],
                'exit_label'=>$tp1?'TP1 then SL · +0.25R':'SL · -1R','tp1_hit'=>$tp1,
                'tp1_time'=>$tp1Time,'outcome_bucket'=>$tp1?'TP1 · 50% CLOSED':'LOSS'];
            if($two)return ['status'=>'WORKED_TP2','worked'=>true,'result_r'=>2.25,
                'exit_time'=>$bar['time'],'exit_price'=>$setup['tp2'],'exit_label'=>'TP2 · +2.25R','tp1_hit'=>true,
                'tp1_time'=>$tp1Time??$bar['time'],'outcome_bucket'=>'TP2 · 100% CLOSED'];
            if($one&&!$tp1){$tp1=true;$tp1Time=$bar['time'];}
        }
        return ['status'=>$tp1?'OPEN_AFTER_TP1':'OPEN','worked'=>$tp1,'result_r'=>$tp1?.75:0,
            'exit_time'=>null,'exit_price'=>null,'exit_label'=>null,'tp1_hit'=>$tp1,'tp1_time'=>$tp1Time,
            'outcome_bucket'=>$tp1?'TP1 · 50% CLOSED':'OPEN'];
    }

    private function breakdown(array $setups,callable $key): array
    {
        $groups=[];
        foreach($setups as $setup)$groups[$key($setup)][]=$setup;
        $rows=[];
        foreach($groups as $label=>$items){
            $worked=count(array_filter($items,fn($s)=>in_array($s['status'],['WORKED_TP2','WORKED_TP1_THEN_SL'],true)));
            $failed=count(array_filter($items,fn($s)=>$s['status']==='FAILED'));
            $open=count(array_filter($items,fn($s)=>in_array($s['status'],['OPEN','OPEN_AFTER_TP1'],true)));
            $resolved=$worked+$failed;
            $net=array_sum(array_map(fn($s)=>$s['result_r'],array_filter($items,fn($s)=>
                in_array($s['status'],['WORKED_TP2','WORKED_TP1_THEN_SL','FAILED'],true)
            )));
            $rows[]=['label'=>$label,'setups'=>count($items),'worked'=>$worked,'failed'=>$failed,'open'=>$open,
                'success_rate'=>$resolved?round(100*$worked/$resolved,1):null,'net_r'=>round($net,2)];
        }
        return $rows;
    }
}
