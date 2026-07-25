<?php

namespace Tests\Unit\Services;

use App\Models\PdsaCycle;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DashboardService;
    }

    public function test_improvement_stats_returns_expected_keys(): void
    {
        $stats = $this->service->getImprovementStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('activePDSA', $stats);
        $this->assertArrayHasKey('opportunities', $stats);
        $this->assertArrayHasKey('libraryItems', $stats);
    }

    public function test_improvement_stats_returns_integer_values(): void
    {
        $stats = $this->service->getImprovementStats();

        $this->assertIsInt($stats['total']);
        $this->assertIsInt($stats['activePDSA']);
        $this->assertIsInt($stats['opportunities']);
        $this->assertIsInt($stats['libraryItems']);
    }

    public function test_bottleneck_stats_returns_expected_keys(): void
    {
        $data = $this->service->getBottleneckStats();

        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('active', $data['stats']);
        $this->assertArrayHasKey('avgResolutionTime', $data['stats']);
        $this->assertArrayHasKey('patientImpact', $data['stats']);
    }

    public function test_bottleneck_stats_returns_numeric_values(): void
    {
        $data = $this->service->getBottleneckStats();

        $this->assertIsInt($data['stats']['active']);
        $this->assertIsNumeric($data['stats']['avgResolutionTime']);
        $this->assertIsNumeric($data['stats']['patientImpact']);
    }

    public function test_root_causes_items_have_required_fields(): void
    {
        foreach ($this->service->getRootCauses() as $cause) {
            $this->assertArrayHasKey('rank', $cause);
            $this->assertArrayHasKey('type', $cause);
            $this->assertArrayHasKey('location', $cause);
            $this->assertArrayHasKey('impactedPatients', $cause);
            $this->assertArrayHasKey('score', $cause);
        }
    }

    public function test_root_causes_are_sorted_by_rank(): void
    {
        $ranks = array_column($this->service->getRootCauses(), 'rank');
        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks);
    }

    public function test_root_causes_items_have_causes_array(): void
    {
        foreach ($this->service->getRootCauses() as $cause) {
            $this->assertArrayHasKey('causes', $cause);
            $this->assertIsArray($cause['causes']);
            $this->assertNotEmpty($cause['causes']);
        }
    }

    public function test_opportunities_returns_an_array(): void
    {
        $this->assertIsArray($this->service->getOpportunities());
    }

    public function test_opportunities_items_have_required_fields(): void
    {
        $opportunities = $this->service->getOpportunities();

        // Empty on a fresh database — the loop asserts shape only when the
        // underlying prod.* signals exist.
        $this->assertIsArray($opportunities);

        foreach ($opportunities as $opportunity) {
            $this->assertArrayHasKey('title', $opportunity);
            $this->assertArrayHasKey('description', $opportunity);
            $this->assertArrayHasKey('department', $opportunity);
            $this->assertArrayHasKey('priority', $opportunity);
            $this->assertArrayHasKey('status', $opportunity);
        }
    }

    public function test_library_resources_returns_an_array(): void
    {
        $this->assertIsArray($this->service->getLibraryResources());
    }

    public function test_library_resources_items_have_required_fields(): void
    {
        $resources = $this->service->getLibraryResources();

        $this->assertIsArray($resources);

        foreach ($resources as $resource) {
            $this->assertArrayHasKey('title', $resource);
            $this->assertArrayHasKey('description', $resource);
            $this->assertArrayHasKey('category', $resource);
            $this->assertArrayHasKey('type', $resource);
            $this->assertArrayHasKey('dateAdded', $resource);
        }
    }

    public function test_active_cycles_returns_an_array(): void
    {
        $this->assertIsArray($this->service->getActiveCycles());
    }

    public function test_active_cycles_have_required_fields(): void
    {
        PdsaCycle::create([
            'title' => 'Reduce discharge order-to-departure time',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $cycles = $this->service->getActiveCycles();

        $this->assertNotEmpty($cycles);

        foreach ($cycles as $cycle) {
            $this->assertArrayHasKey('id', $cycle);
            $this->assertArrayHasKey('title', $cycle);
            $this->assertArrayHasKey('status', $cycle);
            $this->assertArrayHasKey('currentPhase', $cycle);
            $this->assertArrayHasKey('progress', $cycle);
        }
    }

    public function test_active_cycles_have_valid_progress_values(): void
    {
        PdsaCycle::create([
            'title' => 'Reduce discharge order-to-departure time',
            'status' => 'active',
            'started_at' => now(),
        ]);

        foreach ($this->service->getActiveCycles() as $cycle) {
            $this->assertGreaterThanOrEqual(0, $cycle['progress']);
            $this->assertLessThanOrEqual(100, $cycle['progress']);
        }
    }

    public function test_pdsa_cycle_echoes_the_requested_id_when_not_found(): void
    {
        $cycle = $this->service->getPdsaCycle('42');

        $this->assertIsArray($cycle);
        $this->assertSame('42', $cycle['id']);
    }

    public function test_pdsa_cycle_maps_a_persisted_cycle_onto_the_show_shape(): void
    {
        $persisted = PdsaCycle::create([
            'title' => 'Reduce discharge order-to-departure time',
            'objective' => 'Cut median order-to-departure below 120 minutes.',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $cycle = $this->service->getPdsaCycle((string) $persisted->pdsa_cycle_id);

        $this->assertSame($persisted->pdsa_cycle_id, $cycle['id']);
        $this->assertSame($persisted->title, $cycle['title']);
        $this->assertArrayHasKey('plan', $cycle);
        $this->assertArrayHasKey('study', $cycle);
        $this->assertContains($cycle['status'], ['Plan', 'Do', 'Study', 'Act']);
    }

    public function test_updates_user_workflow_preference(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('update')
            ->once()
            ->with(['workflow_preference' => 'perioperative']);

        $this->service->updateWorkflowPreference($user, 'perioperative');
    }

    public function test_accepts_valid_workflow_values(): void
    {
        $workflows = ['superuser', 'rtdc', 'perioperative', 'emergency', 'improvement'];

        foreach ($workflows as $workflow) {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('update')
                ->once()
                ->with(['workflow_preference' => $workflow]);

            $this->service->updateWorkflowPreference($user, $workflow);
        }
    }
}
