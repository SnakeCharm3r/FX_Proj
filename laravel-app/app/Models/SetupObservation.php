<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SetupObservation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['candle_time'=>'integer','score'=>'integer','bull_score'=>'integer','bear_score'=>'integer',
        'history_floor'=>'integer','signal_threshold'=>'integer','entry'=>'float','stop_loss'=>'float',
        'tp1'=>'float','tp2'=>'float','confluence_detail'=>'array','metrics'=>'array','spotted_at'=>'datetime'];

    public function setup(): BelongsTo
    {
        return $this->belongsTo(Setup::class);
    }
}
