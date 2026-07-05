<?php

namespace Tests\Feature\Cockpit;

use App\Models\Unit;
use App\Models\User;
use App\Support\Cockpit\CockpitScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-1 — scope resolution (explicit token → primary unit → house)
 * and the /api/cockpit/scopes catalog endpoint. PHPUnit class syntax (Pest excluded).
 */
class CockpitScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CockpitScopeResolver
    {
        return app(CockpitScopeResolver::class);
    }

    private function makeUnit(string $abbr, string $name, string $type = 'icu'): Unit
    {
        return Unit::create([
            'name' => $name,
            'abbreviation' => $abbr,
            'type' => $type,
            'staffed_bed_count' => 24,
        ]);
    }

    public function test_from_token_parses_and_canonicalizes_valid_scopes(): void
    {
        $r = $this->resolver();

        // Casing is canonicalized against the manifest SSOT.
        $this->assertSame('unit:MICU', $r->fromToken('unit:micu')?->token());
        $this->assertSame('service_line:critical_care', $r->fromToken('service_line:critical_care')?->token());
        $this->assertSame('department:ed', $r->fromToken('department:ED')?->token());
        $this->assertTrue($r->fromToken('house')?->isHouse());
    }

    public function test_from_token_rejects_unknown_or_malformed_tokens(): void
    {
        $r = $this->resolver();

        $this->assertNull($r->fromToken('unit:NOPE'));
        $this->assertNull($r->fromToken('service_line:imaginary'));
        $this->assertNull($r->fromToken('department:pharmacy'));
        $this->assertNull($r->fromToken('bogus'));   // no colon, not 'house'
        $this->assertNull($r->fromToken('unit:'));   // empty key
    }

    public function test_explicit_valid_token_wins_over_assignment(): void
    {
        $user = User::factory()->create();
        $unit = $this->makeUnit('SICU', 'Surgical ICU');
        $user->units()->attach($unit->unit_id, ['is_primary' => true]);

        $this->assertSame('department:ed', $this->resolver()->resolve('department:ed', $user)->token());
    }

    public function test_no_token_falls_back_to_primary_unit_assignment(): void
    {
        $user = User::factory()->create();
        $secondary = $this->makeUnit('6E', 'Six East Med/Surg', 'med_surg');
        $primary = $this->makeUnit('MICU', 'Medical ICU');
        $user->units()->attach($secondary->unit_id, ['is_primary' => false]);
        $user->units()->attach($primary->unit_id, ['is_primary' => true]);

        $this->assertSame('unit:MICU', $this->resolver()->resolve(null, $user)->token());
    }

    public function test_no_token_no_assignment_resolves_to_house(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->resolver()->resolve(null, $user)->isHouse());
    }

    public function test_malformed_token_degrades_gracefully(): void
    {
        // Junk token + an assignment falls through to the assignment (never errors).
        $withUnit = User::factory()->create();
        $unit = $this->makeUnit('MICU', 'Medical ICU');
        $withUnit->units()->attach($unit->unit_id, ['is_primary' => true]);
        $this->assertSame('unit:MICU', $this->resolver()->resolve('unit:GHOST', $withUnit)->token());

        // Junk token + no assignment degrades all the way to house.
        $noUnit = User::factory()->create();
        $this->assertTrue($this->resolver()->resolve('bogus', $noUnit)->isHouse());
    }

    public function test_scopes_endpoint_returns_active_and_catalog_flagging_assigned_units(): void
    {
        $user = User::factory()->create();
        $unit = $this->makeUnit('MICU', 'Medical ICU');
        $user->units()->attach($unit->unit_id, ['is_primary' => true]);

        $response = $this->actingAs($user)->getJson('/api/cockpit/scopes');

        $response->assertOk()
            ->assertJsonPath('active.token', 'unit:MICU')
            ->assertJsonPath('catalog.house.token', 'house');

        $units = collect($response->json('catalog.units'));
        $micu = $units->firstWhere('token', 'unit:MICU');
        $this->assertNotNull($micu);
        $this->assertTrue($micu['assigned']);

        // Departments + service lines are enumerated for the picker.
        $this->assertNotEmpty($response->json('catalog.departments'));
        $this->assertNotEmpty($response->json('catalog.serviceLines'));
    }

    public function test_scopes_endpoint_honors_explicit_scope_param(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/cockpit/scopes?scope=department:periop')
            ->assertOk()
            ->assertJsonPath('active.token', 'department:periop');
    }

    public function test_scopes_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/scopes')->assertStatus(401);
    }
}
