<?php

namespace Tests\Feature\Ops;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterventionAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_workbench_materializes_intervention_attribution_for_completed_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00'));
        $user = User::factory()->create();
        $unitId = $this->seedAttributionCapacity();
        $pdsaCycleId = $this->insertPdsaCycle($unitId);
        $recommendationId = $this->insertRecommendation();
        $actionId = $this->insertCompletedAction($recommendationId, $pdsaCycleId);

        $response = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->assertJsonPath('data.section', 'workbench')
            ->assertJsonPath('data.impact.summary.totalInterventions', 1)
            ->assertJsonPath('data.impact.summary.estimatedNetBedGain', 4);

        $intervention = $response->json('data.impact.interventions.0');
        $this->assertSame($actionId, $intervention['actionId']);
        $this->assertSame($pdsaCycleId, $intervention['pdsaCycleId']);
        $this->assertSame('bed_pressure', $intervention['type']);
        $this->assertSame('medium', $intervention['confidenceLevel']);

        $primary = collect($intervention['metrics'])->firstWhere('isPrimary', true);
        $this->assertSame('net_beds', $primary['metricKey']);
        $this->assertEquals(1.0, $primary['baselineValue']);
        $this->assertEquals(5.0, $primary['followupValue']);
        $this->assertEquals(4.0, $primary['deltaValue']);
        $this->assertSame('success', $primary['status']);

        $this->assertDatabaseHas('ops.interventions', [
            'action_id' => $actionId,
            'pdsa_cycle_id' => $pdsaCycleId,
            'intervention_type' => 'bed_pressure',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('ops.intervention_metrics', [
            'metric_key' => 'net_beds',
            'baseline_value' => '1.0000',
            'followup_value' => '5.0000',
            'delta_value' => '4.0000',
            'status' => 'success',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('ops.outcome_attribution', [
            'confidence_level' => 'medium',
            'sample_size' => 2,
        ]);
    }

    public function test_active_pdsa_cycles_are_linked_as_measuring_interventions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00'));
        $user = User::factory()->create();
        $unitId = $this->seedAttributionCapacity();
        $pdsaCycleId = $this->insertPdsaCycle($unitId, status: 'active');

        $response = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->assertJsonPath('data.impact.summary.totalInterventions', 1)
            ->assertJsonPath('data.impact.summary.measuringInterventions', 1);

        $this->assertSame($pdsaCycleId, $response->json('data.impact.interventions.0.pdsaCycleId'));
        $this->assertSame('pdsa_cycle', $response->json('data.impact.interventions.0.type'));
        $this->assertDatabaseHas('ops.interventions', [
            'pdsa_cycle_id' => $pdsaCycleId,
            'action_id' => null,
            'status' => 'measuring',
        ]);
    }

    private function seedAttributionCapacity(): int
    {
        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => '9 East',
            'abbreviation' => '9E',
            'type' => 'med_surg',
            'staffed_bed_count' => 20,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');

        DB::table('prod.census_snapshots')->insert([
            [
                'unit_id' => $unitId,
                'captured_at' => now()->subHours(2)->toDateTimeString(),
                'staffed_beds' => 20,
                'occupied' => 19,
                'available' => 1,
                'blocked' => 2,
                'acuity_adjusted_capacity' => 20,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'unit_id' => $unitId,
                'captured_at' => now()->subMinutes(30)->toDateTimeString(),
                'staffed_beds' => 20,
                'occupied' => 15,
                'available' => 5,
                'blocked' => 0,
                'acuity_adjusted_capacity' => 20,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        return $unitId;
    }

    private function insertPdsaCycle(int $unitId, string $status = 'completed'): int
    {
        return (int) DB::table('prod.pdsa_cycles')->insertGetId([
            'title' => 'Bed readiness command loop',
            'unit_id' => $unitId,
            'status' => $status,
            'owner' => 'Capacity lead',
            'objective' => 'Increase net bed position after huddle actions',
            'started_at' => now()->subDays(3),
            'completed_at' => $status === 'completed' ? now()->subHour() : null,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'pdsa_cycle_id');
    }

    private function insertRecommendation(): int
    {
        return (int) DB::table('ops.recommendations')->insertGetId([
            'recommendation_uuid' => (string) Str::uuid(),
            'recommendation_type' => 'bed_pressure',
            'scope_type' => 'hospital',
            'scope_key' => 'capacity',
            'title' => 'Resolve bed pressure before next bed meeting',
            'rationale' => 'Pending demand exceeds ready bed supply.',
            'confidence' => 0.9100,
            'risk_level' => 'high',
            'status' => 'completed',
            'expected_impact' => json_encode([
                'metric' => 'net_beds',
                'direction' => 'up',
            ]),
            'evidence' => json_encode([
                'source_tables' => ['prod.census_snapshots', 'prod.bed_requests'],
            ]),
            'created_by_source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'recommendation_id');
    }

    private function insertCompletedAction(int $recommendationId, int $pdsaCycleId): int
    {
        return (int) DB::table('ops.actions')->insertGetId([
            'action_uuid' => (string) Str::uuid(),
            'recommendation_id' => $recommendationId,
            'action_type' => 'review_bed_placement_gap',
            'status' => 'completed',
            'owner_name' => 'Capacity lead',
            'payload' => json_encode([
                'owner' => 'Capacity lead',
                'route' => '/rtdc/bed-placement',
            ]),
            'completion_payload' => json_encode([
                'pdsa_cycle_id' => $pdsaCycleId,
                'note' => 'Bed huddle completed and capacity released.',
            ]),
            'approved_at' => now()->subHours(2),
            'assigned_at' => now()->subHours(2),
            'executed_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'action_id');
    }
}
