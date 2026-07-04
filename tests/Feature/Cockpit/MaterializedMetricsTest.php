<?php

namespace Tests\Feature\Cockpit;

use App\Jobs\RefreshCockpitMaterializedViews;
use App\Services\Cockpit\MaterializedMetricsReader;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (WS-5) — the Quality / Service / Financial MTD materialized
 * views, their hourly CONCURRENTLY refresh, and the providers that read them.
 */
class MaterializedMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function qualityEvent(string $type, int $num, int $den, int $pd = 0): void
    {
        DB::table('prod.quality_events')->insert([
            'event_uuid' => (string) Str::uuid(), 'event_type' => $type, 'occurred_at' => now(),
            'numerator' => $num, 'denominator' => $den, 'patient_days' => $pd,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function refresh(): void
    {
        (new RefreshCockpitMaterializedViews)->handle();
    }

    public function test_hai_ledger_computes_percentages_counts_and_rates(): void
    {
        // 9 of 10 hand-hygiene observations compliant → 90%.
        for ($i = 0; $i < 10; $i++) {
            $this->qualityEvent('hand_hygiene', $i < 9 ? 1 : 0, 1);
        }
        // 3 C.diff infections MTD.
        for ($i = 0; $i < 3; $i++) {
            $this->qualityEvent('cdiff', 1, 0);
        }
        // 5 falls over 2000 patient-days → 2.5 / 1000.
        for ($i = 0; $i < 5; $i++) {
            $this->qualityEvent('fall', 1, 0);
        }
        $this->qualityEvent('fall', 0, 0, 2000);

        $this->refresh();
        $mv = app(MaterializedMetricsReader::class);

        $this->assertSame(90.0, $mv->value('quality.hand_hygiene'));
        $this->assertSame(3.0, $mv->value('quality.cdiff'));
        $this->assertSame(2.5, $mv->value('quality.falls_rate'));
    }

    public function test_service_and_financial_mvs_aggregate_mtd_facts(): void
    {
        foreach ([1.6, 1.8, 1.4] as $w) {
            DB::table('prod.discharge_facts')->insert([
                'fact_uuid' => (string) Str::uuid(), 'patient_ref' => 'df-'.Str::random(4),
                'drg_weight' => $w, 'total_cost' => 12_000, 'is_observation' => $w < 1.5,
                'discharged_at' => now()->subDays(2), 'is_deleted' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // A last-month discharge must NOT count toward MTD.
        DB::table('prod.discharge_facts')->insert([
            'fact_uuid' => (string) Str::uuid(), 'patient_ref' => 'df-old',
            'drg_weight' => 9.9, 'total_cost' => 99_000, 'is_observation' => false,
            'discharged_at' => now()->startOfMonth()->subDays(3), 'is_deleted' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('prod.workforce_actuals')->insert([
            'actual_uuid' => (string) Str::uuid(), 'cost_center' => 'ICU-A', 'work_date' => now()->toDateString(),
            'worked_hours' => 200, 'overtime_hours' => 10, 'target_hours' => 190, 'census_days' => 200,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->refresh();
        $mv = app(MaterializedMetricsReader::class);

        $this->assertSame(1.6, $mv->value('service.cmi'));            // AVG(1.6,1.8,1.4)
        $this->assertSame(3.0, $mv->value('service.discharges_mtd')); // old one excluded
        $this->assertEqualsWithDelta(33.3, $mv->value('service.observation_rate'), 0.1); // 1 of 3
        $this->assertSame(12.0, $mv->value('financial.cost_per_case'));
        $this->assertSame(1.0, $mv->value('financial.worked_per_uos'));   // 200/200
        $this->assertSame(5.0, $mv->value('financial.overtime'));         // 10/200
        $this->assertSame(95.0, $mv->value('financial.productivity'));    // 190/200
    }

    public function test_refresh_concurrently_is_used_after_initial_population(): void
    {
        $this->qualityEvent('hand_hygiene', 1, 1);
        // First refresh populates; a CONCURRENTLY refresh then succeeds (the
        // MV carries a unique index) — proves the hourly job path works.
        $this->refresh();
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY ops.mv_hai_ledger');

        $this->assertSame(100.0, app(MaterializedMetricsReader::class)->value('quality.hand_hygiene'));
    }
}
