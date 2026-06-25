<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsEngineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_engine_endpoints_require_authentication(): void
    {
        $this->getJson('/api/analytics/overview')->assertUnauthorized();
    }

    public function test_overview_returns_live_operations_engine_payload(): void
    {
        $user = User::factory()->create();
        $unitId = $this->insertUnit('7 West');
        $this->insertSnapshot($unitId, staffed: 20, occupied: 18, available: 2, blocked: 0);
        $this->insertPrediction($unitId, demandExpected: 5, bedNeed: 3);

        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'ed-boarder-1',
            'arrived_at' => now()->subHours(5),
            'provider_seen_at' => now()->subHours(4),
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(3),
            'bed_assigned_at' => null,
            'unit_id' => $unitId,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.bed_requests')->insert([
            'patient_ref' => 'bed-request-1',
            'source' => 'ed',
            'service' => 'Medicine',
            'acuity_tier' => 3,
            'required_unit_type' => 'med_surg',
            'status' => 'pending',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.barriers')->insert([
            'unit_id' => $unitId,
            'category' => 'placement',
            'description' => 'Awaiting isolation room',
            'owner' => 'Bed placement',
            'status' => 'open',
            'opened_at' => now()->subHour(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.transport_requests')->insert([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'transfer',
            'priority' => 'stat',
            'status' => 'requested',
            'patient_ref' => 'transport-patient-1',
            'origin' => 'Community ED',
            'destination' => '7 West',
            'transport_mode' => 'stretcher',
            'requested_at' => now(),
            'needed_at' => now()->subMinutes(10),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.operational_events')->insert([
            'event_id' => (string) Str::uuid(),
            'type' => 'BedStatusChanged',
            'encounter_ref' => 'enc-1',
            'payload' => json_encode(['status' => 'blocked']),
            'occurred_at' => now(),
        ]);
        DB::table('prod.pdsa_cycles')->insert([
            'title' => 'Discharge before noon',
            'unit_id' => $unitId,
            'status' => 'active',
            'owner' => 'Unit manager',
            'objective' => 'Improve early discharge reliability',
            'started_at' => now()->subDay(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.section', 'hub');

        $data = $response->json('data');
        $this->assertContains('System Strain', array_column($data['metrics'], 'label'));
        $this->assertTrue(collect($data['actionQueue'])->contains(
            fn (array $item): bool => str_contains($item['title'], 'admitted ED patients')
        ));
        $this->assertTrue(collect($data['sourceMap'])->contains(
            fn (array $source): bool => $source['label'] === 'Capacity census' && $source['recordCount'] > 0
        ));
    }

    public function test_predictive_endpoint_uses_rtdc_prediction_totals(): void
    {
        $user = User::factory()->create();
        $unitId = $this->insertUnit('ICU');
        $this->insertSnapshot($unitId, staffed: 10, occupied: 9, available: 1, blocked: 0);
        $this->insertPrediction($unitId, demandExpected: 6, bedNeed: 4, weightedDischarges: 1.5);

        $response = $this->actingAs($user)->getJson('/api/analytics/predictive')
            ->assertOk()
            ->assertJsonPath('data.section', 'predictive')
            ->assertJsonPath('data.forecast.demandExpected', 6)
            ->assertJsonPath('data.forecast.bedNeed', 4);

        $this->assertContains('Bed Need', array_column($response->json('data.metrics'), 'label'));
    }

    public function test_data_quality_endpoint_returns_governance_checks(): void
    {
        $user = User::factory()->create();
        $unitId = $this->insertUnit('3 North');
        $this->insertSnapshot($unitId, staffed: 12, occupied: 8, available: 4, blocked: 0);
        $this->insertPrediction($unitId, demandExpected: 2, bedNeed: 0);

        $response = $this->actingAs($user)->getJson('/api/analytics/data-quality')
            ->assertOk()
            ->assertJsonPath('data.section', 'data-quality');

        $this->assertSame(7, $response->json('data.summary.total'));
        $this->assertContains('Census freshness', array_column($response->json('data.checks'), 'label'));
    }

    private function insertUnit(string $name): int
    {
        return (int) DB::table('prod.units')->insertGetId([
            'name' => $name,
            'abbreviation' => strtoupper(substr(str_replace(' ', '', $name), 0, 4)),
            'type' => 'med_surg',
            'staffed_bed_count' => 20,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    private function insertSnapshot(int $unitId, int $staffed, int $occupied, int $available, int $blocked): void
    {
        DB::table('prod.census_snapshots')->insert([
            'unit_id' => $unitId,
            'captured_at' => now()->toDateTimeString(),
            'staffed_beds' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'acuity_adjusted_capacity' => $staffed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPrediction(
        int $unitId,
        int $demandExpected,
        int $bedNeed,
        float $weightedDischarges = 1.0
    ): void {
        DB::table('prod.rtdc_predictions')->insert([
            'unit_id' => $unitId,
            'service_date' => Carbon::today()->toDateString(),
            'horizon' => 'by_2pm',
            'discharges_definite' => 1,
            'discharges_probable' => 0,
            'discharges_possible' => 0,
            'discharges_weighted' => $weightedDischarges,
            'demand_ed' => $demandExpected,
            'demand_or' => 0,
            'demand_transfer' => 0,
            'demand_direct' => 0,
            'demand_expected' => $demandExpected,
            'capacity_now' => 1,
            'bed_need' => $bedNeed,
            'status' => 'open',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
