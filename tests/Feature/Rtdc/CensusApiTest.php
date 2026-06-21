<?php

namespace Tests\Feature\Rtdc;

use App\Models\Bed;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CensusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_units_endpoint_returns_live_census(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => '5 East', 'type' => 'med_surg', 'staffed_bed_count' => 4, 'ratio_floor' => 5]);
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-01', 'status' => 'occupied']);
        Bed::create(['unit_id' => $unit->unit_id, 'label' => '5E-02', 'status' => 'available']);

        $response = $this->actingAs($user)->getJson('/api/rtdc/units');

        $response->assertOk()->assertJsonStructure([
            'data' => [['unit_id', 'name', 'type', 'census' => ['occupied', 'available', 'acuity_adjusted_capacity']]],
        ]);
        $response->assertJsonPath('data.0.census.occupied', 1);
    }

    public function test_units_endpoint_requires_auth(): void
    {
        $this->getJson('/api/rtdc/units')->assertUnauthorized();
    }
}
