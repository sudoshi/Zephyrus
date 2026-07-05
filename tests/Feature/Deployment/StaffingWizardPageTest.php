<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase F4: the /deployment/staffing web route renders the Staffing Wizard Inertia page
 * and is gated by manageDeploymentConfig — strictly NARROWER than the console read gate,
 * so a plain 'admin' (who can view the console) still cannot reach the write wizard.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§9, §11)
 */
class StaffingWizardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/deployment/staffing')->assertRedirect('/login');
    }

    public function test_frontline_and_plain_admin_are_forbidden(): void
    {
        foreach (['user', 'admin', 'executive'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);
            $this->actingAs($user)->get('/deployment/staffing')->assertForbidden();
        }
    }

    public function test_config_roles_render_the_wizard(): void
    {
        foreach (['superuser', 'ops-leader', 'super-admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'must_change_password' => false]);

            $this->actingAs($user)
                ->get('/deployment/staffing')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Deployment/StaffingWizard'));
        }
    }
}
