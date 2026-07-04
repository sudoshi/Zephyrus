<?php

namespace App\Events\Cockpit;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Zephyrus 2.0 P6 workstream 7 — the cockpit reload ping, mirroring
 * Events\Rtdc\CensusUpdated. PUBLIC channel by the same doctrine documented
 * in routes/channels.php: the payload is a PHI-free {facility_key,
 * generated_at} reload-ping ONLY — clients refetch /api/cockpit/snapshot
 * over their authenticated session; no operational data rides the wire.
 */
class CockpitSnapshotUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $facilityKey,
        public string $generatedAtIso,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('hospital.cockpit');
    }

    public function broadcastAs(): string
    {
        return 'cockpit.updated';
    }

    /** @return array{facility_key: string, generated_at: string} */
    public function broadcastWith(): array
    {
        return [
            'facility_key' => $this->facilityKey,
            'generated_at' => $this->generatedAtIso,
        ];
    }
}
