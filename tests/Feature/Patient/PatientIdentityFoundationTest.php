<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEnrollmentChallenge;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientNotificationDevice;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use LogicException;
use Tests\TestCase;

class PatientIdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_identity_foundation_tables_and_boundary_columns_exist(): void
    {
        foreach ([
            'patient_experience.principals',
            'patient_experience.identity_links',
            'patient_experience.encounter_access_grants',
            'patient_experience.enrollment_challenges',
            'patient_experience.sessions',
            'patient_experience.access_audit_events',
            'patient_experience.notification_outbox',
            'patient_experience.notification_delivery_attempts',
            'patient_experience.notification_devices',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing patient identity table {$table}");
        }

        $this->assertTrue(Schema::hasColumns('patient_experience.principals', [
            'principal_uuid', 'principal_type', 'email', 'password', 'status', 'is_active',
            'preferences', 'last_authenticated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('patient_experience.identity_links', [
            'identity_link_uuid', 'principal_id', 'encrypted_source_subject',
            'source_subject_digest', 'linkage_method', 'status', 'provenance',
        ]));
        $this->assertTrue(Schema::hasColumns('patient_experience.encounter_access_grants', [
            'grant_uuid', 'encounter_uuid', 'principal_id', 'identity_link_id',
            'encrypted_source_encounter_ref', 'source_encounter_ref_digest',
            'relationship', 'scopes', 'purpose_of_use', 'valid_from', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('patient_experience.sessions', [
            'session_uuid', 'principal_id', 'token_family_uuid', 'refresh_token_id',
            'device_uuid', 'ip_address', 'last_seen_at', 'expires_at', 'revoked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('patient_experience.notification_devices', [
            'device_uuid', 'principal_id', 'platform', 'environment', 'installation_uuid',
            'encrypted_push_token', 'encryption_key_version', 'push_token_digest', 'status',
            'last_seen_at', 'revoked_at', 'revocation_reason',
        ]));
    }

    public function test_patient_schema_has_no_foreign_key_to_staff_users(): void
    {
        $staffCouplings = DB::select(<<<'SQL'
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'patient_experience'
              AND ccu.table_schema = 'prod'
              AND ccu.table_name = 'users'
        SQL);

        $this->assertSame([], $staffCouplings, 'Patient auth must not couple to prod.users.');
    }

    public function test_patient_schema_does_not_define_raw_identity_or_bearer_secret_columns(): void
    {
        $forbidden = DB::table('information_schema.columns')
            ->where('table_schema', 'patient_experience')
            ->whereIn('column_name', [
                'mrn',
                'patient_ref',
                'encounter_ref',
                'challenge_token',
                'verification_code',
                'refresh_token',
                'access_token',
                'push_token',
            ])
            ->pluck('column_name')
            ->all();

        $this->assertSame([], $forbidden);
    }

    public function test_notification_devices_are_patient_owned_and_do_not_expose_token_material(): void
    {
        $principal = $this->createPrincipal();
        $device = PatientNotificationDevice::create([
            'principal_id' => $principal->getKey(),
            'platform' => 'ios',
            'environment' => 'sandbox',
            'installation_uuid' => (string) Str::uuid(),
            'encrypted_push_token' => 'ciphertext-not-a-provider-token',
            'encryption_key_version' => 'notification-device-key-v1',
            'push_token_digest' => hash_hmac('sha256', 'provider-token', 'test-key'),
            'last_seen_at' => now(),
        ]);

        $this->assertTrue(Str::isUuid($device->device_uuid));
        $this->assertSame($principal->getKey(), $device->principal->getKey());
        $this->assertArrayNotHasKey('encrypted_push_token', $device->toArray());
        $this->assertArrayNotHasKey('push_token_digest', $device->toArray());
        $this->assertSame(1, $principal->notificationDevices()->active()->count());
    }

    public function test_patient_principal_is_an_independent_auth_subject_with_hashed_password_and_uuid(): void
    {
        $principal = $this->createPrincipal();

        $this->assertInstanceOf(Authenticatable::class, $principal);
        $this->assertContains(HasApiTokens::class, class_uses_recursive($principal));
        $this->assertTrue(Str::isUuid($principal->principal_uuid));
        $this->assertSame('patient@example.test', $principal->email);
        $this->assertTrue(Hash::check('not-a-raw-database-secret', $principal->getAuthPassword()));
        $this->assertNotSame(
            'not-a-raw-database-secret',
            DB::table('patient_experience.principals')
                ->where('principal_id', $principal->principal_id)
                ->value('password'),
        );
        $this->assertSame(['reduce_motion' => false], $principal->toArray()['preferences']);
    }

    public function test_identity_and_encounter_source_references_are_encrypted_by_models(): void
    {
        $principal = $this->createPrincipal();
        $identity = PatientIdentityLink::create([
            'principal_id' => $principal->principal_id,
            'source_system_key' => 'test-ehr',
            'encrypted_source_subject' => 'MRN-DO-NOT-STORE-RAW',
            'encryption_key_version' => 'app-key-v1',
            'source_subject_digest' => hash_hmac('sha256', 'MRN-DO-NOT-STORE-RAW', 'test-key'),
            'linkage_method' => 'portal_federation',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
        $grant = PatientEncounterAccessGrant::create([
            'principal_id' => $principal->principal_id,
            'identity_link_id' => $identity->identity_link_id,
            'source_system_key' => 'test-ehr',
            'source_encounter_id' => 42,
            'encrypted_source_encounter_ref' => 'ENCOUNTER-DO-NOT-STORE-RAW',
            'source_encounter_ref_digest' => hash_hmac('sha256', 'ENCOUNTER-DO-NOT-STORE-RAW', 'test-key'),
            'relationship' => 'self',
            'scopes' => ['care_pathway', 'care_team'],
            'purpose_of_use' => 'patient_access',
            'status' => 'active',
            'grant_reason' => 'Verified inpatient encounter enrollment.',
        ]);

        $this->assertTrue(Str::isUuid($identity->identity_link_uuid));
        $this->assertTrue(Str::isUuid($grant->grant_uuid));
        $this->assertTrue(Str::isUuid($grant->encounter_uuid));
        $this->assertSame('MRN-DO-NOT-STORE-RAW', $identity->encrypted_source_subject);
        $this->assertSame('ENCOUNTER-DO-NOT-STORE-RAW', $grant->encrypted_source_encounter_ref);
        $this->assertNotSame(
            'MRN-DO-NOT-STORE-RAW',
            DB::table('patient_experience.identity_links')
                ->where('identity_link_id', $identity->identity_link_id)
                ->value('encrypted_source_subject'),
        );
        $this->assertNotSame(
            'ENCOUNTER-DO-NOT-STORE-RAW',
            DB::table('patient_experience.encounter_access_grants')
                ->where('access_grant_id', $grant->access_grant_id)
                ->value('encrypted_source_encounter_ref'),
        );
        $this->assertTrue($grant->permits('care_pathway'));
        $this->assertFalse($grant->permits('staff_operations'));
    }

    public function test_enrollment_challenge_uses_hashes_and_effective_grant_and_session_use_external_uuids(): void
    {
        $principal = $this->createPrincipal();
        $grant = $this->createGrant($principal);
        $challenge = PatientEnrollmentChallenge::create([
            'principal_id' => $principal->principal_id,
            'access_grant_id' => $grant->access_grant_id,
            'challenge_hash' => Hash::make('high-entropy-enrollment-token'),
            'code_hash' => Hash::make('648213'),
            'purpose' => 'encounter_enrollment',
            'delivery_method' => 'portal',
            'status' => 'issued',
            'expires_at' => now()->addMinutes(15),
        ]);
        $session = PatientSession::create([
            'principal_id' => $principal->principal_id,
            'auth_method' => 'enrollment_challenge',
            'assurance_level' => 'aal2',
            'platform' => 'ios',
            'ip_address' => '192.0.2.10',
            'expires_at' => now()->addHours(8),
        ]);

        $this->assertTrue($challenge->matchesChallengeToken('high-entropy-enrollment-token'));
        $this->assertTrue($challenge->matchesVerificationCode('648213'));
        $this->assertFalse($challenge->matchesVerificationCode('000000'));
        $this->assertTrue($challenge->isUsable());
        $this->assertTrue(Str::isUuid($challenge->challenge_uuid));
        $this->assertTrue(Str::isUuid($session->session_uuid));
        $this->assertTrue(Str::isUuid($session->token_family_uuid));
        $this->assertSame($grant->access_grant_id, $challenge->accessGrant->access_grant_id);
    }

    public function test_access_audit_model_and_database_ledger_are_append_only(): void
    {
        $principal = $this->createPrincipal();
        $event = PatientAccessAuditEvent::create([
            'principal_id' => $principal->principal_id,
            'actor_type' => 'patient',
            'event_type' => 'patient.auth.succeeded',
            'category' => 'authentication',
            'action' => 'login',
            'outcome' => 'succeeded',
            'purpose_of_use' => 'patient_access',
            'request_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $event->update(['outcome' => 'failed']);
    }

    public function test_access_audit_trigger_rejects_query_builder_mutation(): void
    {
        $event = PatientAccessAuditEvent::create([
            'actor_type' => 'system',
            'event_type' => 'patient.enrollment.recorded',
            'category' => 'enrollment',
            'action' => 'record',
            'outcome' => 'recorded',
            'request_uuid' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        DB::beginTransaction();
        try {
            DB::table('patient_experience.access_audit_events')
                ->where('access_audit_event_id', $event->access_audit_event_id)
                ->update(['outcome' => 'failed']);
            $this->fail('The patient access ledger accepted an in-place mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_notification_outbox_is_encrypted_and_append_only(): void
    {
        $principal = $this->createPrincipal();
        $outbox = PatientNotificationOutbox::create([
            'principal_id' => $principal->principal_id,
            'aggregate_type' => 'encounter_pathway',
            'aggregate_uuid' => (string) Str::uuid(),
            'event_type' => 'patient.pathway.updated',
            'destination' => 'patient_push',
            'encrypted_payload' => ['notification_type' => 'pathway_updated'],
            'encryption_key_version' => 'app-key-v1',
            'payload_digest' => hash('sha256', 'pathway_updated'),
            'routing_metadata' => ['urgency' => 'routine'],
            'idempotency_key_digest' => hash('sha256', (string) Str::uuid()),
            'occurred_at' => now(),
        ]);

        $this->assertSame(
            ['notification_type' => 'pathway_updated'],
            $outbox->encrypted_payload,
        );
        $this->assertNotSame(
            json_encode(['notification_type' => 'pathway_updated']),
            DB::table('patient_experience.notification_outbox')
                ->where('notification_outbox_id', $outbox->notification_outbox_id)
                ->value('encrypted_payload'),
        );

        DB::beginTransaction();
        try {
            DB::table('patient_experience.notification_outbox')
                ->where('notification_outbox_id', $outbox->notification_outbox_id)
                ->delete();
            $this->fail('The patient notification outbox accepted a destructive mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    private function createPrincipal(): PatientPrincipal
    {
        return PatientPrincipal::create([
            'principal_type' => 'patient',
            'display_name' => 'Test Patient',
            'email' => 'PATIENT@EXAMPLE.TEST',
            'password' => 'not-a-raw-database-secret',
            'status' => 'active',
            'is_active' => true,
            'preferences' => ['reduce_motion' => false],
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    private function createGrant(PatientPrincipal $principal): PatientEncounterAccessGrant
    {
        return PatientEncounterAccessGrant::create([
            'principal_id' => $principal->principal_id,
            'source_system_key' => 'test-ehr',
            'source_encounter_ref_digest' => hash_hmac('sha256', 'test-encounter', 'test-key'),
            'relationship' => 'self',
            'scopes' => ['care_pathway', 'care_team'],
            'purpose_of_use' => 'patient_access',
            'status' => 'active',
            'grant_reason' => 'Verified inpatient encounter enrollment.',
            'valid_from' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);
    }
}
