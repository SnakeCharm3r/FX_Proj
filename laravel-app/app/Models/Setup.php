<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setup extends Model
{
    protected $fillable = [
        'direction', 'timeframe', 'entry', 'stop_loss',
        'tp1', 'tp2', 'score', 'status', 'confluence_detail',
        'triggered_at', 'closed_at',
    ];

    protected $casts = [
        'entry'        => 'float',
        'stop_loss'    => 'float',
        'tp1'          => 'float',
        'tp2'          => 'float',
        'score'        => 'integer',
        'triggered_at' => 'datetime',
        'closed_at'    => 'datetime',
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
