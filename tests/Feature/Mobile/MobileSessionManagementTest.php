<?php

namespace Tests\Feature\Mobile;

use App\Models\Auth\MobileTokenSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MobileSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_schema_enforces_device_pairing_and_exact_access_pointer_index(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'prod')
            ->where('table_name', 'mobile_token_sessions')
            ->whereIn('column_name', [
                'access_token_id',
                'installation_uuid',
                'platform',
                'device_name',
                'app_version',
                'os_version',
                'environment',
                'last_seen_at',
            ])
            ->pluck('column_name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'access_token_id',
            'app_version',
            'device_name',
            'environment',
            'installation_uuid',
            'last_seen_at',
            'os_version',
            'platform',
        ], $columns);

        $constraints = DB::table('pg_constraint')
            ->whereRaw("conrelid = 'prod.mobile_token_sessions'::regclass")
            ->whereIn('conname', [
                'mobile_token_sessions_device_identity_check',
                'mobile_token_sessions_platform_check',
            ])
            ->pluck('conname')
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'mobile_token_sessions_device_identity_check',
            'mobile_token_sessions_platform_check',
        ], $constraints);

        $accessIndex = DB::table('pg_indexes')
            ->where('schemaname', 'prod')
            ->where('indexname', 'uq_mobile_token_sessions_current_access')
            ->value('indexdef');
        $this->assertIsString($accessIndex);
        $this->assertStringContainsString('UNIQUE INDEX', $accessIndex);
        $this->assertStringContainsString(
            'WHERE (access_token_id IS NOT NULL)',
            $accessIndex,
        );
    }

    public function test_migration_backfills_only_one_unambiguous_live_access_generation(): void
    {
        $user = $this->activeUser();
        $issued = $this->issue($user, 'ios', 'Migration iPhone');
        $session = $user->mobileTokenSessions()->firstOrFail();
        $legitimateAccessId = (int) Str::before($issued['access'], '|');

        $session->forceFill(['access_token_id' => null])->save();
        $migrationPath = database_path(
            'migrations/2026_07_23_000200_add_device_metadata_to_mobile_token_sessions.php',
        );
        (require $migrationPath)->up();
        $this->assertSame($legitimateAccessId, $session->fresh()->access_token_id);

        MobileTokenSession::query()
            ->whereKey($session->getKey())
            ->update(['access_token_id' => null]);
        $user->createToken(
            'mobile-access:'.$session->token_family_uuid,
            ['mobile:read'],
            now()->addMinutes(30),
        );
        (require $migrationPath)->up();
        $this->assertNull($session->fresh()->access_token_id);
    }

    public function test_staff_lists_only_owned_active_sessions_with_safe_device_metadata(): void
    {
        $user = $this->activeUser();
        $other = $this->activeUser();
        $first = $this->issue($user, 'ios', 'Rounds iPhone');
        $second = $this->issue($user, 'android', 'Unit Android');
        $this->issue($other, 'ios', 'Other iPhone');

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($first['access'])
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('meta.stale', false);

        $sessions = collect($response->json('data.sessions'));
        $this->assertSame(1, $sessions->where('current', true)->count());
        $this->assertSame(
            $first['session_uuid'],
            $sessions->firstWhere('current', true)['session_uuid'],
        );
        $this->assertEqualsCanonicalizing(
            [$first['session_uuid'], $second['session_uuid']],
            $sessions->pluck('session_uuid')->all(),
        );
        $this->assertSame('Rounds iPhone', $sessions->firstWhere('current', true)['device']['name']);
        $this->assertSame('ios', $sessions->firstWhere('current', true)['device']['platform']);
        $this->assertSame('0.1.0-test', $sessions->firstWhere('current', true)['device']['app_version']);
        $this->assertSame('test-os', $sessions->firstWhere('current', true)['device']['os_version']);
        $this->assertSame('testing', $sessions->firstWhere('current', true)['environment']);

        $serialized = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        foreach ([
            'token_family_uuid',
            'access_token_id',
            'refresh_token_id',
            'installation_uuid',
            'access_token',
            'refresh_token',
            'token_hash',
            'ip_address',
            'user_agent',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'mobile.auth.sessions_viewed',
            'target_type' => 'user',
            'target_id' => (string) $user->getKey(),
            'outcome' => 'success',
        ]);
    }

    public function test_staff_revokes_another_owned_session_idempotently_without_touching_other_families(): void
    {
        $user = $this->activeUser();
        $current = $this->issue($user, 'ios', 'Current iPhone');
        $target = $this->issue($user, 'android', 'Old Android');

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.$target['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.session_uuid', $target['session_uuid'])
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.already_revoked', false)
            ->assertJsonPath('data.current', false);

        $targetSession = MobileTokenSession::query()
            ->where('session_uuid', $target['session_uuid'])
            ->firstOrFail();
        $this->assertSame('revoked', $targetSession->status);
        $this->assertSame('user_session_revoked', $targetSession->revocation_reason);
        $this->assertSame(0, $user->tokens()->whereIn('name', [
            'mobile-access:'.$targetSession->token_family_uuid,
            'mobile-refresh:'.$targetSession->token_family_uuid,
        ])->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.$target['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.already_revoked', true)
            ->assertJsonPath('data.current', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.session_uuid', $current['session_uuid']);

        $this->app['auth']->forgetGuards();
        $this->withToken($current['refresh'])
            ->postJson('/api/auth/token/refresh')
            ->assertOk();

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'mobile.auth.session_revoked',
            'target_type' => 'mobile_token_session',
            'target_id' => $target['session_uuid'],
            'outcome' => 'success',
        ]);
    }

    public function test_cross_user_and_unknown_session_revocation_are_indistinguishable(): void
    {
        $owner = $this->activeUser();
        $attacker = $this->activeUser();
        $ownerSession = $this->issue($owner, 'ios', 'Owner iPhone');
        $attackerSession = $this->issue($attacker, 'android', 'Attacker Android');

        foreach ([$ownerSession['session_uuid'], (string) Str::uuid7()] as $sessionUuid) {
            $this->app['auth']->forgetGuards();
            $this->withToken($attackerSession['access'])
                ->deleteJson('/api/mobile/v1/me/sessions/'.$sessionUuid)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'not_found')
                ->assertJsonPath('error.message', 'The requested resource was not found.');
        }

        $this->assertSame(
            'active',
            MobileTokenSession::query()
                ->where('session_uuid', $ownerSession['session_uuid'])
                ->value('status'),
        );
        $this->assertTrue(PersonalAccessToken::query()
            ->whereKey((int) Str::before($ownerSession['access'], '|'))
            ->exists());
    }

    public function test_transient_cookie_or_unrelated_personal_tokens_cannot_manage_mobile_families(): void
    {
        $user = $this->activeUser();
        $issued = $this->issue($user, 'ios', 'Durable iPhone');

        Sanctum::actingAs($user, ['mobile:read']);

        $this->getJson('/api/mobile/v1/me/sessions')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');
        $this->deleteJson('/api/mobile/v1/me/sessions/'.$issued['session_uuid'])
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');

        $this->app['auth']->forgetGuards();
        $unrelated = $user->createToken('automation-access', ['mobile:read']);
        $this->withToken($unrelated->plainTextToken)
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');

        $this->app['auth']->forgetGuards();
        $this->withToken($unrelated->plainTextToken)
            ->deleteJson('/api/mobile/v1/me/sessions/'.$issued['session_uuid'])
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');

        $session = MobileTokenSession::query()
            ->where('session_uuid', $issued['session_uuid'])
            ->firstOrFail();
        $forgedObservationBaseline = now()->subHour()->startOfSecond();
        $session->forceFill(['last_seen_at' => $forgedObservationBaseline])->save();
        $forgedName = $user->createToken(
            'mobile-access:'.$session->token_family_uuid,
            ['mobile:read'],
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($forgedName->plainTextToken)
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');

        $this->app['auth']->forgetGuards();
        $this->withToken($forgedName->plainTextToken)
            ->deleteJson('/api/mobile/v1/me/sessions/'.$issued['session_uuid'])
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'mobile_session_required');

        $this->assertSame(
            'active',
            MobileTokenSession::query()
                ->where('session_uuid', $issued['session_uuid'])
                ->value('status'),
        );
        $this->assertTrue(
            $session->fresh()->last_seen_at->equalTo($forgedObservationBaseline),
        );
    }

    public function test_staff_can_revoke_the_current_session_and_entire_family(): void
    {
        $user = $this->activeUser();
        $current = $this->issue($user, 'ios', 'Current iPhone');

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.$current['session_uuid'])
            ->assertOk()
            ->assertJsonPath('data.current', true)
            ->assertJsonPath('data.revoked', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($current['refresh'])
            ->postJson('/api/auth/token/refresh')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_and_unknown_session_responses_are_no_store(): void
    {
        $this->getJson('/api/mobile/v1/me/sessions')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $user = $this->activeUser();
        $issued = $this->issue($user, 'ios', 'Current iPhone');
        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.Str::uuid7())
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/not-a-uuid')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0')
            ->assertJsonPath('error.code', 'not_found');

        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.Str::upper($issued['session_uuid']))
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_session_revocation_and_audit_are_one_commit_boundary(): void
    {
        $user = $this->activeUser();
        $current = $this->issue($user, 'ios', 'Current iPhone');
        $target = $this->issue($user, 'android', 'Target Android');
        $targetSession = MobileTokenSession::query()
            ->where('session_uuid', $target['session_uuid'])
            ->firstOrFail();

        $this->mock(\App\Services\Audit\UserAuditRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->withArgs(static fn (
                    string $action,
                    string $category,
                    string $outcome,
                    array $context,
                ): bool => $action === 'mobile.auth.session_revoked'
                    && $category === 'authentication'
                    && $outcome === 'success'
                    && ($context['target_type'] ?? null) === 'mobile_token_session')
                ->andThrow(new RuntimeException('simulated session-audit outage'));
            $mock->shouldReceive('requestWasAudited')->once()->andReturnFalse();
            $mock->shouldReceive('bestEffort')->once()->andReturnNull();
        });

        $this->app['auth']->forgetGuards();
        $this->withToken($current['access'])
            ->deleteJson('/api/mobile/v1/me/sessions/'.$target['session_uuid'])
            ->assertInternalServerError()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->assertSame('active', $targetSession->fresh()->status);
        $this->assertSame(2, $user->tokens()->whereIn('name', [
            'mobile-access:'.$targetSession->token_family_uuid,
            'mobile-refresh:'.$targetSession->token_family_uuid,
        ])->count());
    }

    public function test_session_inventory_fails_closed_without_leaking_when_audit_is_unavailable(): void
    {
        $user = $this->activeUser();
        $issued = $this->issue($user, 'ios', 'Sensitive Device Label');

        $this->mock(\App\Services\Audit\UserAuditRecorder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->withArgs(static fn (
                    string $action,
                    string $category,
                    string $outcome,
                    array $context,
                ): bool => $action === 'mobile.auth.sessions_viewed'
                    && $category === 'access'
                    && $outcome === 'success'
                    && ($context['target_type'] ?? null) === 'user')
                ->andThrow(new RuntimeException('simulated session-inventory audit outage'));
            $mock->shouldReceive('requestWasAudited')->once()->andReturnFalse();
        });

        $this->app['auth']->forgetGuards();
        $body = $this->withToken($issued['access'])
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertInternalServerError()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->getContent();

        $this->assertStringNotContainsString('Sensitive Device Label', $body);
        $this->assertStringNotContainsString($issued['session_uuid'], $body);
        $this->assertStringNotContainsString($issued['installation_uuid'], $body);
    }

    public function test_session_list_is_bounded_and_always_includes_the_current_family(): void
    {
        $user = $this->activeUser();
        $current = $this->issue($user, 'ios', 'Current iPhone');
        $currentSession = MobileTokenSession::query()
            ->where('session_uuid', $current['session_uuid'])
            ->firstOrFail();
        $currentSession->forceFill([
            'last_seen_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();

        foreach (range(1, 105) as $index) {
            MobileTokenSession::query()->create([
                'session_uuid' => (string) Str::uuid7(),
                'user_id' => $user->getKey(),
                'token_family_uuid' => (string) Str::uuid7(),
                'status' => 'active',
                'installation_uuid' => (string) Str::uuid7(),
                'platform' => $index % 2 === 0 ? 'ios' : 'android',
                'device_name' => 'Bounded device '.$index,
                'environment' => 'testing',
                'last_seen_at' => now()->subSeconds($index),
                'expires_at' => now()->addDay(),
            ]);
        }

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($current['access'])
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(100, 'data.sessions');

        $sessions = collect($response->json('data.sessions'));
        $this->assertSame(1, $sessions->where('current', true)->count());
        $this->assertSame(
            $current['session_uuid'],
            $sessions->firstWhere('current', true)['session_uuid'],
        );
    }

    public function test_token_exchange_validates_and_persists_a_server_owned_device_binding(): void
    {
        $user = $this->activeUser();
        $installationUuid = (string) Str::uuid7();

        $response = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
            'device' => [
                'installation_uuid' => $installationUuid,
                'platform' => 'android',
                'name' => 'Pixel Test',
                'app_version' => '1.2.3',
                'os_version' => 'Android 16',
            ],
        ])->assertOk();

        $session = $user->mobileTokenSessions()->firstOrFail();
        $this->assertSame(
            (int) Str::before((string) $response->json('access_token'), '|'),
            $session->access_token_id,
        );
        $this->assertSame($installationUuid, (string) $session->installation_uuid);
        $this->assertSame('android', $session->platform);
        $this->assertSame('Pixel Test', $session->device_name);
        $this->assertSame('1.2.3', $session->app_version);
        $this->assertSame('Android 16', $session->os_version);
        $this->assertSame('testing', $session->environment);
        $this->assertNotNull($session->last_seen_at);
        $this->assertArrayNotHasKey('session_uuid', $response->json());
        $this->assertArrayNotHasKey('installation_uuid', $response->json());

        $other = $this->activeUser();
        $this->postJson('/api/auth/token', [
            'username' => $other->username,
            'password' => 'password',
            'device' => [
                'installation_uuid' => (string) Str::uuid7(),
                'platform' => 'ios',
                'environment' => 'attacker-selected',
            ],
        ])->assertUnprocessable();
        $this->assertSame(0, $other->mobileTokenSessions()->count());

        $this->postJson('/api/auth/token', [
            'username' => $other->username,
            'password' => 'password',
            'device' => [
                'installation_uuid' => Str::upper((string) Str::uuid7()),
                'platform' => 'ios',
            ],
        ])->assertUnprocessable();
        $this->assertSame(0, $other->mobileTokenSessions()->count());
    }

    public function test_refresh_preserves_the_session_and_device_binding(): void
    {
        $user = $this->activeUser();
        $issued = $this->issue($user, 'android', 'Rounding tablet');
        $before = $user->mobileTokenSessions()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $rotated = $this->withToken($issued['refresh'])
            ->postJson('/api/auth/token/refresh')
            ->assertOk();

        $before->refresh();
        $this->assertSame($issued['session_uuid'], (string) $before->session_uuid);
        $this->assertSame($issued['installation_uuid'], (string) $before->installation_uuid);
        $this->assertSame('android', $before->platform);
        $this->assertSame('Rounding tablet', $before->device_name);
        $this->assertSame(
            (int) Str::before((string) $rotated->json('access_token'), '|'),
            $before->access_token_id,
        );
        $this->assertNotSame(
            (int) Str::before($issued['access'], '|'),
            $before->access_token_id,
        );

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $rotated->json('access_token'))
            ->getJson('/api/mobile/v1/me/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions.0.current', true)
            ->assertJsonPath('data.sessions.0.session_uuid', $issued['session_uuid']);
    }

    public function test_last_seen_observation_is_rate_limited_and_never_authorizes_the_request(): void
    {
        $user = $this->activeUser();
        $issued = $this->issue($user, 'ios', 'Observed iPhone');
        $session = $user->mobileTokenSessions()->firstOrFail();
        $old = now()->subMinutes(10)->startOfSecond();
        $session->forceFill(['last_seen_at' => $old])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])->getJson('/api/mobile/v1/me')->assertOk();
        $session->refresh();
        $observed = $session->last_seen_at;
        $this->assertTrue($observed->greaterThan($old));

        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])->getJson('/api/mobile/v1/me')->assertOk();
        $session->refresh();
        $this->assertTrue($session->last_seen_at->equalTo($observed));

        $user->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($issued['access'])->getJson('/api/mobile/v1/me')->assertForbidden();
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{
     *     access: string,
     *     refresh: string,
     *     session_uuid: string,
     *     installation_uuid: string
     * }
     */
    private function issue(User $user, string $platform, string $name): array
    {
        $installationUuid = (string) Str::uuid7();
        $response = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
            'device' => [
                'installation_uuid' => $installationUuid,
                'platform' => $platform,
                'name' => $name,
                'app_version' => '0.1.0-test',
                'os_version' => 'test-os',
            ],
        ])->assertOk();

        return [
            'access' => (string) $response->json('access_token'),
            'refresh' => (string) $response->json('refresh_token'),
            'session_uuid' => (string) $user->mobileTokenSessions()
                ->latest('mobile_token_session_id')
                ->value('session_uuid'),
            'installation_uuid' => $installationUuid,
        ];
    }
}
