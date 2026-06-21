<?php

namespace Tests\Unit\Rtdc;

use App\Rtdc\Events\CanonicalEvent;
use Tests\TestCase;

class CanonicalEventTest extends TestCase
{
    public function test_factory_methods_build_typed_events(): void
    {
        $e = CanonicalEvent::encounterStarted('p-1', unitId: 3, acuityTier: 2, occurredAt: now());

        $this->assertSame(CanonicalEvent::ENCOUNTER_STARTED, $e->type);
        $this->assertSame('p-1', $e->encounterRef);
        $this->assertSame(3, $e->payload['unit_id']);
        $this->assertNotEmpty($e->eventId);
        $this->assertArrayHasKey('unit_id', $e->toArray()['payload']);
    }

    public function test_event_id_is_unique_per_event(): void
    {
        $a = CanonicalEvent::encounterDischarged('p-1', now());
        $b = CanonicalEvent::encounterDischarged('p-1', now());
        $this->assertNotSame($a->eventId, $b->eventId);
    }
}
