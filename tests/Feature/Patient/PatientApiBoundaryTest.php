<?php

namespace Tests\Feature\Patient;

use App\Models\Audit\UserEvent;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientNotificationDevice;
use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use App\Models\User;
use App\Services\Patient\PatientHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PatientApiBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_route_inventory_is_separate_and_explicitly_gated(): void
    {
        $this->assertSame('patient_principals', config('auth.guards.patient.provider'));
        $this->assertSame(PatientPrincipal::class, config('auth.providers.patient_principals.model'));
        $this->assertSame(User::class, config('auth.providers.users.model'));

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/patient/v1'))
            ->mapWithKeys(fn (IlluminateRoute $route): array => [
                collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->implode('|').' '.$route->uri() => $route,
            ]);

        $this->assertSame([
            'DELETE api/patient/v1/me/sessions/{sessionUuid}',
            'DELETE api/patient/v1/me/notification-devices/{deviceUuid}',
            'GET api/patient/v1/encounters',
            'GET api/patient/v1/encounters/{encounterUuid}/care-team',
            'GET api/patient/v1/encounters/{encounterUuid}/discharge-readiness',
            'GET api/patient/v1/encounters/{encounterUuid}/message-topics',
            'GET api/patient/v1/encounters/{encounterUuid}/pathway',
            'GET api/patient/v1/encounters/{encounterUuid}/pathway/events',
            'GET api/patient/v1/encounters/{encounterUuid}/rounds/summary',
            'GET api/patient/v1/encounters/{encounterUuid}/threads',
            'GET api/patient/v1/encounters/{encounterUuid}/today',
            'GET api/patient/v1/me',
            'GET api/patient/v1/me/sessions',
            'GET api/patient/v1/threads/{threadUuid}',
            'POST api/patient/v1/auth/enroll/challenge/verify',
            'POST api/patient/v1/auth/token',
            'POST api/patient/v1/auth/token/refresh',
            'POST api/patient/v1/auth/token/revoke',
            'POST api/patient/v1/encounters/{encounterUuid}/threads',
            'POST api/patient/v1/threads/{threadUuid}/close',
            'POST api/patient/v1/threads/{threadUuid}/messages',
            'POST api/patient/v1/threads/{threadUuid}/messages/{messageUuid}/amend',
            'PUT api/patient/v1/me/preferences',
            'PUT api/patient/v1/me/notification-devices/{deviceUuid}',
        ], $routes->keys()->sort()->values()->all());

        foreach ($routes as $route) {
            $middleware = collect($route->gatherMiddleware());
            $this->assertTrue($middleware->contains('patient.response'));
            $this->assertTrue($middleware->contains('patient.enabled'));
            $this->assertTrue($middleware->contains(
                fn (string $name): bool => str_starts_with($name, 'patient.feature:'),
            ));
            $this->assertTrue($middleware->contains(
                fn (string $name): bool => str_starts_with($name, 'throttle:'),
            ));
        }
    }

    public function test_every_patient_feature_fails_closed_by_default(): void
    {
        config([
            'hummingbird-patient.enabled' => false,
            'hummingbird-patient.features.enrollment' => false,
            'hummingbird-patient.features.token_exchange' => false,
            'hummingbird-patient.features.profile' => false,
            'hummingbird-patient.features.session_management' => false,
            'hummingbird-patient.features.notification_devices' => false,
            'hummingbird-patient.features.encounters' => false,
            'hummingbird-patient.features.today' => false,
            'hummingbird-patient.features.pathway' => false,
            'hummingbird-patient.features.rounds_summary' => false,
            'hummingbird-patient.features.rounds_questions' => false,
            'hummingbird-patient.features.care_team' => false,
            'hummingbird-patient.features.messaging' => false,
        ]);

        $encounterUuid = '018f47a3-65ad-7c44-b1b8-3ca57d2c14ef';

        foreach ([
            ['postJson', '/api/patient/v1/auth/enroll/challenge/verify'],
            ['postJson', '/api/patient/v1/auth/token'],
            ['postJson', '/api/patient/v1/auth/token/refresh'],
            ['postJson', '/api/patient/v1/auth/token/revoke'],
            ['getJson', '/api/patient/v1/me'],
            ['getJson', '/api/patient/v1/me/sessions'],
            ['deleteJson', "/api/patient/v1/me/sessions/{$encounterUuid}"],
            ['putJson', '/api/patient/v1/me/preferences'],
            ['putJson', "/api/patient/v1/me/notification-devices/{$encounterUuid}"],
            ['deleteJson', "/api/patient/v1/me/notification-devices/{$encounterUuid}"],
            ['getJson', '/api/patient/v1/encounters'],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/today"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/pathway"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/pathway/events"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/discharge-readiness"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/rounds/summary"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/care-team"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/message-topics"],
            ['getJson', "/api/patient/v1/encounters/{$encounterUuid}/threads"],
            ['postJson', "/api/patient/v1/encounters/{$encounterUuid}/threads"],
            ['getJson', "/api/patient/v1/threads/{$encounterUuid}"],
            ['postJson', "/api/patient/v1/threads/{$encounterUuid}/messages"],
            ['postJson', "/api/patient/v1/threads/{$encounterUuid}/messages/{$encounterUuid}/amend"],
            ['postJson', "/api/patient/v1/threads/{$encounterUuid}/close"],
        ] as [$method, $path]) {
            $this->{$method}($path)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found')
                ->assertJsonStructure([
                    'data',
                    'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                    'links',
                ])
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        }
    }

    public function test_disabled_subfeature_returns_not_found_before_authentication(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.profile' => false,
        ]);

        $this->getJson('/api/patient/v1/me')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_enabled_patient_reads_require_authentication(): void
    {
        $this->enablePatientReads();

        $this->getJson('/api/patient/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Authentication is required.')
            ->assertJsonMissingPath('message')
            ->assertJsonStructure([
                'data',
                'error' => ['code', 'message'],
                'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                'links',
            ]);
        $this->getJson('/api/patient/v1/encounters')->assertUnauthorized();
    }

    public function test_patient_validation_errors_use_the_patient_metadata_contract(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.token_exchange' => true,
        ]);

        $this->postJson('/api/patient/v1/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'The submitted patient request is invalid.')
            ->assertJsonMissingPath('message')
            ->assertJsonStructure([
                'data',
                'error' => ['code', 'message'],
                'errors' => ['email', 'password'],
                'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                'links',
            ]);
    }

    public function test_staff_credential_exchange_does_not_depend_on_patient_hmac(): void
    {
        config(['hummingbird-patient.hmac_secret' => null]);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->postJson('/api/auth/token', [
                'username' => 'does-not-exist',
                'password' => 'wrong',
            ])
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'invalid_credentials');
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_enabled_patient_auth_fails_closed_without_patient_hmac_outside_testing(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.token_exchange' => true,
            'hummingbird-patient.hmac_secret' => null,
        ]);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->postJson('/api/patient/v1/auth/token', [
                'email' => 'patient@example.test',
                'password' => 'NotARealPatient1!',
            ])
                ->assertStatus(500)
                ->assertJsonPath('data', null)
                ->assertJsonPath('error.code', 'service_unavailable')
                ->assertJsonPath('error.message', 'The patient service is temporarily unavailable.')
                ->assertJsonStructure([
                    'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
                    'links',
                ]);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_staff_session_and_token_cannot_enter_patient_realm(): void
    {
        $this->enablePatientReads();
        $staff = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($staff)
            ->getJson('/api/patient/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'patient_realm_required');

        $token = $staff->createToken('staff-accidental-patient-scope', ['patient:access'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/patient/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'patient_realm_required');
    }

    public function test_patient_token_cannot_enter_staff_realm_even_when_over_scoped(): void
    {
        $principal = $this->patientPrincipal();
        $sessionUuid = (string) Str::uuid7();
        $token = $principal->createToken(
            'patient-access:'.$sessionUuid,
            ['patient:access', 'mobile:read', 'token:refresh'],
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'staff_realm_required');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->postJson('/api/auth/token/refresh')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'staff_realm_required');
    }

    public function test_patient_realm_rejects_noncanonical_token_abilities(): void
    {
        $this->enablePatientReads();
        $principal = $this->patientPrincipal();
        $token = $principal->createToken(
            'patient-access:'.Str::uuid7(),
            ['patient:access', 'mobile:read'],
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/patient/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'patient_realm_required');
    }

    public function test_patient_realm_rejects_an_exact_ability_token_without_an_active_session(): void
    {
        $this->enablePatientReads();
        $principal = $this->patientPrincipal();
        $token = $principal->createToken(
            'patient-access:'.Str::uuid7(),
            ['patient:access'],
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/patient/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'patient_realm_required');

        $this->assertSame(0, $principal->tokens()->count());
    }

    public function test_patient_hmac_uses_testing_fallback_but_fails_closed_outside_testing(): void
    {
        config(['hummingbird-patient.hmac_secret' => null]);
        $hmac = $this->app->make(PatientHmac::class);

        $first = $hmac->digest('test-purpose', 'same-value');
        $this->assertSame($first, $hmac->digest('test-purpose', 'same-value'));
        $this->assertNotSame($first, $hmac->digest('other-purpose', 'same-value'));

        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $hmac->digest('test-purpose', 'same-value');
            $this->fail('Production must reject a missing patient HMAC secret.');
        } catch (RuntimeException $exception) {
            $this->assertSame('hummingbird_patient_hmac_secret_unavailable', $exception->getMessage());
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    public function test_patient_profile_and_encounters_expose_only_patient_safe_handles(): void
    {
        $this->enablePatientReads();
        $principal = $this->patientPrincipal();
        $active = PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_ref_digest' => hash('sha256', (string) Str::uuid()),
            'source_system_key' => 'test-ehr',
            'relationship' => 'self',
            'scopes' => ['today:read', 'pathway:read'],
            'purpose_of_use' => 'treatment',
            'status' => 'active',
            'valid_from' => now()->subMinute(),
            'grant_reason' => 'Automated patient API boundary test.',
            'version' => 1,
        ]);
        PatientEncounterAccessGrant::query()->create([
            'grant_uuid' => (string) Str::uuid7(),
            'principal_id' => $principal->getKey(),
            'encounter_uuid' => (string) Str::uuid7(),
            'source_encounter_ref_digest' => hash('sha256', (string) Str::uuid()),
            'source_system_key' => 'test-ehr',
            'relationship' => 'self',
            'scopes' => ['today:read'],
            'purpose_of_use' => 'treatment',
            'status' => 'revoked',
            'valid_from' => now()->subDay(),
            'revoked_at' => now()->subMinute(),
            'revocation_reason' => 'Automated patient API boundary test.',
            'grant_reason' => 'Automated patient API boundary test.',
            'version' => 2,
        ]);
        foreach (['expired', 'suspended'] as $status) {
            PatientEncounterAccessGrant::query()->create([
                'grant_uuid' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'encounter_uuid' => (string) Str::uuid7(),
                'source_encounter_ref_digest' => hash('sha256', (string) Str::uuid()),
                'source_system_key' => 'test-ehr',
                'relationship' => 'self',
                'scopes' => ['today:read'],
                'purpose_of_use' => 'treatment',
                'status' => $status,
                'valid_from' => now()->subDay(),
                'expires_at' => $status === 'expired' ? now()->subMinute() : now()->addDay(),
                'grant_reason' => 'Automated patient API boundary test.',
                'version' => 3,
            ]);
        }

        $sessionUuid = (string) Str::uuid7();
        PatientSession::query()->create([
            'session_uuid' => $sessionUuid,
            'principal_id' => $principal->getKey(),
            'auth_method' => 'password',
            'status' => 'active',
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
            'idle_expires_at' => now()->addDay(),
        ]);
        $token = $principal->createToken('patient-access:'.$sessionUuid, ['patient:access'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/patient/v1/me')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('data.principal_uuid', (string) $principal->principal_uuid)
            ->assertJsonStructure([
                'meta' => ['request_id', 'generated_at', 'source_freshness', 'policy_version'],
            ])
            ->assertJsonPath('meta.policy_version', 'patient-disclosure-v1-draft')
            ->assertJsonMissingPath('data.principal_id')
            ->assertJsonMissingPath('links.web');

        $staffAuditCount = UserEvent::query()->count();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/patient/v1/encounters')
            ->assertOk()
            ->assertJsonCount(1, 'data.encounters')
            ->assertJsonPath('data.encounters.0.encounter_uuid', (string) $active->encounter_uuid)
            ->assertJsonMissing(['source_encounter_id'])
            ->assertJsonMissing(['encrypted_source_encounter_ref'])
            ->assertJsonMissing(['source_encounter_ref_digest'])
            ->assertJsonMissing(['source_encounter_ref_ciphertext']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->putJson('/api/patient/v1/me/preferences', [
                'locale' => 'es-US',
                'timezone' => 'America/New_York',
                'text_size' => 'large',
                'high_contrast' => true,
                'notification_preview' => 'generic',
            ])
            ->assertOk()
            ->assertJsonPath('data.locale', 'es-US')
            ->assertJsonPath('data.preferences.text_size', 'large')
            ->assertJsonPath('data.preferences.high_contrast', true);

        $principal->refresh();
        $this->assertSame('large', $principal->preferences['text_size']);
        $this->assertTrue($principal->preferences['high_contrast']);
        $this->assertSame($staffAuditCount, UserEvent::query()->count());
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'event_type' => 'patient.profile.preferences_updated',
            'outcome' => 'succeeded',
        ]);
    }

    public function test_patient_notification_device_registration_encrypts_tokens_and_stays_principal_owned(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.notification_devices' => true,
            'hummingbird-patient.notification_devices.encryption_key_version' => 'test-notification-device-v1',
        ]);
        $principal = $this->patientPrincipal();
        $sessionUuid = (string) Str::uuid7();
        PatientSession::query()->create([
            'session_uuid' => $sessionUuid,
            'principal_id' => $principal->getKey(),
            'auth_method' => 'password',
            'status' => 'active',
            'last_authenticated_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
            'idle_expires_at' => now()->addDay(),
        ]);
        $token = $principal->createToken('patient-access:'.$sessionUuid, ['patient:access'])->plainTextToken;
        $deviceUuid = (string) Str::uuid7();
        $providerToken = str_repeat('a', 64);

        $this->withToken($token)
            ->putJson("/api/patient/v1/me/notification-devices/{$deviceUuid}", [
                'platform' => 'ios',
                'environment' => 'sandbox',
                'installation_uuid' => (string) Str::uuid7(),
                'push_token' => $providerToken,
                'app_version' => '1.0.0',
                'os_version' => '18.0',
                'locale' => 'en-US',
            ])
            ->assertOk()
            ->assertJsonPath('data.device.device_uuid', $deviceUuid)
            ->assertJsonPath('data.device.platform', 'ios')
            ->assertJsonPath('data.device.status', 'active')
            ->assertJsonMissing(['push_token'])
            ->assertJsonMissing(['encrypted_push_token'])
            ->assertJsonMissing(['push_token_digest']);

        $stored = PatientNotificationDevice::query()->where('device_uuid', $deviceUuid)->sole();
        $this->assertSame($principal->getKey(), $stored->principal_id);
        $this->assertNotSame($providerToken, $stored->getRawOriginal('encrypted_push_token'));
        $this->assertSame('test-notification-device-v1', $stored->encryption_key_version);
        $this->assertNotSame($providerToken, $stored->getRawOriginal('push_token_digest'));
        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'event_type' => 'patient.notification_device.registered',
            'outcome' => 'succeeded',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->deleteJson("/api/patient/v1/me/notification-devices/{$deviceUuid}")
            ->assertOk()
            ->assertJsonPath('data.device_uuid', $deviceUuid)
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.already_revoked', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->deleteJson("/api/patient/v1/me/notification-devices/{$deviceUuid}")
            ->assertOk()
            ->assertJsonPath('data.already_revoked', true);

        $stored->refresh();
        $this->assertSame('revoked', $stored->status);
        $this->assertSame('patient_revoked', $stored->revocation_reason);
    }

    private function enablePatientReads(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.profile' => true,
            'hummingbird-patient.features.encounters' => true,
        ]);
    }

    private function patientPrincipal(): PatientPrincipal
    {
        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'principal_type' => 'patient',
            'display_name' => 'Sample Patient',
            'email' => 'sample.patient+'.Str::lower(Str::random(8)).'@example.test',
            'password' => Hash::make('NotARealPatient1!'),
            'status' => 'active',
            'is_active' => true,
            'locale' => 'en-US',
            'timezone' => 'America/New_York',
        ]);
    }
}
