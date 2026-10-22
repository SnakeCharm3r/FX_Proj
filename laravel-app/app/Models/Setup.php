<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setup extends Model
{
    protected $fillable = [
        'direction', 'timeframe', 'entry', 'stop_loss',
        'tp1', 'tp2', 'score', 'status', 'confluence_detail',
        'triggered_at', 'closed_at', 'risk_pips', 'rr_ratio', 'vwap', 'poc',
        'source', 'instrument', 'candle_time', 'evaluation_key',
        'strategy_version', 'price_zone_id',
        'tp1_hit_at', 'tp2_hit_at', 'sl_hit_at', 'exit_tracking_state', 'target_hit_details',
    ];

    protected $casts = [
        'entry'        => 'float',
        'stop_loss'    => 'float',
        'tp1'          => 'float',
        'tp2'          => 'float',
        'score'        => 'integer',
        'confluence_detail' => 'array',
        'candle_time' => 'integer',
        'risk_pips' => 'float',
        'rr_ratio' => 'float',
        'triggered_at' => 'datetime',
        'closed_at'    => 'datetime',
        'tp1_hit_at' => 'datetime',
        'tp2_hit_at' => 'datetime',
        'sl_hit_at' => 'datetime',
        'target_hit_details' => 'array',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }
}
