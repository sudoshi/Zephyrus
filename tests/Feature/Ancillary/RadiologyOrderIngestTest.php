<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Models\Radiology\Exam;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RadiologyOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config(['integrations.fhir_resources.ServiceRequest.enabled' => true, 'integrations.fhir_resources.Appointment.enabled' => true]);
    }

    public function test_golden_hl7_orders_project_stat_mri_portable_and_scheduled_exams(): void
    {
        $source = $this->source('ris.orders', ['ORM', 'OMI', 'SIU']);
        foreach (['stat-ed-ct', 'routine-inpatient-mri', 'portable-xr', 'scheduled-outpatient'] as $fixture) {
            app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture($fixture)]));
        }

        $this->assertSame(['CT', 'MRI', 'US', 'XR'], Exam::query()->orderBy('modality_code')->pluck('modality_code')->all());
        $this->assertDatabaseHas('prod.rad_exams', ['source_exam_key' => 'ACC-OP', 'status' => 'scheduled']);
        $this->assertDatabaseHas('prod.ancillary_orders', ['source_order_key' => 'RAD-STAT-CT', 'priority' => 'stat', 'patient_class' => 'emergency']);
        $this->assertContains('protocol', Exam::query()->where('source_exam_key', 'ACC-STAT-CT')->firstOrFail()->metadata['degraded_fields']);
    }

    public function test_modify_cancel_and_replay_update_one_exam_idempotently(): void
    {
        $source = $this->source('ris.lifecycle', ['ORM']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $message = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-ed-ct')]);
        $pipeline->ingest($source->source_key, $message);
        $this->assertTrue($pipeline->ingest($source->source_key, $message)['duplicate']);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('order-modification')]));
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('cancellation')]));

        $this->assertSame(1, Exam::query()->count());
        $exam = Exam::query()->firstOrFail();
        $this->assertSame('cancelled', $exam->status);
        $this->assertSame('CTHEADW', $exam->procedure_code);
        $this->assertNotNull($exam->cancelled_at);
    }

    public function test_multi_obr_produces_isolated_orders_and_exams(): void
    {
        $source = $this->source('ris.multi', ['ORM']);
        $receipt = app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('multi-obr')]));

        $this->assertCount(2, $receipt['canonical_event_ids']);
        $this->assertSame(['ACC-MULTI-A', 'ACC-MULTI-B'], Exam::query()->orderBy('source_exam_key')->pluck('source_exam_key')->all());
        $this->assertSame(2, Exam::query()->with('ancillaryOrder')->get()->pluck('ancillaryOrder.source_order_key')->unique()->count());
    }

    public function test_fhir_service_request_and_appointment_converge_without_fabricating_optional_fields(): void
    {
        $source = $this->source('ris.fhir', ['FHIR']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'ServiceRequest', 'id' => 'sr-1', 'meta' => ['versionId' => '1'], 'status' => 'active',
            'identifier' => [['value' => 'FHIR-RAD-1']], 'authoredOn' => '2026-07-11T12:00:00-04:00',
            'subject' => ['reference' => 'Patient/secret'], 'code' => ['coding' => [['code' => 'CTHEAD', 'display' => 'CT head']]],
        ]]));
        $pipeline->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'Appointment', 'id' => 'appt-1', 'meta' => ['versionId' => '1'], 'status' => 'booked',
            'created' => '2026-07-11T12:05:00-04:00',
            'identifier' => [['value' => 'FHIR-RAD-2']], 'start' => '2026-07-12T10:00:00-04:00', 'end' => '2026-07-12T10:30:00-04:00',
            'serviceType' => [['coding' => [['code' => 'USABD', 'display' => 'Ultrasound abdomen']]]],
        ]]));

        $this->assertSame(2, Exam::query()->count());
        $this->assertDatabaseHas('prod.rad_exams', ['source_exam_key' => 'FHIR-RAD-2', 'status' => 'scheduled', 'modality_code' => null]);
        $this->assertContains('modality', Exam::query()->where('source_exam_key', 'FHIR-RAD-1')->firstOrFail()->metadata['degraded_fields']);
    }

    public function test_changed_payload_under_explicit_idempotency_key_conflicts_safely(): void
    {
        $source = $this->source('ris.conflict', ['ORM']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('stat-ed-ct')], metadata: ['idempotency_key' => 'fixed-key']));

        $this->expectException(AncillaryIngestException::class);
        $this->expectExceptionMessage('different payload');
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('portable-xr')], metadata: ['idempotency_key' => 'fixed-key']));
    }

    private function source(string $key, array $families): Source
    {
        return app(SourceRegistryService::class)->ensureSource(['source_key' => $key, 'source_name' => $key, 'system_class' => 'ris', 'interface_type' => 'hl7v2', 'active_status' => 'active', 'phi_allowed' => true, 'metadata' => ['ancillary_ingest' => ['enabled' => true, 'message_families' => $families, 'departments' => ['rad']]]]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/hl7/radiology/{$name}.hl7"));
    }
}
