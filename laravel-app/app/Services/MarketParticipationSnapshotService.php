<?php

namespace App\Services;

use App\Models\MarketParticipationSnapshot;

class MarketParticipationSnapshotService
{
    public function record(array $metrics): ?MarketParticipationSnapshot
    {
        $p = $metrics['participation'] ?? null;
        if (!$p || !($metrics['closed'] ?? false) || !isset($metrics['bar_time'])) return null;

        return MarketParticipationSnapshot::updateOrCreate([
            'source' => $metrics['source'], 'instrument' => $metrics['instrument'],
            'timeframe' => $metrics['timeframe'], 'candle_time' => $metrics['bar_time'],
        ], [
            'volume' => $p['volume'], 'ask_volume' => $metrics['buy_volume'],
            'bid_volume' => $metrics['sell_volume'], 'delta' => $metrics['delta'],
            'baseline_volume' => $p['baseline_volume'], 'participation_ratio' => $p['ratio'],
            'participation_state' => $p['state'], 'flow_state' => $p['flow'],
            'delta_share' => $p['delta_share'], 'baseline_sample_size' => $p['baseline_sample_size'],
            'session_start' => $p['session_start'], 'session_first_observed' => $p['session_first_observed'],
            'session_coverage' => $p['session_coverage'], 'session_bar_count' => $p['session_bar_count'],
            'session_volume' => $p['session_volume'], 'session_delta' => $p['session_delta'],
            'context' => ['baseline_kind'=>$p['baseline_kind'],'baseline_lookback'=>$p['baseline_lookback']],
        ]);
    }
}
