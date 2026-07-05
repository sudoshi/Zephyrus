<?php

namespace Tests\Feature\Cockpit;

use App\Models\Unit;
use App\Models\User;
use App\Support\Cockpit\CockpitScope;
use App\Support\Cockpit\CockpitScopeAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-6a — the scope-leakage gate. house / department faces are
 * aggregate and public to any authenticated user; unit / service_line faces serve
 * LIVE bed census and are gated to the caller's own assignment. PHPUnit class syntax
 * (Pest excluded on this environment).
 */
class CockpitScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnit(string $abbr, string $name, string $type = 'icu'): Unit
    {
        return Unit::create([
            'name' => $name,
            'abbreviation' => $abbr,
            'type' => $type,
            'staffed_bed_count' => 24,
        ]);
    }

    /** A unit-scoped clinician (charge nurse persona) assigned to one unit. */
    private function chargeNurseOn(string $abbr): User
    {
        $user = User::factory()->create();
        $user->role = 'charge_nurse';
        $user->save();

        $user->units()->attach($this->makeUnit($abbr, "{$abbr} unit")->unit_id, ['role' => 'charge_nurse', 'is_primary' => true]);

        return $user;
    }

    public function test_unit_clinician_may_mount_their_own_unit(): void
    {
        $this->actingAs($this->chargeNurseOn('MICU'))
            ->getJson('/api/cockpit/face?scope=unit:MICU')
            ->assertOk()
            ->assertJsonPath('scope.token', 'unit:MICU');
    }

    public function test_unit_clinician_is_denied_a_sibling_unit_mount(): void
    {
        // MICU nurse reaching for SICU's live census — the exact leak WS-6 closes.
        $this->actingAs($this->chargeNurseOn('MICU'))
            ->getJson('/api/cockpit/face?scope=unit:SICU')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'cockpit_scope_forbidden')
            ->assertJsonPath('error.unauthorized_state', true);
    }

    public function test_unit_clinician_keeps_house_and_department_aggregate_faces(): void
    {
        $nurse = $this->chargeNurseOn('MICU');

        // house = the aggregate overview every authenticated user has always seen.
        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=house')->assertOk();
        // department = the aggregate ED board, likewise public.
        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=department:ed')->assertOk();
    }

    public function test_service_line_mount_requires_an_assigned_unit_in_the_line(): void
    {
        $nurse = $this->chargeNurseOn('MICU'); // MICU ∈ critical_care

        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=service_line:critical_care')->assertOk();
        // SICU ∈ trauma_surgery — the nurse has no unit there.
        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=service_line:trauma_surgery')->assertStatus(403);
    }

    public function test_broad_access_admin_may_mount_any_unit(): void
    {
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();

        $this->actingAs($admin)->getJson('/api/cockpit/face?scope=unit:SICU')->assertOk();
        $this->actingAs($admin)->getJson('/api/cockpit/face?scope=unit:MICU')->assertOk();
    }

    public function test_scopes_catalog_only_offers_mountable_units_to_a_unit_clinician(): void
    {
        $nurse = $this->chargeNurseOn('MICU');

        $catalog = $this->actingAs($nurse)->getJson('/api/cockpit/scopes')->assertOk()->json('catalog');

        $unitTokens = array_column($catalog['units'], 'token');
        $this->assertContains('unit:MICU', $unitTokens);
        $this->assertNotContains('unit:SICU', $unitTokens, 'the picker must never offer an unassigned unit');

        // house + departments stay in the catalog (aggregate, always mountable).
        $this->assertNotEmpty($catalog['departments']);
    }

    public function test_scopes_active_degrades_when_the_requested_scope_is_forbidden(): void
    {
        $nurse = $this->chargeNurseOn('MICU');

        // Explicitly asking for a forbidden unit must not land the picker on it.
        $this->actingAs($nurse)
            ->getJson('/api/cockpit/scopes?scope=unit:SICU')
            ->assertOk()
            ->assertJsonPath('active.token', 'unit:MICU'); // degraded to their primary.
    }

    public function test_authorizer_unit_gate_is_case_insensitive(): void
    {
        $authorizer = app(CockpitScopeAuthorizer::class);
        $nurse = $this->chargeNurseOn('MICU');

        $this->assertTrue($authorizer->canMount($nurse, CockpitScope::unit('micu', 'Medical ICU')));
        $this->assertFalse($authorizer->canMount($nurse, CockpitScope::unit('SICU', 'Surgical ICU')));
    }
}
