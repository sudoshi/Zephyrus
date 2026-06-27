<?php

namespace Tests\Feature\Rtdc;

use App\Models\RtdcRedStretchPlan;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedStretchPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_red_stretch_plan_is_persisted_and_upserted(): void
    {
        $user = User::factory()->create(['name' => 'Charge Nurse']);
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 30, 'ratio_floor' => 5]);

        $this->actingAs($user)
            ->postJson('/rtdc/update-red-stretch-plan', [
                'unitId' => $unit->unit_id,
                'plan' => 'Expedite 2 telemetry discharges with cardiology.',
            ])
            ->assertOk()
            ->assertJsonPath('plan.text', 'Expedite 2 telemetry discharges with cardiology.')
            ->assertJsonPath('plan.updatedBy', 'Charge Nurse');

        $this->assertDatabaseHas('prod.rtdc_red_stretch_plans', [
            'unit_id' => $unit->unit_id,
            'plan' => 'Expedite 2 telemetry discharges with cardiology.',
            'updated_by' => 'Charge Nurse',
        ]);

        // Upsert: a second post updates the same row, not a new one.
        $this->actingAs($user)
            ->postJson('/rtdc/update-red-stretch-plan', [
                'unitId' => $unit->unit_id,
                'plan' => 'Moving stable patients to med/surg.',
            ])
            ->assertOk();

        $this->assertSame(1, RtdcRedStretchPlan::where('unit_id', $unit->unit_id)->count());
        $this->assertSame(
            'Moving stable patients to med/surg.',
            RtdcRedStretchPlan::where('unit_id', $unit->unit_id)->first()->plan,
        );
    }

    public function test_red_stretch_plan_rejects_unknown_unit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/rtdc/update-red-stretch-plan', [
                'unitId' => 999999,
                'plan' => 'x',
            ])
            ->assertStatus(422);
    }
}
