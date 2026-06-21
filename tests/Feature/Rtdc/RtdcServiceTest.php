<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Services\RtdcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtdcServiceTest extends TestCase
{
    use RefreshDatabase;

    private function unit(): Unit
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        // 5 available beds.
        for ($i = 0; $i < 5; $i++) {
            Bed::create(['unit_id' => $unit->unit_id, 'label' => "5E-0$i", 'status' => 'available']);
        }

        return $unit;
    }

    public function test_weighted_discharges_apply_confidence_weights(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);

        $pred = $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 3, probable: 2, possible: 4);

        // definite=1.0, probable=0.6, possible=0.3 -> 3 + 1.2 + 1.2 = 5.4
        $this->assertEqualsWithDelta(5.4, $pred->discharges_weighted, 0.001);
    }

    public function test_demand_sums_by_source(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);

        $pred = $svc->upsertDemand($unit->unit_id, today(), 'by_2pm', ed: 4, or: 1, transfer: 2, direct: 1);

        $this->assertEquals(8, $pred->demand_expected);
    }

    public function test_bed_need_is_demand_minus_available_plus_weighted_discharges(): void
    {
        $unit = $this->unit(); // 5 available beds
        $svc = app(RtdcService::class);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 2, probable: 0, possible: 0); // weighted 2.0
        $svc->upsertDemand($unit->unit_id, today(), 'by_2pm', ed: 10, or: 0, transfer: 0, direct: 0); // demand 10

        $pred = $svc->developPlan($unit->unit_id, today(), 'by_2pm');

        // bed_need = 10 - (5 available + floor(2.0) weighted) = 10 - 7 = 3
        $this->assertEquals(3, $pred->bed_need);
    }

    public function test_upsert_is_idempotent_per_unit_date_horizon(): void
    {
        $unit = $this->unit();
        $svc = app(RtdcService::class);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 1, probable: 0, possible: 0);
        $svc->upsertCapacity($unit->unit_id, today(), 'by_2pm', definite: 5, probable: 0, possible: 0);

        $this->assertDatabaseCount('prod.rtdc_predictions', 1);
    }
}
