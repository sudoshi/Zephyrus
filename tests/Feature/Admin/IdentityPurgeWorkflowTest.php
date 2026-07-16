<?php

namespace Tests\Feature\Admin;

use App\Authorization\GovernedAction;
use App\Models\Auth\UserExternalIdentity;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IdentityPurgeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_independently_approved_purge_removes_identifiers_and_access_but_preserves_the_user_key(): void
    {
        $author = $this->user('admin');
        $approver = $this->user('admin');
        $target = $this->user('user', [
            'name' => 'Identifiable Person',
            'email' => 'identified@example.test',
            'username' => 'identified-person',
            'password' => Hash::make('KnownPassword!123'),
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
            'remember_token' => 'remember-me',
        ]);
        $identity = UserExternalIdentity::query()->create([
            'user_id' => $target->id,
            'provider' => 'authentik',
            'provider_subject' => 'sensitive-upstream-subject',
            'provider_email_at_link' => 'identified@example.test',
            'linked_at' => now(),
            'is_active' => true,
        ]);
        $target->createToken('mobile');
        DB::table('prod.sessions')->insert([
            'id' => 'purge-session', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => 'payload', 'last_activity' => time(),
        ]);

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/users/{$target->id}/identity-purge-requests",
            ['reason' => 'Approved retention request requires identifier erasure.'],
        )->assertRedirect();

        $change = GovernedChangeRequest::query()
            ->where('action_type', GovernedAction::PurgeUserIdentity->value)
            ->where('subject_id', (string) $target->id)
            ->sole();
        $this->assertSame($author->id, $change->author_user_id);

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/admin/identity-purge-requests/{$change->getKey()}/decision",
            ['decision' => 'approved', 'reason' => 'Attempted approval by the original author.'],
        )->assertSessionHasErrors('governance');

        $this->actingAs($approver)->withSession($this->stepUp())->post(
            "/admin/identity-purge-requests/{$change->getKey()}/decision",
            ['decision' => 'approved', 'reason' => 'Independent retention and ownership review completed.'],
        )->assertRedirect();

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/users/{$target->id}/identity-purge-requests/{$change->getKey()}/execute",
        )->assertRedirect(route('users.index'));

        $target->refresh();
        $identity->refresh();
        $this->assertDatabaseHas('prod.users', ['id' => $target->id]);
        $this->assertNotNull($target->identity_purged_at);
        $this->assertSame($change->getKey(), $target->identity_purge_request_uuid);
        $this->assertFalse($target->is_active);
        $this->assertSame('user', $target->role);
        $this->assertStringStartsWith('Purged account ', $target->name);
        $this->assertStringEndsWith('@invalid.test', $target->email);
        $this->assertStringStartsWith('purged_', $target->username);
        $this->assertFalse(Hash::check('KnownPassword!123', $target->password));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, DB::table('prod.sessions')->where('user_id', $target->id)->count());
        $this->assertFalse($identity->is_active);
        $this->assertStringStartsWith('purged:', $identity->provider_subject);
        $this->assertNull($identity->provider_email_at_link);
        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $change->getKey(),
            'outcome' => 'success',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.identity_purged',
            'target_id' => (string) $target->id,
        ]);
    }

    public function test_active_protected_self_and_duplicate_purge_requests_fail_closed(): void
    {
        $admin = $this->user('admin');
        $active = $this->user('user');
        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$active->id}/identity-purge-requests",
            ['reason' => 'Attempted purge before required deactivation.'],
        )->assertSessionHasErrors('user');

        $protected = $this->user('user', ['is_active' => false, 'is_protected' => true]);
        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$protected->id}/identity-purge-requests",
            ['reason' => 'Attempted ordinary protected account purge.'],
        )->assertSessionHasErrors('user');

        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$admin->id}/identity-purge-requests",
            ['reason' => 'Attempted self-authored irreversible purge.'],
        )->assertSessionHasErrors('user');

        $author = $this->user('admin');
        $target = $this->user('user', ['is_active' => false, 'deactivated_at' => now()]);
        foreach ([1, 2] as $attempt) {
            $response = $this->actingAs($author)->withSession($this->stepUp())->post(
                "/users/{$target->id}/identity-purge-requests",
                ['reason' => "Duplicate purge request attempt number {$attempt}."],
            );
            $attempt === 1 ? $response->assertRedirect() : $response->assertSessionHasErrors('governance');
        }
    }

    public function test_payload_drift_after_approval_prevents_execution(): void
    {
        $author = $this->user('admin');
        $approver = $this->user('admin');
        $target = $this->user('user', ['is_active' => false, 'deactivated_at' => now()]);

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/users/{$target->id}/identity-purge-requests",
            ['reason' => 'The approved snapshot must bind exact account state.'],
        );
        $change = GovernedChangeRequest::query()->where('subject_id', (string) $target->id)->latest('requested_at')->firstOrFail();
        $this->actingAs($approver)->withSession($this->stepUp())->post(
            "/admin/identity-purge-requests/{$change->getKey()}/decision",
            ['decision' => 'approved', 'reason' => 'Independent approval of the exact account snapshot.'],
        );

        $target->forceFill(['auth_session_version' => $target->auth_session_version + 1])->save();

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/users/{$target->id}/identity-purge-requests/{$change->getKey()}/execute",
        )->assertSessionHasErrors('governance');
        $this->assertNull($target->fresh()->identity_purged_at);
        $this->assertDatabaseMissing('governance.change_executions', [
            'change_request_uuid' => $change->getKey(),
            'outcome' => 'success',
        ]);
    }

    public function test_identity_author_and_privilege_approver_capabilities_are_separate(): void
    {
        $author = $this->user('identity_admin');
        $identityOnlyReviewer = $this->user('identity_admin');
        $privilegeReviewer = $this->user('privilege_admin');
        $target = $this->user('user', ['is_active' => false, 'deactivated_at' => now()]);

        $this->actingAs($author)->withSession($this->stepUp())->post(
            "/users/{$target->id}/identity-purge-requests",
            ['reason' => 'Identity administrator submits retention reviewed purge.'],
        )->assertRedirect();
        $change = GovernedChangeRequest::query()->where('subject_id', (string) $target->id)->sole();

        $this->actingAs($identityOnlyReviewer)->withSession($this->stepUp())->post(
            "/admin/identity-purge-requests/{$change->getKey()}/decision",
            ['decision' => 'approved', 'reason' => 'Identity-only reviewer attempts approval.'],
        )->assertForbidden();

        $this->actingAs($privilegeReviewer)->withSession($this->stepUp())->post(
            "/admin/identity-purge-requests/{$change->getKey()}/decision",
            ['decision' => 'approved', 'reason' => 'Privilege reviewer independently approves purge.'],
        )->assertRedirect();

        $this->assertDatabaseHas('governance.change_decisions', [
            'change_request_uuid' => $change->getKey(),
            'decided_by_user_id' => $privilegeReviewer->id,
            'decision' => 'approved',
        ]);
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }

    /** @return array<string, int|string> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }
}
