<?php

namespace App\Services;

class H1TrendRangeStrategyService
{
    public function __construct(
        private ZoneAbsorptionStrategyService $zones,
        private H1TrendService $trend,
    ) {}

    public function assess(array $metrics): array
    {
        $base=['qualified'=>false,'strategy_version'=>'h1_trend_range_v3','stage'=>'waiting',
            'direction'=>'NEUTRAL','rule_score'=>0,'signals'=>[]];
        if(($metrics['timeframe']??'')!=='5m') return $base+['reason'=>'h1_trend_range_v3 creates entries on completed M5 candles only.'];
        $trend=$this->trend->assess((int)$metrics['bar_time']+300);
        if(!($trend['qualified']??false)) return array_merge($base,['stage'=>'waiting_for_h1_trend',
            'h1_trend'=>$trend,'signals'=>$this->waitingSignals($trend), 'reason'=>$trend['reason']]);
        $zone=$this->zones->assess($metrics);
        if(!($zone['qualified']??false)) {
            $signals=$this->waitingSignals($trend,$zone);
            $absorptionReady=in_array($zone['stage']??'', ['waiting_for_zone','zone_unverified'], true);
            return array_merge($base,['stage'=>$zone['stage']??'waiting_for_range',
                'direction'=>$zone['direction']??'NEUTRAL','h1_trend'=>$trend,'signals'=>$signals,
                'rule_score'=>20+($absorptionReady?30:0),'reason'=>$zone['reason']]);
        }

        $direction=$zone['direction'];$expected=$direction==='BUY'?'BULLISH':'BEARISH';
        $expectedDivergence=$expected.'_DIVERGENCE';
        $mode=$direction===$trend['direction']?'CONTINUATION':'REVERSAL';
        $footprint=($metrics['stacked_imbalance']??'NONE')===$expected
            ||($metrics['poc_rejection']??'NONE')===$expected;
        $cvd=$mode==='REVERSAL'
            ?($metrics['cvd_divergence']??'NONE')===$expectedDivergence
            :($direction==='BUY'?(float)($metrics['cvd']??0)>0:(float)($metrics['cvd']??0)<0);
        $totalVolume=(float)($metrics['buy_volume']??0)+(float)($metrics['sell_volume']??0);
        $deltaPercentage=$totalVolume>0?round(100*(float)($metrics['delta']??0)/$totalVolume,1):0.0;

        $signals=[
            $this->signal('h1_trend','H1 trend',20,$trend['direction']==='BUY'?'bullish':'bearish',$trend['reason']),
            $this->signal('h1_range','H1 range retest',20,$direction==='BUY'?'bullish':'bearish',
                sprintf('%s zone #%d %.2f–%.2f.',$direction==='BUY'?'Demand':'Supply',$zone['price_zone_id'],$zone['zone_low'],$zone['zone_high'])),
            $this->signal('absorption','M5 absorption sequence',30,$direction==='BUY'?'bullish':'bearish',
                $zone['reason'],$deltaPercentage),
            $this->signal('cvd_confirmation','CVD confirmation',15,$cvd?($direction==='BUY'?'bullish':'bearish'):'neutral',
                $mode==='REVERSAL'?'Countertrend entry requires matching CVD divergence.':'Trend entry requires aligned rolling CVD.'),
            $this->signal('footprint_confirmation','Native footprint confirmation',15,$footprint?($direction==='BUY'?'bullish':'bearish'):'neutral',
                'Requires a matching native stacked imbalance or previous-POC rejection.'),
        ];
        $signals[2]['total_volume']=$totalVolume;
        if(!$cvd||!$footprint) return array_merge($base,$zone,['strategy_version'=>'h1_trend_range_v3',
            'qualified'=>false,'stage'=>'waiting_for_orderflow_confluence','direction'=>$direction,'mode'=>$mode,'h1_trend'=>$trend,
            'signals'=>$signals,'rule_score'=>70+($cvd?15:0)+($footprint?15:0),
            'reason'=>sprintf('%s range reaction found; waiting for%s%s confirmation.',strtolower($mode),
                $cvd?'':' CVD',$footprint?'':' footprint')]);

        $risk=$this->riskDistance($metrics,$zone,$direction);
        $maximum=(float)config('orderflow.live_max_stop_points',5.0);
        if($risk>$maximum) return array_merge($base,$zone,['strategy_version'=>'h1_trend_range_v3',
            'qualified'=>false,'stage'=>'risk_too_wide','direction'=>$direction,'mode'=>$mode,'h1_trend'=>$trend,
            'signals'=>$signals,'rule_score'=>100,'risk_points'=>round($risk,2),
            'reason'=>sprintf('All entry evidence aligned, but %.2f-point stop exceeds the %.2f-point capital gate. No alert.',$risk,$maximum)]);

        return array_merge($base,$zone,['qualified'=>true,'strategy_version'=>'h1_trend_range_v3',
            'stage'=>'confirmed','direction'=>$direction,'mode'=>$mode,'h1_trend'=>$trend,
            'signals'=>$signals,'rule_score'=>100,'risk_points'=>round($risk,2),
            'reason'=>sprintf('%s %s: H1 trend/range, M5 absorption, CVD and native footprint confirmed.',
                $mode,$direction)]);
    }

    private function riskDistance(array $metrics,array $zone,string $direction): float
    {
        $buffer=(float)config('orderflow.sl_buffer_pips',15)/10;
        if($direction==='BUY') return (float)$metrics['price']
            -(min((float)$metrics['low'],(float)$zone['zone_low'],(float)$zone['rejection_low'])-$buffer);
        return max((float)$metrics['high'],(float)$zone['zone_high'],(float)$zone['rejection_high'])+$buffer
            -(float)$metrics['price'];
    }

    private function signal(string $key,string $name,int $weight,string $status,string $detail,mixed $rawValue=null): array
    {
        return compact('key','name','weight','status','detail')+['max_weight'=>$weight,'available'=>true,'raw_value'=>$rawValue];
    }

    private function waitingSignals(array $trend,array $zone=[]): array
    {
        $trendStatus=($trend['direction']??'NEUTRAL')==='BUY'?'bullish'
            :(($trend['direction']??'NEUTRAL')==='SELL'?'bearish':'neutral');
        $direction=$zone['direction']??'NEUTRAL';
        $directionStatus=$direction==='BUY'?'bullish':($direction==='SELL'?'bearish':'neutral');
        $absorptionReady=in_array($zone['stage']??'', ['waiting_for_zone','zone_unverified'], true);
        return [
            $this->signal('h1_trend','H1 trend',20,$trendStatus,$trend['reason']??'Waiting for H1 trend.'),
            $this->signal('h1_range','H1 range retest',20,'neutral',
                $absorptionReady?'Absorption formed away from a valid matching H1 range.':'Waiting for a valid H1 demand/supply range reaction.'),
            $this->signal('absorption','M5 absorption sequence',30,$absorptionReady?$directionStatus:'neutral',
                $zone['reason']??'Waiting for failed effort, rejection and opposite-delta break.'),
            $this->signal('cvd_confirmation','CVD confirmation',15,'neutral','Evaluated after the range absorption direction is confirmed.'),
            $this->signal('footprint_confirmation','Native footprint confirmation',15,'neutral','Evaluated after the range absorption direction is confirmed.'),
        ];
    }
}
