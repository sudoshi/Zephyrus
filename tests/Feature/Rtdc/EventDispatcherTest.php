<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\OperationalEvent;
use App\Models\Unit;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_persists_event_to_ledger_and_projects(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'I-01', 'status' => 'available']);
        $dispatcher = app(EventDispatcher::class);

        $event = CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 4, now(), $bed->bed_id);
        $dispatcher->dispatch($event);

        $this->assertDatabaseHas('prod.operational_events', ['event_id' => $event->eventId, 'type' => 'EncounterStarted']);
        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active']);
    }

    public function test_dispatch_is_idempotent_on_duplicate_event_id(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $dispatcher = app(EventDispatcher::class);
        $event = CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 4, now());

        $dispatcher->dispatch($event);
        $dispatcher->dispatch($event); // replay same event_id

        $this->assertEquals(1, OperationalEvent::where('event_id', $event->eventId)->count());
    }
}
