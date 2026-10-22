<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    protected $fillable = [
        'setup_id', 'type', 'direction', 'timeframe', 'value', 'price', 'weight', 'meta',
    ];

    protected $casts = ['price' => 'float', 'meta' => 'array', 'weight' => 'integer'];

    public function setup(): BelongsTo
    {
        return $this->belongsTo(Setup::class);
    }
}
