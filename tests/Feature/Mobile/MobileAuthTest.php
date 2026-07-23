<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use App\Services\Auth\AccountSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token_type', 'access_token', 'refresh_token', 'expires_in', 'abilities'])
            ->assertJsonPath('token_type', 'Bearer');
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
        $refresh = (string) $issued->json('refresh_token');

        $rotated = $this->withToken($refresh)->postJson('/api/auth/token/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->assertNotSame($issued->json('access_token'), $rotated->json('access_token'));
        $this->assertNotSame($refresh, $rotated->json('refresh_token'));

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $rotated->json('access_token'))
            ->getJson('/api/mobile/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username);

        // A rotated predecessor is one-time material. It cannot mint another pair.
        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->postJson('/api/auth/token/refresh')
            ->assertUnauthorized();
    }

    public function test_an_access_token_cannot_be_used_to_refresh(): void
    {
        $user = $this->activeUser();
        $access = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');

        $this->withToken($access)->postJson('/api/auth/token/refresh')->assertStatus(401);
    }

    public function test_revoke_invalidates_the_token(): void
    {
        $user = $this->activeUser();
        $token = $this->postJson('/api/auth/token', [
            'username' => $user->username,
            'password' => 'password',
        ])->json('access_token');

        $this->withToken($token)->postJson('/api/auth/token/revoke')->assertOk();

        // Forget the guard's memoized user so the next request re-resolves the bearer
        // token against the DB. Each real HTTP request is a fresh bootstrap; within a
        // single test the sanctum RequestGuard would otherwise cache the user resolved
        // during the revoke call and mask the revocation.
        $this->app['auth']->forgetGuards();

        // The token must no longer authenticate.
        $this->withToken($token)->getJson('/api/mobile/v1/me')->assertStatus(401);
    }

    public function test_password_change_revokes_all_prior_tokens_before_issuing_one_new_pair(): void
    {
        $user = $this->activeUser(['must_change_password' => true]);
        $oldAccess = $user->createToken('old-mobile', ['mobile:read'])->plainTextToken;
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
