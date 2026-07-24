<?php

namespace Tests\Feature\Mobile;

use App\Models\Auth\MobileTokenSession;
use App\Models\User;
use App\Services\Auth\AccountSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0 — additive mobile token auth + BFF scaffold.
 *
 * Verifies the Hummingbird auth path WITHOUT touching the web session flow:
 * token issuance, must_change_password challenge, token-gated BFF access,
 * refresh rotation, revoke, and device registration.
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'must_change_password' => false,
            'is_active' => true,
        ], $overrides));
    }

    public function test_valid_credentials_issue_an_access_and_refresh_token(): void
    {
        $user = $this->activeUser();

        $response = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token_type', 'access_token', 'refresh_token', 'expires_in', 'abilities'])
            ->assertJsonPath('token_type', 'Bearer');

        $accessBearer = (string) $response->json('access_token');
        $refreshBearer = (string) $response->json('refresh_token');
        [$accessIdPart, $accessSecret] = explode('|', $accessBearer, 2);
        [$refreshIdPart, $refreshSecret] = explode('|', $refreshBearer, 2);
        $accessId = (int) $accessIdPart;
        $refreshId = (int) $refreshIdPart;
        $session = $user->mobileTokenSessions()->firstOrFail();
        $accessRow = PersonalAccessToken::findOrFail($accessId);
        $refreshRow = PersonalAccessToken::findOrFail($refreshId);

        $this->assertTrue(Str::isUuid((string) $session->session_uuid));
        $this->assertTrue(Str::isUuid((string) $session->token_family_uuid));
        $this->assertSame($accessId, $session->access_token_id);
        $this->assertSame($refreshId, $session->refresh_token_id);
        $this->assertSame('mobile-access:'.$session->token_family_uuid, $accessRow->name);
        $this->assertSame('mobile-refresh:'.$session->token_family_uuid, $refreshRow->name);
        $this->assertSame(hash('sha256', $accessSecret), $accessRow->token);
        $this->assertSame(hash('sha256', $refreshSecret), $refreshRow->token);
        $this->assertStringNotContainsString($accessSecret, $accessRow->token);
        $this->assertStringNotContainsString($refreshSecret, $refreshRow->token);
    }

    public function test_invalid_credentials_are_rejected_generically(): void
    {
        $this->postJson('/api/auth/token', [
            'username' => 'does-not-exist',
            'password' => 'wrong',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_must_change_password_returns_a_scoped_challenge_not_a_session(): void
    {
        $user = $this->activeUser(['must_change_password' => true]);

        $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('password_change_required', true)
            ->assertJsonStructure(['change_token'])
            ->assertJsonMissing(['access_token']);
    }

    public function test_inactive_account_cannot_obtain_a_token(): void
    {
        $user = $this->activeUser(['is_active' => false]);

        $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertStatus(403)->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_access_token_authenticates_the_bff_me_endpoint(): void
    {
        $user = $this->activeUser(['workflow_preference' => 'rtdc']);
        $token = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');

        $this->withToken($token)->getJson('/api/mobile/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.workflow_preference', 'rtdc')
            ->assertJsonPath('meta.stale', false);
    }

    public function test_bff_requires_a_token(): void
    {
        $this->getJson('/api/mobile/v1/me')->assertStatus(401);
    }

    public function test_refresh_rotates_into_a_new_token_pair(): void
    {
        $user = $this->activeUser();
        $issued = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();
        $access = (string) $issued->json('access_token');
        $refresh = (string) $issued->json('refresh_token');
        $accessId = (int) Str::before($access, '|');
        $refreshId = (int) Str::before($refresh, '|');
        $session = $user->mobileTokenSessions()->firstOrFail();
        $familyUuid = (string) $session->token_family_uuid;

        $rotated = $this->withToken($refresh)->postJson('/api/auth/token/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
        $rotatedAccess = (string) $rotated->json('access_token');
        $rotatedRefresh = (string) $rotated->json('refresh_token');
        $rotatedAccessId = (int) Str::before($rotatedAccess, '|');
        $rotatedRefreshId = (int) Str::before($rotatedRefresh, '|');

        $this->assertNotSame($access, $rotatedAccess);
        $this->assertNotSame($refresh, $rotatedRefresh);
        $this->assertFalse(PersonalAccessToken::query()->whereKey($accessId)->exists());
        $predecessor = PersonalAccessToken::query()->findOrFail($refreshId);
        $this->assertSame([], $predecessor->abilities);
        $this->assertFalse($predecessor->can('token:refresh'));
        $session->refresh();
        $this->assertSame($familyUuid, (string) $session->token_family_uuid);
        $this->assertSame($rotatedAccessId, $session->access_token_id);
        $this->assertSame($rotatedRefreshId, $session->refresh_token_id);
        $this->assertSame('active', $session->status);

        // Neither the current refresh credential nor its ability-less predecessor
        // can cross the mobile:read gate into the staff BFF.
        $this->app['auth']->forgetGuards();
        $this->withToken($rotatedRefresh)
            ->getJson('/api/mobile/v1/me')
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->getJson('/api/mobile/v1/me')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($rotatedAccess)
            ->getJson('/api/mobile/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username);

        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password',
                'new_password' => 'MustNeverRunForATombstone!123',
                'new_password_confirmation' => 'MustNeverRunForATombstone!123',
            ])
            ->assertForbidden();
        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));

        // A rotated predecessor is retained only as a one-way hash. Reusing it is
        // suspected theft and revokes the entire stable family.
        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->postJson('/api/auth/token/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_refresh_token');

        $session->refresh();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('refresh_token_reuse_detected', $session->revocation_reason);
        $this->assertNull($session->access_token_id);
        $this->assertNull($session->refresh_token_id);
        $this->assertSame(0, $user->tokens()
            ->whereIn('name', [
                'mobile-access:'.$session->token_family_uuid,
                'mobile-refresh:'.$session->token_family_uuid,
            ])
            ->count());
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->getKey(),
            'action' => 'mobile.auth.token_refresh',
            'outcome' => 'denied',
            'reason' => 'refresh_token_reuse_detected',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($rotatedAccess)
            ->getJson('/api/mobile/v1/me')
            ->assertUnauthorized();
    }

    public function test_an_access_token_cannot_be_used_to_refresh(): void
    {
        $user = $this->activeUser();
        $issued = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ]);
        $access = (string) $issued->json('access_token');
        $refresh = (string) $issued->json('refresh_token');

        $this->withToken($access)->postJson('/api/auth/token/refresh')->assertStatus(401);

        $session = $user->mobileTokenSessions()->firstOrFail();
        $this->assertSame('active', $session->status);

        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->postJson('/api/auth/token/refresh')
            ->assertOk();
    }

    public function test_revoke_invalidates_the_entire_presented_token_family(): void
    {
        $user = $this->activeUser();
        $issued = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();
        $access = (string) $issued->json('access_token');
        $refresh = (string) $issued->json('refresh_token');
        $session = $user->mobileTokenSessions()->firstOrFail();

        $this->withToken($access)->postJson('/api/auth/token/revoke')->assertOk();

        // Forget the guard's memoized user so the next request re-resolves the bearer
        // token against the DB. Each real HTTP request is a fresh bootstrap; within a
        // single test the sanctum RequestGuard would otherwise cache the user resolved
        // during the revoke call and mask the revocation.
        $this->app['auth']->forgetGuards();

        $this->withToken($access)->getJson('/api/mobile/v1/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)->postJson('/api/auth/token/refresh')->assertStatus(401);

        $session->refresh();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('user_logout', $session->revocation_reason);
        $this->assertNull($session->access_token_id);
        $this->assertNull($session->refresh_token_id);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_refresh_reuse_revokes_only_the_compromised_family(): void
    {
        $user = $this->activeUser();
        $first = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();
        $second = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();

        $firstRefresh = (string) $first->json('refresh_token');
        $rotatedFirst = $this->withToken($firstRefresh)
            ->postJson('/api/auth/token/refresh')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($firstRefresh)
            ->postJson('/api/auth/token/refresh')
            ->assertUnauthorized();

        $sessions = $user->mobileTokenSessions()->orderBy('mobile_token_session_id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame('revoked', $sessions[0]->status);
        $this->assertNull($sessions[0]->access_token_id);
        $this->assertNull($sessions[0]->refresh_token_id);
        $this->assertSame('active', $sessions[1]->status);
        $this->assertNotNull($sessions[1]->access_token_id);
        $this->assertNotNull($sessions[1]->refresh_token_id);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $rotatedFirst->json('access_token'))
            ->getJson('/api/mobile/v1/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $second->json('access_token'))
            ->getJson('/api/mobile/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username);
    }

    public function test_refresh_family_has_an_absolute_expiry_that_rotation_cannot_extend(): void
    {
        CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
        try {
            config(['hummingbird.token.refresh_ttl_days' => 30]);
            $user = $this->activeUser();
            $issued = $this->postJson('/api/auth/token', [
                'username' => $user->username,
                'password' => 'password',
            ])->assertOk();
            $session = $user->mobileTokenSessions()->firstOrFail();
            $originalExpiry = $session->expires_at;
            $originalRefreshExpiry = PersonalAccessToken::query()
                ->findOrFail((int) Str::before((string) $issued->json('refresh_token'), '|'))
                ->expires_at;

            CarbonImmutable::setTestNow('2026-08-01T12:00:00Z');
            $rotated = $this->withToken((string) $issued->json('refresh_token'))
                ->postJson('/api/auth/token/refresh')
                ->assertOk();
            $rotatedRefreshExpiry = PersonalAccessToken::query()
                ->findOrFail((int) Str::before((string) $rotated->json('refresh_token'), '|'))
                ->expires_at;

            $session->refresh();
            $this->assertTrue($session->expires_at->equalTo($originalExpiry));
            $this->assertTrue($originalRefreshExpiry->equalTo($originalExpiry));
            $this->assertTrue($rotatedRefreshExpiry->equalTo($originalExpiry));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_inactive_account_refresh_revokes_the_entire_family(): void
    {
        $user = $this->activeUser();
        $issued = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();
        $user->forceFill(['is_active' => false])->save();

        $this->withToken((string) $issued->json('refresh_token'))
            ->postJson('/api/auth/token/refresh')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_inactive');

        $session = $user->mobileTokenSessions()->firstOrFail();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('account_inactive', $session->revocation_reason);
        $this->assertNull($session->access_token_id);
        $this->assertNull($session->refresh_token_id);
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $issued->json('access_token'))
            ->getJson('/api/mobile/v1/me')
            ->assertUnauthorized();
    }

    public function test_expired_refresh_tombstones_have_a_bounded_prune_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired --hours=24')
            ->assertSuccessful();
    }

    public function test_legacy_refresh_is_upgraded_transactionally_and_its_reuse_revokes_the_new_family(): void
    {
        $user = $this->activeUser();
        $legacyAccess = $user->createToken(
            'mobile-access',
            $user->mobileTokenAbilities(),
            now()->addMinutes(30),
        );
        $legacyRefresh = $user->createToken(
            'mobile-refresh',
            ['token:refresh'],
            now()->addDays(30),
        );
        $legacyRefreshToken = $legacyRefresh->plainTextToken;

        $rotated = $this->withToken($legacyRefreshToken)
            ->postJson('/api/auth/token/refresh')
            ->assertOk();

        $session = $user->mobileTokenSessions()->sole();
        $legacyRefresh->accessToken->refresh();
        $this->assertSame('mobile-refresh:'.$session->token_family_uuid, $legacyRefresh->accessToken->name);
        $this->assertFalse(PersonalAccessToken::query()->whereKey($legacyAccess->accessToken->getKey())->exists());
        $this->assertSame('active', $session->status);
        $this->assertSame(
            (int) Str::before((string) $rotated->json('access_token'), '|'),
            $session->access_token_id,
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($legacyRefreshToken)
            ->postJson('/api/auth/token/refresh')
            ->assertUnauthorized();

        $session->refresh();
        $this->assertSame('revoked', $session->status);
        $this->assertSame('refresh_token_reuse_detected', $session->revocation_reason);
        $this->assertNull($session->access_token_id);
        $this->assertNull($session->refresh_token_id);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $rotated->json('access_token'))
            ->getJson('/api/mobile/v1/me')
            ->assertUnauthorized();
    }

    public function test_password_change_revokes_all_prior_tokens_before_issuing_one_new_pair(): void
    {
        $user = $this->activeUser();
        $oldPair = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk();
        $oldAccess = (string) $oldPair->json('access_token');
        $oldSession = $user->mobileTokenSessions()->firstOrFail();
        $user->forceFill(['must_change_password' => true])->save();
        $changeToken = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('change_token');

        $response = $this->withToken($changeToken)->postJson('/api/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'NewMobilePassword!123',
            'new_password_confirmation' => 'NewMobilePassword!123',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertSame(1, $user->auth_session_version);
        $this->assertSame(2, $user->tokens()->count());
        $this->assertNotSame($oldAccess, $response->json('access_token'));
        $oldSession->refresh();
        $this->assertSame('revoked', $oldSession->status);
        $this->assertSame('password_changed', $oldSession->revocation_reason);
        $this->assertNull($oldSession->access_token_id);
        $this->assertNull($oldSession->refresh_token_id);
        $newSession = MobileTokenSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->sole();
        $this->assertSame(
            (int) Str::before((string) $response->json('access_token'), '|'),
            $newSession->access_token_id,
        );
        $this->assertSame(1, MobileTokenSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($oldAccess)->getJson('/api/mobile/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'security.user.access_revoked',
            'reason' => 'password_changed',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_password_change_rolls_back_when_session_revocation_fails(): void
    {
        $user = $this->activeUser(['must_change_password' => true]);
        $changeToken = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('change_token');

        $this->mock(AccountSessionService::class, function ($mock): void {
            $mock->shouldReceive('revoke')
                ->once()
                ->andThrow(new RuntimeException('simulated session revocation failure'));
        });

        $this->withToken($changeToken)->postJson('/api/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'NewMobilePassword!123',
            'new_password_confirmation' => 'NewMobilePassword!123',
        ])->assertInternalServerError();

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('NewMobilePassword!123', $user->password));
    }

    public function test_device_registration_is_stored_for_the_user(): void
    {
        $user = $this->activeUser();
        $token = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');

        $this->withToken($token)->postJson('/api/mobile/v1/devices', [
            'platform' => 'ios',
            'push_token' => 'apns-token-abc123',
            'app_version' => '1.0.0',
        ])
            ->assertCreated()
            ->assertJsonPath('data.platform', 'ios');

        $this->assertDatabaseHas('prod.mobile_devices', [
            'push_token' => 'apns-token-abc123',
            'user_id' => $user->getKey(),
            'platform' => 'ios',
        ]);
    }
}
