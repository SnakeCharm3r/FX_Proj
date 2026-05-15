<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    protected $fillable = [
        'setup_id', 'type', 'direction', 'timeframe', 'value', 'price',
    ];

    protected $casts = ['price' => 'float'];

    public function setup(): BelongsTo
    {
        return $this->belongsTo(Setup::class);
    }
}
