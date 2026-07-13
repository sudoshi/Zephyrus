<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\Oidc\ExternalIdentityEventRecorder;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SsoOnlyProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_account_creation_is_blocked_when_sso_only_policy_applies(): void
    {
        config(['auth-drivers.local.enabled' => false]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->from('/users/create')->post('/users', [
            'name' => 'Blocked Local',
            'email' => 'blocked-local@example.test',
            'username' => 'blocked-local',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
            'role' => 'user',
            'is_active' => true,
            'change_reason' => 'new_account',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('prod.users', ['username' => 'blocked-local']);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.create_denied',
            'outcome' => 'denied',
            'reason' => 'sso_only_policy',
        ]);
    }

    public function test_local_account_creation_remains_available_when_local_auth_is_enabled(): void
    {
        config(['auth-drivers.local.enabled' => true]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Allowed Local',
            'email' => 'allowed-local@example.test',
            'username' => 'allowed-local',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
            'role' => 'user',
            'is_active' => true,
            'change_reason' => 'new_account',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('prod.users', [
            'username' => 'allowed-local',
            'provisioning_state' => 'local',
        ]);
    }

    public function test_admin_password_rotation_is_blocked_for_ordinary_accounts_under_sso_only(): void
    {
        config(['auth-drivers.local.enabled' => false]);
        $admin = $this->user('admin');
        $target = $this->user('user');

        $this->actingAs($admin)->from('/users/'.$target->id.'/edit')->put('/users/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'username' => $target->username,
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
            'role' => $target->role,
            'is_active' => true,
            'change_reason' => 'credential_reset',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.update_denied',
            'outcome' => 'denied',
            'reason' => 'sso_only_policy',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_break_glass_accounts_keep_local_credentials_under_sso_only(): void
    {
        config(['auth-drivers.local.enabled' => false]);
        $breakGlass = $this->user('admin', [
            'is_protected' => true,
            'password' => Hash::make('OldBreakGlass123'),
        ]);

        $this->actingAs($breakGlass)
            ->withSession([
                StepUpAuthenticationService::VERIFIED_AT => time(),
                StepUpAuthenticationService::METHOD => 'password',
            ])
            ->put('/users/'.$breakGlass->id, [
                'name' => $breakGlass->name,
                'email' => $breakGlass->email,
                'username' => $breakGlass->username,
                'password' => 'NewBreakGlass1234',
                'password_confirmation' => 'NewBreakGlass1234',
                'role' => $breakGlass->role,
                'is_active' => true,
                'change_reason' => 'credential_reset',
            ])->assertRedirect(route('users.index'));

        $this->assertTrue(Hash::check('NewBreakGlass1234', $breakGlass->fresh()->password));
    }

    public function test_jit_provisioning_records_the_jit_state_and_email_link_marks_local_accounts_synced(): void
    {
        $service = new OidcReconciliationService(
            allowedGroups: ['Zephyrus Users'],
            adminGroups: ['Zephyrus Admins'],
            events: app(ExternalIdentityEventRecorder::class),
        );

        $jit = $service->reconcile(new ValidatedClaims(
            sub: 'jit-subject-1',
            email: 'jit-user@example.test',
            name: 'Jit User',
            groups: ['Zephyrus Users'],
        ));
        $this->assertSame('created_jit', $jit['reason']);
        $this->assertSame('jit', $jit['user']->fresh()->provisioning_state);

        $local = $this->user('user', ['email' => 'linked-local@example.test']);
        $this->assertSame('local', (string) $local->fresh()->provisioning_state);

        $linked = $service->reconcile(new ValidatedClaims(
            sub: 'linked-subject-1',
            email: 'linked-local@example.test',
            name: 'Linked Local',
            groups: ['Zephyrus Users'],
        ));
        $this->assertSame('linked_by_email', $linked['reason']);
        $this->assertSame('synced', $local->fresh()->provisioning_state);
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
}
