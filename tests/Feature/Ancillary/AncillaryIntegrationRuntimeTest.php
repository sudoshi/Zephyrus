<?php

namespace Tests\Feature\Ancillary;

use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Integrations\Healthcare\Ancillary\AncillarySourceProfile;
use App\Integrations\Healthcare\DTO\BackfillRequest;
use App\Integrations\Healthcare\DTO\SourceMessage;
use App\Integrations\Healthcare\Exceptions\AncillaryIngestException;
use App\Integrations\Healthcare\Services\AncillaryBulkBackfillAdapter;
use App\Integrations\Healthcare\Services\AncillaryMessageIngestPipeline;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AncillaryIntegrationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $facilityId;

    private string $facilityKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $this->seed(AncillaryReferenceSeeder::class);

        $organization = Organization::create([
            'organization_key' => 'ANCILLARY_RUNTIME_IDN',
            'name' => 'Ancillary Runtime Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ANCILLARY_RUNTIME_FACILITY',
            'facility_name' => 'Ancillary Runtime Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->facilityKey = (string) $facility->facility_key;
    }

    public function test_governed_hl7_order_traverses_raw_canonical_projection_and_provenance(): void
    {
        $source = $this->source('ris.hl7.orders', 'ris', ['ORM'], ['rad']);
        $raw = $this->hl7('ORM^O01', 'RAD-CONTROL-1', 'RAD-ORDER-1');

        $receipt = app(AncillaryMessageIngestPipeline::class)->ingest(
            $source->source_key,
            new SourceMessage('HL7V2', ['raw_hl7' => $raw]),
        );

        $this->assertTrue($receipt['accepted']);
        $this->assertFalse($receipt['duplicate']);
        $this->assertTrue(Str::isUuid($receipt['run_id']));
        $this->assertTrue(Str::isUuid($receipt['message_id']));
        $this->assertCount(1, $receipt['canonical_event_ids']);
        $this->assertTrue(Str::isUuid($receipt['canonical_event_ids'][0]));
        $this->assertStringNotContainsString('PATIENT-SENSITIVE', json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('raw.inbound_messages', [
            'source_id' => $source->source_id,
            'message_type' => 'HL7V2_ORM',
            'external_id' => 'RAD-CONTROL-1',
            'parse_status' => 'projected',
        ]);
        $canonical = DB::table('integration.canonical_events')->where('source_id', $source->source_id)->first();
        $this->assertSame(AncillaryEventVocabulary::eventTypeFor('RAD_ORDERED'), $canonical->event_type);
        $this->assertSame('projected', $canonical->projection_status);
        $order = DB::table('prod.ancillary_orders')->where('source_order_key', 'RAD-ORDER-1')->first();
        $this->assertNotNull($order);
        $this->assertSame('RAD_ORDERED', $order->current_milestone_code);
        $this->assertDatabaseHas('integration.provenance_records', [
            'canonical_event_id' => $canonical->canonical_event_id,
            'target_schema' => 'prod',
            'target_table' => 'ancillary_milestones',
        ]);

        $normalized = DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->value('normalized_payload');
        $this->assertStringNotContainsString('raw_hl7', (string) $normalized);
        $this->assertStringNotContainsString('PATIENT-SENSITIVE', (string) $normalized);
    }

    public function test_structured_mpps_uses_explicit_source_binding_and_same_projection_path(): void
    {
        $source = $this->source('pacs.mpps', 'pacs', ['MPPS'], ['rad'], ['MPPS' => 'RAD_EXAM_END']);
        $receipt = app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, new SourceMessage(
            'MPPS',
            [
                'control_id' => 'MPPS-1',
                'source_order_key' => 'RAD-MPPS-ORDER',
                'source_study_key' => 'RAD-MPPS-STUDY',
                'sop_instance_uid' => '1.2.840.10008.1',
                'status' => 'COMPLETED',
                'occurred_at' => '2026-07-11T12:30:00-04:00',
                'performed_start_at' => '2026-07-11T12:00:00-04:00',
                'performed_end_at' => '2026-07-11T12:30:00-04:00',
                'modality' => 'CT',
                'source_signature' => ['algorithm' => 'EdDSA', 'key_id' => 'test-relay', 'verified' => true],
                'vendor_secret' => 'NEVER-NORMALIZE-ME',
            ],
        ));

        $this->assertSame('projected', $receipt['status']);
        $this->assertDatabaseHas('prod.ancillary_milestones', ['milestone_code' => 'RAD_EXAM_END']);
        $normalized = DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->value('normalized_payload');
        $this->assertStringNotContainsString('vendor_secret', (string) $normalized);
        $this->assertStringNotContainsString('NEVER-NORMALIZE-ME', (string) $normalized);
    }

    public function test_duplicate_delivery_is_idempotent_across_canonical_assertion_and_provenance(): void
    {
        $source = $this->source('ris.hl7.duplicate', 'ris', ['ORM'], ['rad']);
        $message = new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', 'DUP-1', 'DUP-ORDER')]);
        $pipeline = app(AncillaryMessageIngestPipeline::class);

        $first = $pipeline->ingest($source->source_key, $message);
        $second = $pipeline->ingest($source->source_key, $message);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('integration.canonical_events')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('prod.ancillary_orders')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('prod.ancillary_milestones')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('integration.provenance_records')->where('source_id', $source->source_id)->count());
    }

    public function test_invalid_family_missing_identities_bad_timestamp_and_source_mismatch_are_sanitized_dead_letters(): void
    {
        $pipeline = app(AncillaryMessageIngestPipeline::class);
        $scenarios = [
            [
                $this->source('anc.invalid.family', 'ris', ['ADT'], ['rad']),
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ADT^A01', 'BAD-FAMILY', 'ORDER-1')]),
                'unsupported_message_family',
            ],
            [
                $this->source('anc.missing.control', 'ris', ['ORM'], ['rad']),
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', '', 'ORDER-2')]),
                'missing_control_identity',
            ],
            [
                $this->source('anc.missing.order', 'ris', ['ORM'], ['rad']),
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', 'NO-ORDER', null)]),
                'missing_order_identity',
            ],
            [
                $this->source('anc.bad.time', 'ris', ['ORM'], ['rad']),
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', 'BAD-TIME', 'ORDER-3', 'NOT-A-TIME')]),
                'malformed_timestamp',
            ],
            [
                $this->source('anc.source.mismatch', 'ris', ['ORU'], ['rad']),
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', 'WRONG-SOURCE', 'ORDER-4')]),
                'source_message_mismatch',
            ],
        ];

        foreach ($scenarios as [$source, $message, $reasonCode]) {
            try {
                $pipeline->ingest($source->source_key, $message);
                $this->fail("{$reasonCode} should reject the source message.");
            } catch (AncillaryIngestException $exception) {
                $this->assertSame($reasonCode, $exception->reasonCode);
                $this->assertStringNotContainsString('PATIENT-SENSITIVE', $exception->getMessage());
            }

            $deadLetter = DB::table('raw.dead_letters')->where('source_id', $source->source_id)->first();
            $this->assertSame($reasonCode, $deadLetter->reason_code);
            $this->assertStringNotContainsString('PATIENT-SENSITIVE', json_encode($deadLetter, JSON_THROW_ON_ERROR));
            $this->assertSame(0, DB::table('integration.canonical_events')->where('source_id', $source->source_id)->count());
        }
    }

    public function test_oversized_message_is_retained_raw_but_dead_lettered_before_normalization(): void
    {
        config(['integrations.ancillary.max_message_bytes' => 1024]);
        $source = $this->source('rx.queue.oversized', 'pharmacy', ['QUEUE'], ['rx'], ['QUEUE' => 'RX_QUEUE_IN']);
        $message = new SourceMessage('QUEUE', [
            'control_id' => 'TOO-LARGE',
            'source_order_key' => 'RX-LARGE',
            'milestone_code' => 'RX_QUEUE_IN',
            'occurred_at' => '2026-07-11T12:00:00Z',
            'raw_vendor_blob' => str_repeat('SENSITIVE-', 200),
        ]);

        try {
            app(AncillaryMessageIngestPipeline::class)->ingest($source->source_key, $message);
            $this->fail('Oversized payload should be rejected.');
        } catch (AncillaryIngestException $exception) {
            $this->assertSame('message_too_large', $exception->reasonCode);
            $this->assertStringNotContainsString('SENSITIVE-', $exception->getMessage());
        }

        $this->assertDatabaseHas('raw.inbound_messages', ['source_id' => $source->source_id, 'parse_status' => 'failed']);
        $this->assertDatabaseHas('raw.dead_letters', ['source_id' => $source->source_id, 'reason_code' => 'message_too_large']);
        $this->assertSame(0, DB::table('integration.canonical_events')->where('source_id', $source->source_id)->count());
    }

    public function test_inactive_or_non_phi_source_is_rejected_before_raw_storage(): void
    {
        $source = $this->source('anc.disabled', 'ris', ['ORM'], ['rad'], active: false);

        $this->expectException(AncillaryIngestException::class);
        try {
            app(AncillaryMessageIngestPipeline::class)->ingest(
                $source->source_key,
                new SourceMessage('HL7V2', ['raw_hl7' => $this->hl7('ORM^O01', 'DISABLED', 'ORDER')]),
            );
        } finally {
            $this->assertSame(0, DB::table('raw.ingest_runs')->where('source_id', $source->source_id)->count());
            $this->assertSame(0, DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->count());
        }
    }

    public function test_bulk_backfill_advances_opaque_checkpoint_only_after_whole_bounded_batch_succeeds(): void
    {
        $source = $this->source('rx.queue.bulk', 'pharmacy', ['QUEUE'], ['rx'], ['QUEUE' => 'RX_QUEUE_IN']);
        $request = new BackfillRequest(
            messages: [
                $this->structuredRecord('QUEUE', 'RX-BULK-1', 'RX-CONTROL-1', 'RX_QUEUE_IN'),
                $this->structuredRecord('QUEUE', 'RX-BULK-2', 'RX-CONTROL-2', 'RX_QUEUE_IN'),
            ],
            scope: ['scope_key' => 'rx-2026-07-11', 'next_cursor' => 'page-2'],
        );

        $result = app(AncillaryBulkBackfillAdapter::class)->backfill($source->source_key, $request);

        $this->assertSame(2, $result->received);
        $this->assertSame(2, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->checkpointAdvanced);
        $this->assertSame('page-2', $result->cursorAfter);
        $this->assertDatabaseHas('integration.connector_watermarks', [
            'source_id' => $source->source_id,
            'connector_key' => AncillaryMessageIngestPipeline::CONNECTOR_KEY,
            'scope_type' => 'bulk_backfill',
            'scope_key' => 'rx-2026-07-11',
            'watermark_kind' => 'opaque_cursor',
            'watermark_value' => 'page-2',
        ]);
        $this->assertSame(2, DB::table('prod.ancillary_orders')->where('source_id', $source->source_id)->count());

        $this->expectException(InvalidArgumentException::class);
        app(AncillaryBulkBackfillAdapter::class)->backfill($source->source_key, new BackfillRequest(
            messages: [$this->structuredRecord('QUEUE', 'RX-BULK-3', 'RX-CONTROL-3', 'RX_QUEUE_IN')],
            scope: ['scope_key' => 'rx-2026-07-11', 'next_cursor' => 'page-3'],
            cursor: 'wrong-page',
        ));
    }

    public function test_bulk_backfill_failure_does_not_advance_checkpoint_and_reports_only_safe_codes(): void
    {
        $source = $this->source('rx.queue.bulk.failure', 'pharmacy', ['QUEUE'], ['rx'], ['QUEUE' => 'RX_QUEUE_IN']);
        $result = app(AncillaryBulkBackfillAdapter::class)->backfill($source->source_key, new BackfillRequest(
            messages: [
                $this->structuredRecord('QUEUE', 'RX-GOOD', 'RX-GOOD-CONTROL', 'RX_QUEUE_IN'),
                ['message_type' => 'UNKNOWN', 'payload' => ['secret' => 'DO-NOT-REPORT']],
            ],
            scope: ['scope_key' => 'failure-scope', 'next_cursor' => 'page-2'],
        ));

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertFalse($result->checkpointAdvanced);
        $this->assertNull($result->cursorAfter);
        $this->assertSame([['index' => 1, 'reasonCode' => 'unsupported_message_family']], $result->failures);
        $this->assertStringNotContainsString('DO-NOT-REPORT', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseMissing('integration.connector_watermarks', [
            'source_id' => $source->source_id,
            'scope_type' => 'bulk_backfill',
            'scope_key' => 'failure-scope',
        ]);
    }

    public function test_bulk_backfill_enforces_configured_record_bound(): void
    {
        config(['integrations.ancillary.bulk_max_records' => 1]);
        $source = $this->source('rx.queue.bulk.bound', 'pharmacy', ['QUEUE'], ['rx'], ['QUEUE' => 'RX_QUEUE_IN']);

        $this->expectException(InvalidArgumentException::class);
        app(AncillaryBulkBackfillAdapter::class)->backfill($source->source_key, new BackfillRequest(
            messages: [
                $this->structuredRecord('QUEUE', 'RX-1', 'CONTROL-1', 'RX_QUEUE_IN'),
                $this->structuredRecord('QUEUE', 'RX-2', 'CONTROL-2', 'RX_QUEUE_IN'),
            ],
            scope: ['scope_key' => 'bound', 'next_cursor' => 'next'],
        ));
    }

    public function test_concrete_hl7_default_maps_resolve_to_seeded_catalog_codes(): void
    {
        $cases = [
            ['ris', 'rad', 'ORM', 'RAD_ORDERED'],
            ['ris', 'rad', 'OMI', 'RAD_ORDERED'],
            ['ris', 'rad', 'ORU', 'RAD_FINAL'],
            ['ris', 'rad', 'SIU', 'RAD_SCHEDULED'],
            ['lis', 'lab', 'ORM', 'LAB_ORDERED'],
            ['lis', 'lab', 'OML', 'LAB_ORDERED'],
            ['lis', 'lab', 'ORU', 'LAB_RESULTED'],
            ['pharmacy', 'rx', 'RDE', 'RX_ORDERED'],
            ['pharmacy', 'rx', 'RDS', 'RX_DISPENSED'],
        ];

        foreach ($cases as [$systemClass, $department, $family, $expected]) {
            $profile = AncillarySourceProfile::from(new SourceMessage('test', [], metadata: [
                'source_key' => "test.{$systemClass}.{$family}",
                'system_class' => $systemClass,
                'ancillary_ingest' => [
                    'message_families' => [$family],
                    'departments' => [$department],
                ],
            ]));

            $this->assertSame($expected, $profile->milestoneFor($family));
            $this->assertDatabaseHas('hosp_ref.ancillary_milestone_types', [
                'department' => $department,
                'code' => $expected,
            ]);
        }
    }

    private function source(
        string $key,
        string $systemClass,
        array $families,
        array $departments,
        array $milestoneMap = [],
        bool $active = true,
    ): Source {
        return app(SourceRegistryService::class)->ensureSource([
            'source_key' => $key,
            'source_name' => $key,
            'system_class' => $systemClass,
            'interface_type' => 'hl7v2',
            'active_status' => $active ? 'active' : 'inactive',
            'phi_allowed' => $active,
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'ANCILLARY_RUNTIME_IDN',
            'facility_key' => $this->facilityKey,
            'metadata' => [
                'ancillary_ingest' => [
                    'enabled' => true,
                    'message_families' => $families,
                    'departments' => $departments,
                    'milestone_map' => $milestoneMap,
                ],
            ],
        ]);
    }

    private function hl7(string $event, string $controlId, ?string $orderKey, string $timestamp = '20260711120000-0400'): string
    {
        $segments = [
            "MSH|^~\\&|SOURCE|FACILITY|ZEPHYRUS|FACILITY|{$timestamp}||{$event}|{$controlId}|P|2.5.1",
            'PID|||PATIENT-SENSITIVE^^^FACILITY^MR',
        ];
        if ($orderKey !== null) {
            $segments[] = "ORC|NW|{$orderKey}";
            $segments[] = "OBR|1|{$orderKey}|ACCESSION|CTHEAD^CT Head|||{$timestamp}|||||||||||||||{$timestamp}||CT";
        }

        return implode("\r", $segments)."\r";
    }

    /** @return array<string, mixed> */
    private function structuredRecord(string $family, string $orderKey, string $controlId, string $milestoneCode): array
    {
        return [
            'message_type' => $family,
            'payload' => [
                'control_id' => $controlId,
                'source_order_key' => $orderKey,
                'milestone_code' => $milestoneCode,
                'occurred_at' => '2026-07-11T12:00:00Z',
            ],
        ];
    }
}
