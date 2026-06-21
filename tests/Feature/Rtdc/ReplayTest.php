<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use App\Rtdc\CensusRebuilder;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_from_ledger_reproduces_census(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $dispatcher = app(EventDispatcher::class);
        $dispatcher->dispatch(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        // Wipe the read model but keep the ledger.
        Encounter::query()->delete();
        Bed::where('bed_id', $bed->bed_id)->update(['status' => 'available']);

        app(CensusRebuilder::class)->rebuild();

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active']);
        $this->assertEquals('occupied', $bed->fresh()->status);
    }
}
