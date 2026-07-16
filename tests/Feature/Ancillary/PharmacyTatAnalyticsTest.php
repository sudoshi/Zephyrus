<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Http\Controllers\Analytics\PharmacyTatController;
use App\Http\Controllers\Api\Pharmacy\PharmacyFlowBoardController;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Pharmacy\PharmacyTatAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PharmacyTatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        // A Monday so the queue-depth heatmap resolves to a single known weekday.
        $this->anchor = CarbonImmutable::parse('2026-07-13T14:30:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed([
            RtdcSeeder::class, CaseManagementSeeder::class, StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class, AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($this->anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_waterfall_percentiles_segments_and_heatmap_reconcile_to_the_governed_cohort(): void
    {
        $payload = app(PharmacyTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 100,
        ]);

        // Primary order-to-administration percentiles: scenarios 5 (80 min),
        // 6 (65 min), 8 (200 min) carry warehouse administration evidence.
        $postgres = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY value) AS median,
                   percentile_cont(0.9) WITHIN GROUP (ORDER BY value) AS p90
            FROM (VALUES (80.0), (65.0), (200.0)) samples(value)
        SQL);
        $this->assertSame('degraded', $payload['state']);
        $this->assertSame(3, $payload['summary']['count']);
        $this->assertSame((float) $postgres->median, (float) $payload['summary']['medianMinutes']);
        $this->assertSame((float) $postgres->p90, (float) $payload['summary']['p90Minutes']);
        $this->assertSame(115, $payload['summary']['meanMinutes']);
        $this->assertSame('warehouse_as_of', $payload['summary']['basis']);
        $this->assertSame('rx.study.order_admin', $payload['summary']['clockDefinition']['metricKey']);

        // The five-segment waterfall in governed order, each with median AND P90.
        $this->assertSame(
            ['verification', 'preparation', 'dispense', 'delivery', 'end_to_end'],
            array_column($payload['waterfall'], 'phase'),
        );
        $this->assertSame([
            'rx.study.order_verify', 'rx.study.verify_dispense', 'rx.study.dispense_deliver',
            'rx.study.deliver_admin', 'rx.study.order_admin',
        ], array_column(array_column($payload['waterfall'], 'definition'), 'metricKey'));
        $this->assertSame(
            ['real_time', 'real_time', 'real_time', 'warehouse_as_of', 'warehouse_as_of'],
            array_column($payload['waterfall'], 'basis'),
        );
        foreach ($payload['waterfall'] as $segment) {
            $this->assertGreaterThan(0, $segment['cohortCount']);
            $this->assertNotNull($segment['medianMinutes']);
            $this->assertNotNull($segment['p90Minutes']);
            $this->assertNotEmpty($segment['definition']['startMilestoneCode']);
            $this->assertNotEmpty($segment['definition']['stopMilestoneCode']);
        }
        $verification = collect($payload['waterfall'])->firstWhere('phase', 'verification');
        $this->assertSame(21, $verification['cohortCount']);
        $this->assertSame(15, $verification['medianMinutes']);
        $this->assertSame(30, $verification['p90Minutes']);
        $endToEnd = collect($payload['waterfall'])->firstWhere('phase', 'end_to_end');
        $this->assertSame(80, $endToEnd['medianMinutes']);
        $this->assertSame(176, $endToEnd['p90Minutes']);

        // Queue-depth heatmap: every queued verification counts once. The demo
        // seeds all 24 verifications on the Monday anchor day.
        $heatmap = $payload['queueDepthHeatmap'];
        $this->assertSame('real_time', $heatmap['basis']);
        $this->assertSame(24, $heatmap['totalQueued']);
        $this->assertSame(24, array_sum(array_column($heatmap['cells'], 'count')));
        $this->assertSame(['Mon'], collect($heatmap['cells'])->pluck('day')->unique()->values()->all());
        $this->assertSame(5, collect($heatmap['cells'])->firstWhere('hour', 9)['count']);
        $this->assertSame(5, $heatmap['peakCount']);

        // Breakdowns keep the primary cohort of 3 administered orders.
        foreach (['priority', 'shift', 'unit', 'branch'] as $dimension) {
            $this->assertSame('warehouse_as_of', $payload['breakdowns'][$dimension]['basis']);
            $this->assertSame(3, collect($payload['breakdowns'][$dimension]['points'])->sum('count'));
        }
        $this->assertSame($payload['lineage']['count'], $payload['coverage']['includedIntervalCount']);
    }

    public function test_administration_segments_are_batch_cutoff_qualified_and_never_real_time(): void
    {
        $payload = app(PharmacyTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 100,
        ]);

        // The warehouse extract cutoff is five hours behind the anchor.
        $administrationCutoff = '2026-07-13T09:30:00+00:00';
        $this->assertSame($administrationCutoff, $payload['administrationCutoffAt']);
        $this->assertSame($administrationCutoff, $payload['summary']['administrationCutoffAt']);
        $this->assertSame('batch', $payload['administrationFreshness']['status']);
        $this->assertNotSame('fresh', $payload['administrationFreshness']['status']);
        $this->assertSame($administrationCutoff, $payload['administrationFreshness']['sourceCutoffAt']);

        // Every admin-stopping segment carries the batch cutoff; real-time
        // segments carry the operational (anchor-time) cutoff instead.
        foreach ($payload['waterfall'] as $segment) {
            if ($segment['basis'] === 'warehouse_as_of') {
                $this->assertSame($administrationCutoff, $segment['sourceCutoffAt'], "{$segment['phase']} must carry the batch cutoff");
            } else {
                $this->assertNotSame($administrationCutoff, $segment['sourceCutoffAt'], "{$segment['phase']} is real-time and must not carry the batch cutoff");
            }
        }
        $this->assertSame('warehouse_as_of', $payload['dailyTrend']['basis']);
        $this->assertSame('warehouse_as_of', $payload['shortageImpact']['basis']);
        $this->assertSame($administrationCutoff, $payload['shortageImpact']['sourceCutoffAt']);

        // Every warehouse-stopped lineage interval names the RX_ADMINISTERED
        // stop with a warehouse basis and the batch cutoff as its received time.
        $adminIntervals = collect($payload['lineage']['items'])->where('basis', 'warehouse_as_of');
        $this->assertGreaterThan(0, $adminIntervals->count());
        foreach ($adminIntervals as $interval) {
            $this->assertSame('warehouse_as_of', $interval['stopAssertion']['basis']);
            $this->assertSame('RX_ADMINISTERED', $interval['stopAssertion']['code']);
            $this->assertSame($administrationCutoff, $interval['stopAssertion']['receivedAt']);
            $this->assertSame('real_time', $interval['startAssertion']['basis']);
        }
    }

    public function test_mapping_coverage_missing_dose_discharge_and_shortage_are_quantified_and_descriptive(): void
    {
        $payload = app(PharmacyTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 100,
        ]);

        // Unmapped-local data is counted, never hidden: the TPN order is the one
        // unmapped_local medication in the 24-order cohort.
        $this->assertSame(24, $payload['mappingCoverage']['totalOrderCount']);
        $this->assertSame(23, $payload['mappingCoverage']['mappedCount']);
        $this->assertSame(1, $payload['mappingCoverage']['unmappedLocalCount']);
        $this->assertSame(4.2, $payload['mappingCoverage']['unmappedLocalPercent']);
        $this->assertSame(
            ['mapped', 'unmapped_local'],
            array_column($payload['mappingCoverage']['points'], 'key'),
        );

        // Missing-dose Pareto: the scenario-10 ADC loop is one chain, grouped by
        // preparation branch, cumulative percentage closes at 100.
        $this->assertSame('real_time', $payload['missingDosePareto']['basis']);
        $this->assertSame(1, $payload['missingDosePareto']['chainCount']);
        $this->assertSame(['adc'], array_column($payload['missingDosePareto']['points'], 'key'));
        $this->assertSame(100.0, (float) collect($payload['missingDosePareto']['points'])->last()['cumulativePercent']);

        // Discharge-readiness trend: scenario 12 ready on time, scenario 13
        // (prior-auth pending) not ready.
        $this->assertSame(2, $payload['dischargeReadinessTrend']['cohortCount']);
        $this->assertSame(1, collect($payload['dischargeReadinessTrend']['points'])->sum('readyOnTimeCount'));
        $this->assertSame(50.0, (float) $payload['dischargeReadinessTrend']['points'][0]['readyOnTimePercent']);

        // Shortage impact is a labeled descriptive contrast, not a causal claim.
        $this->assertSame(1, $payload['shortageImpact']['shortageOrderCount']);
        $this->assertSame(
            ['on_shortage', 'not_on_shortage'],
            array_column($payload['shortageImpact']['points'], 'key'),
        );
        $this->assertStringContainsString('does not assert', $payload['shortageImpact']['clockDefinition']);
        $this->assertStringContainsString('no causal claim', $payload['missingDosePareto']['clockDefinition']);

        // Every study segment is a reference clock with no fabricated benchmark,
        // and no benchmark silently becomes an SLA.
        $study = collect($payload['benchmarkReferences'])->firstWhere('metricKey', 'rx.study.order_admin');
        $this->assertSame('no_numeric_benchmark', $study['classification']);
        $this->assertSame([], $study['numericLines']);
        $this->assertSame('warehouse_as_of', $study['basis']);
        $stat = collect($payload['benchmarkReferences'])->firstWhere('metricKey', 'rx.stat_dispense');
        $this->assertSame('local_policy', $stat['classification']);
        $this->assertSame([
            ['kind' => 'warning', 'value' => 10, 'unit' => 'minutes'],
            ['kind' => 'breach', 'value' => 15, 'unit' => 'minutes'],
        ], $stat['numericLines']);

        // No user-level dimension is ever computed or exposed.
        $this->assertFalse($payload['privacy']['individualPerformanceIncluded']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['patient_ref', 'verifier_ref', 'source_administration_key', 'diversion', 'staff_rank', 'risk_score'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_filters_freshness_empty_bounds_and_authenticated_route_contracts_are_honest(): void
    {
        $service = app(PharmacyTatAnalyticsService::class);

        $branchFiltered = $service->build(['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'branch' => 'iv_room', 'limit' => 100]);
        $this->assertGreaterThan(0, $branchFiltered['mappingCoverage']['totalOrderCount']);
        $this->assertSame(
            ['iv_room'],
            collect($branchFiltered['lineage']['items'])->pluck('branch')->unique()->values()->all(),
        );

        $limited = $service->build(['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 2]);
        $this->assertTrue($limited['coverage']['truncated']);
        $this->assertSame(22, $limited['coverage']['unanalyzedCandidateCount']);
        $this->assertSame('degraded', $limited['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'stale']);
        $stale = $service->build(['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13']);
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshnessStatus']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_orders')->update(['status' => 'current']);

        $empty = $service->build(['dateFrom' => '2026-06-01', 'dateTo' => '2026-06-02']);
        $this->assertSame('no_data', $empty['state']);
        $this->assertSame(0, $empty['summary']['count']);
        $this->assertNull($empty['summary']['medianMinutes']);
        $this->assertNull($empty['summary']['p90Minutes']);
        $this->assertSame(0, $empty['queueDepthHeatmap']['totalQueued']);

        // Query count is bounded and independent of the row limit.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 2]);
        $twoQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'limit' => 100]);
        $hundredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($twoQueries, $hundredQueries);
        $this->assertLessThanOrEqual(20, $hundredQueries);

        $filters = ['dateFrom' => '2026-07-13', 'dateTo' => '2026-07-13', 'priority' => 'first_dose', 'limit' => 50];
        $expected = json_decode(json_encode($service->build($filters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $query = http_build_query($filters);
        $user = User::factory()->create(['role' => 'pharmacy_manager', 'must_change_password' => false]);

        $this->getJson('/api/pharmacy/tat')->assertUnauthorized();
        $this->actingAs($user)->get('/analytics/pharmacy-tat?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analytics/PharmacyTat')->where('pharmacyTat', $expected));
        $api = $this->actingAs($user)->getJson('/api/pharmacy/tat?'.$query)->assertOk()->assertExactJson($expected);
        $this->assertStringContainsString('private', (string) $api->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $api->headers->get('Cache-Control'));
        $this->actingAs($user)->getJson('/api/pharmacy/tat?dateFrom=2026-01-01&dateTo=2026-07-13')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/pharmacy/tat?dateFrom=2027-01-01')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/pharmacy/tat?shift=am_draw')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/pharmacy/tat?branch=robot')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/pharmacy/tat?limit=2001')->assertUnprocessable();

        // Analytics owns the Study route uniquely; the API tail belongs to the
        // Pharmacy flow-board controller under the pharmacy prefix.
        $pageRoute = app('router')->getRoutes()->getByName('analytics.pharmacy-tat');
        $apiRoute = app('router')->getRoutes()->getByName('api.pharmacy.tat');
        $this->assertInstanceOf(Route::class, $pageRoute);
        $this->assertSame('analytics/pharmacy-tat', $pageRoute->uri());
        $this->assertSame(PharmacyTatController::class, $pageRoute->getActionName());
        $this->assertContains('App\\Http\\Middleware\\SessionAuthMiddleware', $pageRoute->gatherMiddleware());
        $this->assertInstanceOf(Route::class, $apiRoute);
        $this->assertSame('api/pharmacy/tat', $apiRoute->uri());
        $this->assertSame(PharmacyFlowBoardController::class.'@tat', $apiRoute->getActionName());
        $this->assertContains('auth', $apiRoute->gatherMiddleware());
    }
}
