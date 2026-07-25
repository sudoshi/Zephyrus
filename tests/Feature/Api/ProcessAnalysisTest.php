<?php

namespace Tests\Feature\Api;

use App\Models\PdsaCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'must_change_password' => false,
            'workflow_preference' => 'improvement',
        ]);
    }

    public function test_returns_nursing_operations_data_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?hospital=Summit+Regional+Medical+Center&workflow=Admissions&timeRange=24+Hours');

        $response->assertOk()
            ->assertJsonStructure(['nodes', 'edges', 'metrics']);
    }

    public function test_returns_admissions_data_by_default(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations');

        $response->assertOk()
            ->assertJsonStructure([
                'nodes' => [
                    '*' => ['id', 'position', 'data'],
                ],
                'edges' => [
                    '*' => ['id', 'source', 'target'],
                ],
            ]);
    }

    public function test_returns_discharge_workflow_data(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?workflow=Discharges');

        $response->assertOk();

        $nodeIds = collect($response->json('nodes'))->pluck('id')->all();

        $this->assertContains('discharge_order', $nodeIds);
    }

    public function test_applies_time_range_multiplier(): void
    {
        $dayResponse = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?timeRange=24+Hours');

        $weekResponse = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?timeRange=7+Days');

        $dayCount = $dayResponse->json('nodes')[0]['data']['metrics']['count'];
        $weekCount = $weekResponse->json('nodes')[0]['data']['metrics']['count'];

        $this->assertSame($dayCount * 7, $weekCount);
    }

    public function test_saves_a_process_layout(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Summit Regional Medical Center',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'nodes' => [['id' => 'test', 'position' => ['x' => 0, 'y' => 0]]],
                ],
            ]);

        $response->assertNoContent();
    }

    public function test_retrieves_a_saved_process_layout(): void
    {
        $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Summit Regional Medical Center',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'nodes' => [['id' => 'test', 'position' => ['x' => 100, 'y' => 200]]],
                ],
            ]);

        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout?hospital=Summit+Regional+Medical+Center&workflow=Admissions&time_range=24+Hours');

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('process_type', 'nursing_operations');
    }

    public function test_returns_not_found_for_nonexistent_layout(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout?hospital=NonExistent&workflow=Admissions&time_range=24+Hours');

        $response->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_validates_required_fields_when_saving_layout(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['process_type', 'hospital', 'workflow', 'time_range', 'layout_data']);
    }

    public function test_validates_required_fields_when_getting_layout(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['hospital', 'workflow', 'time_range']);
    }

    public function test_saves_viewport_state(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/viewport', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Summit Regional Medical Center',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
                ],
            ]);

        $response->assertNoContent();
    }

    public function test_improvement_dashboard_redirects_into_the_cockpit_drill(): void
    {
        // Zephyrus 2.0 P4a: the legacy overview permanently redirects into the
        // cockpit quality drill (COCKPIT_OVERVIEW_REDIRECTS is the rollback lever).
        $response = $this->actingAs($this->user)
            ->get('/dashboard/improvement');

        $response->assertRedirect('/dashboard?drill=quality');
    }

    public function test_loads_bottlenecks_page(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->user)
            ->get('/improvement/bottlenecks');

        $response->assertStatus(200);
    }

    public function test_loads_root_cause_page(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->user)
            ->get('/improvement/root-cause');

        $response->assertStatus(200);
    }

    public function test_loads_pdsa_index_page(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->user)
            ->get('/improvement/pdsa');

        $response->assertStatus(200);
    }

    public function test_loads_pdsa_show_page(): void
    {
        $this->withoutVite();

        $cycle = PdsaCycle::create([
            'title' => 'Reduce discharge order-to-departure time',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->get('/improvement/pdsa/'.$cycle->pdsa_cycle_id);

        $response->assertStatus(200);
    }

    public function test_changes_workflow_preference(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/set-preference/perioperative');

        $response->assertRedirect('/dashboard/perioperative');

        $this->user->refresh();
        $this->assertSame('perioperative', $this->user->workflow_preference);
    }
}
