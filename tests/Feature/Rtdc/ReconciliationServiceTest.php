<?php

namespace Tests\Feature\Rtdc;

use App\Models\Encounter;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_computes_reliability_from_predicted_vs_actual(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $yesterday = today()->subDay();

        // Predicted 4 weighted discharges for yesterday.
        RtdcPrediction::create([
            'unit_id' => $unit->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight',
            'discharges_weighted' => 4, 'demand_expected' => 3,
        ]);

        // Actual: 5 discharges happened yesterday.
        for ($i = 0; $i < 5; $i++) {
            Encounter::create([
                'patient_ref' => "p$i", 'unit_id' => $unit->unit_id, 'acuity_tier' => 2,
                'status' => 'discharged', 'discharged_at' => $yesterday->copy()->addHours(14),
            ]);
        }

        $recon = app(ReconciliationService::class)->reconcile($unit->unit_id, $yesterday);

        $this->assertEquals(5, $recon->actual_discharges);
        $this->assertEqualsWithDelta(4.0, $recon->predicted_discharges, 0.001);
        // reliability = 1 - |pred-actual|/max(pred,actual) = 1 - 1/5 = 0.8
        $this->assertEqualsWithDelta(0.8, $recon->reliability_score, 0.001);
    }

    public function test_reconcile_is_idempotent_per_unit_date(): void
    {
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);
        $yesterday = today()->subDay();
        RtdcPrediction::create(['unit_id' => $unit->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 2]);

        $svc = app(ReconciliationService::class);
        $svc->reconcile($unit->unit_id, $yesterday);
        $svc->reconcile($unit->unit_id, $yesterday);

        $this->assertDatabaseCount('prod.rtdc_reconciliations', 1);
    }
}
