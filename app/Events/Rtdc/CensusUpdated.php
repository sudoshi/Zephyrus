<?php

namespace App\Events\Rtdc;

use App\Models\CensusSnapshot;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CensusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CensusSnapshot $snapshot) {}

    public function broadcastOn(): Channel
    {
        return new Channel('unit.'.$this->snapshot->unit_id);
    }

    public function broadcastAs(): string
    {
        return 'census.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->snapshot->unit_id,
            'captured_at' => $this->snapshot->captured_at?->toIso8601String(),
            'staffed_beds' => $this->snapshot->staffed_beds,
            'occupied' => $this->snapshot->occupied,
            'available' => $this->snapshot->available,
            'blocked' => $this->snapshot->blocked,
            'acuity_adjusted_capacity' => $this->snapshot->acuity_adjusted_capacity,
        ];
    }
}
