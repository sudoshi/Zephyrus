<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Models\Radiology\Read;
use App\Services\Ancillary\AncillaryStatistics;
use App\Services\Radiology\RadiologyReadContractSerializer;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RadiologyResultIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config(['integrations.fhir_resources.ImagingStudy.enabled' => true, 'integrations.fhir_resources.DiagnosticReport.enabled' => true]);
    }

    public function test_preliminary_final_and_corrected_reports_append_reads_and_exact_milestones(): void
    {
        $source = $this->source('reporting.oru', ['ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        foreach (['oru-preliminary', 'oru-final', 'oru-corrected', 'oru-addendum'] as $fixture) {
            $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture($fixture)]));
        }

        $reads = Read::query()->orderBy('rad_read_id')->get();
        $this->assertSame(['preliminary', 'final', 'corrected', 'addendum'], $reads->pluck('status')->all());
        $this->assertNull($reads[0]->parent_rad_read_id);
        $this->assertSame($reads[1]->rad_read_id, $reads[2]->parent_rad_read_id);
        $this->assertSame($reads[2]->rad_read_id, $reads[3]->parent_rad_read_id);
        $this->assertSame(['RAD_PRELIM', 'RAD_FINAL', 'RAD_FINAL', 'RAD_FINAL'], $reads[0]->exam->ancillaryOrder->milestones()->orderBy('occurred_at')->pluck('milestone_code')->all());
        $this->assertSame(4, DB::table('integration.provenance_records')->where('target_table', 'rad_reads')->count());

        $statistics = new AncillaryStatistics;
        $this->assertSame(90.0, $statistics->intervalMinutes($reads[0]->exam->ancillaryOrder->ordered_at, $reads[1]->final_at));
        $this->assertSame(30.0, $statistics->intervalMinutes($reads[0]->preliminary_at, $reads[1]->final_at));
    }

    public function test_final_only_timezone_and_duplicate_delivery_are_valid_and_idempotent(): void
    {
        $source = $this->source('reporting.final-only', ['ORU']);
        $message = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('oru-final-only')]);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $message);
        $this->assertTrue($pipeline->ingest($source->source_key, $message)['duplicate']);

        $read = Read::query()->sole();
        $this->assertSame('final', $read->status);
        $this->assertSame('2026-07-11T13:00:00+00:00', $read->final_at->toAtomString());
        $this->assertSame(1, $read->exam->ancillaryOrder->milestones()->where('milestone_code', 'RAD_FINAL')->count());
    }

    public function test_multiple_oru_groups_do_not_cross_contaminate_status_or_identity(): void
    {
        $source = $this->source('reporting.multi', ['ORU']);
        $receipt = app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('oru-multiple-groups')]));

        $this->assertCount(2, $receipt['canonical_event_ids']);
        $this->assertSame(['final', 'preliminary'], Read::query()->orderBy('rad_read_id')->pluck('status')->all());
        $this->assertSame(['ACC-REPORT-A', 'ACC-REPORT-B'], Read::query()->with('exam')->get()->pluck('exam.source_exam_key')->sort()->values()->all());
    }

    public function test_fhir_imaging_study_and_diagnostic_report_preserve_versions_and_corrections(): void
    {
        $source = $this->source('reporting.fhir', ['FHIR'], 'radiology_reporting');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, $this->fhir(['resourceType' => 'ImagingStudy', 'id' => 'study-1', 'meta' => ['versionId' => '1'], 'identifier' => [['value' => 'FHIR-ACC-1']], 'status' => 'available', 'started' => '2026-07-11T12:00:00-04:00', 'modality' => [['code' => 'CT']]]));
        $pipeline->ingest($source->source_key, $this->fhir(['resourceType' => 'DiagnosticReport', 'id' => 'report-1', 'meta' => ['versionId' => '1'], 'identifier' => [['value' => 'FHIR-ACC-1']], 'status' => 'final', 'issued' => '2026-07-11T13:00:00-04:00', 'conclusion' => 'SECRET FHIR NARRATIVE']));
        $pipeline->ingest($source->source_key, $this->fhir(['resourceType' => 'DiagnosticReport', 'id' => 'report-1', 'meta' => ['versionId' => '2'], 'identifier' => [['value' => 'FHIR-ACC-1']], 'status' => 'amended', 'issued' => '2026-07-11T13:15:00-04:00', 'conclusion' => 'SECRET CORRECTION']));

        $this->assertSame(['final', 'corrected'], Read::query()->orderBy('rad_read_id')->pluck('status')->all());
        $this->assertSame(['1', '2'], Read::query()->orderBy('rad_read_id')->pluck('source_report_version')->all());
        $this->assertDatabaseHas('prod.ancillary_milestones', ['milestone_code' => 'RAD_IMAGES_AVAILABLE']);
        $this->assertSame(Read::query()->first()->rad_read_id, Read::query()->latest('rad_read_id')->first()->parent_rad_read_id);
    }

    public function test_narrative_and_direct_identifiers_stay_out_of_normalized_canonical_read_and_list_contracts(): void
    {
        $source = $this->source('reporting.privacy', ['ORU']);
        app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('oru-final')]));

        $read = Read::query()->sole();
        $contract = app(RadiologyReadContractSerializer::class)->serialize($read);
        $surfaces = [
            json_encode(DB::table('raw.inbound_messages')->value('normalized_payload'), JSON_THROW_ON_ERROR),
            json_encode(DB::table('integration.canonical_events')->value('payload'), JSON_THROW_ON_ERROR),
            json_encode($read->metadata, JSON_THROW_ON_ERROR),
            json_encode($contract, JSON_THROW_ON_ERROR),
        ];
        foreach ($surfaces as $surface) {
            $this->assertStringNotContainsString('SECRET', $surface);
            $this->assertStringNotContainsString('PATIENT-REPORT', $surface);
        }
        $this->assertSame(['readUuid', 'examUuid', 'status', 'sourceReportVersion', 'isTeleradiology', 'preliminaryAt', 'finalAt', 'correctedAt', 'parentReadUuid'], array_keys($contract));
    }

    private function source(string $key, array $families, string $systemClass = 'radiology_reporting'): Source
    {
        return app(SourceRegistryService::class)->ensureSource([...$this->canonicalIntegrationSourceScope(), 'source_key' => $key, 'source_name' => $key, 'system_class' => $systemClass, 'interface_type' => 'hl7v2', 'active_status' => 'active', 'phi_allowed' => true, 'metadata' => ['ancillary_ingest' => ['enabled' => true, 'message_families' => $families, 'departments' => ['rad']]]]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/hl7/radiology/{$name}.hl7"));
    }

    /** @param array<string, mixed> $resource */
    private function fhir(array $resource): SourceMessage
    {
        return new SourceMessage('FHIR', ['resource' => $resource]);
    }
}
