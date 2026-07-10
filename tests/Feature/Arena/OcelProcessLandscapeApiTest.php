<?php

namespace Tests\Feature\Arena;

use App\Models\User;
use Database\Seeders\OcelProcessLandscapeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OcelProcessLandscapeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.arena.enabled' => true]);
        $this->seed(OcelProcessLandscapeSeeder::class);
    }

    public function test_index_returns_all_93_seeded_reference_models(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/arena/models');

        $response
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('document.id', 'ACUM-OPS-OCEL-001')
            ->assertJsonPath('document.catalog_count', 93)
            ->assertJsonPath('document.observed_claim', false)
            ->assertJsonPath('counts.models', 93)
            ->assertJsonPath('counts.domains', 8)
            ->assertJsonCount(93, 'models');

        $this->assertSame(93, DB::table('ocel.process_models')->count());
        $this->assertSame(558, DB::table('ocel.process_model_nodes')->count());
        $this->assertSame(465, DB::table('ocel.process_model_edges')->count());
    }

    public function test_detail_returns_a_renderable_non_observed_reference_flow(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/arena/models/A8')
            ->assertOk()
            ->assertJsonPath('data_basis', 'seeded_reference_model')
            ->assertJsonPath('observed_claim', false)
            ->assertJsonPath('model.process_id', 'A8')
            ->assertJsonPath('model.current_readiness', 'partial_projection')
            ->assertJsonPath('nodes.0.activity', 'admit-decision-recorded')
            ->assertJsonPath('nodes.5.activity', 'physical-occupancy-started')
            ->assertJsonCount(6, 'nodes')
            ->assertJsonCount(5, 'edges');
    }

    public function test_model_routes_are_flag_gated_and_authenticated(): void
    {
        $this->getJson('/api/arena/models')->assertUnauthorized();

        config(['services.arena.enabled' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/arena/models')->assertNotFound();
    }
}
