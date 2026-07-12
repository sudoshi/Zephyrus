<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Read;
use App\Models\User;
use App\Services\Radiology\RadiologyReadsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RadiologyReadsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T14:30:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed(AncillaryReferenceSeeder::class);
        $this->source = $this->reportingSource('test.reporting');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unread_states_aging_critical_loops_privacy_and_cockpit_health_reconcile(): void
    {
        $this->sourceFreshness('current', 360);

        $noReport = $this->exam('stat', 'emergency', 'CT', 'neuro', '11:00');
        $this->milestone($noReport, 'RAD_IMAGES_AVAILABLE', '11:05');

        $preliminary = $this->exam('urgent', 'inpatient', 'MRI', 'msk', '12:00');
        $this->milestone($preliminary, 'RAD_IMAGES_AVAILABLE', '12:00');
        $this->read($preliminary, 'preliminary', '12:30', ['metadata' => ['forbidden_report_text' => 'SECRET NARRATIVE']]);

        $final = $this->exam('routine', 'outpatient', 'US', 'body', '10:00');
        $this->read($final, 'preliminary', '10:10');
        $finalRead = $this->read($final, 'final', '10:40');

        $corrected = $this->exam('routine', 'inpatient', 'CT', 'body', '09:00');
        $this->read($corrected, 'preliminary', '09:15');
        $originalFinal = $this->read($corrected, 'final', '09:45');
        $this->read($corrected, 'corrected', '11:00', ['parent_rad_read_id' => $originalFinal->rad_read_id]);

        CriticalResult::factory()->for($finalRead, 'read')->create([
            'rad_exam_id' => $final->rad_exam_id, 'source_id' => $this->source->source_id,
            'policy_state' => 'notified', 'identified_at' => $this->at('13:00'), 'notified_at' => $this->at('13:05'),
            'acknowledged_at' => null, 'closed_at' => null,
        ]);
        CriticalResult::factory()->for($originalFinal, 'read')->create([
            'rad_exam_id' => $corrected->rad_exam_id, 'source_id' => $this->source->source_id,
            'policy_state' => 'acknowledged', 'identified_at' => $this->at('10:00'), 'notified_at' => $this->at('10:05'),
            'acknowledged_at' => $this->at('10:10'),
        ]);

        $service = app(RadiologyReadsService::class);
        $payload = $service->build();

        $this->assertSame('normal', $payload['state']);
        $this->assertTrue($payload['privacy']['patientContextIncluded']);
        $this->assertSame(2, $payload['unread']['total']);
        $this->assertSame(205, $payload['unread']['oldestAgeMinutes']);
        $this->assertSame([
            ['state' => 'no_report', 'count' => 1], ['state' => 'preliminary', 'count' => 1],
            ['state' => 'final', 'count' => 1], ['state' => 'corrected', 'count' => 1],
        ], $payload['reportStates']);

        $redacted = $service->build([], false);
        $this->assertFalse($redacted['privacy']['patientContextIncluded']);
        $this->assertTrue(collect($redacted['items'])->every(
            fn (array $item): bool => $item['patientRef'] === 'Patient context restricted'
        ));
        $this->assertSame(2, $payload['preliminaryToFinal']['count']);
        $this->assertSame(30.0, $payload['preliminaryToFinal']['medianMinutes']);
        $this->assertSame(30.0, $payload['preliminaryToFinal']['p90Minutes']);
        $this->assertSame(30, $payload['preliminaryToFinal']['maxMinutes']);
        $this->assertSame(1, $payload['criticalLoops']['summary']['open']);
        $this->assertSame(1, collect($payload['criticalLoops']['summary']['byState'])->firstWhere('state', 'notified')['count']);
        $this->assertSame(1, collect($payload['criticalLoops']['summary']['byState'])->firstWhere('state', 'acknowledged')['count']);
        $this->assertSame($payload['health'], $service->cockpitHealth());
        $this->assertSame(2, $payload['health']['unreadCount']);
        $this->assertSame(1, $payload['health']['openCriticalLoopCount']);
        $this->assertFalse($payload['privacy']['clinicalReportTextIncluded']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET NARRATIVE', $encoded);
        $this->assertCount(2, $payload['items']);
        $this->assertSame(['no_report', 'preliminary'], collect($payload['items'])->pluck('reportState')->sort()->values()->all());
        $this->assertTrue(collect($payload['items'])->every(fn (array $item): bool => str_starts_with($item['drillHref'], '/radiology/worklist?search=')));

        $correctedPayload = $service->build(['state' => 'corrected']);
        $this->assertCount(1, $correctedPayload['items']);
        $this->assertSame('corrected', $correctedPayload['items'][0]['reportState']);
        $this->assertSame(1, $correctedPayload['items'][0]['correctionCount']);
        $this->assertSame($this->at('09:45')->toAtomString(), $correctedPayload['items'][0]['firstFinalAt']);
        $this->assertSame($this->at('11:00')->toAtomString(), $correctedPayload['items'][0]['latestCorrectedAt']);
    }

    public function test_backlog_uses_full_comparable_hour_buckets_and_documents_missing_timestamps(): void
    {
        $this->sourceFreshness('current', 360);

        $open = $this->exam('stat', 'emergency', 'CT', 'neuro', '11:00');
        $this->milestone($open, 'RAD_IMAGES_AVAILABLE', '11:00');
        $prelim = $this->exam('urgent', 'inpatient', 'MRI', 'msk', '12:00');
        $this->read($prelim, 'preliminary', '12:15');
        $closed = $this->exam('routine', 'outpatient', 'US', 'body', '10:00');
        $this->read($closed, 'final', '10:40');
        $missingCompletion = $this->exam('routine', 'inpatient', 'CT', 'body', null);
        $this->read($missingCompletion, 'final', '13:10');

        $payload = app(RadiologyReadsService::class)->build(['windowHours' => 6]);
        $points = collect($payload['backlog']['points']);

        $this->assertSame('degraded', $payload['state']);
        $this->assertTrue($payload['backlog']['comparable']);
        $this->assertSame(60, $payload['backlog']['bucketMinutes']);
        $this->assertCount(6, $points);
        $this->assertSame('2026-07-12T08:00:00+00:00', $payload['backlog']['windowStart']);
        $this->assertSame('2026-07-12T14:00:00+00:00', $payload['backlog']['windowEnd']);
        $this->assertSame(1, $payload['backlog']['missing']['completionTimestampCount']);
        $this->assertSame(0, $payload['backlog']['missing']['finalTimestampCount']);
        $this->assertSame(1, $points->firstWhere('bucketEnd', '2026-07-12T11:00:00+00:00')['entered']);
        $this->assertSame(1, $points->firstWhere('bucketEnd', '2026-07-12T11:00:00+00:00')['finalized']);
        $this->assertSame(2, $points->last()['openAtEnd']);
        $this->assertTrue($points->every(fn (array $point): bool => CarbonImmutable::parse($point['bucketStart'])->diffInMinutes(CarbonImmutable::parse($point['bucketEnd'])) === 60.0));
        $this->assertStringContainsString('current partial hour is excluded', $payload['backlog']['definition']);
    }

    public function test_missing_stale_and_error_reporting_states_never_render_current_health(): void
    {
        $exam = $this->exam('routine', 'inpatient', 'CT', 'body', '12:00');
        $service = app(RadiologyReadsService::class);

        $missing = $service->build();
        $this->assertSame('missing_feed', $missing['state']);
        $this->assertSame('missing', $missing['health']['sourceState']);
        $this->assertSame('unknown', $missing['freshness']['status']);

        $this->milestone($exam, 'RAD_FINAL', '12:15', receivedAt: '12:16');
        $stale = $service->build();
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['health']['sourceState']);
        $this->assertSame('stale', $stale['freshness']['status']);

        $this->sourceFreshness('error', 60);
        $error = $service->build();
        $this->assertSame('source_error', $error['state']);
        $this->assertSame('error', $error['health']['sourceState']);
        $this->assertNotSame('normal', $error['items'][0]['urgency']);
    }

    public function test_critical_loop_transitions_are_distinct_and_route_contracts_match(): void
    {
        $this->sourceFreshness('current', 360);

        $exam = $this->exam('stat', 'emergency', 'CT', 'neuro', '12:00');
        $final = $this->read($exam, 'final', '12:30');
        $critical = CriticalResult::factory()->for($final, 'read')->create([
            'rad_exam_id' => $exam->rad_exam_id, 'source_id' => $this->source->source_id,
            'policy_state' => 'pending_notification', 'identified_at' => $this->at('13:00'),
            'notified_at' => null, 'acknowledged_at' => null, 'closed_at' => null,
        ]);
        $service = app(RadiologyReadsService::class);

        $pending = $service->build();
        $this->assertSame(1, collect($pending['criticalLoops']['summary']['byState'])->firstWhere('state', 'pending_notification')['count']);
        $critical->update(['policy_state' => 'notified', 'notified_at' => $this->at('13:05')]);
        $notified = $service->build();
        $this->assertSame(1, collect($notified['criticalLoops']['summary']['byState'])->firstWhere('state', 'notified')['count']);
        $critical->update(['policy_state' => 'acknowledged', 'acknowledged_at' => $this->at('13:10')]);
        $acknowledged = $service->build();
        $this->assertSame(0, $acknowledged['criticalLoops']['summary']['open']);
        $this->assertSame(1, collect($acknowledged['criticalLoops']['summary']['byState'])->firstWhere('state', 'acknowledged')['count']);
        $critical->update(['policy_state' => 'closed', 'closed_at' => $this->at('13:15')]);
        $closed = $service->build();
        $this->assertSame(1, collect($closed['criticalLoops']['summary']['byState'])->firstWhere('state', 'closed')['count']);

        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $filters = ['state' => 'final', 'priority' => 'stat', 'subspecialty' => 'neuro', 'modality' => 'CT', 'windowHours' => 12, 'limit' => 10];
        $expected = json_decode(json_encode($service->build($filters), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $query = http_build_query($filters);
        $this->actingAs($user)->get('/radiology/reads?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Radiology/Reads')->where('radiologyReads', $expected));
        $this->actingAs($user)->getJson('/api/radiology/reads?'.$query)->assertOk()->assertExactJson($expected);
        $this->actingAs($user)->getJson('/api/radiology/reads?state=dictated')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/reads?windowHours=7')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/reads?subspecialty=unknown')->assertUnprocessable();
    }

    private function reportingSource(string $key): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key, 'source_name' => $key, 'system_class' => 'radiology_reporting',
            'interface_type' => 'hl7v2', 'active_status' => 'active', 'phi_allowed' => false,
            'metadata' => ['ancillary_ingest' => ['enabled' => true, 'message_families' => ['ORU'], 'departments' => ['rad']]],
        ]);
    }

    private function exam(string $priority, string $patientClass, string $modality, string $subspecialty, ?string $completed): Exam
    {
        $ordered = $completed === null ? $this->at('11:00') : $this->at($completed)->subHour();
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $this->source->source_id, 'priority' => $priority, 'patient_class' => $patientClass,
            'ordered_at' => $ordered, 'source_cutoff_at' => $this->anchor,
        ]);

        return Exam::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id, 'source_id' => $this->source->source_id,
            'modality_code' => $modality, 'subspecialty_code' => $subspecialty,
            'status' => 'complete', 'started_at' => $completed === null ? null : $this->at($completed)->subMinutes(20),
            'completed_at' => $completed === null ? null : $this->at($completed),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function read(Exam $exam, string $status, string $clock, array $overrides = []): Read
    {
        $at = $this->at($clock);
        $attributes = [
            'rad_exam_id' => $exam->rad_exam_id, 'source_id' => $this->source->source_id,
            'status' => $status, 'subspecialty_code' => $exam->subspecialty_code,
            'preliminary_at' => $status === 'preliminary' ? $at : null,
            'final_at' => $status === 'final' || $status === 'addendum' ? $at : null,
            'corrected_at' => $status === 'corrected' ? $at : null,
            ...$overrides,
        ];
        $read = Read::factory()->create($attributes);
        $this->milestone($exam, $status === 'preliminary' ? 'RAD_PRELIM' : 'RAD_FINAL', $clock, receivedAt: CarbonImmutable::parse($clock, 'UTC')->format('H:i'));

        return $read;
    }

    private function milestone(Exam $exam, string $code, string $clock, ?string $receivedAt = null): void
    {
        $occurred = $this->at($clock);
        $received = $receivedAt === null ? $occurred->addMinute() : $this->at($receivedAt);
        AncillaryMilestone::factory()->create([
            'ancillary_order_id' => $exam->ancillary_order_id, 'source_id' => $this->source->source_id,
            'milestone_code' => $code, 'occurred_at' => $occurred, 'received_at' => $received,
            'assertion_key' => 'reads-test-'.Str::uuid(),
        ]);
    }

    private function sourceFreshness(string $status, int $warning): void
    {
        DB::table('ops.source_freshness')->updateOrInsert(['source_key' => 'ancillary_milestones'], [
            'source_label' => 'Radiology reporting feeds', 'source_schema' => 'prod', 'source_table' => 'ancillary_milestones',
            'freshness_column' => 'received_at', 'latest_observed_at' => $this->anchor, 'expected_lag_minutes' => 15,
            'warning_lag_minutes' => $warning, 'record_count' => 1, 'status' => $status, 'checked_at' => $this->anchor,
            'metadata' => '{}', 'created_at' => $this->anchor, 'updated_at' => $this->anchor,
        ]);
    }

    private function at(string $clock): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-12 '.$clock.':00', 'UTC');
    }
}
