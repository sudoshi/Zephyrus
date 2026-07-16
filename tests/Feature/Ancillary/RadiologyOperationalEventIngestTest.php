<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Exam;
use App\Models\Raw\InboundMessage;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RadiologyOperationalEventIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_forwarded_mpps_projects_acquisition_scanner_and_discontinued_states(): void
    {
        $source = $this->source('pacs.mpps', 'pacs', ['MPPS'], 'mpps');
        $this->ingest($source, 'MPPS', 'mpps-start');
        $this->ingest($source, 'MPPS', 'mpps-complete');
        $this->ingest($source, 'MPPS', 'mpps-discontinued');

        $exam = Exam::query()->where('source_exam_key', 'ACC-MPPS-1')->firstOrFail();
        $this->assertSame('complete', $exam->status);
        $this->assertSame('CT-ED-1', $exam->scanner->source_scanner_key);
        $this->assertNotNull($exam->started_at);
        $this->assertNotNull($exam->completed_at);
        $this->assertSame(64, strlen($exam->metadata['source_sop_instance_uid_hash']));
        $this->assertSame(['RAD_EXAM_START', 'RAD_EXAM_END'], $exam->ancillaryOrder->milestones()->orderBy('occurred_at')->pluck('milestone_code')->all());
        $this->assertDatabaseHas('prod.rad_exams', ['source_exam_key' => 'ACC-MPPS-DISC', 'status' => 'discontinued']);
    }

    public function test_mpps_precedence_beats_ris_while_both_assertions_are_retained(): void
    {
        $ris = $this->source('ris.mpps.fallback', 'ris', ['MPPS'], 'ris');
        $mpps = $this->source('pacs.mpps.authoritative', 'pacs', ['MPPS'], 'mpps');
        $risPayload = $this->fixture('mpps-complete');
        $risPayload['control_id'] = 'RIS-END-1';
        $risPayload['performed_end_at'] = '2026-07-11T13:10:00-04:00';
        $risPayload['occurred_at'] = '2026-07-11T13:10:00-04:00';
        app(AncillaryMessageIngestPipeline::class)->ingest($ris->source_key, new SourceMessage('MPPS', $risPayload));
        $this->ingest($mpps, 'MPPS', 'mpps-complete');

        $orderId = Exam::query()->where('source_exam_key', 'ACC-MPPS-1')->value('ancillary_order_id');
        $assertions = DB::table('prod.ancillary_milestones')->where('ancillary_order_id', $orderId)->where('milestone_code', 'RAD_EXAM_END')->get();
        $this->assertCount(2, $assertions);
        $selected = DB::table('prod.ancillary_current_assertions')->where('ancillary_order_id', $orderId)->where('milestone_code', 'RAD_EXAM_END')->first();
        $this->assertSame($mpps->source_id, $selected->source_id);
        $this->assertSame(1, $selected->source_rank);
        $this->assertSame(2, $selected->assertion_count);
    }

    public function test_pacs_transport_and_critical_result_relays_use_the_shared_order(): void
    {
        $mpps = $this->source('pacs.mpps.shared', 'pacs', ['MPPS'], 'mpps');
        $this->ingest($mpps, 'MPPS', 'mpps-complete');
        $this->ingest($this->source('pacs.storage', 'pacs', ['PACS'], 'pacs'), 'PACS', 'pacs-images');
        DB::table('prod.transport_requests')->insert([
            'request_uuid' => (string) Str::uuid(), 'request_type' => 'inpatient', 'priority' => 'routine', 'status' => 'requested',
            'patient_ref' => 'pseudonymous-transport-patient', 'origin' => 'Unit', 'destination' => 'Radiology',
            'transport_mode' => 'stretcher', 'requested_at' => now(), 'external_system' => 'zephyrus', 'external_id' => 'transport-501',
            'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $transport = $this->source('zephyrus.transport', 'zephyrus_transport', ['RAD_TRANSPORT'], 'zephyrus_transport');
        $this->ingest($transport, 'RAD_TRANSPORT', 'transport-requested');
        $this->ingest($transport, 'RAD_TRANSPORT', 'transport-completed');
        $ctrm = $this->source('ctrm.results', 'ctrm', ['CTRM'], 'ctrm');
        $this->ingest($ctrm, 'CTRM', 'critical-notified');
        $this->ingest($ctrm, 'CTRM', 'critical-acknowledged');

        $exam = Exam::query()->where('source_exam_key', 'ACC-MPPS-1')->firstOrFail();
        $this->assertSame(1, Exam::query()->count());
        $this->assertDatabaseHas('prod.transport_requests', ['external_id' => $exam->metadata['transport_request_ref']]);
        $this->assertEqualsCanonicalizing(['RAD_EXAM_END', 'RAD_IMAGES_AVAILABLE', 'RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE', 'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED'], $exam->ancillaryOrder->milestones()->pluck('milestone_code')->all());
        $critical = CriticalResult::query()->sole();
        $this->assertSame('acknowledged', $critical->policy_state);
        $this->assertSame('emergency_physician', $critical->recipient_role);
        $this->assertSame(2, DB::table('integration.provenance_records')->where('target_table', 'rad_critical_results')->count());
    }

    public function test_invalid_status_missing_source_identity_and_impossible_interval_dead_letter_safely(): void
    {
        $source = $this->source('pacs.mpps.invalid', 'pacs', ['MPPS'], 'mpps');
        $cases = [
            ['invalid_status', ['status' => 'UNKNOWN']],
            ['missing_source_identity', ['source_study_key' => null]],
            ['impossible_interval', ['performed_end_at' => '2026-07-11T12:00:00-04:00']],
        ];
        foreach ($cases as [$reason, $changes]) {
            $payload = [...$this->fixture('mpps-complete'), ...$changes, 'control_id' => 'INVALID-'.$reason];
            try {
                app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('MPPS', $payload));
                $this->fail("{$reason} was accepted");
            } catch (AncillaryIngestException $exception) {
                $this->assertSame($reason, $exception->reasonCode);
            }
            $this->assertDatabaseHas('raw.dead_letters', ['reason_code' => $reason]);
        }
        $this->assertSame(0, DB::table('integration.canonical_events')->count());
    }

    public function test_portable_exam_remains_valid_without_transport_and_relay_secrets_are_not_normalized(): void
    {
        $portable = Exam::factory()->portable()->create();
        $this->assertTrue($portable->is_portable);
        $this->assertFalse($portable->ancillaryOrder->milestones()->whereIn('milestone_code', ['RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE'])->exists());

        $source = $this->source('pacs.mpps.privacy', 'pacs', ['MPPS'], 'mpps');
        $this->ingest($source, 'MPPS', 'mpps-start');
        $normalized = json_encode(
            InboundMessage::query()->where('source_id', $source->source_id)->firstOrFail()->normalized_payload,
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString('1.2.840.secret.1', $normalized);
        $this->assertStringNotContainsString('signature_value', $normalized);
        $this->assertStringContainsString('source_sop_instance_uid_hash', $normalized);
    }

    private function source(string $key, string $systemClass, array $families, string $sourceClass): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            ...$this->canonicalIntegrationSourceScope(),
            'source_key' => $key, 'source_name' => $key, 'system_class' => $systemClass, 'interface_type' => 'forwarded_json',
            'active_status' => 'active', 'phi_allowed' => true,
            'metadata' => ['ancillary_source_class' => $sourceClass, 'ancillary_ingest' => ['enabled' => true, 'message_families' => $families, 'departments' => ['rad']]],
        ]);
    }

    private function ingest(Source $source, string $family, string $fixture): array
    {
        return app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage($family, $this->fixture($fixture)));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/json/radiology/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
