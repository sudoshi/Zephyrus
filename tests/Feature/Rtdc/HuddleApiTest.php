<?php

namespace Tests\Feature\Rtdc;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuddleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_close_unit_huddle_and_rollup(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);
        $date = today()->toDateString();

        $open = $this->actingAs($user)->postJson('/api/rtdc/huddles', [
            'type' => 'unit', 'unit_id' => $unit->unit_id, 'service_date' => $date,
        ])->assertOk()->json('data');

        $this->actingAs($user)->getJson("/api/rtdc/bed-meeting?service_date={$date}&horizon=by_2pm")
            ->assertOk()->assertJsonStructure(['data' => ['net_bed_need', 'total_positive_bed_need', 'units']]);

        $this->actingAs($user)->postJson("/api/rtdc/huddles/{$open['huddle_id']}/close")->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_barrier_create_and_resolve(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);

        $barrier = $this->actingAs($user)->postJson('/api/rtdc/barriers', [
            'unit_id' => $unit->unit_id, 'category' => 'placement', 'description' => 'Awaiting SNF bed',
        ])->assertOk()->json('data');

        $this->actingAs($user)->postJson("/api/rtdc/barriers/{$barrier['barrier_id']}/resolve")->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }

    public function test_barrier_rejects_invalid_category(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 10, 'ratio_floor' => 5]);

        $this->actingAs($user)->postJson('/api/rtdc/barriers', [
            'unit_id' => $unit->unit_id, 'category' => 'financial',
        ])->assertStatus(422);
    }
}
