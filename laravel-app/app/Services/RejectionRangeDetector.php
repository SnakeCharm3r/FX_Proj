<?php

namespace App\Services;

class RejectionRangeDetector
{
    /** A wick at a confirmed swing, regardless of the origin candle's colour. */
    public function detect(array $bars, int $decisionTime): array
    {
        $bars=array_values(array_filter($bars,fn($b)=>($b['closed']??false)&&$b['time']+3600<=$decisionTime));
        usort($bars,fn($a,$b)=>$a['time']<=>$b['time']);
        $span=(int)config('price_zones.swing_bars',2);$zones=[];
        for($i=$span;$i<count($bars)-$span;$i++){
            $origin=$bars[$i];$range=$origin['high']-$origin['low'];if($range<=0)continue;
            $window=array_slice($bars,$i-$span,2*$span+1);
            // Unseen hours cannot confirm a swing. Broker session gaps are conservative exclusions.
            if(end($window)['time']-$window[0]['time']!==2*$span*3600)continue;
            $neighbors=array_merge(array_slice($bars,$i-$span,$span),array_slice($bars,$i+1,$span));
            $previous=array_slice($bars,max(0,$i-14),min(14,$i));
            $atr=$previous?array_sum(array_map(fn($b)=>$b['high']-$b['low'],$previous))/count($previous):$range;
            foreach(['DEMAND','SUPPLY'] as $type){
                $demand=$type==='DEMAND';
                $low=$demand?$origin['low']:max($origin['open'],$origin['close']);
                $high=$demand?min($origin['open'],$origin['close']):$origin['high'];
                $extreme=$demand?$origin['low']<min(array_column($neighbors,'low'))
                    :$origin['high']>max(array_column($neighbors,'high'));
                if(!$extreme||($high-$low)/$range<config('price_zones.rejection_wick_ratio',.2))continue;
                $zones[]=['type'=>$type,'timeframe'=>'1h','low'=>$low,'high'=>$high,'atr'=>$atr,
                    'origin_time'=>$origin['time'],'confirmed_time'=>$bars[$i+$span]['time']+3600,
                    'metadata'=>['origin'=>$origin,'confirmation'=>$bars[$i+$span],
                        'bounds'=>'rejection_wick_to_body','swing_bars'=>$span,
                        'wick_ratio'=>($high-$low)/$range,'origin_colour_required'=>false]];
            }
        }
        return $zones;
    }
}
