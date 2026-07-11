<?php

namespace App\Events\Rounds;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Reload ping: the run's ordered queue changed (reorder, pin, cohort apply,
 * ETA recalculation). Clients holding an older queue_version refetch.
 */
class RoundQueueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $runUuid,
        public int $queueVersion,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('rounds.run.'.$this->runUuid);
    }

    public function broadcastAs(): string
    {
        return 'round-queue.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'queue_version' => $this->queueVersion,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
