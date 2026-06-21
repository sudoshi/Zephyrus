<?php

namespace App\Events\Rtdc;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HuddleUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $unitId, public array $prediction) {}

    public function broadcastOn(): Channel
    {
        return new Channel('unit.'.$this->unitId);
    }

    public function broadcastAs(): string
    {
        return 'huddle.updated';
    }

    public function broadcastWith(): array
    {
        return ['unit_id' => $this->unitId, 'prediction' => $this->prediction];
    }
}
