<?php

namespace App\Events\Rounds;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reload ping: a run's lifecycle state changed. Payload is opaque IDs +
 * version only (PHI-free doctrine, routes/channels.php) — clients refetch
 * the authorized projection over their authenticated session.
 */
class RoundRunUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $runUuid,
        public string $status,
        public int $queueVersion,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('rounds.run.'.$this->runUuid);
    }

    public function broadcastAs(): string
    {
        return 'round-run.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'status' => $this->status,
            'queue_version' => $this->queueVersion,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
