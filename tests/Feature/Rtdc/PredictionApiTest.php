<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_four_step_cycle_over_http(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);
        for ($i = 0; $i < 5; $i++) {
            Bed::create(['unit_id' => $unit->unit_id, 'label' => "5E-0$i", 'status' => 'available']);
        }
        $date = today()->toDateString();

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/capacity", [
            'service_date' => $date, 'horizon' => 'by_2pm', 'definite' => 2, 'probable' => 0, 'possible' => 0,
        ])->assertOk();

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/demand", [
            'service_date' => $date, 'horizon' => 'by_2pm', 'ed' => 10, 'or' => 0, 'transfer' => 0, 'direct' => 0,
        ])->assertOk();

        $resp = $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/plan", [
            'service_date' => $date, 'horizon' => 'by_2pm',
        ])->assertOk();

        $resp->assertJsonPath('data.bed_need', 3); // 10 - (5 + floor(2.0)) = 3
    }

    public function test_capacity_rejects_invalid_horizon(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 12, 'ratio_floor' => 2]);

        $this->actingAs($user)->postJson("/api/rtdc/units/{$unit->unit_id}/capacity", [
            'service_date' => today()->toDateString(), 'horizon' => 'tomorrow', 'definite' => 1, 'probable' => 0, 'possible' => 0,
        ])->assertStatus(422);
    }
}
