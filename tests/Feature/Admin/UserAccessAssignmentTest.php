<?php

namespace Tests\Feature\Admin;

use App\Models\Auth\UserAccessScope;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class UserAccessAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_and_capability_editors_enforce_their_distinct_capabilities(): void
    {
        $auditor = $this->user('auditor');
        $identityAdmin = $this->user('identity_admin');
        $target = $this->user('user');

        // auditor has neither manageIdentity nor managePrivileges.
        $this->actingAs($auditor)->post('/users/'.$target->id.'/access-scopes', [])->assertForbidden();
        $this->actingAs($auditor)->post('/users/'.$target->id.'/capabilities', [])->assertForbidden();

        // identity_admin may manage scopes but not privileges.
        $this->actingAs($identityAdmin)->post('/users/'.$target->id.'/capabilities', [
            'capability' => 'viewAudit',
        ])->assertForbidden();
        $this->actingAs($identityAdmin)->post('/users/'.$target->id.'/capabilities/revoke', [
            'capability' => 'viewAudit',
        ])->assertForbidden();
    }

    public function test_scope_grant_requires_step_up_then_creates_an_effective_dated_audited_scope(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $facility = $this->facility();

        $payload = [
            'facility_id' => $facility->facility_id,
            'grant_reason' => 'Approved facility administration boundary.',
        ];

        $this->actingAs($admin)->post('/users/'.$target->id.'/access-scopes', $payload)
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(0, UserAccessScope::query()->where('user_id', $target->id)->count());

        $this->actingAs($admin)->withSession($this->stepUp())
            ->from('/users/'.$target->id.'/edit')
            ->post('/users/'.$target->id.'/access-scopes', $payload)
            ->assertRedirect('/users/'.$target->id.'/edit');

        $scope = UserAccessScope::query()->where('user_id', $target->id)->sole();
        $this->assertSame($facility->facility_id, (int) $scope->facility_id);
        $this->assertNull($scope->revoked_at);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.access_scope_granted',
            'actor_user_id' => $admin->id,
            'target_id' => (string) $target->id,
        ]);

        // A duplicate effective boundary fails closed and is audited as denied.
        $this->actingAs($admin)->withSession($this->stepUp())
            ->from('/users/'.$target->id.'/edit')
            ->post('/users/'.$target->id.'/access-scopes', $payload)
            ->assertSessionHasErrors('scope');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.access_scope_grant_denied',
            'outcome' => 'denied',
            'reason' => 'scope_already_granted',
        ]);
    }

    public function test_scope_revocation_is_effective_dated_and_audited(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $facility = $this->facility();
        $scope = UserAccessScope::query()->create([
            'user_id' => $target->id,
            'facility_id' => $facility->facility_id,
            'granted_by_user_id' => $admin->id,
            'grant_reason' => 'Approved facility administration boundary.',
            'valid_from' => now()->subDay(),
        ]);

        $this->actingAs($admin)->withSession($this->stepUp())
            ->post('/users/'.$target->id.'/access-scopes/'.$scope->id.'/revoke', [
                'revocation_reason' => 'Access boundary no longer required.',
            ])->assertRedirect();

        $scope->refresh();
        $this->assertNotNull($scope->revoked_at);
        $this->assertSame($admin->id, (int) $scope->revoked_by_user_id);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.access_scope_revoked',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_capability_grant_and_revoke_are_step_up_gated_audited_and_revoke_retires_credentials(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $target->createToken('mobile');

        $this->actingAs($admin)->post('/users/'.$target->id.'/capabilities', [
            'capability' => 'viewAudit',
        ])->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)->withSession($this->stepUp())
            ->post('/users/'.$target->id.'/capabilities', ['capability' => 'viewAudit'])
            ->assertRedirect();
        $this->assertTrue($target->fresh()->permissions()->where('name', 'capability:viewAudit')->exists());
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.capability_granted',
            'target_id' => (string) $target->id,
        ]);

        $this->actingAs($admin)->withSession($this->stepUp())
            ->post('/users/'.$target->id.'/capabilities/revoke', ['capability' => 'viewAudit'])
            ->assertRedirect();
        $fresh = $target->fresh();
        $this->assertFalse($fresh->permissions()->where('name', 'capability:viewAudit')->exists());
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(1, (int) $fresh->auth_session_version);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.capability_revoked',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_protected_and_self_targets_fail_closed_with_denied_audits(): void
    {
        $admin = $this->user('admin');
        $protected = $this->user('user', ['is_protected' => true]);
        $facility = $this->facility();

        $this->actingAs($admin)->withSession($this->stepUp())
            ->from('/users/'.$protected->id.'/edit')
            ->post('/users/'.$protected->id.'/access-scopes', [
                'facility_id' => $facility->facility_id,
                'grant_reason' => 'Attempted protected assignment.',
            ])->assertSessionHasErrors('user');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.access_scope_grant_denied',
            'reason' => 'protected_account',
        ]);

        $this->actingAs($admin)->withSession($this->stepUp())
            ->from('/users/'.$admin->id.'/edit')
            ->post('/users/'.$admin->id.'/capabilities', ['capability' => 'executeDestructiveReplay'])
            ->assertSessionHasErrors('user');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.capability_grant_denied',
            'reason' => 'self_privilege_mutation',
        ]);
        $this->assertFalse($admin->fresh()->permissions()->where('name', 'capability:executeDestructiveReplay')->exists());
    }

    public function test_revoking_a_role_profile_capability_directly_is_rejected(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('auditor');
        Permission::findOrCreate('capability:viewAudit', 'web');

        $this->actingAs($admin)->withSession($this->stepUp())
            ->from('/users/'.$target->id.'/edit')
            ->post('/users/'.$target->id.'/capabilities/revoke', ['capability' => 'viewAudit'])
            ->assertSessionHasErrors('capability');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.capability_revoke_denied',
            'reason' => 'capability_not_directly_granted',
        ]);
    }

    private function facility(): Facility
    {
        $organization = Organization::query()->create([
            'organization_key' => 'ASSIGNMENT_IDN',
            'name' => 'Assignment Test IDN',
            'kind' => 'idn',
        ]);

        return Facility::query()->create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ASSIGNMENT_HOSPITAL',
            'facility_name' => 'Assignment Test Hospital',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
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
