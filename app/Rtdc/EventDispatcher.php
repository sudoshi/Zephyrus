<?php

namespace App\Rtdc;

use App\Events\Rtdc\CensusUpdated;
use App\Models\OperationalEvent;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Support\Facades\DB;

/**
 * The seam between event producers and the domain.
 *
 * S2: synchronous in-process — persist to the ledger, project, broadcast.
 * S1: this class is replaced by a Redis Streams publisher + async consumer;
 *     producers (simulator, HL7v2/FHIR adapters) and the projector do not change.
 */
class EventDispatcher
{
    public function __construct(private readonly CensusProjector $projector) {}

    public function dispatch(CanonicalEvent $event): void
    {
        $isNew = false;

        DB::transaction(function () use ($event, &$isNew) {
            // Idempotency: insert-or-ignore on the unique event_id.
            $created = OperationalEvent::firstOrCreate(
                ['event_id' => $event->eventId],
                [
                    'type' => $event->type,
                    'encounter_ref' => $event->encounterRef,
                    'payload' => $event->payload,
                    'occurred_at' => $event->occurredAt,
                ],
            );
            $isNew = $created->wasRecentlyCreated;

            if ($isNew) {
                $this->projector->apply($event);
            }
        });

        if (! $isNew) {
            return; // duplicate — already projected, do not re-broadcast
        }

        $unitId = $this->affectedUnitId($event);
        if ($unitId !== null) {
            $snapshot = $this->projector->snapshot($unitId);
            broadcast(new CensusUpdated($snapshot));
        }
    }

    private function affectedUnitId(CanonicalEvent $event): ?int
    {
        return match ($event->type) {
            CanonicalEvent::ENCOUNTER_STARTED => $event->payload['unit_id'] ?? null,
            CanonicalEvent::ENCOUNTER_TRANSFERRED => $event->payload['to_unit_id'] ?? null,
            CanonicalEvent::ENCOUNTER_DISCHARGED, CanonicalEvent::ACUITY_CHANGED => \App\Models\Encounter::where('patient_ref', $event->encounterRef)->value('unit_id'),
            CanonicalEvent::BED_STATUS_CHANGED => \App\Models\Bed::where('bed_id', $event->payload['bed_id'] ?? 0)->value('unit_id'),
            default => null,
        };
    }
}
