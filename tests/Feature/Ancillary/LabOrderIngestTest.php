<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Integration\Source;
use App\Models\Lab\Specimen;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabOrderIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
        config([
            'integrations.fhir_resources.ServiceRequest.enabled' => true,
            'integrations.fhir_resources.Specimen.enabled' => true,
        ]);
    }

    public function test_golden_hl7_orders_cover_ed_stat_am_draw_add_on_and_cancellation_without_clinical_values(): void
    {
        $source = $this->source('lis.orders', ['OML', 'ORM', 'ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $receipts = [];
        foreach (['ed-stat-troponin', 'am-bmp', 'add-on-cbc', 'cancelled-order'] as $fixture) {
            $receipts[$fixture] = $pipeline->ingest(
                $source->source_key,
                new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture($fixture)]),
            );
        }

        $this->assertCount(2, $receipts['ed-stat-troponin']['canonical_event_ids']);
        $this->assertCount(2, $receipts['am-bmp']['canonical_event_ids']);
        $this->assertCount(1, $receipts['add-on-cbc']['canonical_event_ids']);
        $this->assertCount(1, $receipts['cancelled-order']['canonical_event_ids']);
        $this->assertSame(4, AncillaryOrder::query()->department('lab')->count());
        $this->assertSame(3, Specimen::query()->count());

        $troponin = AncillaryOrder::query()->where('source_order_key', 'ACC-ED-TROP')->firstOrFail();
        $this->assertSame('stat', $troponin->priority);
        $this->assertSame('emergency', $troponin->patient_class);
        $this->assertSame('TROPONIN_I', $troponin->metadata['test_code']);
        $this->assertSame('10839-9', $troponin->metadata['loinc_code']);
        $this->assertSame('PLACER-ED-TROP', $troponin->metadata['placer_order_key']);
        $this->assertSame('ACC-ED-TROP', $troponin->metadata['filler_order_key']);
        $this->assertNotSame('PATIENT-ED-TROP', $troponin->patient_ref);
        $this->assertNotSame('ENC-ED-TROP', $troponin->encounter_ref);

        $troponinSpecimen = Specimen::query()->where('source_specimen_key', 'SPEC-ED-TROP')->firstOrFail();
        $this->assertSame($troponin->ancillary_order_id, $troponinSpecimen->ancillary_order_id);
        $this->assertSame('collected', $troponinSpecimen->status);
        $this->assertSame('nurse_collect', $troponinSpecimen->collector_role);
        $this->assertSame('VENIPUNCTURE', $troponinSpecimen->collection_method);
        $this->assertSame('PST', $troponinSpecimen->container_type);
        $this->assertSame('SPM-17', $troponinSpecimen->metadata['collection_source']);
        $this->assertNotSame('COLLECTOR-RN-1', $troponinSpecimen->metadata['collector_ref']);

        $bmpSpecimen = Specimen::query()->where('source_specimen_key', 'SPEC-AM-BMP')->firstOrFail();
        $this->assertSame('lab_collect', $bmpSpecimen->collector_role);
        $this->assertSame('SPM-17', $bmpSpecimen->metadata['collection_source']);

        $addOn = AncillaryOrder::query()->where('source_order_key', 'ACC-ADDON-CBC')->firstOrFail();
        $this->assertTrue($addOn->metadata['add_on']);
        $this->assertSame('urgent', $addOn->priority);
        $addOnSpecimen = Specimen::query()->where('source_specimen_key', 'SPEC-ADDON')->firstOrFail();
        $this->assertNull($addOnSpecimen->collected_at);
        $this->assertNull($addOnSpecimen->collector_role);

        $cancelled = AncillaryOrder::query()->where('source_order_key', 'ACC-CANCEL')->firstOrFail();
        $this->assertSame('LAB_CANCELLED', $cancelled->current_milestone_code);
        $this->assertNotNull($cancelled->terminal_at);

        $canonicalPayloads = DB::table('integration.canonical_events')
            ->where('source_id', $source->source_id)
            ->pluck('payload')
            ->implode('\n');
        $this->assertStringNotContainsString('PATIENT-ED-TROP', $canonicalPayloads);
        $this->assertStringNotContainsString('COLLECTOR-RN-1', $canonicalPayloads);
        $this->assertStringNotContainsString('result_value', strtolower($canonicalPayloads));
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod',
            'target_table' => 'lab_specimens',
            'target_pk' => (string) $troponinSpecimen->lab_specimen_id,
        ]);
    }

    public function test_missing_obr7_does_not_fabricate_collection_and_later_oru_backfills_once(): void
    {
        $source = $this->source('lis.collection-backfill', ['OML', 'ORU']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);

        $orderReceipt = $pipeline->ingest(
            $source->source_key,
            new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('missing-collection')]),
        );
        $this->assertCount(1, $orderReceipt['canonical_event_ids']);
        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $pending = Specimen::query()->firstOrFail();
        $this->assertSame('collection_pending', $pending->status);
        $this->assertNull($pending->collected_at);
        $this->assertDatabaseMissing('prod.ancillary_milestones', ['milestone_code' => 'LAB_COLLECTED']);

        $backfill = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('oru-collection-backfill')]);
        $collectionReceipt = $pipeline->ingest($source->source_key, $backfill);
        $this->assertCount(1, $collectionReceipt['canonical_event_ids']);
        $this->assertFalse($collectionReceipt['duplicate']);
        $this->assertTrue($pipeline->ingest($source->source_key, $backfill)['duplicate']);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $this->assertSame('ACC-MISSING', AncillaryOrder::query()->firstOrFail()->source_order_key);
        $this->assertSame('SPEC-MISSING', Specimen::query()->firstOrFail()->source_specimen_key);
        $this->assertSame('collected', Specimen::query()->firstOrFail()->status);
        $this->assertNotNull(Specimen::query()->firstOrFail()->collected_at);
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_COLLECTED')->count());
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->count());

        $canonicalPayloads = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('REDACTED', $canonicalPayloads);
        $this->assertStringNotContainsString('GLU', $canonicalPayloads);

        $retainedCollection = Specimen::query()->firstOrFail()->collected_at;
        $changedCollection = str_replace(
            ['LAB-MISSING-COLLECTION-ORU-1', '20260712071000-0400'],
            ['LAB-MISSING-COLLECTION-ORU-2', '20260712071200-0400'],
            $this->fixture('oru-collection-backfill'),
        );
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $changedCollection]));
        $conflicted = Specimen::query()->firstOrFail();
        $this->assertTrue($conflicted->collected_at->equalTo($retainedCollection));
        $this->assertSame('2026-07-12T11:10:00+00:00', $conflicted->metadata['collection_time_conflict']['retained']);
        $this->assertSame('2026-07-12T11:12:00+00:00', $conflicted->metadata['collection_time_conflict']['candidate']);
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_COLLECTED')->count());
    }

    public function test_cancellation_closes_the_order_and_only_its_pending_uncollected_specimen(): void
    {
        $source = $this->source('lis.pending-cancel', ['OML']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('missing-collection')]));

        $cancellation = str_replace(
            ['LAB-MISSING-COLLECTION-1', 'ORC|NW|PLACER-MISSING|ACC-MISSING'],
            ['LAB-MISSING-COLLECTION-CANCEL-1', 'ORC|CA|PLACER-MISSING|ACC-MISSING'],
            $this->fixture('missing-collection'),
        );
        $pipeline->ingest($source->source_key, new SourceMessage('HL7V2', ['raw_hl7' => $cancellation]));

        $order = AncillaryOrder::query()->firstOrFail();
        $specimen = Specimen::query()->firstOrFail();
        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $this->assertSame('LAB_CANCELLED', $order->current_milestone_code);
        $this->assertNotNull($order->terminal_at);
        $this->assertSame('cancelled', $specimen->status);
        $this->assertNotNull($specimen->cancelled_at);
        $this->assertNull($specimen->collected_at);
        $this->assertDatabaseMissing('prod.ancillary_milestones', ['milestone_code' => 'LAB_COLLECTED']);
    }

    public function test_multi_obr_panel_keeps_tests_specimens_and_milestones_isolated_under_replay(): void
    {
        $source = $this->source('lis.multi', ['OML']);
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $message = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('multi-specimen-panel')]);
        $receipt = $pipeline->ingest($source->source_key, $message);

        $this->assertCount(5, $receipt['canonical_event_ids']);
        $this->assertSame(['ACC-MULTI-CBC', 'ACC-MULTI-INR'], AncillaryOrder::query()->orderBy('source_order_key')->pluck('source_order_key')->all());
        $this->assertSame(['SPEC-MULTI-CITRATE', 'SPEC-MULTI-EDTA', 'SPEC-MULTI-EDTA-2'], Specimen::query()->orderBy('source_specimen_key')->pluck('source_specimen_key')->all());
        $this->assertSame(2, Specimen::query()->distinct()->count('ancillary_order_id'));
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_ORDERED')->count());
        $this->assertSame(3, DB::table('prod.ancillary_milestones')->where('milestone_code', 'LAB_COLLECTED')->count());
        $this->assertSame(['CBC', 'PT_INR'], AncillaryOrder::query()->get()->pluck('metadata.test_code')->sort()->values()->all());
        $canonicalPayloads = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('REDACTED-CBC', $canonicalPayloads);
        $this->assertStringNotContainsString('REDACTED-INR', $canonicalPayloads);
        $this->assertTrue($pipeline->ingest($source->source_key, $message)['duplicate']);
        $this->assertSame(2, AncillaryOrder::query()->count());
        $this->assertSame(3, Specimen::query()->count());
        $this->assertSame(5, DB::table('prod.ancillary_milestones')->count());
    }

    public function test_fhir_service_request_and_specimen_converge_on_one_order_and_specimen(): void
    {
        $source = $this->source('lis.fhir', ['FHIR'], 'fhir');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $serviceRequest = new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'ServiceRequest',
            'id' => 'sr-lab-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['system' => 'urn:lab:placer', 'value' => 'FHIR-LAB-PLACER']],
            'status' => 'active',
            'intent' => 'order',
            'priority' => 'stat',
            'authoredOn' => '2026-07-12T08:00:00-04:00',
            'subject' => ['reference' => 'Patient/secret-patient'],
            'encounter' => ['reference' => 'Encounter/secret-encounter'],
            'code' => ['coding' => [
                ['system' => 'urn:lab:local', 'code' => 'TROPONIN_I', 'display' => 'Troponin I'],
                ['system' => 'http://loinc.org', 'code' => '10839-9', 'display' => 'Troponin I'],
            ]],
            'specimen' => [['reference' => 'Specimen/spec-lab-1']],
            'extension' => [['url' => 'https://zephyrus.example/fhir/StructureDefinition/patient-class', 'valueCode' => 'emergency']],
        ]]);
        $pipeline->ingest($source->source_key, $serviceRequest);

        $order = AncillaryOrder::query()->firstOrFail();
        $pending = Specimen::query()->firstOrFail();
        $this->assertSame('FHIR-LAB-PLACER', $order->source_order_key);
        $this->assertSame('ServiceRequest/sr-lab-1', $order->metadata['reconciliation_key']);
        $this->assertSame('stat', $order->priority);
        $this->assertSame('emergency', $order->patient_class);
        $this->assertSame('TROPONIN_I', $order->metadata['test_code']);
        $this->assertSame('10839-9', $order->metadata['loinc_code']);
        $this->assertSame('spec-lab-1', $pending->source_specimen_key);
        $this->assertSame('collection_pending', $pending->status);

        $specimen = new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'Specimen',
            'id' => 'spec-lab-1',
            'meta' => ['versionId' => '1'],
            'identifier' => [['system' => 'urn:lab:specimen', 'value' => 'FHIR-SPECIMEN-BUSINESS-1']],
            'status' => 'available',
            'request' => [['reference' => 'https://fhir.example.test/r4/ServiceRequest/sr-lab-1']],
            'subject' => ['reference' => 'Patient/secret-patient'],
            'type' => ['coding' => [['code' => 'PLAS']]],
            'container' => [['type' => ['coding' => [['code' => 'PST']]]]],
            'collection' => [
                'collector' => ['reference' => 'Practitioner/secret-collector'],
                'collectedDateTime' => '2026-07-12T08:04:00-04:00',
                'method' => ['coding' => [['code' => 'VENIPUNCTURE']]],
            ],
            'extension' => [['url' => 'https://zephyrus.example/fhir/StructureDefinition/collector-role', 'valueCode' => 'lab_collect']],
        ]]);
        $pipeline->ingest($source->source_key, $specimen);
        $this->assertTrue($pipeline->ingest($source->source_key, $specimen)['duplicate']);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $collected = Specimen::query()->firstOrFail();
        $this->assertSame($order->ancillary_order_id, $collected->ancillary_order_id);
        $this->assertSame('collected', $collected->status);
        $this->assertSame('PLAS', $collected->specimen_type);
        $this->assertSame('PST', $collected->container_type);
        $this->assertSame('lab_collect', $collected->collector_role);
        $this->assertSame('VENIPUNCTURE', $collected->collection_method);
        $this->assertSame('FHIR-SPECIMEN-BUSINESS-1', $collected->source_accession_key);
        $this->assertNotSame('Practitioner/secret-collector', $collected->metadata['collector_ref']);

        $canonicalPayloads = DB::table('integration.canonical_events')->pluck('payload')->implode('\n');
        $this->assertStringNotContainsString('secret-patient', $canonicalPayloads);
        $this->assertStringNotContainsString('secret-collector', $canonicalPayloads);
    }

    public function test_governed_sources_reconcile_one_order_while_specimen_natural_keys_remain_source_scoped(): void
    {
        $first = $this->source('lis.source-a', ['OML']);
        $second = $this->source('lis.source-b', ['OML']);
        $message = new SourceMessage('HL7V2', ['raw_hl7' => $this->fixture('ed-stat-troponin')]);
        $pipeline = app(AncillaryMessageIngestPipeline::class);

        $pipeline->ingest($first->source_key, $message);
        $pipeline->ingest($second->source_key, $message);

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(2, Specimen::query()->count());
        $this->assertSame(2, Specimen::query()->distinct()->count('source_id'));
        $this->assertSame(1, AncillaryOrder::query()->where('source_order_key', 'ACC-ED-TROP')->count());
        $this->assertSame(2, Specimen::query()->where('source_specimen_key', 'SPEC-ED-TROP')->count());
        $this->assertSame(2, DB::table('prod.ancillary_milestones')->distinct()->count('source_id'));
    }

    public function test_fhir_specimen_first_is_enriched_without_identity_or_collection_regression(): void
    {
        $source = $this->source('lis.fhir-reverse', ['FHIR'], 'fhir');
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $pipeline->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'Specimen',
            'id' => 'spec-reverse',
            'meta' => ['versionId' => '1'],
            'request' => [['reference' => 'ServiceRequest/sr-reverse']],
            'subject' => ['reference' => 'Patient/reverse-secret'],
            'type' => ['coding' => [['code' => 'PLAS']]],
            'collection' => ['collectedDateTime' => '2026-07-12T08:04:00-04:00'],
        ]]));

        $initialOrder = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('sr-reverse', $initialOrder->source_order_key);
        $this->assertSame('unknown', $initialOrder->priority);
        $this->assertSame('collection_fallback', $initialOrder->metadata['ordered_at_source']);
        $this->assertSame('collected', Specimen::query()->firstOrFail()->status);

        $pipeline->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
            'resourceType' => 'ServiceRequest',
            'id' => 'sr-reverse',
            'meta' => ['versionId' => '1'],
            'identifier' => [['value' => 'FHIR-LAB-REVERSE']],
            'status' => 'active',
            'priority' => 'stat',
            'authoredOn' => '2026-07-12T08:00:00-04:00',
            'subject' => ['reference' => 'Patient/reverse-secret'],
            'code' => ['coding' => [
                ['system' => 'urn:lab:local', 'code' => 'TROPONIN_I', 'display' => 'Troponin I'],
                ['system' => 'http://loinc.org', 'code' => '10839-9', 'display' => 'Troponin I'],
            ]],
            'specimen' => [['reference' => 'Specimen/spec-reverse']],
            'extension' => [['url' => 'https://zephyrus.example/fhir/StructureDefinition/patient-class', 'valueCode' => 'emergency']],
        ]]));

        $this->assertSame(1, AncillaryOrder::query()->count());
        $this->assertSame(1, Specimen::query()->count());
        $enriched = AncillaryOrder::query()->firstOrFail();
        $this->assertSame('sr-reverse', $enriched->source_order_key);
        $this->assertSame('stat', $enriched->priority);
        $this->assertSame('emergency', $enriched->patient_class);
        $this->assertSame('TROPONIN_I', $enriched->metadata['test_code']);
        $this->assertSame('10839-9', $enriched->metadata['loinc_code']);
        $this->assertSame('ServiceRequest.authoredOn', $enriched->metadata['ordered_at_source']);
        $this->assertSame('2026-07-12T12:00:00+00:00', $enriched->ordered_at->toIso8601String());
        $this->assertSame('collected', Specimen::query()->firstOrFail()->status);
    }

    public function test_fhir_specimen_without_collection_time_fails_closed_and_is_dead_lettered(): void
    {
        $source = $this->source('lis.fhir-invalid', ['FHIR'], 'fhir');

        try {
            app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage('FHIR', ['resource' => [
                'resourceType' => 'Specimen',
                'id' => 'spec-missing-time',
                'meta' => ['versionId' => '1'],
                'request' => [['reference' => 'ServiceRequest/sr-missing-time']],
                'collection' => [],
            ]]));
            $this->fail('A FHIR Specimen without a collection assertion was accepted.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('missing_collection_assertion', $exception->reasonCode);
        }

        $this->assertDatabaseHas('raw.dead_letters', [
            'source_id' => $source->source_id,
            'reason_code' => 'missing_collection_assertion',
            'failure_stage' => 'ancillary_ingress',
        ]);
        $this->assertSame(0, AncillaryOrder::query()->count());
        $this->assertSame(0, Specimen::query()->count());
    }

    /** @param list<string> $families */
    private function source(string $key, array $families, string $interfaceType = 'hl7v2'): Source
    {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => 'lis',
            'interface_type' => $interfaceType,
            'active_status' => 'active',
            'phi_allowed' => true,
            'metadata' => [
                'ancillary_ingest' => [
                    'enabled' => true,
                    'message_families' => $families,
                    'departments' => ['lab'],
                ],
            ],
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/hl7/lab/{$name}.hl7"));
    }
}
