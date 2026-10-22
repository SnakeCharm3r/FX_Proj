<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoneObservation extends Model
{
    protected $guarded=['id'];
    protected $casts=['candle_time'=>'integer','decision_time'=>'integer','price'=>'float',
        'historical'=>'boolean','evidence'=>'array'];
}
