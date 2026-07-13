<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Http\Controllers\Analytics\LabTatController;
use App\Http\Controllers\Api\Lab\LabFlowBoardController;
use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use App\Services\Lab\LabTatAnalyticsService;
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

final class LabTatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T14:30:00Z');
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

    public function test_fixed_demo_percentiles_segments_and_am_readiness_reconcile_to_governed_assertions(): void
    {
        $payload = app(LabTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 100,
        ]);
        $postgres = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY value) AS median,
                   percentile_cont(0.9) WITHIN GROUP (ORDER BY value) AS p90
            FROM (VALUES (62.0), (75.0), (80.0), (115.0), (130.0), (30.0), (45.0)) samples(value)
        SQL);

        $this->assertSame('degraded', $payload['state']);
        $this->assertSame(7, $payload['summary']['count']);
        $this->assertSame((float) $postgres->median, (float) $payload['summary']['medianMinutes']);
        $this->assertSame((float) $postgres->p90, (float) $payload['summary']['p90Minutes']);
        $this->assertSame(76.71, (float) $payload['summary']['meanMinutes']);
        $this->assertSame('lab.study.order_verify', $payload['summary']['clockDefinition']['metricKey']);
        $this->assertSame(
            ['collection', 'transport', 'analytic', 'post_analytic', 'end_to_end'],
            array_column($payload['waterfall'], 'phase'),
        );
        $this->assertSame([
            'lab.study.order_collect', 'lab.study.collect_receive', 'lab.study.receive_result',
            'lab.study.result_verify', 'lab.study.order_verify',
        ], array_column(array_column($payload['waterfall'], 'definition'), 'metricKey'));
        foreach ($payload['waterfall'] as $segment) {
            $this->assertGreaterThan(0, $segment['cohortCount']);
            $this->assertNotNull($segment['medianMinutes']);
            $this->assertNotNull($segment['p90Minutes']);
            $this->assertNotEmpty($segment['definition']['startMilestoneCode']);
            $this->assertNotEmpty($segment['definition']['stopMilestoneCode']);
            $this->assertNotEmpty($segment['benchmarkSourceLabel']);
        }

        $this->assertSame(6, $payload['amReadiness']['cohortCount']);
        $this->assertSame(50.0, $payload['amReadiness']['points'][0]['readyPercent']);
        $this->assertSame(83.3, $payload['amReadiness']['points'][1]['readyPercent']);
        $this->assertSame(83.3, collect($payload['amReadiness']['points'])->last()['readyPercent']);
        $this->assertSame('LAB_ORDERED', $payload['amReadiness']['clockDefinition']['startMilestoneCode']);
        $this->assertSame('LAB_VERIFIED', $payload['amReadiness']['clockDefinition']['stopMilestoneCode']);

        foreach (['test', 'priority', 'patientClass', 'shift'] as $dimension) {
            $this->assertSame(7, $payload['breakdowns'][$dimension]['cohortCount']);
            $this->assertNotEmpty($payload['breakdowns'][$dimension]['points']);
            $this->assertNotNull($payload['breakdowns'][$dimension]['clockDefinition']);
            $this->assertNotNull($payload['breakdowns'][$dimension]['sourceCutoffAt']);
            $this->assertNotEmpty($payload['breakdowns'][$dimension]['benchmarkSourceLabel']);
        }
        $this->assertSame(['blood_count', 'metabolic_panel', 'troponin'], collect($payload['breakdowns']['test']['points'])->pluck('key')->sort()->values()->all());
        $this->assertSame($payload['lineage']['count'], $payload['coverage']['includedIntervalCount']);
        foreach ($payload['lineage']['items'] as $interval) {
            $this->assertNotEmpty($interval['definitionUuid']);
            $this->assertNotEmpty($interval['startAssertion']['milestoneUuid']);
            $this->assertNotEmpty($interval['stopAssertion']['milestoneUuid']);
            $this->assertGreaterThanOrEqual(0, $interval['minutes']);
        }
    }

    public function test_quality_callbacks_barriers_benchmarks_and_non_comparable_cohorts_are_explicit_and_private(): void
    {
        $payload = app(LabTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 100,
        ]);

        $this->assertSame(7, $payload['autoVerification']['cohortCount']);
        $this->assertSame(2, collect($payload['autoVerification']['points'])->sum('autoVerifiedCount'));
        $this->assertSame(13, $payload['specimenQuality']['denominator']);
        $this->assertSame(2, $payload['specimenQuality']['rejectedCount']);
        $this->assertSame(15.4, $payload['specimenQuality']['rejectionRatePercent']);
        $this->assertSame(2, $payload['specimenQuality']['recollectCount']);
        $this->assertSame(15.4, $payload['specimenQuality']['recollectRatePercent']);
        $this->assertSame(['CLOTTED', 'HEMOLYZED'], collect($payload['specimenQuality']['reasonCounts'])->pluck('key')->sort()->values()->all());

        $this->assertSame(2, $payload['criticalCallbacks']['cohortCount']);
        $this->assertSame(1, $payload['criticalCallbacks']['openCount']);
        $this->assertSame(12, $payload['criticalCallbacks']['medianMinutes']);
        $this->assertSame(0, $payload['criticalCallbacks']['invalidIntervalCount']);
        $this->assertSame('lab.critical_notify', $payload['criticalCallbacks']['clockDefinition']['metricKey']);
        $this->assertNotEmpty($payload['barrierPareto']['points']);
        $this->assertSame(100.0, (float) collect($payload['barrierPareto']['points'])->last()['cumulativePercent']);

        $this->assertSame('historical_study_only', $payload['cohorts']['microbiology']['windowClass']);
        $this->assertSame(1, $payload['cohorts']['microbiology']['historicalCount']);
        $this->assertSame(0, $payload['cohorts']['microbiology']['currentCount']);
        $this->assertStringContainsString('outside the live operational window', $payload['cohorts']['microbiology']['windowLabel']);
        $this->assertSame(['final', 'organism_identification', 'preliminary', 'susceptibility'], array_column($payload['cohorts']['microbiology']['stageCounts'], 'key'));
        $this->assertSame('mixed_current_and_historical', $payload['cohorts']['anatomicPathology']['windowClass']);
        $this->assertSame(1, $payload['cohorts']['anatomicPathology']['historicalCount']);
        $this->assertSame(2130, $payload['cohorts']['anatomicPathology']['signOut']['medianMinutes']);
        $this->assertSame(18, $payload['cohorts']['anatomicPathology']['frozen']['medianMinutes']);
        $this->assertSame(6, $payload['cohorts']['bloodBank']['candidateCount']);
        $this->assertSame(60, $payload['cohorts']['bloodBank']['typeScreen']['medianMinutes']);
        $this->assertSame(125, $payload['cohorts']['bloodBank']['crossmatch']['medianMinutes']);
        $this->assertSame(230, $payload['cohorts']['bloodBank']['issue']['medianMinutes']);

        $stat = collect($payload['benchmarkReferences'])->firstWhere('metricKey', 'lab.stat_tat');
        $this->assertSame('local_policy', $stat['classification']);
        $this->assertSame([
            ['kind' => 'warning', 'value' => 45, 'unit' => 'minutes'],
            ['kind' => 'breach', 'value' => 60, 'unit' => 'minutes'],
        ], $stat['numericLines']);
        $collect = collect($payload['benchmarkReferences'])->firstWhere('metricKey', 'lab.collect_receive');
        $this->assertSame('established_reference', $collect['classification']);
        $this->assertSame([], $collect['numericLines']);
        $this->assertFalse($payload['privacy']['patientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['clinicalResultContentIncluded']);
        $this->assertFalse($payload['privacy']['sourceResultKeysIncluded']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['patient_ref', 'specimen_uuid', 'source_specimen_key', 'result_uuid', 'source_result_key', 'source_critical_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_filters_limits_freshness_empty_query_bound_and_authenticated_route_contracts_are_honest(): void
    {
        $service = app(LabTatAnalyticsService::class);
        $filtered = $service->build([
            'dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12', 'priority' => 'stat',
            'testFamily' => 'troponin', 'patientClass' => 'emergency', 'shift' => 'weekend', 'limit' => 100,
        ]);
        $this->assertSame(1, $filtered['summary']['count']);
        $this->assertSame(30, $filtered['summary']['medianMinutes']);
        $this->assertSame(['troponin'], array_column($filtered['breakdowns']['test']['points'], 'key'));

        $limited = $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 2]);
        $this->assertTrue($limited['coverage']['truncated']);
        $this->assertSame(11, $limited['coverage']['unanalyzedCandidateCount']);
        $this->assertSame('degraded', $limited['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->update(['status' => 'stale']);
        $stale = $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12']);
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshnessStatus']);
        DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->update(['status' => 'current']);

        $empty = $service->build(['dateFrom' => '2026-06-01', 'dateTo' => '2026-06-02']);
        $this->assertSame('no_data', $empty['state']);
        $this->assertSame(0, $empty['summary']['count']);
        $this->assertNull($empty['summary']['medianMinutes']);
        $this->assertNull($empty['summary']['p90Minutes']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 2]);
        $twoQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 100]);
        $hundredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($twoQueries, $hundredQueries);
        $this->assertLessThanOrEqual(20, $hundredQueries);

        $filters = ['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'testFamily' => 'metabolic_panel', 'limit' => 50];
        $expected = json_decode(json_encode($service->build($filters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $query = http_build_query($filters);
        $user = User::factory()->create(['role' => 'lab_manager', 'must_change_password' => false]);

        $this->getJson('/api/lab/tat')->assertUnauthorized();
        $this->actingAs($user)->get('/analytics/lab-tat?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analytics/LabTat')->where('labTat', $expected));
        $api = $this->actingAs($user)->getJson('/api/lab/tat?'.$query)->assertOk()->assertExactJson($expected);
        $this->assertStringContainsString('private', (string) $api->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $api->headers->get('Cache-Control'));
        $this->actingAs($user)->getJson('/api/lab/tat?dateFrom=2026-01-01&dateTo=2026-07-12')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/lab/tat?dateFrom=2027-01-01')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/lab/tat?shift=am_draw')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/lab/tat?limit=2001')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/lab/tat?testFamily=culture')->assertUnprocessable();

        $pageRoute = app('router')->getRoutes()->getByName('analytics.lab-tat');
        $apiRoute = app('router')->getRoutes()->getByName('api.lab.tat');
        $this->assertInstanceOf(Route::class, $pageRoute);
        $this->assertSame('analytics/lab-tat', $pageRoute->uri());
        $this->assertSame(LabTatController::class, $pageRoute->getActionName());
        $this->assertContains('App\\Http\\Middleware\\SessionAuthMiddleware', $pageRoute->gatherMiddleware());
        $this->assertInstanceOf(Route::class, $apiRoute);
        $this->assertSame('api/lab/tat', $apiRoute->uri());
        $this->assertSame(LabFlowBoardController::class.'@tat', $apiRoute->getActionName());
        $this->assertContains('auth', $apiRoute->gatherMiddleware());
    }
}
