<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    protected $fillable = [
        'setup_id', 'channel', 'type', 'message', 'status', 'error', 'price_zone_id', 'zone_entry',
    ];

    public function setup(): BelongsTo
    {
        return $this->belongsTo(Setup::class);
    }
}
