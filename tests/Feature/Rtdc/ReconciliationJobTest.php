<?php

namespace Tests\Feature\Rtdc;

use App\Jobs\ReconcileRtdcPredictions;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_reconciles_all_units_for_yesterday(): void
    {
        $yesterday = today()->subDay();
        $a = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $b = Unit::create(['name' => 'B', 'type' => 'icu', 'staffed_bed_count' => 8, 'ratio_floor' => 2]);
        RtdcPrediction::create(['unit_id' => $a->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 2]);
        RtdcPrediction::create(['unit_id' => $b->unit_id, 'service_date' => $yesterday, 'horizon' => 'by_midnight', 'discharges_weighted' => 1]);

        (new ReconcileRtdcPredictions)->handle(app(\App\Services\ReconciliationService::class));

        $this->assertDatabaseCount('prod.rtdc_reconciliations', 2);
    }

    public function test_reliability_api_returns_unit_score(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'A', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        \App\Models\RtdcReconciliation::create([
            'unit_id' => $unit->unit_id, 'service_date' => today()->subDay(),
            'predicted_discharges' => 4, 'actual_discharges' => 5, 'reliability_score' => 0.8,
        ]);

        $this->actingAs($user)->getJson("/api/rtdc/units/{$unit->unit_id}/reliability")
            ->assertOk()->assertJsonPath('data.reliability_score', 0.8);
    }
}
