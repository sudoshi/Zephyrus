<?php

namespace Tests\Feature\Ops;

use App\Models\Staffing\StaffingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffingIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendations_include_staffing_gap_with_graph_evidence(): void
    {
        $user = User::factory()->create();
        $this->seedStaffingGap();

        $recommendations = collect(
            $this->actingAs($user)
                ->getJson('/api/ops/recommendations')
                ->assertOk()
                ->json('data.recommendations')
        );

        $this->assertContains('staffing_gap', $recommendations->pluck('type'));
        $staffing = $recommendations->firstWhere('type', 'staffing_gap');
        $this->assertSame('critical', $staffing['riskLevel']);
        $this->assertSame(2, $staffing['expectedImpact']['units_short']);
        $this->assertContains('prod.staffing_plans', $staffing['evidence']['source_tables']);
        $this->assertSame('Staffing office', $staffing['actions'][0]['ownerName']);

        $this->assertDatabaseHas('ops.recommendations', [
            'recommendation_type' => 'staffing_gap',
            'scope_key' => 'staffing',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_type' => 'mitigate_staffing_gap',
            'status' => 'draft',
        ]);
    }

    public function test_simulation_models_staffing_relief_scenario(): void
    {
        $user = User::factory()->create();
        $this->seedStaffingGap();

        $simulation = $this->actingAs($user)
            ->getJson('/api/analytics/workbench')
            ->assertOk()
            ->json('data.simulation');

        $this->assertGreaterThan(0, $simulation['baseline']['staffing_gap']);
        $this->assertContains('staffing_relief', array_column($simulation['scenarios'], 'key'));

        $relief = collect($simulation['scenarios'])->firstWhere('key', 'staffing_relief');
        $reliefMetric = collect($relief['resultMetrics'])->firstWhere('metricKey', 'staffing_gap');
        $this->assertLessThan($reliefMetric['baselineValue'], $reliefMetric['projectedValue']);

        $combined = collect($simulation['scenarios'])->firstWhere('key', 'combined_capacity_plan');
        $combinedMetric = collect($combined['resultMetrics'])->firstWhere('metricKey', 'staffing_gap');
        $this->assertLessThan($combinedMetric['baselineValue'], $combinedMetric['projectedValue']);
    }

    private function seedStaffingGap(): void
    {
        foreach ([['7 West', '7W', 6, 4, 5], ['3 East ICU', '3E', 5, 3, 4]] as [$name, $abbr, $required, $scheduled, $minSafe]) {
            $unitId = (int) DB::table('prod.units')->insertGetId([
                'name' => $name,
                'abbreviation' => $abbr,
                'type' => 'med_surg',
                'staffed_bed_count' => 24,
                'ratio_floor' => 4,
                'access_standard_minutes' => 120,
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'unit_id');

            StaffingPlan::create([
                'plan_uuid' => (string) Str::uuid(),
                'unit_id' => $unitId,
                'unit_label' => $name,
                'role' => 'rn',
                'shift_date' => now()->toDateString(),
                'shift' => 'day',
                'required_count' => $required,
                'scheduled_count' => $scheduled,
                'actual_count' => $scheduled,
                'minimum_safe_count' => $minSafe,
                'census' => 22,
                'ratio_target' => 4,
                'status' => 'critical_gap',
                'is_deleted' => false,
            ]);
        }
    }
}
