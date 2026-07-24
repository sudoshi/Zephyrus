<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Auth\AccountLifecyclePolicy;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\MobileTokenSessionService;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_revokes_every_credential_and_preserves_the_account(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user', [
            'username' => 'revoked-user',
            'remember_token' => 'remember-me',
        ]);
        app(MobileTokenSessionService::class)->issueNewFamily($target);
        $mobileSession = $target->mobileTokenSessions()->sole();
        DB::table('prod.sessions')->insert([
            [
                'id' => 'session-one',
                'user_id' => $target->id,
                'ip_address' => '10.0.0.1',
                'user_agent' => 'test',
                'payload' => 'payload',
                'last_activity' => time(),
            ],
            [
                'id' => 'session-two',
                'user_id' => $target->id,
                'ip_address' => '10.0.0.2',
                'user_agent' => 'test',
                'payload' => 'payload',
                'last_activity' => time(),
            ],
        ]);

        $this->actingAs($admin)->put('/users/'.$target->id, $this->payload($target, [
            'is_active' => false,
            'change_reason' => 'account_deactivation',
        ]))->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame(1, $target->auth_session_version);
        $this->assertSame('', $target->getRememberToken());
        $this->assertSame(0, $target->tokens()->count());
        $mobileSession->refresh();
        $this->assertSame('revoked', $mobileSession->status);
        $this->assertSame('account_deactivation', $mobileSession->revocation_reason);
        $this->assertNull($mobileSession->access_token_id);
        $this->assertNull($mobileSession->refresh_token_id);
        $this->assertSame(0, DB::table('prod.sessions')->where('user_id', $target->id)->count());
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'security.user.access_revoked',
            'reason' => 'account_deactivation',
            'target_id' => (string) $target->id,
        ]);
        $this->assertDatabaseHas('prod.users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_stale_and_inactive_browser_sessions_are_rejected_on_the_next_request(): void
    {
        $user = $this->user('user');
        $this->actingAs($user);
        $user->forceFill(['auth_session_version' => 1])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'security.session.rejected',
            'outcome' => 'denied',
            'reason' => 'session_generation_stale',
        ]);

        $inactive = $this->user('user');
        $this->actingAs($inactive);
        $inactive->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit.user_events', [
            'target_id' => (string) $inactive->id,
            'action' => 'security.session.rejected',
            'reason' => 'account_inactive',
        ]);
    }

    public function test_current_password_change_rotates_generation_and_keeps_only_current_browser(): void
    {
        $user = $this->user('user', ['password' => Hash::make('old-password')]);
        $user->createToken('mobile');

        $this->actingAs($user)->put('/password', [
            'current_password' => 'old-password',
            'password' => 'NewStrongPassword!123',
            'password_confirmation' => 'NewStrongPassword!123',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame(1, $user->auth_session_version);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, session(AccountSessionService::SESSION_VERSION_KEY));
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'security.user.access_revoked',
            'reason' => 'password_changed',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_self_deactivation_and_self_demotion_are_denied_and_audited(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->from('/users/'.$admin->id.'/edit')->put('/users/'.$admin->id, $this->payload($admin, [
            'is_active' => false,
            'change_reason' => 'account_deactivation',
        ]))->assertSessionHasErrors('is_active');

        $admin->refresh();
        $this->assertTrue($admin->is_active);

        $this->actingAs($admin)->from('/users/'.$admin->id.'/edit')->put('/users/'.$admin->id, $this->payload($admin, [
            'role' => 'user',
            'change_reason' => 'role_change_approved',
        ]))->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame(2, UserEvent::query()
            ->where('action', 'administration.user.update_denied')
            ->where('actor_user_id', $admin->id)
            ->count());
    }

    public function test_protected_accounts_and_hard_deletion_fail_closed(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user', ['is_protected' => true]);

        $this->actingAs($admin)->put('/users/'.$target->id, $this->payload($target, [
            'email' => 'changed@example.test',
            'change_reason' => 'identity_correction',
        ]))->assertSessionHasErrors('user');
        $this->assertNotSame('changed@example.test', $target->fresh()->email);

        $this->actingAs($admin)->delete('/users/'.$target->id)
            ->assertSessionHasErrors('user');
        $this->assertDatabaseHas('prod.users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.delete_denied',
            'outcome' => 'denied',
            'reason' => 'hard_delete_disabled',
        ]);
    }

    public function test_policy_prevents_removal_of_the_last_active_administrator(): void
    {
        User::query()->update(['role' => 'user', 'is_active' => true]);
        DB::table('prod.model_has_roles')->delete();
        $actor = $this->user('user');
        $lastAdmin = $this->user('admin');

        $this->expectException(AccountLifecycleViolation::class);
        try {
            app(AccountLifecyclePolicy::class)->assertUpdateAllowed(
                $actor,
                $lastAdmin,
                'user',
                true,
                false,
                false,
            );
        } catch (AccountLifecycleViolation $exception) {
            $this->assertSame('last_administrator', $exception->reason);
            throw $exception;
        }
    }

    public function test_purged_accounts_cannot_be_edited_or_reactivated_through_routine_administration(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user', [
            'is_active' => false,
            'identity_purged_at' => now(),
        ]);

        $this->actingAs($admin)->put('/users/'.$target->id, $this->payload($target, [
            'name' => 'Attempted resurrection',
            'is_active' => true,
            'change_reason' => 'account_reactivation',
        ]))->assertSessionHasErrors('user');

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertNotSame('Attempted resurrection', $target->name);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.update_denied',
            'reason' => 'identity_purged',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_assigning_privilege_requires_recent_step_up_authentication(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $payload = $this->payload($target, [
            'role' => 'admin',
            'change_reason' => 'role_change_approved',
        ]);

        $this->actingAs($admin)->put('/users/'.$target->id, $payload)
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('user', $target->fresh()->role);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'security.step_up.required',
            'reason' => 'privilege_assignment_changed',
        ]);

        $this->actingAs($admin)->withSession([
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ])->put('/users/'.$target->id, $payload)
            ->assertRedirect(route('users.index'));
        $this->assertSame('admin', $target->fresh()->role);
    }

    /** @param array<string, mixed> $overrides */
    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'change_reason' => 'routine_profile_update',
        ], $overrides);
    }
}
