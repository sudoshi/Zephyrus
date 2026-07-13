<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class UserAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_pages_are_admin_only_and_use_zephyrus_components(): void
    {
        $frontline = $this->user('user');
        $admin = $this->user('admin');
        $userCount = User::query()->count();
        $activeUserCount = User::query()->where('is_active', true)->count();

        $this->actingAs($frontline)->get('/admin')->assertForbidden();
        $this->actingAs($frontline)->get('/admin/user-audit')->assertForbidden();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('metrics.totalUsers', $userCount)
                ->where('metrics.activeUsers', $activeUserCount)
                ->where('readiness.health.overallStatus', 'degraded')
                ->has('readiness.readinessMetrics', 7)
                ->has('readiness.scopeIndicators', 4)
                ->has('readiness.actionQueue')
                ->has('sections', 14)
                ->where('sections.10.href', '/integrations?tab=sources'));

        $this->actingAs($admin)->get('/admin/user-audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/UserAudit')
                ->has('events.data')
                ->has('stats')
                ->has('options.actions'));
    }

    public function test_administration_dashboard_redacts_domain_rollups_without_their_capabilities(): void
    {
        $facilityAdmin = $this->user('facility_admin');
        $this->user('user', ['must_change_password' => true]);

        app(UserAuditRecorder::class)->record(
            'auth.login',
            'authentication',
            'failure',
            ['reason' => 'invalid_credentials'],
        );

        $this->actingAs($facilityAdmin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('metrics.totalUsers', null)
                ->where('metrics.activeUsers', null)
                ->where('metrics.mustChangePassword', null)
                ->where('metrics.failedLoginsToday', null)
                ->where('readiness.health.visible', false)
                ->where('readiness.health.overallStatus', 'restricted')
                ->where('readiness.health.counts', [])
                ->where('readiness.readinessMetrics.0.value', 'Restricted')
                ->where('readiness.readinessMetrics.1.value', 'Restricted')
                ->where('readiness.readinessMetrics.2.value', 'Restricted')
                ->has('readiness.actionQueue', 0)
                ->where('recentEvents', [])
                ->where('canViewAuditActivity', false)
                ->where('sections.2.state', 'restricted')
                ->where('sections.2.detail', null));
    }

    public function test_password_login_failure_success_and_logout_are_recorded_without_credentials(): void
    {
        $user = $this->user('user', [
            'username' => 'audit-user',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post('/login', [
            'username' => 'unknown-audit-user',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('general');

        $failed = UserEvent::query()
            ->where('action', 'auth.login')
            ->where('outcome', 'failure')
            ->sole();

        $this->assertNull($failed->actor_user_id);
        $this->assertNotNull($failed->principal_ref);
        $this->assertSame('invalid_credentials', $failed->reason);
        $this->assertStringNotContainsString('wrong-password', json_encode($failed->toArray(), JSON_THROW_ON_ERROR));

        $successfulLoginsBefore = UserEvent::query()
            ->where('action', 'auth.login')
            ->where('outcome', 'success')
            ->count();

        $this->post('/login', [
            'username' => 'audit-user',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $login = UserEvent::query()
            ->where('action', 'auth.login')
            ->where('outcome', 'success')
            ->latest('event_cursor')
            ->firstOrFail();

        $this->assertSame($successfulLoginsBefore + 1, UserEvent::query()
            ->where('action', 'auth.login')
            ->where('outcome', 'success')
            ->count());

        $this->assertSame($user->id, $login->actor_user_id);
        $this->assertSame('audit-user', $login->actor_username);
        $this->assertSame('password', $login->auth_method);

        $this->post('/logout')->assertRedirect('/');

        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->id,
            'action' => 'auth.logout',
            'outcome' => 'success',
            'auth_method' => 'session',
        ]);
    }

    public function test_inactive_user_cannot_use_password_login_and_attempt_is_audited(): void
    {
        $this->user('user', [
            'username' => 'inactive-user',
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        $this->post('/login', [
            'username' => 'inactive-user',
            'password' => 'correct-password',
        ])->assertSessionHasErrors('general');

        $this->assertGuest();
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'auth.login',
            'outcome' => 'failure',
            'reason' => 'invalid_credentials',
        ]);
    }

    public function test_hummingbird_token_login_success_and_failure_are_recorded(): void
    {
        $user = $this->user('bed_manager', [
            'username' => 'mobile-audited',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/token', [
            'username' => 'unknown-mobile-user',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->postJson('/api/auth/token', [
            'username' => 'mobile-audited',
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'abilities']);

        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'mobile.auth.token_exchange',
            'outcome' => 'failure',
            'source_surface' => 'hummingbird',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $user->id,
            'action' => 'mobile.auth.token_exchange',
            'outcome' => 'success',
            'source_surface' => 'hummingbird',
        ]);
    }

    public function test_page_access_and_user_administration_are_recorded_once(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/profile')->assertOk();
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'web.page.viewed',
            'route_name' => 'profile.edit',
            'outcome' => 'success',
        ]);

        $this->actingAs($admin)->post('/users', [
            'name' => 'New Operations User',
            'email' => 'new-operations@example.test',
            'username' => 'new-operations',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
            'role' => 'user',
            'is_active' => true,
            'change_reason' => 'new_account',
        ])->assertRedirect(route('users.index'));

        $created = User::query()->where('username', 'new-operations')->sole();
        $event = UserEvent::query()
            ->where('action', 'administration.user.created')
            ->where('target_id', (string) $created->id)
            ->sole();

        $this->assertEqualsCanonicalizing(
            ['is_active', 'must_change_password', 'role'],
            array_keys($event->changes),
        );
        $this->assertSame(1, UserEvent::query()
            ->where('request_uuid', $event->request_uuid)
            ->count());
        $this->assertStringNotContainsString('StrongPassword', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_audit_page_filters_actions_and_preserves_actor_identity(): void
    {
        $admin = $this->user('admin', ['username' => 'audit-admin']);
        app(UserAuditRecorder::class)->record(
            'administration.user.updated',
            'administration',
            'success',
            [
                'actor' => $admin,
                'target_type' => 'user',
                'target_id' => '77',
                'changes' => ['role' => ['from' => 'user', 'to' => 'admin']],
            ],
        );

        $this->actingAs($admin)->get('/admin/user-audit?action=administration.user.updated&search=administration.user')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/UserAudit')
                ->where('filters.action', 'administration.user.updated')
                ->where('events.total', 1)
                ->where('events.data.0.actor.username', 'audit-admin')
                ->where('events.data.0.action', 'administration.user.updated'));

        $admin->delete();
        $event = UserEvent::query()->where('action', 'administration.user.updated')->sole();
        $this->assertSame('audit-admin', $event->actor_username);
    }

    public function test_recorder_allowlists_changes_and_metadata(): void
    {
        $event = app(UserAuditRecorder::class)->record(
            'security.audit.tested',
            'security',
            'success',
            [
                'changes' => [
                    'role' => ['from' => 'user', 'to' => 'admin'],
                    'password' => ['from' => 'secret-a', 'to' => 'secret-b'],
                    'mrn' => ['from' => '1', 'to' => '2'],
                ],
                'metadata' => [
                    'changed_fields' => ['role'],
                    'token' => 'secret-token',
                    'payload' => ['patient' => 'secret-patient'],
                ],
            ],
        );

        $this->assertSame(['role' => ['from' => 'user', 'to' => 'admin']], $event->changes);
        $this->assertSame(['changed_fields' => ['role']], $event->metadata);
        $this->assertStringNotContainsString('secret-', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_audit_rows_are_database_enforced_append_only(): void
    {
        $event = app(UserAuditRecorder::class)->record('security.audit.created', 'security', 'success');

        $this->expectException(QueryException::class);
        DB::table('audit.user_events')
            ->where('event_cursor', $event->event_cursor)
            ->update(['outcome' => 'failure']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }
}
