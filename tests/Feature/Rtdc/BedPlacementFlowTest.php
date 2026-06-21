<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedPlacementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_request_to_live_census(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 4, 'ratio_floor' => 2]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => 'ICU-01', 'status' => 'available', 'isolation_capable' => true]);

        // 1. ED requests an ICU bed for a tier-4 isolation patient.
        $req = $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'crit-1', 'source' => 'ed', 'acuity_tier' => 4, 'isolation_required' => 'contact', 'required_unit_type' => 'icu',
        ])->json('data');

        // 2. Recommendations are returned; the ICU isolation bed is the only feasible one.
        $recs = $this->actingAs($user)->getJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/recommendations")->json('data');
        $this->assertEquals($bed->bed_id, $recs['recommendations'][0]['bed_id']);

        // 3. Accept → census reflects the placement live, audit captured.
        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertOk();

        $census = $this->actingAs($user)->getJson('/api/rtdc/units')->json('data');
        $icu = collect($census)->firstWhere('unit_id', $unit->unit_id);
        $this->assertEquals(1, $icu['census']['occupied']);
        $this->assertDatabaseHas('prod.bed_placement_decisions', ['bed_request_id' => $req['bed_request_id'], 'action' => 'accepted']);
    }
}
