<?php

namespace Tests\Feature\Integrations;

use App\Authorization\GovernedAction;
use App\Models\Fhir\ResourceVersion;
use App\Models\Integration\CanonicalEventRecord;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\Raw\InboundMessage;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalPayloadBackfillService;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadLifecycleService;
use App\Security\ClinicalPayloads\ClinicalPayloadQuarantineService;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClinicalPayloadOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_and_backfill_protect_every_legacy_jsonb_authority_and_are_idempotent(): void
    {
        $sourceId = $this->source();
        $runId = $this->ingestRun($sourceId);
        $recognizable = 'RECOGNIZABLE-BACKFILL-PATIENT-7711';
        $rawId = DB::table('raw.inbound_messages')->insertGetId([
            'message_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'ingest_run_id' => $runId,
            'message_type' => 'HL7V2_ADT',
            'idempotency_key' => 'legacy-raw-1',
            'payload_hash' => hash('sha256', $recognizable),
            'payload' => json_encode(['raw_hl7' => $recognizable], JSON_THROW_ON_ERROR),
            'normalized_payload' => json_encode(['patient_id' => $recognizable], JSON_THROW_ON_ERROR),
            'received_at' => now(),
            'parse_status' => 'normalized',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'inbound_message_id');
        $fhirId = DB::table('fhir.resource_versions')->insertGetId([
            'source_id' => $sourceId,
            'resource_type' => 'Patient',
            'fhir_id' => 'legacy-patient',
            'version_id' => '1',
            'resource_hash' => hash('sha256', $recognizable),
            'resource_data' => json_encode(['resourceType' => 'Patient', 'name' => $recognizable], JSON_THROW_ON_ERROR),
            'ingest_run_id' => $runId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'resource_version_id');
        $canonicalId = DB::table('integration.canonical_events')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'ingest_run_id' => $runId,
            'inbound_message_id' => $rawId,
            'event_type' => 'patient.updated',
            'occurred_at' => now(),
            'received_at' => now(),
            'payload' => json_encode(['patient_id' => $recognizable], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', $recognizable),
            'idempotency_key' => 'legacy-canonical-1',
            'projection_status' => 'projected',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'canonical_event_id');
        $writebackId = DB::table('ops.writeback_drafts')->insertGetId([
            'writeback_draft_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'target_system' => 'legacy-ehr',
            'resource_type' => 'Task',
            'draft_type' => 'fhir_task',
            'status' => 'pending_approval',
            'resource_payload' => json_encode([
                'resourceType' => 'Task',
                'description' => $recognizable,
            ], JSON_THROW_ON_ERROR),
            'routing_payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'writeback_draft_id');

        $inventory = app(ClinicalPayloadBackfillService::class)->run('inventory', $sourceId, 10);
        $this->assertSame(5, $inventory['scanned']);
        $legacyRaw = json_decode(
            (string) DB::table('raw.inbound_messages')->where('inbound_message_id', $rawId)->value('payload'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($recognizable, $legacyRaw['raw_hl7']);

        $backfill = app(ClinicalPayloadBackfillService::class)->run('backfill', $sourceId, 10);
        $this->assertSame('completed', $backfill['status']);
        $this->assertSame(5, $backfill['protected']);
        $this->assertSame(5, DB::table('raw.payload_backfill_items')->where('status', 'verified')->count());
        $this->assertSame(5, DB::table('raw.payload_objects')->where('source_id', $sourceId)->where('status', 'ready')->count());

        $raw = InboundMessage::query()->findOrFail($rawId);
        $fhir = ResourceVersion::query()->findOrFail($fhirId);
        $canonical = CanonicalEventRecord::query()->findOrFail($canonicalId);
        $this->assertSame($recognizable, $raw->payload['raw_hl7']);
        $this->assertSame($recognizable, $raw->normalized_payload['patient_id']);
        $this->assertSame($recognizable, $fhir->resource_data['name']);
        $this->assertSame($recognizable, $canonical->payload['patient_id']);

        $rawAuthority = DB::table('raw.inbound_messages')->where('inbound_message_id', $rawId)->first();
        $this->assertNull($rawAuthority->payload);
        $this->assertNull($rawAuthority->normalized_payload);
        $this->assertSame('{}', DB::table('fhir.resource_versions')->where('resource_version_id', $fhirId)->value('resource_data'));
        $this->assertSame('{}', DB::table('integration.canonical_events')->where('canonical_event_id', $canonicalId)->value('payload'));
        $writeback = DB::table('ops.writeback_drafts')->where('writeback_draft_id', $writebackId)->firstOrFail();
        $this->assertSame('{}', $writeback->resource_payload);
        $this->assertSame(
            $recognizable,
            app(ClinicalPayloadStore::class)->readJson((int) $writeback->payload_object_id, $sourceId, 'writeback_draft')['description'],
        );
        $this->assertStringNotContainsString($recognizable, DB::table('raw.payload_backfill_events')->get()->toJson());

        $repeat = app(ClinicalPayloadBackfillService::class)->run('backfill', $sourceId, 10);
        $this->assertSame(0, $repeat['scanned']);
        $this->assertSame(5, DB::table('raw.payload_objects')->where('source_id', $sourceId)->where('status', 'ready')->count());
    }

    public function test_retention_blocks_pending_writeback_and_payload_links_require_exact_source_authority(): void
    {
        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson(
            $sourceId,
            'writeback_draft',
            ['resourceType' => 'Task', 'description' => 'RECOGNIZABLE-PENDING-WRITEBACK'],
            retainUntil: now()->toImmutable()->addDay(),
        );
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();

        DB::beginTransaction();
        try {
            DB::table('ops.writeback_drafts')->insert([
                'writeback_draft_uuid' => (string) Str::uuid(),
                'source_id' => null,
                'target_system' => 'legacy-ehr',
                'resource_type' => 'Task',
                'draft_type' => 'fhir_task',
                'status' => 'pending_approval',
                'resource_payload' => '{}',
                'payload_object_id' => $stored->payloadObjectId,
                'routing_payload' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A protected writeback payload accepted a missing source authority.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('writeback payload object authority mismatch', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        $draftId = DB::table('ops.writeback_drafts')->insertGetId([
            'writeback_draft_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'target_system' => 'legacy-ehr',
            'resource_type' => 'Task',
            'draft_type' => 'fhir_task',
            'status' => 'pending_approval',
            'resource_payload' => '{}',
            'payload_object_id' => $stored->payloadObjectId,
            'routing_payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'writeback_draft_id');
        $this->travel(2)->days();

        $blocked = app(ClinicalPayloadLifecycleService::class)->enforce($sourceId, execute: true);
        $this->assertSame(1, $blocked['blocked']);
        $this->assertTrue(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));

        DB::table('ops.writeback_drafts')->where('writeback_draft_id', $draftId)->update([
            'status' => 'cancelled',
            'updated_at' => now(),
        ]);
        $deleted = app(ClinicalPayloadLifecycleService::class)->enforce($sourceId, execute: true);
        $this->assertSame(1, $deleted['deleted']);
        $this->assertFalse(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));
    }

    public function test_retention_honors_legal_hold_and_replay_dependencies_then_leaves_a_tombstone(): void
    {
        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson(
            $sourceId,
            'canonical_event',
            ['patient' => 'RECOGNIZABLE-RETENTION-PATIENT'],
            retainUntil: now()->toImmutable()->addDay(),
        );
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        DB::table('integration.canonical_events')->insert([
            'event_id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'event_type' => 'patient.retention',
            'occurred_at' => now(),
            'received_at' => now(),
            'payload' => '{}',
            'payload_object_id' => $stored->payloadObjectId,
            'payload_hash' => $stored->plaintextSha256,
            'idempotency_key' => 'retention-event-1',
            'projection_status' => 'pending',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $applyUuid = $this->approvedPayloadChange(
            $stored->payloadObjectId,
            GovernedAction::ApplyClinicalPayloadHold,
            $author,
            $approver,
        );
        app(ClinicalPayloadStore::class)->applyLegalHold(
            $stored->payloadObjectId,
            $sourceId,
            'legal_case_2026',
            (int) $author->getKey(),
            $applyUuid,
        );
        $this->travel(2)->days();

        $held = app(ClinicalPayloadLifecycleService::class)->enforce($sourceId, execute: true);
        $this->assertSame(0, $held['scanned']);
        $this->assertTrue(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));

        $releaseUuid = $this->approvedPayloadChange(
            $stored->payloadObjectId,
            GovernedAction::ReleaseClinicalPayloadHold,
            $author,
            $approver,
        );
        app(ClinicalPayloadStore::class)->releaseLegalHold(
            $stored->payloadObjectId,
            $sourceId,
            'legal_case_closed',
            (int) $author->getKey(),
            $releaseUuid,
        );
        $blocked = app(ClinicalPayloadLifecycleService::class)->enforce($sourceId, execute: true);
        $this->assertSame(1, $blocked['blocked']);

        DB::table('integration.canonical_events')->where('payload_object_id', $stored->payloadObjectId)->update([
            'projection_status' => 'projected',
            'projected_at' => now(),
            'updated_at' => now(),
        ]);
        $deleted = app(ClinicalPayloadLifecycleService::class)->enforce($sourceId, execute: true);
        $this->assertSame(1, $deleted['deleted']);
        $this->assertFalse(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));
        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'deleted',
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'deleted',
        ]);
    }

    public function test_quarantine_is_separate_append_only_and_blocks_payload_access(): void
    {
        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'raw_message', [
            'patient' => 'RECOGNIZABLE-QUARANTINE-PATIENT',
        ]);
        $messageId = DB::table('raw.inbound_messages')->insertGetId([
            'message_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'message_type' => 'FHIR_R4_Patient',
            'idempotency_key' => 'quarantine-message-1',
            'payload_hash' => $stored->plaintextSha256,
            'payload' => null,
            'payload_object_id' => $stored->payloadObjectId,
            'received_at' => now(),
            'parse_status' => 'received',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'inbound_message_id');

        $quarantineId = app(ClinicalPayloadQuarantineService::class)->quarantine(
            $stored->payloadObjectId,
            $sourceId,
            'unsafe_content',
            'content_scanner_rejected',
            'bounded-content-scanner',
            $messageId,
            ['scanner_rule' => 'rule-42'],
        );
        $this->assertDatabaseHas('raw.payload_quarantines', [
            'payload_quarantine_id' => $quarantineId,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'quarantined',
        ]);
        try {
            app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $sourceId, 'raw_message');
            $this->fail('Quarantined content was readable.');
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame('clinical_payload_not_readable', $exception->errorCode);
        }
        $this->assertStringNotContainsString(
            'RECOGNIZABLE-QUARANTINE-PATIENT',
            DB::table('raw.payload_quarantines')->get()->toJson().DB::table('raw.payload_quarantine_events')->get()->toJson(),
        );

        DB::beginTransaction();
        try {
            DB::table('raw.payload_quarantines')->where('payload_quarantine_id', $quarantineId)->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
            $this->fail('Quarantine projection accepted a direct lifecycle update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only event', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    private function ingestRun(int $sourceId): int
    {
        return (int) DB::table('raw.ingest_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'connector_key' => 'payload-operations-test',
            'run_type' => 'test',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ingest_run_id');
    }

    private function approvedPayloadChange(
        int $payloadObjectId,
        GovernedAction $action,
        User $author,
        User $approver,
    ): string {
        $payload = DB::table('raw.payload_objects')->where('payload_object_id', $payloadObjectId)->firstOrFail();
        $changeUuid = (string) Str::uuid7();
        DB::table('governance.change_requests')->insert([
            'change_request_uuid' => $changeUuid,
            'action_type' => $action->value,
            'subject_type' => 'clinical_payload',
            'subject_id' => (string) $payload->payload_uuid,
            'organization_id' => null,
            'facility_id' => $payload->facility_id,
            'author_user_id' => $author->getKey(),
            'reason' => 'Exercise the independently approved legal-hold lifecycle in retention testing.',
            'payload_sha256' => hash('sha256', $action->value.'|'.$payload->payload_uuid),
            'requested_at' => now(),
            'expires_at' => now()->addDay(),
            'metadata' => '{}',
        ]);
        DB::table('governance.change_decisions')->insert([
            'change_request_uuid' => $changeUuid,
            'decision' => 'approved',
            'decided_by_user_id' => $approver->getKey(),
            'reason' => 'Independent retention-test approver accepted the exact object authority.',
            'decided_at' => now(),
        ]);

        return $changeUuid;
    }

    private function source(): int
    {
        $organization = Organization::query()->create([
            'organization_key' => 'PAYLOAD_OPERATIONS_ORG',
            'name' => 'Payload Operations Organization',
            'kind' => 'idn',
        ]);
        $facility = Facility::query()->create([
            'facility_key' => 'PAYLOAD_OPERATIONS_FACILITY',
            'organization_id' => $organization->organization_id,
            'facility_name' => 'Payload Operations Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        return (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'payload.operations.source',
            'source_name' => 'Payload Operations Source',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'system_class' => 'ehr',
            'environment' => 'testing',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }
}
