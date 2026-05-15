<?php

namespace App\Events;

use App\Models\Setup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSetupDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Setup $setup) {}

    public function broadcastOn(): Channel { return new Channel('xauusd.setups'); }
    public function broadcastAs(): string  { return 'setup.detected'; }

    public function broadcastWith(): array
    {
        return [
            'id'        => $this->setup->id,
            'direction' => $this->setup->direction,
            'entry'     => $this->setup->entry,
            'stop_loss' => $this->setup->stop_loss,
            'tp1'       => $this->setup->tp1,
            'tp2'       => $this->setup->tp2,
            'score'     => $this->setup->score,
            'timeframe' => $this->setup->timeframe,
            'status'    => $this->setup->status,
        ];
    }
}
