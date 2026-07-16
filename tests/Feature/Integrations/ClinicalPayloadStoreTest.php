<?php

namespace Tests\Feature\Integrations;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ClinicalPayloadStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_is_encrypted_with_envelope_authority_and_round_trips_without_database_phi(): void
    {
        $sourceId = $this->source();
        $payload = [
            'patient_name' => 'RECOGNIZABLE-PATIENT-ALPHA',
            'mrn' => 'MRN-88442211',
            'event' => 'ADT-A01',
        ];

        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'raw_message', $payload);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        $object = Storage::disk('clinical-payloads')->get((string) $authority->object_key);

        $this->assertSame($payload, app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $sourceId, 'raw_message'));
        $this->assertStringStartsWith('ZCP1', $object);
        $this->assertStringNotContainsString('RECOGNIZABLE-PATIENT-ALPHA', $object);
        $this->assertStringNotContainsString('MRN-88442211', $object);
        $this->assertStringNotContainsString('RECOGNIZABLE-PATIENT-ALPHA', json_encode($authority, JSON_THROW_ON_ERROR));
        $this->assertSame('xchacha20-poly1305-ietf', $authority->cipher);
        $this->assertSame('restricted_phi', $authority->data_classification);
        $this->assertSame('test-version-1', $authority->key_provider_version);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'stored',
            'to_status' => 'ready',
        ]);

        $verification = app(ClinicalPayloadStore::class)->verify($stored->payloadObjectId, $sourceId);
        $this->assertSame('ready', $verification['status']);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'verified',
        ]);
    }

    public function test_ciphertext_tampering_fails_closed_and_records_only_a_stable_integrity_event(): void
    {
        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'canonical_event', [
            'patient_name' => 'RECOGNIZABLE-PATIENT-BETA',
        ]);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        Storage::disk('clinical-payloads')->put((string) $authority->object_key, 'ZCP1-tampered-ciphertext');

        try {
            app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $sourceId, 'canonical_event');
            $this->fail('Tampered ciphertext must not decrypt.');
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame('clinical_payload_ciphertext_hash_mismatch', $exception->errorCode);
        }

        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'integrity_failed',
        ]);
        $events = DB::table('raw.payload_object_events')->where('payload_object_id', $stored->payloadObjectId)->get();
        $this->assertStringNotContainsString('RECOGNIZABLE-PATIENT-BETA', $events->toJson());
        $this->assertTrue($events->contains('reason_code', 'clinical_payload_ciphertext_hash_mismatch'));
    }

    public function test_unlinked_internal_payload_discard_uses_audited_two_step_lifecycle(): void
    {
        $sourceId = $this->source();
        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'raw_message', [
            'message' => 'UNLINKED-ENCRYPTED-PAYLOAD',
        ]);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();

        app(ClinicalPayloadStore::class)->discard(
            $stored->payloadObjectId,
            $sourceId,
            'raw_message_insert_aborted',
            'Encrypted raw message was never linked to an authoritative inbound record.',
        );

        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'retention_marked',
            'from_status' => 'ready',
            'to_status' => 'retention_pending',
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'deleted',
            'from_status' => 'retention_pending',
            'to_status' => 'deleted',
        ]);
        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'deleted',
        ]);
        $this->assertFalse(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));
    }

    public function test_authority_scope_and_append_only_database_guards_fail_closed(): void
    {
        $sourceId = $this->source();
        $otherSourceId = $this->source('OTHER_PAYLOAD_SOURCE');
        $stored = app(ClinicalPayloadStore::class)->storeJson($sourceId, 'fhir_resource', ['resourceType' => 'Patient']);

        $this->expectPayloadError('clinical_payload_authority_mismatch', fn () => app(ClinicalPayloadStore::class)
            ->readJson($stored->payloadObjectId, $otherSourceId, 'fhir_resource'));
        $this->expectPayloadError('clinical_payload_authority_mismatch', fn () => app(ClinicalPayloadStore::class)
            ->readJson($stored->payloadObjectId, $sourceId, 'canonical_event'));

        DB::beginTransaction();
        try {
            DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)
                ->update(['plaintext_sha256' => str_repeat('a', 64)]);
            $this->fail('Payload cryptographic authority accepted direct drift.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::table('raw.payload_object_events')->where('payload_object_id', $stored->payloadObjectId)->delete();
            $this->fail('Payload evidence accepted deletion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::table('raw.payload_object_events')->insert([
                'event_uuid' => (string) str()->uuid7(),
                'payload_object_id' => $stored->payloadObjectId,
                'source_id' => $sourceId,
                'event_type' => 'hold_applied',
                'from_status' => 'ready',
                'to_status' => 'ready',
                'legal_hold' => true,
                'reason_code' => 'direct_hold_bypass',
                'reason' => 'Attempt to bypass the independently governed legal-hold workflow.',
                'occurred_at' => now(),
            ]);
            $this->fail('Payload event ledger accepted an ungoverned legal hold.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('payload_object_events_governance_chk', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            DB::table('raw.payload_object_events')->insert([
                'event_uuid' => (string) str()->uuid7(),
                'payload_object_id' => $stored->payloadObjectId,
                'source_id' => $sourceId,
                'event_type' => 'deleted',
                'from_status' => 'ready',
                'to_status' => 'deleted',
                'legal_hold' => false,
                'reason_code' => 'direct_delete_bypass',
                'reason' => 'Attempt to bypass retention and governed exceptional-purge transitions.',
                'occurred_at' => now(),
            ]);
            $this->fail('Payload event ledger accepted an invalid direct deletion transition.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('payload object lifecycle transition is invalid', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    private function source(string $key = 'CLINICAL_PAYLOAD_SOURCE'): int
    {
        $organization = Organization::query()->firstOrCreate(
            ['organization_key' => 'CLINICAL_PAYLOAD_ORG'],
            ['name' => 'Clinical Payload Organization', 'kind' => 'idn'],
        );
        $facility = Facility::query()->firstOrCreate(
            ['facility_key' => 'CLINICAL_PAYLOAD_FACILITY'],
            [
                'organization_id' => $organization->organization_id,
                'facility_name' => 'Clinical Payload Facility',
                'idn_role' => 'community_hospital',
                'review_status' => 'client_verified',
                'is_active' => true,
            ],
        );

        return (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) str()->uuid(),
            'source_key' => strtolower($key),
            'source_name' => str($key)->headline()->toString(),
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }

    private function expectPayloadError(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected {$code}.");
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
