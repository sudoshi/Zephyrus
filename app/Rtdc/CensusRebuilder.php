<?php

namespace App\Rtdc;

use App\Models\OperationalEvent;
use App\Rtdc\Events\CanonicalEvent;
use Carbon\Carbon;

class CensusRebuilder
{
    public function __construct(private readonly CensusProjector $projector) {}

    public function rebuild(): int
    {
        $count = 0;
        OperationalEvent::orderBy('occurred_at')->orderBy('operational_event_id')
            ->chunk(500, function ($events) use (&$count) {
                foreach ($events as $row) {
                    $this->projector->apply(new CanonicalEvent(
                        eventId: $row->event_id,
                        type: $row->type,
                        encounterRef: $row->encounter_ref,
                        payload: $row->payload,
                        occurredAt: Carbon::parse($row->occurred_at),
                    ));
                    $count++;
                }
            });

        return $count;
    }
}
