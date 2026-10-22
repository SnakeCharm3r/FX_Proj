<?php

namespace App\Services;

class H1TrendService
{
    /** Uses only H1 candles that were closed at the M5 decision time. */
    public function assess(int $decisionTime, ?array $bars = null): array
    {
        $bars ??= app(HourlyPriceHistoryService::class)->bars(500);
        $known = array_values(array_filter($bars, fn ($bar) => ($bar['closed'] ?? false)
            && (int) $bar['time'] + 3600 <= $decisionTime));
        usort($known, fn ($a, $b) => $a['time'] <=> $b['time']);
        if (count($known) < 20) return ['direction'=>'NEUTRAL','qualified'=>false,
            'reason'=>'Need 20 completed H1 candles to establish trend.','bars'=>count($known)];

        $window=array_slice($known,-20);$recent=array_slice($window,-5);$prior=array_slice($window,-10,5);
        $sma20=array_sum(array_column($window,'close'))/20;
        $recentMean=array_sum(array_column($recent,'close'))/5;
        $priorMean=array_sum(array_column($prior,'close'))/5;
        $latest=(float)end($window)['close'];
        $direction=$latest>$sma20&&$recentMean>$priorMean?'BUY'
            :($latest<$sma20&&$recentMean<$priorMean?'SELL':'NEUTRAL');
        return ['direction'=>$direction,'qualified'=>$direction!=='NEUTRAL','latest_close'=>$latest,
            'sma20'=>round($sma20,3),'recent_mean'=>round($recentMean,3),'prior_mean'=>round($priorMean,3),
            'bars'=>count($known),'last_h1_time'=>(int)end($window)['time'],
            'reason'=>$direction==='NEUTRAL'
                ?'H1 close and five-bar direction are not aligned around the 20-bar mean.'
                :"H1 {$direction} trend: latest close and five-bar mean align beyond the 20-bar mean."];
    }
}
