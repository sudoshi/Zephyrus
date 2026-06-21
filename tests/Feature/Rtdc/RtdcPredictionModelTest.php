<?php

namespace Tests\Feature\Rtdc;

use App\Models\RtdcPrediction;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RtdcPredictionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_belongs_to_unit_and_has_plans(): void
    {
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        $pred = RtdcPrediction::create([
            'unit_id' => $unit->unit_id, 'service_date' => today(), 'horizon' => 'by_2pm',
        ]);
        $pred->plans()->create(['action_text' => 'Expedite 2 telemetry discharges', 'owner' => 'Charge RN']);

        $this->assertEquals($unit->unit_id, $pred->unit->unit_id);
        $this->assertEquals(1, $pred->plans()->count());
    }
}
