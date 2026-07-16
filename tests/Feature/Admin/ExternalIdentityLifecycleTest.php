<?php

namespace Tests\Feature\Admin;

use App\Models\Auth\UserExternalIdentity;
use App\Models\Governance\IdentityLinkEvent;
use App\Models\User;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ExternalIdentityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlink_revokes_access_persists_hash_only_evidence_and_blocks_silent_reconciliation(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user', ['remember_token' => 'remember-me']);
        $identity = $this->identity($target, 'subject-sensitive-123');
        $target->createToken('mobile');
        DB::table('prod.sessions')->insert([
            'id' => 'external-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$target->id}/external-identities/{$identity->id}/unlink",
            ['reason' => 'Provider identity was reported compromised.'],
        )->assertRedirect();

        $identity->refresh();
        $target->refresh();
        $this->assertFalse($identity->is_active);
        $this->assertNotNull($identity->unlinked_at);
        $this->assertSame($admin->id, $identity->unlinked_by_user_id);
        $this->assertSame(1, $target->auth_session_version);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, DB::table('prod.sessions')->where('user_id', $target->id)->count());

        $event = IdentityLinkEvent::query()->sole();
        $this->assertSame('unlinked', $event->event_type);
        $this->assertSame(hash('sha256', 'authentik:subject-sensitive-123'), $event->provider_subject_sha256);
        $this->assertStringNotContainsString('subject-sensitive-123', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.external_identity.unlinked',
            'target_id' => (string) $identity->id,
        ]);

        try {
            $this->reconciler()->reconcile($this->claims('subject-sensitive-123', $target->email));
            $this->fail('An administratively unlinked subject must not silently relink by email.');
        } catch (OidcAccessDeniedException $exception) {
            $this->assertSame('identity_unlinked', $exception->reason);
        }
    }

    public function test_relink_restores_only_the_previously_validated_subject_and_records_a_second_transition(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $identity = $this->identity($target, 'stable-subject', false);

        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$target->id}/external-identities/{$identity->id}/relink",
            ['reason' => 'Identity ownership was independently reverified.'],
        )->assertRedirect();

        $identity->refresh();
        $this->assertTrue($identity->is_active);
        $this->assertSame('stable-subject', $identity->provider_subject);
        $this->assertNotNull($identity->relinked_at);
        $this->assertSame('linked_by_sub', $this->reconciler()
            ->reconcile($this->claims('stable-subject', $target->email))['reason']);
        $this->assertDatabaseHas('governance.identity_link_events', [
            'external_identity_id' => $identity->id,
            'event_type' => 'relinked',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_step_up_wrong_user_protected_account_and_self_mutation_all_fail_closed(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $identity = $this->identity($target, 'target-subject');

        $this->actingAs($admin)->post(
            "/users/{$target->id}/external-identities/{$identity->id}/unlink",
            ['reason' => 'This request lacks recent reauthentication.'],
        )->assertRedirect(route('password.confirm'));
        $this->assertTrue($identity->fresh()->is_active);

        $protected = $this->user('user', ['is_protected' => true]);
        $protectedIdentity = $this->identity($protected, 'protected-subject');
        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$protected->id}/external-identities/{$protectedIdentity->id}/unlink",
            ['reason' => 'Attempted ordinary protected-account unlink.'],
        )->assertSessionHasErrors('identity');

        $adminIdentity = $this->identity($admin, 'self-subject');
        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$admin->id}/external-identities/{$adminIdentity->id}/unlink",
            ['reason' => 'Attempted self-service identity unlink.'],
        )->assertSessionHasErrors('identity');

        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$protected->id}/external-identities/{$identity->id}/unlink",
            ['reason' => 'Identity belongs to a different user account.'],
        )->assertNotFound();
    }

    public function test_identity_event_ledger_is_database_append_only(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $identity = $this->identity($target, 'immutable-subject');
        $this->actingAs($admin)->withSession($this->stepUp())->post(
            "/users/{$target->id}/external-identities/{$identity->id}/unlink",
            ['reason' => 'Create immutable identity transition evidence.'],
        );

        $this->expectException(QueryException::class);
        IdentityLinkEvent::query()->firstOrFail()->delete();
    }

    public function test_edit_page_exposes_safe_identity_state_without_the_raw_subject(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $this->identity($target, 'never-send-this-subject');

        $response = $this->actingAs($admin)->get("/users/{$target->id}/edit")->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Edit')
            ->where('user.external_identities.0.provider', 'authentik')
            ->where('user.external_identities.0.is_active', true)
            ->missing('user.external_identities.0.provider_subject'));
        $this->assertStringNotContainsString('never-send-this-subject', $response->getContent());
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }

    private function identity(User $user, string $subject, bool $active = true): UserExternalIdentity
    {
        return UserExternalIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'authentik',
            'provider_subject' => $subject,
            'provider_email_at_link' => $user->email,
            'linked_at' => now(),
            'is_active' => $active,
            'unlinked_at' => $active ? null : now()->subMinute(),
            'unlink_reason' => $active ? null : 'Previous administrative unlink for investigation.',
        ]);
    }

    private function claims(string $subject, string $email): ValidatedClaims
    {
        return new ValidatedClaims($subject, $email, 'External User', ['Zephyrus Users']);
    }

    private function reconciler(): OidcReconciliationService
    {
        return new OidcReconciliationService(['Zephyrus Users'], ['Zephyrus Admins']);
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
