<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase F1: the /deployment web route renders the Deployment Console Inertia page and
 * is gated by the same viewDeploymentConsole ability as the /api/deployment/* data it
 * reads — frontline users never render it.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase F1)
 */
class DeploymentConsolePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/deployment')->assertRedirect('/login');
    }

    public function test_frontline_role_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user', 'must_change_password' => false]);

        $this->actingAs($user)->get('/deployment')->assertForbidden();
    }

    public function test_privileged_role_renders_the_console(): void
    {
        foreach (['superuser', 'ops-leader', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);

            $this->actingAs($user)
                ->get('/deployment')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Deployment/DeploymentConsole'));
        }
    }
}
