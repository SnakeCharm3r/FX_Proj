<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariableOpportunity extends Model
{
    protected $guarded=['id'];
    protected $casts=['candle_time'=>'integer','score'=>'integer','entry'=>'float','stop_loss'=>'float',
        'tp1'=>'float','tp2'=>'float','risk_points'=>'float','delta_pct'=>'float','historical'=>'boolean',
        'evidence'=>'array','telegram_attempted_at'=>'datetime'];
}
