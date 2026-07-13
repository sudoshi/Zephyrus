<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enterprise taxonomy/readiness now lives under Administration. The legacy
 * deployment URL remains an authorized redirect for existing bookmarks.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase F1)
 */
class DeploymentConsolePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_by_default(): void
    {
        $this->get('/admin/enterprise-setup')->assertRedirect('/login');
    }

    public function test_explicit_nonproduction_demo_account_can_read_the_console(): void
    {
        $admin = User::factory()->create([
            'username' => 'deployment-demo',
            'role' => 'admin',
            'must_change_password' => false,
            'is_active' => true,
        ]);
        config()->set('demo.auto_login_enabled', true);
        config()->set('demo.auto_login_username', $admin->username);

        $this->get('/admin/enterprise-setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Deployment/DeploymentConsole'));
    }

    public function test_frontline_role_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user', 'must_change_password' => false]);

        $this->actingAs($user)->get('/admin/enterprise-setup')->assertForbidden();
    }

    public function test_privileged_role_renders_the_console(): void
    {
        foreach (['superuser', 'ops-leader', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);

            $this->actingAs($user)
                ->get('/admin/enterprise-setup')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Deployment/DeploymentConsole'));
        }
    }

    public function test_legacy_deployment_route_redirects_after_authorization(): void
    {
        $user = User::factory()->create(['role' => 'ops-leader', 'must_change_password' => false]);

        $this->actingAs($user)
            ->get('/deployment')
            ->assertRedirect('/admin/enterprise-setup');

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $this->actingAs($frontline)->get('/deployment')->assertForbidden();
    }
}
