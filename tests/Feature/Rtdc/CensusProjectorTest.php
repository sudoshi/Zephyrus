<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Rtdc\CensusProjector;
use App\Rtdc\Events\CanonicalEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CensusProjectorTest extends TestCase
{
    use RefreshDatabase;

    private function unitWithBed(): array
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);

        return [$unit, $bed];
    }

    public function test_encounter_started_creates_active_encounter_and_occupies_bed(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        $projector = app(CensusProjector::class);

        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'active', 'acuity_tier' => 3]);
        $this->assertEquals('occupied', $bed->fresh()->status);
    }

    public function test_encounter_discharged_marks_discharged_and_frees_bed(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        $projector = app(CensusProjector::class);
        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $projector->apply(CanonicalEvent::encounterDischarged('p-1', now()));

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p-1', 'status' => 'discharged']);
        $this->assertEquals('dirty', $bed->fresh()->status); // freed but needs cleaning
    }

    public function test_snapshot_reflects_occupancy(): void
    {
        [$unit, $bed] = $this->unitWithBed();
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available']);
        $projector = app(CensusProjector::class);
        $projector->apply(CanonicalEvent::encounterStarted('p-1', $unit->unit_id, 3, now(), $bed->bed_id));

        $snap = $projector->snapshot($unit->unit_id);

        $this->assertEquals(1, $snap->occupied);
        $this->assertEquals(1, $snap->available);
    }
}
