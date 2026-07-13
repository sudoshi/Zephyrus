<?php

namespace Tests\Feature\Admin;

use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class UserLifecycleDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_list_is_denied_without_the_view_identity_capability(): void
    {
        $frontline = $this->user('user');
        $auditor = $this->user('auditor');

        $this->actingAs($frontline)->get('/users')->assertForbidden();
        $this->actingAs($auditor)->get('/users')->assertForbidden();
    }

    public function test_user_list_shows_identity_source_provisioning_reconciliation_mfa_and_live_counts(): void
    {
        $admin = $this->user('admin', ['name' => 'Alpha Admin']);
        $target = $this->user('user', [
            'name' => 'Zed Target',
            'provisioning_state' => 'jit',
        ]);

        UserExternalIdentity::query()->create([
            'user_id' => $target->id,
            'provider' => 'authentik',
            'provider_subject' => 'subject-42',
            'provider_email_at_link' => $target->email,
            'linked_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $recorder = app(UserAuditRecorder::class);
        $recorder->record('auth.login', 'authentication', 'success', [
            'actor' => $target,
            'auth_method' => 'oidc',
            'source_surface' => 'web',
        ]);
        $recorder->record('security.step_up.completed', 'authentication', 'success', [
            'actor' => $target,
            'auth_method' => 'oidc',
            'source_surface' => 'web',
            'metadata' => ['step_up_method' => 'oidc_mfa'],
        ]);
        $recorder->record('activity.page.visited', 'activity', 'success', [
            'actor' => $target,
            'source_surface' => 'web',
        ]);

        $target->createToken('hummingbird');
        DB::table('prod.sessions')->insert([
            'id' => 'lifecycle-session',
            'user_id' => $target->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $response = $this->actingAs($admin)->get('/users')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Index')
            ->where('redaction.piiVisible', true));

        $users = collect($response->viewData('page')['props']['users']);
        $targetRow = $users->firstWhere('name', 'Zed Target');
        $adminRow = $users->firstWhere('name', 'Alpha Admin');

        $this->assertSame($target->email, $targetRow['email']);
        $this->assertSame('authentik', $targetRow['lifecycle']['identity_source']);
        $this->assertSame('jit', $targetRow['lifecycle']['provisioning_state']);
        $this->assertSame('reconciled', $targetRow['lifecycle']['group_reconciliation_state']);
        $this->assertSame('idp_mfa', $targetRow['lifecycle']['mfa_assurance']['method']);
        $this->assertSame(1, $targetRow['lifecycle']['active_session_count']);
        $this->assertSame(1, $targetRow['lifecycle']['active_token_count']);
        $this->assertNotNull($targetRow['lifecycle']['last_login_at']);
        $this->assertNotNull($targetRow['lifecycle']['last_meaningful_activity_at']);

        $this->assertSame('local', $adminRow['lifecycle']['identity_source']);
        $this->assertSame('local', $adminRow['lifecycle']['provisioning_state']);
        $this->assertSame('not_applicable', $adminRow['lifecycle']['group_reconciliation_state']);
        $this->assertNull($adminRow['lifecycle']['mfa_assurance']);
    }

    public function test_view_only_identity_reviewers_receive_masked_emails(): void
    {
        $this->user('admin', ['name' => 'Middle Admin']);
        $target = $this->user('user', [
            'name' => 'Zed Target',
            'email' => 'target-person@example.test',
        ]);

        $reviewer = $this->user('auditor', ['name' => 'Aardvark Auditor']);
        Permission::findOrCreate('capability:viewIdentity', 'web');
        $reviewer->givePermissionTo('capability:viewIdentity');

        $response = $this->actingAs($reviewer)->get('/users')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('redaction.piiVisible', false));
        $users = collect($response->viewData('page')['props']['users']);
        $this->assertSame('t***@example.test', $users->firstWhere('name', 'Zed Target')['email']);

        $this->actingAs($reviewer)->get('/users/'.$target->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('redaction.piiVisible', false)
                ->where('user.email', 't***@example.test'));
    }

    public function test_edit_page_exposes_lifecycle_authorization_and_scope_editors(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        Permission::findOrCreate('capability:viewAudit', 'web');
        $target->givePermissionTo('capability:viewAudit');

        $this->actingAs($admin)->get('/users/'.$target->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Edit')
                ->where('user.email', $target->email)
                ->has('lifecycle', fn (Assert $lifecycle) => $lifecycle
                    ->where('identity_source', 'local')
                    ->where('active_session_count', 0)
                    ->where('active_token_count', 0)
                    ->etc())
                ->where('authorization.direct_capabilities.0', 'viewAudit')
                ->has('authorization.effective_roles')
                ->has('authorization.effective_capabilities')
                ->has('authorization.capability_options')
                ->has('access_scopes')
                ->has('scope_options.organizations')
                ->has('scope_options.facilities')
                ->where('sso_only', false));
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
