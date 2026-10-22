<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceZone extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['low' => 'float', 'high' => 'float', 'atr' => 'float', 'metadata' => 'array',
        'origin_time' => 'integer', 'confirmed_time' => 'integer', 'invalidated_time' => 'integer',
        'last_price_received' => 'integer', 'was_inside' => 'boolean', 'entry_count' => 'integer',
        'last_alerted_at' => 'datetime'];
}
