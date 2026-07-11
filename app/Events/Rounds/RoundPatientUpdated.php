<?php

namespace App\Events\Rounds;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reload ping: one round patient changed state or version. Opaque IDs only —
 * never a patient identifier, location, or clinical value on the wire.
 */
class RoundPatientUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $runUuid,
        public string $roundPatientUuid,
        public string $status,
        public int $version,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('rounds.run.'.$this->runUuid);
    }

    public function broadcastAs(): string
    {
        return 'round-patient.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'round_patient_uuid' => $this->roundPatientUuid,
            'status' => $this->status,
            'version' => $this->version,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
