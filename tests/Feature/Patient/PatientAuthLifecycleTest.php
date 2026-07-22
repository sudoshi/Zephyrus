<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientAccessAuditEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientEnrollmentChallenge;
use App\Models\Patient\PatientIdentityLink;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PatientAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_exchange_rotates_refresh_and_revoke_closes_the_session_family(): void
    {
        $this->enablePatientAuth();
        $principal = $this->activePrincipal('patient.auth@example.test', 'PatientReady1!Secure');
        $deviceUuid = (string) Str::uuid7();

        $issued = $this->postJson('/api/patient/v1/auth/token', [
            'email' => $principal->email,
            'password' => 'PatientReady1!Secure',
            'device' => [
                'uuid' => $deviceUuid,
                'platform' => 'ios',
                'name' => 'Test iPhone',
                'app_version' => '0.1.0',
                'os_version' => '18.5',
            ],
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.abilities.0', 'patient:access')
            ->assertJsonStructure([
                'data' => ['access_token', 'refresh_token', 'expires_in', 'session_uuid'],
                'meta' => ['as_of', 'stale', 'version'],
                'links',
            ]);

        $firstAccess = (string) $issued->json('data.access_token');
        $firstRefresh = (string) $issued->json('data.refresh_token');
        $sessionUuid = (string) $issued->json('data.session_uuid');
        $firstAccessId = (int) Str::before($firstAccess, '|');
        $firstRefreshId = (int) Str::before($firstRefresh, '|');

        $session = PatientSession::query()->where('session_uuid', $sessionUuid)->firstOrFail();
        $this->assertSame($principal->getKey(), $session->principal_id);
        $this->assertSame($deviceUuid, (string) $session->device_uuid);
        $this->assertSame('ios', $session->platform);
        $this->assertSame('active', $session->status);
        $this->assertSame(['patient:access'], PersonalAccessToken::query()
            ->findOrFail($firstAccessId)->abilities);
        $this->assertSame(['patient:refresh'], PersonalAccessToken::query()
            ->findOrFail($firstRefreshId)->abilities);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'patient_session_id' => $session->getKey(),
            'event_type' => 'patient.auth.token_issued',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($firstAccess)
            ->postJson('/api/patient/v1/auth/token/refresh')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($firstRefresh)
            ->getJson('/api/patient/v1/me')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $rotated = $this->withToken($firstRefresh)
            ->postJson('/api/patient/v1/auth/token/refresh')
            ->assertOk()
            ->assertJsonPath('data.session_uuid', $sessionUuid);

        $secondAccess = (string) $rotated->json('data.access_token');
        $secondRefresh = (string) $rotated->json('data.refresh_token');
        $this->assertNotSame($firstAccess, $secondAccess);
        $this->assertNotSame($firstRefresh, $secondRefresh);
        $this->assertFalse(PersonalAccessToken::query()->whereKey($firstAccessId)->exists());
        $this->assertTrue(PersonalAccessToken::query()->whereKey($firstRefreshId)->exists());
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'patient_session_id' => $session->getKey(),
            'event_type' => 'patient.auth.token_refreshed',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($secondAccess)
            ->postJson('/api/patient/v1/auth/token/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $session->refresh();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('user_logout', $session->revocation_reason);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame(0, $principal->tokens()->whereIn('name', [
            'patient-access:'.$sessionUuid,
            'patient-refresh:'.$sessionUuid,
        ])->count());
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'patient_session_id' => $session->getKey(),
            'event_type' => 'patient.auth.token_revoked',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($secondRefresh)
            ->postJson('/api/patient/v1/auth/token/refresh')
            ->assertUnauthorized();
    }

    public function test_reused_rotated_refresh_token_revokes_the_entire_session_family(): void
    {
        $this->enablePatientAuth();
        $principal = $this->activePrincipal('patient.reuse@example.test', 'PatientReady1!Secure');

        $issued = $this->postJson('/api/patient/v1/auth/token', [
            'email' => $principal->email,
            'password' => 'PatientReady1!Secure',
        ])->assertOk();
        $firstRefresh = (string) $issued->json('data.refresh_token');
        $sessionUuid = (string) $issued->json('data.session_uuid');

        $this->app['auth']->forgetGuards();
        $rotated = $this->withToken($firstRefresh)
            ->postJson('/api/patient/v1/auth/token/refresh')
            ->assertOk();
        $secondAccess = (string) $rotated->json('data.access_token');

        $this->app['auth']->forgetGuards();
        $this->withToken($firstRefresh)
            ->postJson('/api/patient/v1/auth/token/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_refresh_token');

        $session = PatientSession::query()->where('session_uuid', $sessionUuid)->firstOrFail();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('invalid_refresh_token', $session->revocation_reason);
        $this->assertSame(0, $principal->tokens()->whereIn('name', [
            'patient-access:'.$sessionUuid,
            'patient-refresh:'.$sessionUuid,
        ])->count());
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'patient_session_id' => $session->getKey(),
            'event_type' => 'patient.auth.refresh_denied',
            'outcome' => 'denied',
            'reason_code' => 'invalid_refresh_token',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($secondAccess)
            ->getJson('/api/patient/v1/me')
            ->assertUnauthorized();
    }

    public function test_enrollment_consumes_bound_challenge_and_activates_principal_and_grant(): void
    {
        $this->enablePatientAuth();
        [$principal, $grant, $challenge, $challengeToken, $verificationCode] = $this->enrollmentFixture();

        $response = $this->postJson('/api/patient/v1/auth/enroll/challenge/verify', [
            'challenge_uuid' => (string) $challenge->challenge_uuid,
            'challenge_token' => $challengeToken,
            'verification_code' => $verificationCode,
            'display_name' => 'Alex Patient',
            'email' => 'alex.patient@example.test',
            'password' => 'PatientReady1!Secure',
            'password_confirmation' => 'PatientReady1!Secure',
            'device' => [
                'uuid' => (string) Str::uuid7(),
                'platform' => 'android',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'session_uuid']]);

        $principal->refresh();
        $grant->refresh();
        $challenge->refresh();

        $this->assertSame('active', $principal->status);
        $this->assertTrue($principal->is_active);
        $this->assertSame('alex.patient@example.test', $principal->email);
        $this->assertTrue(Hash::check('PatientReady1!Secure', (string) $principal->password));
        $this->assertSame('active', $grant->status);
        $this->assertSame('consumed', $challenge->status);
        $this->assertNotNull($challenge->consumed_at);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'event_type' => 'patient.auth.enrollment_succeeded',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $response->json('data.access_token'))
            ->getJson('/api/patient/v1/me')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Alex Patient');
    }

    public function test_invalid_enrollment_attempt_is_persisted_and_locks_at_limit(): void
    {
        $this->enablePatientAuth();
        [, , $challenge, $challengeToken] = $this->enrollmentFixture(maxAttempts: 1);

        $this->postJson('/api/patient/v1/auth/enroll/challenge/verify', [
            'challenge_uuid' => (string) $challenge->challenge_uuid,
            'challenge_token' => $challengeToken,
            'verification_code' => '999999',
            'display_name' => 'Alex Patient',
            'email' => 'alex.patient@example.test',
            'password' => 'PatientReady1!Secure',
            'password_confirmation' => 'PatientReady1!Secure',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_enrollment_challenge');

        $challenge->refresh();
        $this->assertSame(1, $challenge->failed_attempts);
        $this->assertSame('locked', $challenge->status);
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'event_type' => 'patient.auth.enrollment_denied',
            'outcome' => 'denied',
            'reason_code' => 'invalid_enrollment_challenge',
        ]);
    }

    public function test_enrollment_verifies_and_consumes_under_one_challenge_lock(): void
    {
        $this->enablePatientAuth();
        [, , $challenge, $challengeToken, $verificationCode] = $this->enrollmentFixture();
        $challengeSelects = 0;

        DB::listen(function (QueryExecuted $query) use (&$challengeSelects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains($query->sql, 'patient_experience')
                && str_contains($query->sql, 'enrollment_challenges')) {
                $challengeSelects++;
            }
        });

        $this->postJson('/api/patient/v1/auth/enroll/challenge/verify', [
            'challenge_uuid' => (string) $challenge->challenge_uuid,
            'challenge_token' => $challengeToken,
            'verification_code' => $verificationCode,
            'display_name' => 'Atomic Enrollment Patient',
            'email' => 'atomic.enrollment@example.test',
            'password' => 'PatientReady1!Secure',
            'password_confirmation' => 'PatientReady1!Secure',
        ])->assertCreated();

        $this->assertSame(
            1,
            $challengeSelects,
            'Enrollment must not release the challenge row lock between verification and consumption.',
        );
    }

    public function test_invalid_credentials_use_generic_error_and_are_audited_without_identifier(): void
    {
        $this->enablePatientAuth();

        $this->postJson('/api/patient/v1/auth/token', [
            'email' => 'does.not.exist@example.test',
            'password' => 'PatientReady1!Secure',
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credentials');

        $event = PatientAccessAuditEvent::query()
            ->where('event_type', 'patient.auth.token_denied')
            ->firstOrFail();
        $this->assertNull($event->principal_id);
        $this->assertSame('invalid_credentials', $event->reason_code);
        $this->assertStringNotContainsString('does.not.exist', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    private function enablePatientAuth(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.enrollment' => true,
            'hummingbird-patient.features.token_exchange' => true,
            'hummingbird-patient.features.profile' => true,
        ]);
    }

    private function activePrincipal(string $email, string $password): PatientPrincipal
    {
        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Patient Auth Test',
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'is_active' => true,
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }

    /** @return array{PatientPrincipal, PatientEncounterAccessGrant, PatientEnrollmentChallenge, string, string} */
    private function enrollmentFixture(int $maxAttempts = 5): array
    {
        $principal = PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Pending Patient',
            'status' => 'pending',
            'is_active' => false,
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
        $identityLink = PatientIdentityLink::query()->create([
            'identity_link_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'source_system_key' => 'test-ehr',
            'encrypted_source_subject' => 'TEST-SUBJECT-DO-NOT-EXPOSE',
            'encryption_key_version' => 'test-v1',
            'source_subject_digest' => hash('sha256', (string) Str::uuid()),
            'linkage_method' => 'encounter_enrollment',
            'status' => 'verified',
            'assurance_level' => 'test-verified',
            'provenance' => ['source' => 'automated_test'],
            'verified_at' => now(),
        ]);
        $grant = PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'identity_link_id' => $identityLink->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_ref_digest' => hash('sha256', (string) Str::uuid()),
            'source_system_key' => 'test-ehr',
            'relationship' => 'self',
            'scopes' => ['care_pathway', 'care_team'],
            'purpose_of_use' => 'patient_access',
            'status' => 'pending',
            'valid_from' => now()->subMinute(),
            'grant_reason' => 'Automated enrollment lifecycle test.',
            'version' => 1,
        ]);
        $challengeToken = Str::random(64);
        $verificationCode = '314159';
        $challenge = PatientEnrollmentChallenge::query()->create([
            'challenge_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'identity_link_id' => $identityLink->getKey(),
            'access_grant_id' => $grant->getKey(),
            'challenge_hash' => Hash::make($challengeToken),
            'code_hash' => Hash::make($verificationCode),
            'purpose' => 'encounter_enrollment',
            'delivery_method' => 'in_person',
            'status' => 'issued',
            'failed_attempts' => 0,
            'max_attempts' => $maxAttempts,
            'expires_at' => now()->addMinutes(10),
            'metadata' => ['source' => 'automated_test'],
        ]);

        return [$principal, $grant, $challenge, $challengeToken, $verificationCode];
    }
}
