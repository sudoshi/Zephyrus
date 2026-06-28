<?php

namespace App\Events\Rtdc;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A PHI-free signal that bed occupancy changed, broadcast on the public `hospital.beds`
 * channel the Hummingbird mobile clients subscribe to. Carries no clinical detail — Reverb
 * does not replay, so clients re-snapshot /api/mobile/v1/rtdc/census on receipt.
 */
class BedsChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ?int $unitId = null) {}

    public function broadcastOn(): Channel
    {
        return new Channel('hospital.beds');
    }

    public function broadcastAs(): string
    {
        return 'beds.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'unit_id' => $this->unitId,
            'at' => now()->toIso8601String(),
        ];
    }
}
