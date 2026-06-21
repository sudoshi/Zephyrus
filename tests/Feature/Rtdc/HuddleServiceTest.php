<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Services\HuddleService;
use App\Services\RtdcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_unit_huddle_is_idempotent_per_unit_date(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $svc = app(HuddleService::class);

        $svc->openUnitHuddle($unit->unit_id, today());
        $svc->openUnitHuddle($unit->unit_id, today());

        $this->assertDatabaseCount('prod.huddles', 1);
    }

    public function test_hospital_rollup_sums_positive_bed_need(): void
    {
        $rtdc = app(RtdcService::class);
        $huddles = app(HuddleService::class);

        $a = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $b = Unit::create(['name' => 'B', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        // A short by 3, B surplus by 2.
        foreach ([$a, $b] as $u) {
            for ($i = 0; $i < 2; $i++) {
                Bed::create(['unit_id' => $u->unit_id, 'label' => "{$u->name}-$i", 'status' => 'available']);
            }
        }
        $rtdc->upsertDemand($a->unit_id, today(), 'by_2pm', ed: 5, or: 0, transfer: 0, direct: 0);
        $rtdc->developPlan($a->unit_id, today(), 'by_2pm'); // need 5-2=3
        $rtdc->upsertDemand($b->unit_id, today(), 'by_2pm', ed: 0, or: 0, transfer: 0, direct: 0);
        $rtdc->developPlan($b->unit_id, today(), 'by_2pm'); // need 0-2=-2

        $rollup = $huddles->hospitalRollup(today(), 'by_2pm');

        $this->assertEquals(3, $rollup['total_positive_bed_need']);
        $this->assertEquals(1, $rollup['net_bed_need']);
    }
}
