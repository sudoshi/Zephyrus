<?php

namespace Tests\Feature\Cockpit;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-6a — the cockpit mount model (CMIO ruling 2026-07-05).
 *
 * A scoped face is per-unit bed OCCUPANCY (counts, no patient identity) — the same
 * house-wide situational-awareness data the /snapshot heat strip and the /drill
 * descent already serve to every authenticated user (plan §A0). So `?scope=` is a
 * relevance default, NOT a confidentiality wall: any authenticated user may mount
 * any altitude. The real PHI boundary is A2P — /cockpit/patient stays gated by
 * EnforceFlowLens (covered by PatientLensApiTest). A prior WS-6a build added a
 * per-unit mount 403; adversarial review showed it was inconsistent (the same
 * occupancy is on the house RTDC board), and the CMIO ruled occupancy house-wide.
 * PHPUnit class syntax (Pest excluded on this environment).
 */
class CockpitScopeMountTest extends TestCase
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

    public function test_a_unit_clinician_may_mount_a_sibling_unit_face(): void
    {
        // Occupancy is house-wide (no PHI) — a MICU nurse mounting SICU is served,
        // not 403'd (the same SICU occupancy is on the house RTDC board anyway).
        $this->actingAs($this->chargeNurseOn('MICU'))
            ->getJson('/api/cockpit/face?scope=unit:SICU')
            ->assertOk()
            ->assertJsonPath('scope.token', 'unit:SICU');
    }

    public function test_house_department_and_service_line_mounts_are_open(): void
    {
        $nurse = $this->chargeNurseOn('MICU');

        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=house')->assertOk();
        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=department:ed')->assertOk();
        $this->actingAs($nurse)->getJson('/api/cockpit/face?scope=service_line:trauma_surgery')->assertOk();
    }

    public function test_scopes_catalog_offers_every_unit_but_flags_the_callers_own(): void
    {
        $nurse = $this->chargeNurseOn('MICU');

        $catalog = $this->actingAs($nurse)->getJson('/api/cockpit/scopes')->assertOk()->json('catalog');
        $tokens = array_column($catalog['units'], 'token');

        // Unfiltered — the picker offers every unit (occupancy is house-wide)…
        $this->assertContains('unit:MICU', $tokens);
        $this->assertContains('unit:SICU', $tokens);

        // …but still foregrounds the caller's assigned unit via the `assigned` flag.
        $micu = collect($catalog['units'])->firstWhere('token', 'unit:MICU');
        $this->assertTrue($micu['assigned']);
        $sicu = collect($catalog['units'])->firstWhere('token', 'unit:SICU');
        $this->assertFalse($sicu['assigned']);
    }

    public function test_the_face_endpoint_still_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/face?scope=unit:MICU')->assertStatus(401);
    }
}
