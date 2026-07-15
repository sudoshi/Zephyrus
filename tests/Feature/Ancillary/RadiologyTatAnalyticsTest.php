<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use App\Models\User;
use App\Services\Radiology\RadiologyTatAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class RadiologyTatAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    private Source $source;

    /** @var list<Exam> */
    private array $validExams = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T14:30:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed(AncillaryReferenceSeeder::class);
        $this->source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'tat-test.reporting', 'source_name' => 'TAT test reporting',
            'system_class' => 'radiology_reporting', 'interface_type' => 'hl7v2',
            'active_status' => 'active', 'phi_allowed' => false,
            'metadata' => ['ancillary_ingest' => ['enabled' => true, 'message_families' => ['ORU'], 'departments' => ['rad']]],
        ]);
        $this->sourceFreshness('current', 1440);
        $this->seedCohort();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fixed_fixture_percentiles_match_postgresql_and_every_interval_retains_definition_and_assertions(): void
    {
        $payload = app(RadiologyTatAnalyticsService::class)->build([
            'dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 100,
        ]);
        $postgres = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY value) AS median,
                   percentile_cont(0.9) WITHIN GROUP (ORDER BY value) AS p90
            FROM (VALUES (150.0), (60.0), (90.0), (120.0), (30.0)) samples(value)
        SQL);

        $this->assertSame('degraded', $payload['state']);
        $this->assertSame(5, $payload['summary']['count']);
        $this->assertSame((float) $postgres->median, (float) $payload['summary']['median']);
        $this->assertSame((float) $postgres->p90, (float) $payload['summary']['p90']);
        $this->assertSame(90, $payload['summary']['meanMinutes']);
        $this->assertCount(6, $payload['waterfall']);
        $this->assertSame([
            'rad.study.order_exam_start', 'rad.study.exam_duration', 'rad.study.acquisition_pacs',
            'rad.study.images_preliminary', 'rad.study.images_final', 'rad.study.order_final',
        ], array_column(array_column($payload['waterfall'], 'definition'), 'metricKey'));
        $this->assertNotEmpty($payload['benchmarkLines']);
        $this->assertTrue(collect($payload['benchmarkLines'])->contains(fn (array $line): bool => $line['metricKey'] === 'rad.stat_order_final' && $line['lineKind'] === 'breach' && $line['valueMinutes'] === 120));
        $this->assertSame('Reference clock only; no governed numeric benchmark', $payload['dailyTrend']['benchmarkSourceLabel']);
        $this->assertSame(5, $payload['dailyTrend']['cohortCount']);
        $this->assertNotNull($payload['dailyTrend']['clockDefinition']);
        $this->assertSame('rad.study.order_final', $payload['dailyTrend']['clockDefinition']['metricKey']);
        $this->assertSame(['day', 'evening', 'night', 'weekend'], collect($payload['nightWeekendComparison']['points'])->pluck('key')->sort()->values()->all());
        foreach (['priority', 'modality', 'patientClass', 'shift'] as $dimension) {
            $this->assertSame(5, $payload['breakdowns'][$dimension]['cohortCount']);
            $this->assertNotEmpty($payload['breakdowns'][$dimension]['points']);
            $this->assertNotNull($payload['breakdowns'][$dimension]['sourceCutoffAt']);
            $this->assertNotEmpty($payload['breakdowns'][$dimension]['benchmarkSourceLabel']);
        }

        $this->assertSame(8, $payload['coverage']['candidateExamCount']);
        $this->assertSame(1, $payload['coverage']['excludedCorrectedExamCount']);
        $this->assertGreaterThan(0, $payload['coverage']['excludedNegativeIntervalCount']);
        $this->assertGreaterThan(0, $payload['coverage']['missingAssertionIntervalCount']);
        $this->assertSame(1, $payload['coverage']['selectedAssertionConflictCount']);
        $this->assertFalse($payload['coverage']['truncated']);
        $this->assertSame($payload['lineage']['count'], $payload['coverage']['includedIntervalCount']);
        $this->assertFalse($payload['privacy']['patientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['clinicalReportTextIncluded']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('tat-patient-', $encoded);

        foreach ($payload['lineage']['items'] as $interval) {
            $this->assertNotEmpty($interval['definitionUuid']);
            $this->assertNotEmpty($interval['startAssertion']['milestoneUuid']);
            $this->assertNotEmpty($interval['stopAssertion']['milestoneUuid']);
            $this->assertNotEmpty($interval['startAssertion']['sourceKey']);
            $this->assertGreaterThanOrEqual(0, $interval['minutes']);
        }
        $this->assertNotEmpty($payload['breachPareto']['points']);
        $this->assertSame(100.0, (float) collect($payload['breachPareto']['points'])->last()['cumulativePercent']);
    }

    public function test_filters_limit_freshness_and_empty_states_are_bounded_and_honest(): void
    {
        $service = app(RadiologyTatAnalyticsService::class);
        $filtered = $service->build([
            'dateFrom' => '2026-07-06', 'dateTo' => '2026-07-06',
            'priority' => 'stat', 'modality' => 'CT', 'patientClass' => 'emergency', 'shift' => 'day', 'limit' => 1,
        ]);
        $this->assertSame(1, $filtered['summary']['count']);
        $this->assertSame(150.0, $filtered['summary']['median']);
        $this->assertFalse($filtered['coverage']['truncated']);
        $this->assertSame('day', $filtered['nightWeekendComparison']['points'][0]['key']);

        $limited = $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 2]);
        $this->assertTrue($limited['coverage']['truncated']);
        $this->assertSame(6, $limited['coverage']['unanalyzedCandidateCount']);
        $this->assertSame('degraded', $limited['state']);

        DB::table('ops.source_freshness')->where('source_key', 'ancillary_milestones')->update(['status' => 'stale']);
        $stale = $service->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12']);
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['freshness']['status']);

        $empty = $service->build(['dateFrom' => '2026-06-01', 'dateTo' => '2026-06-02']);
        $this->assertSame('no_data', $empty['state']);
        $this->assertSame(0, $empty['summary']['count']);
        $this->assertNull($empty['summary']['median']);
        $this->assertNull($empty['summary']['p90']);
    }

    public function test_web_api_auth_contract_validation_navigation_route_and_analytics_index_are_proven(): void
    {
        $this->assertNotNull(DB::table('pg_indexes')->where('schemaname', 'prod')->where('indexname', 'ancillary_orders_department_ordered_idx')->first());
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select(<<<'SQL'
            EXPLAIN SELECT ancillary_order_id
            FROM prod.ancillary_orders
            WHERE department = 'rad'
              AND ordered_at >= '2026-07-01T00:00:00Z'
              AND ordered_at < '2026-07-13T00:00:00Z'
            ORDER BY ordered_at, ancillary_order_id
            LIMIT 100
        SQL))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString('ancillary_orders_department_ordered_idx', $plan);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RadiologyTatAnalyticsService::class)->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 2]);
        $twoQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        app(RadiologyTatAnalyticsService::class)->build(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-12', 'limit' => 100]);
        $hundredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($twoQueries, $hundredQueries);
        $this->assertLessThanOrEqual(10, $hundredQueries);

        $filters = [
            'dateFrom' => '2026-07-06', 'dateTo' => '2026-07-12', 'priority' => 'stat',
            'modality' => 'CT', 'patientClass' => 'emergency', 'limit' => 50,
        ];
        $expected = json_decode(json_encode(app(RadiologyTatAnalyticsService::class)->build($filters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $query = http_build_query($filters);
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);

        $this->getJson('/api/radiology/tat')->assertUnauthorized();
        $this->actingAs($user)->get('/analytics/radiology-tat?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analytics/RadiologyTat')->where('radiologyTat', $expected));
        $api = $this->actingAs($user)->getJson('/api/radiology/tat?'.$query)->assertOk()->assertExactJson($expected);
        $this->assertStringContainsString('private', (string) $api->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $api->headers->get('Cache-Control'));
        $this->actingAs($user)->getJson('/api/radiology/tat?dateFrom=2026-01-01&dateTo=2026-07-12')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/tat?dateFrom=2027-01-01')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/tat?shift=overnight')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/tat?limit=2001')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/tat?modality=BAD')->assertUnprocessable();
    }

    private function seedCohort(): void
    {
        $this->validExams[] = $this->examWithTimeline('2026-07-06 09:00', 150, 'stat', 'emergency', 'CT', conflict: true);
        $this->validExams[] = $this->examWithTimeline('2026-07-07 16:00', 60, 'urgent', 'inpatient', 'MRI');
        $this->validExams[] = $this->examWithTimeline('2026-07-08 22:00', 90, 'routine', 'outpatient', 'US');
        $this->validExams[] = $this->examWithTimeline('2026-07-11 10:00', 120, 'discharge', 'inpatient', 'CT');
        $this->validExams[] = $this->examWithTimeline('2026-07-12 08:00', 30, 'stat', 'emergency', 'CT');
        $corrected = $this->examWithTimeline('2026-07-09 10:00', 45, 'routine', 'inpatient', 'XR');
        Read::factory()->create([
            'rad_exam_id' => $corrected->rad_exam_id, 'source_id' => $this->source->source_id,
            'status' => 'corrected', 'final_at' => null, 'corrected_at' => $this->at('2026-07-09 11:00'),
        ]);
        $this->examWithTimeline('2026-07-10 12:00', -5, 'routine', 'observation', 'CT');
        $this->examWithTimeline('2026-07-10 13:00', null, 'urgent', 'inpatient', 'MRI');

        $firstOrderId = (int) $this->validExams[0]->ancillary_order_id;
        $start = DB::table('prod.ancillary_current_assertions')->where('ancillary_order_id', $firstOrderId)->where('milestone_code', 'RAD_ORDERED')->first();
        $stop = DB::table('prod.ancillary_current_assertions')->where('ancillary_order_id', $firstOrderId)->where('milestone_code', 'RAD_FINAL')->first();
        $definitionId = AncillarySlaDefinition::query()->where('metric_key', 'rad.stat_order_final')->value('ancillary_sla_definition_id');
        DB::table('prod.ancillary_breaches')->insert([
            'breach_uuid' => (string) Str::uuid(), 'ancillary_order_id' => $firstOrderId,
            'ancillary_sla_definition_id' => $definitionId, 'status' => 'cleared',
            'warning_at' => $this->at('2026-07-06 10:30'), 'breached_at' => $this->at('2026-07-06 11:00'),
            'cleared_at' => $this->at('2026-07-06 11:30'), 'start_assertion_id' => $start->ancillary_milestone_id,
            'stop_assertion_id' => $stop->ancillary_milestone_id, 'elapsed_minutes_at_open' => 120,
            'elapsed_minutes_at_clear' => 150, 'opened_event_uuid' => (string) Str::uuid(),
            'cleared_event_uuid' => (string) Str::uuid(), 'last_evaluated_at' => $this->anchor,
            'metadata' => '{}', 'created_at' => $this->anchor, 'updated_at' => $this->anchor,
        ]);
    }

    private function examWithTimeline(
        string $orderedClock,
        ?int $totalMinutes,
        string $priority,
        string $patientClass,
        string $modality,
        bool $conflict = false,
    ): Exam {
        $ordered = $this->at($orderedClock);
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $this->source->source_id, 'patient_ref' => 'tat-patient-'.Str::uuid(),
            'priority' => $priority, 'patient_class' => $patientClass,
            'ordered_at' => $ordered, 'source_cutoff_at' => $this->anchor,
        ]);
        $exam = Exam::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $this->source->source_id,
            'modality_code' => $modality, 'status' => 'complete',
            'started_at' => $ordered->addMinutes(5), 'completed_at' => $ordered->addMinutes(10),
        ]);
        $timeline = [
            'RAD_ORDERED' => 0, 'RAD_EXAM_START' => 5, 'RAD_EXAM_END' => 10,
            'RAD_IMAGES_AVAILABLE' => 15, 'RAD_PRELIM' => 20,
        ];
        if ($totalMinutes !== null) {
            $timeline['RAD_FINAL'] = $totalMinutes;
        }
        foreach ($timeline as $code => $minutes) {
            $this->milestone($order, $code, $ordered->addMinutes($minutes), 100);
        }
        if ($conflict && $totalMinutes !== null) {
            $this->milestone($order, 'RAD_FINAL', $ordered->addMinutes($totalMinutes + 3), 200);
        }

        return $exam;
    }

    private function milestone(AncillaryOrder $order, string $code, CarbonImmutable $occurred, int $rank): AncillaryMilestone
    {
        return AncillaryMilestone::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $this->source->source_id,
            'milestone_code' => $code, 'occurred_at' => $occurred, 'received_at' => $occurred->addMinute(),
            'source_rank' => $rank, 'assertion_key' => 'tat-test-'.Str::uuid(),
        ]);
    }

    private function sourceFreshness(string $status, int $warningMinutes): void
    {
        DB::table('ops.source_freshness')->updateOrInsert(['source_key' => 'ancillary_milestones'], [
            'source_label' => 'Radiology milestone feeds', 'source_schema' => 'prod', 'source_table' => 'ancillary_milestones',
            'freshness_column' => 'received_at', 'latest_observed_at' => $this->anchor, 'expected_lag_minutes' => 15,
            'warning_lag_minutes' => $warningMinutes, 'record_count' => 1, 'status' => $status, 'checked_at' => $this->anchor,
            'metadata' => '{}', 'created_at' => $this->anchor, 'updated_at' => $this->anchor,
        ]);
    }

    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }
}
