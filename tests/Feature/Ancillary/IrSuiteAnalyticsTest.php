<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Scanner;
use App\Models\User;
use App\Services\Analytics\SuiteMetricCalculator;
use App\Services\Radiology\IrSuiteAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class IrSuiteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    private Source $source;

    private Scanner $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed(AncillaryReferenceSeeder::class);
        $this->source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'ir-test.mpps', 'source_name' => 'IR test MPPS', 'system_class' => 'mpps',
            'interface_type' => 'forwarded_json', 'active_status' => 'active', 'phi_allowed' => false,
            'metadata' => ['ancillary_source_class' => 'mpps'],
        ]);
        $this->room = Scanner::factory()->modality('IR')->create([
            'source_id' => $this->source->source_id, 'label' => 'IR Suite 1', 'capacity' => 1,
            'metadata' => [
                'ir_suite_declared' => true,
                'staffed_operating_hours' => [
                    'timezone' => 'UTC',
                    'weekly' => ['sunday' => [['start' => '08:00', 'end' => '16:00']]],
                ],
            ],
        ]);
        $this->case('08:00', '08:10', '09:00', 'IR_DRAIN', 'inpatient');
        $this->case('09:30', '09:40', '10:30', 'IR_BIOPSY', 'inpatient');
        $this->case('11:00', '11:20', '12:00', 'IR_EMBOLIZE', 'emergency');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_declared_windows_shared_suite_metrics_and_imaging_gates_reconcile_exactly(): void
    {
        $payload = app(IrSuiteAnalyticsService::class)->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12']);
        $shared = app(SuiteMetricCalculator::class)->utilization(
            [['start' => $this->at('08:00'), 'end' => $this->at('16:00')]],
            [
                ['start' => $this->at('08:10'), 'end' => $this->at('09:00')],
                ['start' => $this->at('09:40'), 'end' => $this->at('10:30')],
                ['start' => $this->at('11:20'), 'end' => $this->at('12:00')],
            ],
        );

        $this->assertSame('normal', $payload['state']);
        $this->assertFalse($payload['degradedMode']);
        $this->assertSame('fresh', $payload['freshnessStatus']);
        $this->assertSame(1, $payload['summary']['declaredRoomCount']);
        $this->assertSame(3, $payload['summary']['candidateCaseCount']);
        $this->assertSame($shared['availableMinutes'], $payload['summary']['availableMinutes']);
        $this->assertSame($shared['examMinutes'], $payload['summary']['occupiedMinutes']);
        $this->assertSame($shared['idleMinutes'], $payload['summary']['idleMinutes']);
        $this->assertSame($shared['utilizationPercent'], $payload['summary']['utilizationPercent']);
        $this->assertSame(0, $payload['summary']['reconciliationDeltaMinutes']);
        $this->assertSame(['eligibleCount' => 1, 'onTimeCount' => 1, 'percent' => 100, 'graceMinutes' => 15], $payload['summary']['fcots']);
        $this->assertSame(2, $payload['summary']['turnover']['count']);
        $this->assertSame(45, $payload['summary']['turnover']['median']);
        $this->assertSame(49, $payload['summary']['turnover']['p90']);
        $this->assertSame(45, $payload['summary']['turnover']['meanMinutes']);
        $this->assertSame(['preparation', 'transport', 'read'], array_column($payload['gates'], 'key'));
        foreach ($payload['gates'] as $gate) {
            $this->assertSame(3, $gate['count']);
            $this->assertSame(0, $gate['missingCount']);
            $this->assertSame(0, $gate['invalidCount']);
            $this->assertNotNull($gate['sourceCutoffAt']);
        }
        $this->assertSame('complete', $payload['coverage']['status']);
        $this->assertSame(0, $payload['coverage']['uncoveredRoomCount']);
        $this->assertSame(100, $payload['coverage']['percent']);
        $this->assertSame(3, $payload['lineage']['count']);
        $this->assertFalse($payload['privacy']['patientIdentifiersIncluded']);
        $this->assertFalse($payload['privacy']['clinicalReportTextIncluded']);
        $this->assertSame(SuiteMetricCalculator::class, $payload['ownership']['definitionAuthority']);
        $this->assertSame(SuiteMetricCalculator::class.'::utilizationPercent', collect($payload['definitions']['shared'])->firstWhere('label', 'Suite utilization')['authority']);
        $this->assertNotEmpty($payload['roomRunning']['points']);
        $this->assertSame(1, $payload['roomRunning']['maxRoomsRunning']);
        $this->assertStringNotContainsString('ir-test-patient', json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($payload['lineage']['items'] as $item) {
            $this->assertNotEmpty($item['startAssertion']['milestoneUuid']);
            $this->assertNotEmpty($item['endAssertion']['milestoneUuid']);
            $this->assertSame('ir-test.mpps', $item['startAssertion']['sourceKey']);
        }
    }

    public function test_filters_limits_missing_declarations_and_partial_evidence_are_honest(): void
    {
        $service = app(IrSuiteAnalyticsService::class);
        $filtered = $service->build([
            'dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12',
            'roomUuid' => (string) $this->room->scanner_uuid, 'patientClass' => 'emergency', 'limit' => 10,
        ]);
        $this->assertSame(1, $filtered['summary']['candidateCaseCount']);
        $this->assertSame('emergency', $filtered['filters']['patientClass']);

        $limited = $service->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12', 'limit' => 1]);
        $this->assertTrue($limited['coverage']['truncated']);
        $this->assertSame(2, $limited['coverage']['unanalyzedCandidateCount']);
        $this->assertSame('degraded', $limited['state']);

        $lastOrder = (int) Exam::query()->orderByDesc('rad_exam_id')->value('ancillary_order_id');
        $this->milestone(AncillaryOrder::query()->findOrFail($lastOrder), 'RAD_EXAM_START', $this->at('11:25'), 10);
        $conflicted = $service->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12']);
        $this->assertSame('normal', $conflicted['state']);
        $this->assertSame(2, collect($conflicted['lineage']['items'])->last()['startAssertion']['assertionCount']);

        $this->room->update(['metadata' => ['ir_suite_declared' => true]]);
        $missingSchedule = $service->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12']);
        $this->assertSame('degraded', $missingSchedule['state']);
        $this->assertSame(1, $missingSchedule['coverage']['missingOperatingWindowRoomCount']);
        $this->assertSame(1, $missingSchedule['coverage']['uncoveredRoomCount']);
        $this->assertNull($missingSchedule['summary']['utilizationPercent']);

        $this->room->update(['metadata' => ['ir_suite_declared' => false]]);
        $undeclared = $service->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12']);
        $this->assertSame('no_data', $undeclared['state']);
        $this->assertSame(0, $undeclared['summary']['declaredRoomCount']);
    }

    public function test_web_api_auth_validation_index_and_constant_query_shape_are_proven(): void
    {
        $this->assertNotNull(DB::table('pg_indexes')->where('schemaname', 'prod')->where('indexname', 'rad_exams_ir_scheduled_idx')->first());
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select(<<<'SQL'
            EXPLAIN SELECT rad_exam_id
            FROM prod.rad_exams
            WHERE is_ir = true
              AND scheduled_start_at >= '2026-07-12T00:00:00Z'
              AND scheduled_start_at <= '2026-07-12T23:59:59Z'
            ORDER BY scheduled_start_at, rad_exam_id
            LIMIT 100
        SQL))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString('rad_exams_ir_scheduled_idx', $plan);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(IrSuiteAnalyticsService::class)->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12', 'limit' => 1]);
        $oneQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        app(IrSuiteAnalyticsService::class)->build(['dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12', 'limit' => 100]);
        $hundredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($oneQueries, $hundredQueries);
        $this->assertLessThanOrEqual(8, $hundredQueries);

        $filters = [
            'dateFrom' => '2026-07-12', 'dateTo' => '2026-07-12',
            'roomUuid' => (string) $this->room->scanner_uuid, 'patientClass' => 'inpatient', 'limit' => 50,
        ];
        $expected = json_decode(json_encode(app(IrSuiteAnalyticsService::class)->build($filters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $query = http_build_query($filters);
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);

        $this->getJson('/api/radiology/ir-utilization')->assertUnauthorized();
        $this->actingAs($user)->get('/analytics/ir-utilization?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analytics/IrSuite')->where('irSuite', $expected));
        $api = $this->actingAs($user)->getJson('/api/radiology/ir-utilization?'.$query)->assertOk()->assertExactJson($expected);
        $this->assertStringContainsString('private', (string) $api->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $api->headers->get('Cache-Control'));
        $this->actingAs($user)->getJson('/api/radiology/ir-utilization?dateFrom=2026-06-01&dateTo=2026-07-12')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/ir-utilization?patientClass=invalid')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/ir-utilization?limit=1001')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/ir-utilization?roomUuid='.Str::uuid())->assertUnprocessable();
    }

    private function case(string $scheduled, string $start, string $end, string $procedure, string $patientClass): Exam
    {
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $this->source->source_id, 'patient_ref' => 'ir-test-patient-'.Str::uuid(),
            'patient_class' => $patientClass, 'ordered_at' => $this->at($scheduled)->subHour(), 'source_cutoff_at' => $this->anchor,
        ]);
        $exam = Exam::factory()->interventional()->create([
            'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $this->source->source_id,
            'rad_scanner_id' => $this->room->rad_scanner_id, 'procedure_code' => $procedure,
            'scheduled_start_at' => $this->at($scheduled), 'scheduled_end_at' => $this->at($end),
            'started_at' => $this->at($start), 'completed_at' => $this->at($end), 'status' => 'complete',
        ]);
        $timeline = [
            'RAD_ORDERED' => $this->at($scheduled)->subHour(), 'RAD_PREP_COMPLETE' => $this->at($scheduled)->subMinutes(20),
            'RAD_TRANSPORT_REQUESTED' => $this->at($scheduled)->subMinutes(15), 'RAD_TRANSPORT_COMPLETE' => $this->at($scheduled)->subMinutes(5),
            'RAD_EXAM_START' => $this->at($start), 'RAD_EXAM_END' => $this->at($end),
            'RAD_IMAGES_AVAILABLE' => $this->at($end)->addMinutes(2), 'RAD_FINAL' => $this->at($end)->addMinutes(20),
        ];
        foreach ($timeline as $code => $occurred) {
            $this->milestone($order, $code, $occurred, 100);
        }

        return $exam;
    }

    private function milestone(AncillaryOrder $order, string $code, CarbonImmutable $occurred, int $rank): AncillaryMilestone
    {
        return AncillaryMilestone::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $this->source->source_id,
            'milestone_code' => $code, 'occurred_at' => $occurred, 'received_at' => $occurred->addMinute(),
            'source_rank' => $rank, 'assertion_key' => 'ir-suite-test-'.Str::uuid(),
        ]);
    }

    private function at(string $clock): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-12 '.$clock.':00', 'UTC');
    }
}
