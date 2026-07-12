<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\OrUtilizationService;
use App\Services\Analytics\RoomRunningService;
use App\Services\Analytics\SuiteMetricCalculator;
use App\Services\Dashboard\PerioperativeMetricsService;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SuiteMetricReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_perioperative_dashboard_and_or_utilization_delegate_to_shared_suite_math(): void
    {
        $this->seed([RtdcSeeder::class, CaseManagementSeeder::class, CommandCenterDemoSeeder::class]);
        $calculator = app(SuiteMetricCalculator::class);

        $perioperative = app(PerioperativeMetricsService::class)->build();
        $firstCases = DB::select(<<<'SQL'
            SELECT first_start, sched
            FROM (
                SELECT DISTINCT ON (oc.room_id, oc.surgery_date)
                    l.procedure_start_time AS first_start,
                    oc.scheduled_start_time AS sched
                FROM prod.or_cases oc
                JOIN prod.or_logs l ON l.case_id = oc.case_id
                WHERE oc.is_deleted = false
                  AND l.is_deleted = false
                  AND l.procedure_start_time IS NOT NULL
                ORDER BY oc.room_id, oc.surgery_date, oc.scheduled_start_time ASC
            ) first_case
        SQL);
        $fcots = collect($firstCases)
            ->map(fn (object $row): ?bool => $calculator->firstCaseOnTime($row->sched, $row->first_start))
            ->filter(fn (?bool $value): bool => $value !== null);
        $expectedFcots = $fcots->isEmpty() ? 0 : (int) round(100 * $fcots->filter()->count() / $fcots->count());

        $this->assertSame($expectedFcots, $perioperative['monthToDate']['onTimeStarts']['overall']);

        $primaryLocation = DB::table('prod.or_cases as oc')
            ->join('prod.locations as l', 'l.location_id', '=', 'oc.location_id')
            ->where('oc.is_deleted', false)->where('l.is_deleted', false)
            ->groupBy('l.location_id')->orderByRaw('COUNT(*) DESC')->value('l.location_id');
        $roomDays = DB::table('prod.or_cases as oc')
            ->join('prod.case_metrics as cm', 'cm.case_id', '=', 'oc.case_id')
            ->where('oc.is_deleted', false)->where('cm.is_deleted', false)->where('oc.location_id', $primaryLocation)
            ->groupBy('oc.room_id', 'oc.surgery_date')
            ->get([DB::raw('SUM(COALESCE(cm.prime_time_minutes, 0)) AS occupied_minutes')]);
        $expectedUtilization = round((float) $roomDays->avg(
            fn (object $row): int|float|null => $calculator->utilizationPercent(
                (float) $row->occupied_minutes,
                SuiteMetricCalculator::PERIOP_STAFFED_PRIME_MINUTES,
                100,
            )
        ), 1);
        $orUtilization = app(OrUtilizationService::class)->build();
        $primary = collect($orUtilization['locations'])->first();

        $this->assertNotNull($primary);
        $this->assertSame($expectedUtilization, $primary['utilization']);
        $this->assertNotEmpty(app(RoomRunningService::class)->build()['sites']);
        $this->assertSame(SuiteMetricCalculator::class.'::firstCaseOnTime', $calculator->definitions()['fcots']['authority']);
        $this->assertSame(SuiteMetricCalculator::class.'::utilizationPercent', $calculator->definitions()['utilization']['authority']);
    }
}
