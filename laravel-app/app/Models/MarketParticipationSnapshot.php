<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketParticipationSnapshot extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['ask_volume', 'bid_volume', 'flow_state', 'delta_share'];

    protected $casts = [
        'candle_time' => 'integer', 'volume' => 'float', 'ask_volume' => 'float',
        'bid_volume' => 'float', 'delta' => 'float', 'baseline_volume' => 'float',
        'participation_ratio' => 'float', 'delta_share' => 'float',
        'baseline_sample_size' => 'integer', 'session_start' => 'integer',
        'session_first_observed' => 'integer', 'session_bar_count' => 'integer',
        'session_volume' => 'float', 'session_delta' => 'float', 'context' => 'array',
    ];
}
