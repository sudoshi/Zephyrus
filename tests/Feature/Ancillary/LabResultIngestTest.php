<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Lab\CriticalValue;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabResultIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config([
            'integrations.fhir_resources.ServiceRequest.enabled' => true,
            'integrations.fhir_resources.Specimen.enabled' => true,
            'integrations.fhir_resources.Observation.enabled' => true,
            'integrations.fhir_resources.DiagnosticReport.enabled' => true,
        ]);
    }

    public function test_critical_stat_result_projects_exact_tat_and_waits_for_explicit_callback_evidence(): void
    {
        $source = $this->source('lis.results.critical', ['OML', 'ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $this->hl7('ed-stat-troponin'));
        $receipt = $pipeline->ingest($source->source_key, $this->hl7('critical-stat-final'));

        $this->assertCount(4, $receipt['canonical_event_ids']);
        $this->assertSame(1, Result::query()->count());
        $this->assertSame(1, CriticalValue::query()->count());
        $result = Result::query()->firstOrFail();
        $critical = CriticalValue::query()->firstOrFail();
        $specimen = Specimen::query()->where('source_specimen_key', 'SPEC-ED-TROP')->firstOrFail();
        $order = AncillaryOrder::query()->where('source_order_key', 'ACC-ED-TROP')->firstOrFail();

        $this->assertSame('final', $result->result_status);
        $this->assertSame('final', $result->result_stage);
        $this->assertTrue($result->auto_verified);
        $this->assertTrue($result->is_critical);
        $this->assertSame('critical', $result->abnormal_flag);
        $this->assertSame('ANALYZER-TROP-1', $result->analyzer_ref);
        $this->assertSame('LIS-MIDDLEWARE', $result->metadata['middleware_ref']);
        $this->assertSame('TROPONIN_I', $result->local_code);
        $this->assertSame('10839-9', $result->loinc_code);
        $this->assertSame($specimen->lab_specimen_id, $result->lab_specimen_id);
        $this->assertSame('received', $specimen->status);
        $this->assertSame(25, (int) $order->ordered_at->diffInMinutes($result->verified_at));
        $this->assertSame(8, (int) $specimen->collected_at->diffInMinutes($specimen->received_at));
        $this->assertSame(15, (int) $specimen->received_at->diffInMinutes($result->resulted_at));

        $this->assertSame('pending_notification', $critical->callback_state);
        $this->assertNull($critical->notified_at);
        $this->assertNull($critical->acknowledged_at);
        $this->assertDatabaseMissing('prod.ancillary_milestones', ['milestone_code' => 'LAB_CRITICAL_NOTIFIED']);
        $this->assertDatabaseMissing('prod.ancillary_milestones', ['milestone_code' => 'LAB_CRITICAL_ACKED']);

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('SECRET-CRITICAL-VALUE', $canonical);
        $this->assertSame('excluded', $result->metadata['value_storage']);

        $notify = $this->callbackSource('lis.callback.notify', 'LAB_CRITICAL_NOTIFIED');
        $ack = $this->callbackSource('lis.callback.ack', 'LAB_CRITICAL_ACKED');
        $pipeline->ingest($notify->source_key, $this->callbackMessage(
            controlId: 'CALLBACK-NOTIFY-1',
            occurredAt: '2026-07-12T08:27:00-04:00',
            extra: ['notified_at' => '2026-07-12T08:27:00-04:00', 'recipient_role' => 'ordering_clinician'],
        ));

        $critical->refresh();
        $this->assertSame('notified', $critical->callback_state);
        $this->assertNotNull($critical->notified_at);
        $this->assertNull($critical->acknowledged_at);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_CRITICAL_NOTIFIED')->count());
        $this->assertSame(0, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_CRITICAL_ACKED')->count());

        $pipeline->ingest($ack->source_key, $this->callbackMessage(
            controlId: 'CALLBACK-ACK-1',
            occurredAt: '2026-07-12T08:29:00-04:00',
            extra: ['notified_at' => '2026-07-12T08:27:00-04:00', 'acknowledged_at' => '2026-07-12T08:29:00-04:00', 'recipient_role' => 'ordering_clinician'],
        ));

        $critical->refresh();
        $this->assertSame('acknowledged', $critical->callback_state);
        $this->assertNotNull($critical->acknowledged_at);
        $this->assertSame(2, (int) $critical->notified_at->diffInMinutes($critical->acknowledged_at));
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_CRITICAL_ACKED')->count());
    }

    public function test_corrected_result_appends_a_child_version_without_erasing_the_original(): void
    {
        $source = $this->source('lis.results.corrected', ['ORM', 'ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $this->hl7('am-bmp'));
        $pipeline->ingest($source->source_key, $this->hl7('bmp-final-v1'));
        $correction = $this->hl7('bmp-corrected-v2');
        $pipeline->ingest($source->source_key, $correction);
        $this->assertTrue($pipeline->ingest($source->source_key, $correction)['duplicate']);

        $this->assertSame(2, Result::query()->count());
        $original = Result::query()->where('source_result_version', '1')->firstOrFail();
        $corrected = Result::query()->where('source_result_version', '2')->firstOrFail();
        $this->assertSame('final', $original->result_status);
        $this->assertNull($original->corrected_at);
        $this->assertSame('2026-07-12T10:30:00+00:00', $original->resulted_at->toIso8601String());
        $this->assertSame('corrected', $corrected->result_status);
        $this->assertSame('corrected', $corrected->result_stage);
        $this->assertSame($original->lab_result_id, $corrected->parent_lab_result_id);
        $this->assertTrue($original->corrections->contains($corrected));
        $this->assertSame('2026-07-12T11:00:00+00:00', $corrected->corrected_at->toIso8601String());
        $this->assertSame('RESULT-BMP', $original->source_result_key);
        $this->assertSame($original->source_result_key, $corrected->source_result_key);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_CORRECTED')->count());

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('SECRET-ORIGINAL', $canonical);
        $this->assertStringNotContainsString('SECRET-CORRECTED', $canonical);
    }

    public function test_hemolysis_rejection_creates_a_parented_pending_recollect_without_a_result(): void
    {
        $source = $this->source('lis.results.recollect', ['ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $message = $this->hl7('hemolyzed-recollect');
        $receipt = $pipeline->ingest($source->source_key, $message);

        $this->assertCount(4, $receipt['canonical_event_ids']);
        $this->assertTrue($pipeline->ingest($source->source_key, $message)['duplicate']);
        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(2, Specimen::query()->count());
        $this->assertSame(0, Result::query()->count());
        $parent = Specimen::query()->where('source_specimen_key', 'SPEC-HEMO-1')->firstOrFail();
        $child = Specimen::query()->where('source_specimen_key', 'SPEC-HEMO-2')->firstOrFail();
        $this->assertSame('recollect_requested', $parent->status);
        $this->assertSame('hemolysis', $parent->rejection_reason_code);
        $this->assertNotNull($parent->rejected_at);
        $this->assertNotNull($parent->recollect_ordered_at);
        $this->assertSame('collection_pending', $child->status);
        $this->assertNull($child->collected_at);
        $this->assertSame($parent->lab_specimen_id, $child->parent_specimen_id);
        $this->assertSame('recollect', $child->metadata['collection_reason']);
        $this->assertSame(['LAB_COLLECTED', 'LAB_RECEIVED', 'LAB_RECOLLECT_ORDERED', 'LAB_REJECTED'], DB::table('prod.ancillary_milestones')->orderBy('milestone_code')->pluck('milestone_code')->all());
    }

    public function test_microbiology_progression_appends_distinct_operational_stages(): void
    {
        $source = $this->source('lis.results.micro', ['ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        foreach (['micro-preliminary', 'micro-organism', 'micro-susceptibility', 'micro-final'] as $fixture) {
            $pipeline->ingest($source->source_key, $this->hl7($fixture));
        }

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $this->assertSame(4, Result::query()->count());
        $this->assertSame(
            ['preliminary', 'organism_identification', 'susceptibility', 'final'],
            Result::query()->orderBy('lab_result_id')->pluck('result_stage')->all(),
        );
        $this->assertSame(['1', '2', '3', '4'], Result::query()->orderBy('lab_result_id')->pluck('source_result_version')->all());
        $this->assertSame(1, Result::query()->distinct()->count('source_result_key'));
        $this->assertSame(3, Result::query()->where('result_status', 'preliminary')->count());
        $this->assertSame(1, Result::query()->where('result_status', 'final')->count());
        $this->assertSame(3, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_PRELIM')->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_RESULTED')->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_VERIFIED')->count());
        $this->assertSame('MICRO-WORKCELL-1', Result::query()->firstOrFail()->analyzer_ref);

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        foreach (['SECRET-PRELIM', 'SECRET-ORGANISM', 'SECRET-SUSCEPTIBILITY', 'SECRET-FINAL'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $canonical);
        }
    }

    public function test_multi_obx_group_creates_isolated_catalog_results_without_values(): void
    {
        $source = $this->source('lis.results.multi', ['ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $message = $this->hl7('multi-obx-results');
        $receipt = $pipeline->ingest($source->source_key, $message);

        $this->assertCount(6, $receipt['canonical_event_ids']);
        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $this->assertSame(2, Result::query()->count());
        $this->assertSame(['BMP', 'CBC'], Result::query()->orderBy('local_code')->pluck('local_code')->all());
        $this->assertSame(['RESULT-MULTI-BMP', 'RESULT-MULTI-CBC'], Result::query()->orderBy('source_result_key')->pluck('source_result_key')->all());
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_RESULTED')->count());
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_VERIFIED')->count());
        $this->assertTrue($pipeline->ingest($source->source_key, $message)['duplicate']);
        $this->assertSame(2, Result::query()->count());

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('SECRET-BMP', $canonical);
        $this->assertStringNotContainsString('SECRET-CBC', $canonical);
        foreach (Result::query()->get() as $result) {
            $this->assertSame('excluded', $result->metadata['value_storage']);
        }
    }

    public function test_fhir_observation_and_diagnostic_report_retain_versions_without_values_or_conclusions(): void
    {
        $source = $this->source('lis.results.fhir', ['FHIR'], 'fhir');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $this->serviceRequest());
        $pipeline->ingest($source->source_key, $this->fhirSpecimen());

        $observationV1 = $this->observation('1', 'final', '2026-07-12T08:25:00-04:00', 'SECRET-FHIR-V1');
        $pipeline->ingest($source->source_key, $observationV1);
        $observationV2 = $this->observation('2', 'corrected', '2026-07-12T08:40:00-04:00', 'SECRET-FHIR-V2');
        $pipeline->ingest($source->source_key, $observationV2);
        $report = new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'DiagnosticReport',
            'id' => 'dr-lab-1',
            'meta' => ['versionId' => '1'],
            'status' => 'final',
            'basedOn' => [['reference' => 'ServiceRequest/sr-result-1']],
            'subject' => ['reference' => 'Patient/secret-fhir-patient'],
            'specimen' => [['reference' => 'Specimen/spec-result-1']],
            'code' => ['coding' => [
                ['system' => 'urn:lab:local', 'code' => 'BMP', 'display' => 'Basic metabolic panel'],
                ['system' => 'http://loinc.org', 'code' => '24321-2', 'display' => 'Basic metabolic panel'],
            ]],
            'issued' => '2026-07-12T08:45:00-04:00',
            'conclusion' => 'SECRET-DIAGNOSTIC-CONCLUSION',
            'result' => [['reference' => 'Observation/obs-lab-1']],
        ]]);
        $pipeline->ingest($source->source_key, $report);
        $this->assertTrue($pipeline->ingest($source->source_key, $report)['duplicate']);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $this->assertSame(3, Result::query()->count());
        $original = Result::query()->where('source_result_key', 'Observation/obs-lab-1')->where('source_result_version', '1')->firstOrFail();
        $corrected = Result::query()->where('source_result_key', 'Observation/obs-lab-1')->where('source_result_version', '2')->firstOrFail();
        $diagnostic = Result::query()->where('source_result_key', 'DiagnosticReport/dr-lab-1')->firstOrFail();
        $this->assertSame($original->lab_result_id, $corrected->parent_lab_result_id);
        $this->assertSame('corrected', $corrected->result_status);
        $this->assertSame('final', $diagnostic->result_status);
        $this->assertSame(Specimen::query()->firstOrFail()->lab_specimen_id, $diagnostic->lab_specimen_id);
        $this->assertTrue($original->is_critical);
        $this->assertTrue($original->auto_verified);
        $this->assertSame(1, CriticalValue::query()->where('lab_result_id', $original->lab_result_id)->count());

        $canonical = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        foreach (['SECRET-FHIR-V1', 'SECRET-FHIR-V2', 'SECRET-DIAGNOSTIC-CONCLUSION'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $canonical);
        }
    }

    public function test_corrected_result_without_prior_version_fails_closed(): void
    {
        $source = $this->source('lis.results.invalid-correction', ['FHIR'], 'fhir');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $this->serviceRequest());
        $pipeline->ingest($source->source_key, $this->fhirSpecimen());

        try {
            $pipeline->ingest($source->source_key, $this->observation('2', 'corrected', '2026-07-12T08:40:00-04:00', 'SECRET-ORPHAN-CORRECTION'));
            $this->fail('An orphan corrected result was accepted.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('ancillary_pipeline_failed', $exception->reasonCode);
        }

        $this->assertSame(0, Result::query()->count());
        $this->assertDatabaseHas('raw.dead_letters', [
            'source_id' => $source->source_id,
            'reason_code' => 'ancillary_pipeline_failed',
        ]);
    }

    /** @param list<string> $families @param array<string,string> $milestoneMap */
    private function source(string $key, array $families, string $interfaceType = 'hl7v2', array $milestoneMap = []): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => 'lis',
            'interface_type' => $interfaceType,
            'active_status' => 'active',
            'phi_allowed' => true,
            'metadata' => ['ancillary_ingest' => [
                'enabled' => true,
                'message_families' => $families,
                'departments' => ['lab'],
                'milestone_map' => $milestoneMap,
            ]],
        ]);
    }

    private function callbackSource(string $key, string $milestone): Source
    {
        return $this->source($key, ['WORKFLOW'], 'structured', ['WORKFLOW' => $milestone]);
    }

    /** @param array<string,mixed> $extra */
    private function callbackMessage(string $controlId, string $occurredAt, array $extra): SourceMessage
    {
        return new SourceMessage('STRUCTURED.WORKFLOW', [
            'control_id' => $controlId,
            'source_order_key' => 'ACC-ED-TROP',
            'reconciliation_key' => 'ACC-ED-TROP',
            'source_result_key' => 'RESULT-TROP',
            'source_result_version' => '1',
            'occurred_at' => $occurredAt,
            ...$extra,
        ]);
    }

    private function hl7(string $fixture): SourceMessage
    {
        return new SourceMessage('HL7V2', ['raw_hl7' => (string) file_get_contents(base_path("tests/Fixtures/hl7/lab/{$fixture}.hl7"))]);
    }

    private function serviceRequest(): SourceMessage
    {
        return new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'ServiceRequest',
            'id' => 'sr-result-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['value' => 'FHIR-RESULT-ORDER']],
            'status' => 'active',
            'priority' => 'stat',
            'authoredOn' => '2026-07-12T08:00:00-04:00',
            'subject' => ['reference' => 'Patient/secret-fhir-patient'],
            'code' => ['coding' => [
                ['system' => 'urn:lab:local', 'code' => 'TROPONIN_I', 'display' => 'Troponin I'],
                ['system' => 'http://loinc.org', 'code' => '10839-9', 'display' => 'Troponin I'],
            ]],
            'specimen' => [['reference' => 'Specimen/spec-result-1']],
        ]]);
    }

    private function fhirSpecimen(): SourceMessage
    {
        return new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'Specimen',
            'id' => 'spec-result-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['value' => 'FHIR-SPEC-RESULT']],
            'request' => [['reference' => 'ServiceRequest/sr-result-1']],
            'subject' => ['reference' => 'Patient/secret-fhir-patient'],
            'type' => ['coding' => [['code' => 'PLAS']]],
            'collection' => ['collectedDateTime' => '2026-07-12T08:04:00-04:00'],
        ]]);
    }

    private function observation(string $version, string $status, string $issued, string $secretValue): SourceMessage
    {
        return new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'Observation',
            'id' => 'obs-lab-1',
            'meta' => ['versionId' => $version],
            'status' => $status,
            'basedOn' => [['reference' => 'ServiceRequest/sr-result-1']],
            'subject' => ['reference' => 'Patient/secret-fhir-patient'],
            'specimen' => ['reference' => 'Specimen/spec-result-1'],
            'code' => ['coding' => [
                ['system' => 'urn:lab:local', 'code' => 'TROPONIN_I', 'display' => 'Troponin I'],
                ['system' => 'http://loinc.org', 'code' => '10839-9', 'display' => 'Troponin I'],
            ]],
            'effectiveDateTime' => '2026-07-12T08:20:00-04:00',
            'issued' => $issued,
            'interpretation' => [['coding' => [['code' => 'HH']]]],
            'device' => ['reference' => 'Device/secret-analyzer'],
            'valueString' => $secretValue,
            'extension' => [['url' => 'https://zephyrus.example/fhir/StructureDefinition/auto-verified', 'valueBoolean' => true]],
        ]]);
    }
}
