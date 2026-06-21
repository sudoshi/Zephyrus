<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_has_beds_and_encounters(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);
        $enc = Encounter::create(['patient_ref' => 'p-1', 'unit_id' => $unit->unit_id, 'bed_id' => $bed->bed_id, 'acuity_tier' => 3, 'status' => 'active']);

        $this->assertEquals(1, $unit->beds()->count());
        $this->assertEquals(1, $unit->encounters()->count());
        $this->assertEquals($unit->unit_id, $enc->unit->unit_id);
        $this->assertEquals('5E-01', $enc->bed->label);
    }

    public function test_encounter_active_scope_excludes_discharged(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        Encounter::create(['patient_ref' => 'a', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        Encounter::create(['patient_ref' => 'b', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'discharged']);

        $this->assertEquals(1, Encounter::active()->count());
    }
}
