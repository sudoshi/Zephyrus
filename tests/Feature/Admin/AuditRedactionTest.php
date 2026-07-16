<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_only_reviewers_receive_masked_emails_and_no_client_ips(): void
    {
        $auditor = $this->user('auditor');
        $actor = $this->user('user', ['email' => 'clinical-user@example.test']);
        $this->loginEvent($actor, '203.0.113.9', now()->subHour());

        $this->actingAs($auditor)->get('/admin/user-audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/UserAudit')
                ->where('redaction.piiVisible', false)
                ->where('events.data.0.clientIp', null)
                ->where('events.data.0.actor.email', 'c***@example.test'));
    }

    public function test_identity_administrators_see_unredacted_recent_records(): void
    {
        $admin = $this->user('admin');
        $actor = $this->user('user', ['email' => 'clinical-user@example.test']);
        $this->loginEvent($actor, '203.0.113.9', now()->subHour());

        $this->actingAs($admin)->get('/admin/user-audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('redaction.piiVisible', true)
                ->where('events.data.0.clientIp', '203.0.113.9')
                ->where('events.data.0.actor.email', 'clinical-user@example.test'));
    }

    public function test_client_ips_age_out_of_presentation_after_the_retention_window(): void
    {
        config(['audit.ip_retention_days' => 90]);
        $admin = $this->user('admin');
        $actor = $this->user('user');
        $this->loginEvent($actor, '203.0.113.9', now()->subDays(120));

        $this->actingAs($admin)->get('/admin/user-audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('redaction.piiVisible', true)
                ->where('events.data.0.clientIp', null)
                ->where('redaction.ipRetentionDays', 90));
    }

    public function test_dashboard_recent_events_apply_the_same_redaction(): void
    {
        $auditor = $this->user('auditor');
        $actor = $this->user('user', ['email' => 'clinical-user@example.test']);
        $this->loginEvent($actor, '203.0.113.9', now()->subHour());

        $this->actingAs($auditor)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recentEvents.0.clientIp', null)
                ->where('recentEvents.0.actor.email', 'c***@example.test'));
    }

    private function loginEvent(User $actor, string $ip, \Carbon\CarbonInterface $occurredAt): UserEvent
    {
        return UserEvent::query()->create([
            'event_uuid' => (string) Str::uuid7(),
            'occurred_at' => $occurredAt,
            'actor_user_id' => $actor->id,
            'actor_username' => $actor->username,
            'actor_role' => (string) $actor->role,
            'action' => 'auth.login',
            'category' => 'authentication',
            'outcome' => 'success',
            'auth_method' => 'password',
            'source_surface' => 'web',
            'request_uuid' => (string) Str::uuid7(),
            'client_ip' => $ip,
            'changes' => [],
            'metadata' => [],
            'schema_version' => 1,
        ]);
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
