<?php

namespace Tests\Unit\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcuityCanAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_workload_decreases_with_load(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $svc = new AcuityService;
        $this->assertEqualsWithDelta(12.0, $svc->remainingWorkload($unit->unit_id), 0.001);

        Encounter::create(['patient_ref' => 'p1', 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        // 12 - 2.2 = 9.8
        $this->assertEqualsWithDelta(9.8, $svc->remainingWorkload($unit->unit_id), 0.001);
    }

    public function test_can_accept_respects_remaining_workload_and_acuity(): void
    {
        $unit = Unit::create(['name' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 2, 'ratio_floor' => 3]);
        $svc = new AcuityService;
        // Fill to remaining 0.3 workload (2 - 1.7 = 0.3 with one tier-3).
        Encounter::create(['patient_ref' => 'a', 'unit_id' => $unit->unit_id, 'acuity_tier' => 3, 'status' => 'active']);

        $this->assertTrue($svc->canAccept($unit->unit_id, 1) === false || $svc->canAccept($unit->unit_id, 1) === true); // sanity
        // remaining 0.3 cannot take a tier-1 (weight 1.0)
        $this->assertFalse($svc->canAccept($unit->unit_id, 1));

        $empty = Unit::create(['name' => 'M', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $this->assertTrue($svc->canAccept($empty->unit_id, 4));
    }
}
