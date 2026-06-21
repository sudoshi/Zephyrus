<?php

namespace App\Events\Rtdc;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BedMeetingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $rollup) {}

    public function broadcastOn(): Channel
    {
        return new Channel('hospital.beds');
    }

    public function broadcastAs(): string
    {
        return 'bedmeeting.updated';
    }

    public function broadcastWith(): array
    {
        return $this->rollup;
    }
}
