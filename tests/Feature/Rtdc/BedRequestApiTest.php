<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BedRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_request_then_get_recommendations_then_accept(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $bed = Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'available']);

        $req = $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'p1', 'source' => 'ed', 'acuity_tier' => 2, 'isolation_required' => 'none', 'required_unit_type' => 'med_surg',
        ])->assertOk()->json('data');

        $recs = $this->actingAs($user)->getJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/recommendations")
            ->assertOk()->assertJsonStructure(['data' => ['recommendations' => [['bed_id', 'score', 'breakdown', 'chips']], 'runner_up_delta', 'excluded']])
            ->json('data');
        $this->assertEquals($bed->bed_id, $recs['recommendations'][0]['bed_id']);

        $this->actingAs($user)->postJson("/api/rtdc/bed-requests/{$req['bed_request_id']}/decision", [
            'action' => 'accepted', 'chosen_bed_id' => $bed->bed_id,
        ])->assertOk();

        $this->assertDatabaseHas('prod.encounters', ['patient_ref' => 'p1', 'status' => 'active']);
    }

    public function test_create_rejects_invalid_source(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/rtdc/bed-requests', [
            'patient_ref' => 'p', 'source' => 'walkin', 'acuity_tier' => 2,
        ])->assertStatus(422);
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/rtdc/bed-requests/1/recommendations')->assertUnauthorized();
    }
}
