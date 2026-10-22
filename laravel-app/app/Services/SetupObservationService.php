<?php

namespace App\Services;

use App\Models\Setup;
use App\Models\SetupObservation;

class SetupObservationService
{
    /** Called only after the engine validates completed candles and matching footprint inputs. */
    public function record(array $metrics, array $assessment): ?SetupObservation
    {
        $floor=(int)config('orderflow.history_min_score',45);
        $gate=$metrics['zone_retest']??null;
        $dedicated=($gate['qualified']??false)
            && in_array($gate['strategy_version']??'',config('orderflow.dedicated_strategies',[]),true);
        if (!$dedicated && $assessment['score'] < $floor) return null;
        $direction=$dedicated?$gate['direction']:$assessment['direction'];
        $levels=in_array($direction,['BUY','SELL'],true)
            ? app(SetupBuilderService::class)->referenceLevels($metrics,$direction) : [null,null,null,null];
        return SetupObservation::firstOrCreate([
            'source'=>$metrics['source'],'instrument'=>$metrics['instrument'],
            'timeframe'=>$metrics['timeframe'],'candle_time'=>$metrics['bar_time'],
        ],[
            'direction'=>$direction,'score'=>$assessment['score'],
            'bull_score'=>$assessment['bull_score'],'bear_score'=>$assessment['bear_score'],
            'history_floor'=>$floor,'signal_threshold'=>$assessment['threshold'],
            'entry'=>$levels[0],'stop_loss'=>$levels[1],'tp1'=>$levels[2],'tp2'=>$levels[3],
            'confluence_detail'=>$assessment['signals'],'metrics'=>$metrics,'origin'=>'live_evaluation',
            'strategy_version'=>$metrics['zone_retest']['strategy_version'] ?? 'weighted_orderflow_v1','spotted_at'=>now(),
        ]);
    }

    /** Import evidence that was actually saved; never replay missing historical scores. */
    public function import(Setup $setup): ?SetupObservation
    {
        $floor=(int)config('orderflow.history_min_score',45);
        if ($setup->score < $floor || !$setup->source || !$setup->instrument || !$setup->candle_time) return null;
        $signals=$setup->confluence_detail ?? [];
        $sum=fn ($direction)=>$signals ? array_sum(array_column(array_filter($signals,
            fn ($signal)=>($signal['status']??'')===$direction),'weight')) : null;
        $observation=SetupObservation::firstOrCreate([
            'source'=>$setup->source,'instrument'=>$setup->instrument,'timeframe'=>$setup->timeframe,
            'candle_time'=>$setup->candle_time,
        ],[
            'setup_id'=>$setup->id,'direction'=>$setup->direction,'score'=>$setup->score,
            'bull_score'=>$sum('bullish'),'bear_score'=>$sum('bearish'),'history_floor'=>$floor,
            'signal_threshold'=>null,'entry'=>$setup->entry,'stop_loss'=>$setup->stop_loss,
            'tp1'=>$setup->tp1,'tp2'=>$setup->tp2,'confluence_detail'=>$signals,'metrics'=>null,
            'origin'=>'saved_setup_import','strategy_version'=>'weighted_orderflow_v1',
            'spotted_at'=>$setup->created_at,
        ]);
        if (!$observation->setup_id) $observation->update(['setup_id'=>$setup->id]);
        return $observation;
    }
}
