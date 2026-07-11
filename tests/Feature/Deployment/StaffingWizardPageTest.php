<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staffing Alignment is presented under Staffing administration while the
 * deployment/staffing URL remains an authorized legacy redirect.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§9, §11)
 */
class StaffingWizardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_guest_is_forbidden_from_config_writer(): void
    {
        $this->get('/staffing/administration')->assertForbidden();
    }

    public function test_frontline_and_plain_admin_are_forbidden(): void
    {
        foreach (['user', 'admin', 'executive'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
            $this->actingAs($user)->get('/staffing/administration')->assertForbidden();
        }
    }

    public function test_config_roles_render_the_wizard(): void
    {
        foreach (['superuser', 'ops-leader', 'super-admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);

            $this->actingAs($user)
                ->get('/staffing/administration')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Deployment/StaffingWizard'));
        }
    }

    public function test_legacy_staffing_route_redirects_after_authorization(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);

        $this->actingAs($user)
            ->get('/deployment/staffing')
            ->assertRedirect('/staffing/administration');

        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $this->actingAs($admin)->get('/deployment/staffing')->assertForbidden();
    }
}
