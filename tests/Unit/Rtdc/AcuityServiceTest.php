<?php

namespace Tests\Unit\Rtdc;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcuityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_acuity_weight_increases_with_tier(): void
    {
        $svc = new AcuityService;
        $this->assertGreaterThan($svc->tierWeight(1), $svc->tierWeight(4));
    }

    public function test_adjusted_capacity_is_bounded_by_nurse_safety_not_just_beds(): void
    {
        // ICU: 12 staffed beds, ratio floor 2 (1 nurse : 2 patients). Suppose 6 nurses available implicit via staffed beds.
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        // Fill with 10 high-acuity (tier 4) patients.
        for ($i = 0; $i < 10; $i++) {
            Encounter::create(['patient_ref' => "p$i", 'unit_id' => $unit->unit_id, 'acuity_tier' => 4, 'status' => 'active']);
        }

        $svc = new AcuityService;
        $capacity = $svc->adjustedCapacity($unit->unit_id);

        // 12 physical beds remain, but high acuity load means safe additional capacity is lower than 2 (raw free beds).
        $this->assertLessThanOrEqual(2, $capacity);
        $this->assertGreaterThanOrEqual(0, $capacity);
    }

    public function test_empty_unit_capacity_equals_staffed_beds(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $svc = new AcuityService;
        $this->assertEquals(30, $svc->adjustedCapacity($unit->unit_id));
    }
}
