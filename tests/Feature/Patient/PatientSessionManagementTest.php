<?php

namespace Tests\Feature\Patient;

use App\Models\Patient\PatientPrincipal;
use App\Models\Patient\PatientSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PatientSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_lists_only_own_active_sessions_with_safe_device_metadata(): void
    {
        $this->enablePatientSessions();
        $principal = $this->activePrincipal('session.owner@example.test');
        $other = $this->activePrincipal('session.other@example.test');
        $first = $this->issue($principal, 'ios', 'Patient iPhone');
        $second = $this->issue($principal, 'android', 'Patient Android');
        $this->issue($other, 'ios', 'Other iPhone');

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($first['access'])
            ->getJson('/api/patient/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data.sessions');

        $sessions = collect($response->json('data.sessions'));
        $this->assertSame(1, $sessions->where('current', true)->count());
        $this->assertSame($first['session_uuid'], $sessions->firstWhere('current', true)['session_uuid']);
        $this->assertEqualsCanonicalizing(
            [$first['session_uuid'], $second['session_uuid']],
            $sessions->pluck('session_uuid')->all(),
        );

        $serialized = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        foreach ([
            'token_family_uuid', 'refresh_token_id', 'client_instance_digest',
            'user_agent_digest', 'ip_address', 'access_token', 'refresh_token',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'event_type' => 'patient.auth.sessions_viewed',
            'outcome' => 'allowed',
        ]);
    }

    public function test_patient_revokes_another_owned_session_idempotently(): void
    {
        $this->enablePatientSessions();
        $principal = $this->activePrincipal('session.revoke@example.test');
        $current = $this->issue($principal, 'ios', 'Current iPhone');
        $target = $this->issue($principal, 'android', 'Old Android');

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/patient/v1/me/sessions/'.$target['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.already_revoked', false);

        $session = PatientSession::query()->where('session_uuid', $target['session_uuid'])->firstOrFail();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('user_session_revoked', $session->revocation_reason);
        $this->assertSame(0, $principal->tokens()->whereIn('name', [
            'patient-access:'.$target['session_uuid'],
            'patient-refresh:'.$target['session_uuid'],
        ])->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/patient/v1/me/sessions/'.$target['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.already_revoked', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->getJson('/api/patient/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data.sessions');

        $this->assertDatabaseHas('patient_experience.access_audit_events', [
            'principal_id' => $principal->getKey(),
            'event_type' => 'patient.auth.session_revoked',
            'resource_uuid' => $target['session_uuid'],
            'outcome' => 'succeeded',
        ]);
    }

    public function test_cross_principal_and_unknown_session_revocation_are_indistinguishable(): void
    {
        $this->enablePatientSessions();
        $owner = $this->activePrincipal('session.owner2@example.test');
        $attacker = $this->activePrincipal('session.attacker@example.test');
        $ownerSession = $this->issue($owner, 'ios', 'Owner iPhone');
        $attackerSession = $this->issue($attacker, 'android', 'Attacker Android');

        foreach ([$ownerSession['session_uuid'], (string) Str::uuid7()] as $sessionUuid) {
            $this->app['auth']->forgetGuards();
            $this->withToken($attackerSession['access'])
                ->deleteJson('/api/patient/v1/me/sessions/'.$sessionUuid)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found')
                ->assertJsonPath('error.message', 'The requested resource was not found.');
        }

        $this->assertSame(
            'active',
            PatientSession::query()->where('session_uuid', $ownerSession['session_uuid'])->value('status'),
        );
        $this->assertTrue(PersonalAccessToken::query()->whereKey(
            (int) Str::before($ownerSession['access'], '|'),
        )->exists());
    }

    public function test_patient_can_revoke_the_current_session_and_token_family(): void
    {
        $this->enablePatientSessions();
        $principal = $this->activePrincipal('session.current@example.test');
        $current = $this->issue($principal, 'ios', 'Current iPhone');

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/patient/v1/me/sessions/'.$current['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->getJson('/api/patient/v1/me/sessions')
            ->assertUnauthorized();
    }

    public function test_session_list_is_bounded_and_always_includes_the_current_device(): void
    {
        $this->enablePatientSessions();
        $principal = $this->activePrincipal('session.bound@example.test');
        $current = $this->issue($principal, 'ios', 'Current iPhone');
        $currentSession = PatientSession::query()
            ->where('session_uuid', $current['session_uuid'])
            ->firstOrFail();
        $currentSession->forceFill([
            'last_seen_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();

        foreach (range(1, 105) as $index) {
            PatientSession::query()->create([
                'session_uuid' => (string) Str::uuid7(),
                'principal_id' => $principal->getKey(),
                'token_family_uuid' => (string) Str::uuid7(),
                'status' => 'active',
                'device_uuid' => (string) Str::uuid7(),
                'platform' => $index % 2 === 0 ? 'ios' : 'android',
                'device_name' => 'Bounded device '.$index,
                'app_version' => '0.1.0',
                'os_version' => 'test',
                'auth_method' => 'password',
                'last_authenticated_at' => now(),
                'last_seen_at' => now()->subSeconds($index),
                'expires_at' => now()->addDay(),
            ]);
        }

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($current['access'])
            ->getJson('/api/patient/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(100, 'data.sessions');

        $sessions = collect($response->json('data.sessions'));
        $this->assertSame(1, $sessions->where('current', true)->count());
        $this->assertSame(
            $current['session_uuid'],
            $sessions->firstWhere('current', true)['session_uuid'],
        );
    }

    private function enablePatientSessions(): void
    {
        config([
            'hummingbird-patient.enabled' => true,
            'hummingbird-patient.features.token_exchange' => true,
            'hummingbird-patient.features.session_management' => true,
        ]);
    }

    private function activePrincipal(string $email): PatientPrincipal
    {
        return PatientPrincipal::query()->create([
            'principal_uuid' => (string) Str::uuid7(),
            'display_name' => 'Session Test Patient',
            'email' => $email,
            'password' => Hash::make('PatientReady1!Secure'),
            'status' => 'active',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** @return array{access: string, refresh: string, session_uuid: string} */
    private function issue(PatientPrincipal $principal, string $platform, string $name): array
    {
        $response = $this->postJson('/api/patient/v1/auth/token', [
            'email' => $principal->email,
            'password' => 'PatientReady1!Secure',
            'device' => [
                'uuid' => (string) Str::uuid7(),
                'platform' => $platform,
                'name' => $name,
                'app_version' => '0.1.0',
                'os_version' => 'test',
            ],
        ])->assertOk();

        return [
            'access' => (string) $response->json('data.access_token'),
            'refresh' => (string) $response->json('data.refresh_token'),
            'session_uuid' => (string) $response->json('data.session_uuid'),
        ];
    }
}
