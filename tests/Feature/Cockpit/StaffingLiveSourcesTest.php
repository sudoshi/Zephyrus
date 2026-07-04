<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Staffing\WorkforceActualsService;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P7 (Staffing) — OT% / agency RNs / callouts / sitters /
 * productivity retired from config demo constants to today's live
 * prod.workforce_actuals rows via WorkforceActualsService.
 */
class StaffingLiveSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function actual(string $costCenter, array $overrides): void
    {
        DB::table('prod.workforce_actuals')->insert($overrides + [
            'actual_uuid' => (string) Str::uuid(),
            'cost_center' => $costCenter,
            'work_date' => now()->toDateString(),
            'worked_hours' => 0,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_today_summary_aggregates_percentages_and_counts(): void
    {
        $this->actual('ICU-A', [
            'worked_hours' => 200, 'overtime_hours' => 10, 'target_hours' => 190,
            'agency_rn_headcount' => 3, 'callouts' => 4, 'sitters' => 2,
            'premium_cost' => 100_000, 'agency_cost' => 50_000,
        ]);
        $this->actual('MS-B', [
            'worked_hours' => 300, 'overtime_hours' => 16, 'target_hours' => 290,
            'agency_rn_headcount' => 5, 'callouts' => 3, 'sitters' => 1,
            'premium_cost' => 82_000, 'agency_cost' => 46_000,
        ]);
        // A stale row from yesterday must not leak into "today".
        $this->actual('ICU-A', ['work_date' => now()->subDay()->toDateString(), 'worked_hours' => 999, 'callouts' => 99]);

        $summary = app(WorkforceActualsService::class)->todaySummary();

        // 26 OT / 500 worked = 5.2%
        $this->assertSame(5.2, $summary['overtime_pct']);
        // 480 target / 500 worked = 96.0%
        $this->assertSame(96.0, $summary['productivity_pct']);
        $this->assertSame(8, $summary['agency_rns']);
        $this->assertSame(7, $summary['callouts']);
        $this->assertSame(3, $summary['sitters']);
        $this->assertSame(182_000.0, $summary['premium_cost']);
        $this->assertSame(96_000.0, $summary['agency_cost']);
    }

    public function test_empty_day_yields_null_tiles_not_fabricated_numbers(): void
    {
        $summary = app(WorkforceActualsService::class)->todaySummary();

        $this->assertNull($summary['overtime_pct']);
        $this->assertNull($summary['productivity_pct']);
        $this->assertNull($summary['callouts']);
    }

    public function test_staffing_domain_tiles_are_live_from_workforce_actuals(): void
    {
        $this->actual('ICU-A', [
            'worked_hours' => 500, 'overtime_hours' => 26, 'target_hours' => 480,
            'agency_rn_headcount' => 14, 'callouts' => 9, 'sitters' => 7,
        ]);

        $payload = app(SnapshotBuilder::class)->build();
        $tiles = collect($payload['domains']['staffing']['tiles'])->keyBy('key');

        $overtime = $tiles->get('staffing.overtime');
        $this->assertNotNull($overtime);
        $this->assertSame(5.2, $overtime['value']);
        // The point of P7: no demo provenance survives on the staffing tiles.
        $this->assertNull($overtime['metadata']['provenance'] ?? null);

        $this->assertSame(9.0, $tiles->get('staffing.callouts')['value']);
        $this->assertSame('warn', $tiles->get('staffing.callouts')['status']); // 9 ≥ warn 8, < crit 12
        $this->assertSame(14.0, $tiles->get('staffing.agency')['value']);
    }
}
