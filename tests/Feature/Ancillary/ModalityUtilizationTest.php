<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use App\Models\User;
use App\Services\Radiology\ModalityUtilizationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModalityUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $anchor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anchor = CarbonImmutable::parse('2026-07-12T14:00:00Z');
        CarbonImmutable::setTestNow($this->anchor);
        $this->seed(AncillaryReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_staffed_window_exam_and_downtime_intervals_reconcile_without_double_counting(): void
    {
        $source = $this->source('test.mpps', 'mpps');
        $scanner = $this->scanner($source, 'CT', 'CT 1');
        $this->exam($source, $scanner, 'emergency', '09:00', '10:30', true);
        $this->exam($source, $scanner, 'inpatient', '10:00', '11:00', true);
        $this->downtime($source, $scanner, 'scheduled', 'PREVENTIVE_MAINTENANCE', '11:00', '12:00');
        $this->downtime($source, $scanner, 'active', 'UNPLANNED_SERVICE', '11:30', '12:30');

        $payload = app(ModalityUtilizationService::class)->build(['date' => '2026-07-12']);

        $this->assertSame('normal', $payload['state']);
        $this->assertSame(480, $payload['summary']['availableMinutes']);
        $this->assertSame(120, $payload['summary']['examMinutes']);
        $this->assertSame(30, $payload['summary']['plannedDowntimeMinutes']);
        $this->assertSame(60, $payload['summary']['unplannedDowntimeMinutes']);
        $this->assertSame(270, $payload['summary']['idleMinutes']);
        $this->assertSame(25, $payload['summary']['utilizationPercent']);
        $this->assertSame(0, $payload['summary']['reconciliationDeltaMinutes']);
        $this->assertSame(['ed' => 1, 'inpatient' => 1, 'outpatient' => 0, 'other' => 0, 'total' => 2], $payload['summary']['patientMix']);
        $this->assertSame(['idle', 'exam', 'planned_downtime', 'unplanned_downtime', 'idle'], array_column($payload['scanners'][0]['segments'], 'type'));
    }

    public function test_missing_mpps_feed_withholds_utilization_and_marks_non_downtime_time_unknown(): void
    {
        $source = $this->source('test.ris', 'ris');
        $scanner = $this->scanner($source, 'CT', 'CT RIS');
        $this->exam($source, $scanner, 'outpatient', '09:00', '10:00', false);

        $payload = app(ModalityUtilizationService::class)->build(['date' => '2026-07-12']);

        $this->assertSame('degraded', $payload['state']);
        $this->assertSame('missing', $payload['coverage']['status']);
        $this->assertFalse($payload['coverage']['mppsFeedPresent']);
        $this->assertNull($payload['summary']['utilizationPercent']);
        $this->assertNull($payload['summary']['examMinutes']);
        $this->assertNull($payload['summary']['idleMinutes']);
        $this->assertSame('missing_feed', $payload['scanners'][0]['coverage']['status']);
        $this->assertContains('unknown', array_column($payload['scanners'][0]['segments'], 'type'));

        $unrelated = $this->source('test.mpps.unrelated', 'mpps');
        $unrelated->update(['protocol_health_status' => 'healthy', 'protocol_health_checked_at' => $this->anchor]);
        $unmapped = app(ModalityUtilizationService::class)->build(['date' => '2026-07-12']);
        $this->assertTrue($unmapped['coverage']['mppsFeedPresent']);
        $this->assertSame('partial', $unmapped['coverage']['status']);
        $this->assertSame('missing_feed', $unmapped['scanners'][0]['coverage']['status']);
        $this->assertNull($unmapped['summary']['utilizationPercent']);
    }

    public function test_date_time_and_modality_filters_clip_the_declared_denominator(): void
    {
        $source = $this->source('test.mpps.filters', 'mpps');
        $this->scanner($source, 'CT', 'CT Filter');
        $this->scanner($source, 'MRI', 'MRI Filter');

        $payload = app(ModalityUtilizationService::class)->build([
            'date' => '2026-07-12', 'startTime' => '09:00', 'endTime' => '12:00', 'modality' => 'CT',
        ]);

        $this->assertSame(1, $payload['summary']['scannerCount']);
        $this->assertSame('CT', $payload['scanners'][0]['modality']);
        $this->assertSame(180, $payload['summary']['availableMinutes']);
        $this->assertSame('09:00', $payload['filters']['startTime']);
        $this->assertSame('12:00', $payload['filters']['endTime']);
    }

    public function test_web_and_api_use_the_same_contract_and_reject_malformed_filters(): void
    {
        $source = $this->source('test.mpps.routes', 'mpps');
        $this->scanner($source, 'CT', 'CT Route');
        $user = User::factory()->create(['role' => 'radiology_manager', 'must_change_password' => false]);
        $filters = ['date' => '2026-07-12', 'startTime' => '08:00', 'endTime' => '16:00', 'modality' => 'CT'];
        $expected = app(ModalityUtilizationService::class)->build($filters);
        $query = http_build_query($filters);

        $this->actingAs($user)->get('/radiology/modality?'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Radiology/ModalityUtilization')->where('modalityUtilization', $expected));
        $this->actingAs($user)->getJson('/api/radiology/modality?'.$query)->assertOk()->assertExactJson($expected);
        $this->actingAs($user)->getJson('/api/radiology/modality?startTime=12:00&endTime=08:00')->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/radiology/modality?modality=UNKNOWN')->assertUnprocessable();
    }

    private function source(string $key, string $systemClass): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => $systemClass,
            'interface_type' => 'forwarded_json',
            'active_status' => 'active',
            'phi_allowed' => false,
            'metadata' => $systemClass === 'mpps' ? ['ancillary_source_class' => 'mpps'] : [],
        ]);
    }

    private function scanner(Source $source, string $modality, string $label): Scanner
    {
        return Scanner::factory()->modality($modality)->create([
            'source_id' => $source->source_id,
            'label' => $label,
            'metadata' => ['staffed_operating_hours' => [
                'timezone' => 'UTC',
                'weekly' => ['sunday' => [['start' => '08:00', 'end' => '16:00']]],
            ]],
        ]);
    }

    private function exam(Source $source, Scanner $scanner, string $patientClass, string $start, string $end, bool $withEvidence): Exam
    {
        $order = AncillaryOrder::factory()->radiology()->create([
            'source_id' => $source->source_id,
            'patient_class' => $patientClass,
            'ordered_at' => $this->at('08:00'),
            'source_cutoff_at' => $this->at($end),
        ]);
        $exam = Exam::factory()->create([
            'ancillary_order_id' => $order->ancillary_order_id,
            'source_id' => $source->source_id,
            'rad_scanner_id' => $scanner->rad_scanner_id,
            'modality_code' => $scanner->modality_code,
            'status' => 'complete',
            'started_at' => $this->at($start),
            'completed_at' => $this->at($end),
        ]);
        if ($withEvidence) {
            foreach ([['RAD_EXAM_START', $start], ['RAD_EXAM_END', $end]] as [$code, $clock]) {
                AncillaryMilestone::factory()->create([
                    'ancillary_order_id' => $order->ancillary_order_id,
                    'source_id' => $source->source_id,
                    'milestone_code' => $code,
                    'occurred_at' => $this->at($clock),
                    'received_at' => $this->at($clock)->addMinute(),
                ]);
            }
        }

        return $exam;
    }

    private function downtime(Source $source, Scanner $scanner, string $status, string $reason, string $start, string $end): void
    {
        ScannerDowntime::factory()->for($scanner, 'scanner')->create([
            'source_id' => $source->source_id,
            'status' => $status,
            'reason_code' => $reason,
            'starts_at' => $this->at($start),
            'ends_at' => $this->at($end),
        ]);
    }

    private function at(string $clock): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-12 '.$clock.':00', 'UTC');
    }
}
