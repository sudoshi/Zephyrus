<?php

namespace Tests\Feature\Cockpit;

use App\Models\User;
use App\Services\Cockpit\ScopedFaceBuilder;
use App\Services\Cockpit\SnapshotBuilder;
use App\Support\Cockpit\CockpitScope;
use App\Support\Cockpit\CockpitScopeResolver;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P8 WS-2 — the altitude-appropriate face builder + /api/cockpit/face
 * endpoint. Department faces reuse the DrillBuilder verbatim; unit / service-line
 * faces are live-census rollups. PHPUnit class syntax (Pest excluded).
 */
class ScopedFaceBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    private function faces(): ScopedFaceBuilder
    {
        return app(ScopedFaceBuilder::class);
    }

    public function test_house_face_signals_the_grid(): void
    {
        $face = $this->faces()->build(CockpitScope::house('Summit Regional Medical Center'));

        $this->assertSame('grid', $face['render']);
        $this->assertSame('house', $face['scope']['token']);
    }

    public function test_department_face_reuses_the_domain_drill(): void
    {
        $face = $this->faces()->build(CockpitScope::department('ed', 'Emergency Department'));

        $this->assertSame('face', $face['render']);
        $this->assertSame('department:ed', $face['scope']['token']);
        // The reused drill payload carries its domain, kpis, and Cell-grammar tables.
        $this->assertSame('ed', $face['domain']);
        $this->assertNotEmpty($face['kpis']);
        $this->assertIsArray($face['tables']);
    }

    public function test_unit_face_is_a_live_census_rollup(): void
    {
        $this->seed(RtdcSeeder::class);

        $face = $this->faces()->build(CockpitScope::unit('MICU', 'Medical ICU'));

        $this->assertSame('face', $face['render']);
        $this->assertSame('unit:MICU', $face['scope']['token']);

        $keys = array_column($face['kpis'], 'key');
        $this->assertContains('unit.occupancy', $keys);
        // The patient-care tiles ride alongside capacity (P8 unit enrichment).
        $this->assertContains('unit.dc_due', $keys);

        // Patient board leads (bed / acuity / LOS / EDD — de-identified),
        // followed by the shared capacity board column set.
        $this->assertSame('Patient board', $face['tables'][0]['caption']);
        $this->assertSame('bed', $face['tables'][0]['columns'][0]['key']);
        $capacity = $face['tables'][1];
        $this->assertSame('unit', $capacity['columns'][0]['key']);
        $this->assertNotEmpty($capacity['rows']);
    }

    public function test_unit_roster_rows_descend_to_the_patient_lens(): void
    {
        $this->seed(RtdcSeeder::class);

        $unitId = (int) DB::table('prod.units')->where('abbreviation', 'MICU')->value('unit_id');
        $bedId = (int) DB::table('prod.beds')->where('unit_id', $unitId)->orderBy('bed_id')->value('bed_id');
        DB::table('prod.beds')->where('bed_id', $bedId)->update(['status' => 'occupied']);
        DB::table('prod.encounters')->insert([
            'patient_ref' => 'test-roster-1',
            'unit_id' => $unitId,
            'bed_id' => $bedId,
            'admitted_at' => now()->subHours(30),
            'expected_discharge_date' => now()->toDateString(),
            'acuity_tier' => 4,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'created_by' => 'test',
            'modified_by' => 'test',
            'is_deleted' => false,
        ]);

        $face = $this->faces()->build(CockpitScope::unit('MICU', 'Medical ICU'));

        $board = $face['tables'][0];
        $this->assertSame('Patient board', $board['caption']);
        $this->assertCount(1, $board['rows']);

        $row = $board['rows'][0];
        // The bed cell is a drill affordance carrying ONLY the opaque ptok — the
        // face itself stays de-identified (PHI is gated at A2P, not here).
        $this->assertArrayHasKey('drill', $row['bed']);
        $this->assertStringStartsWith('ptok_', $row['bed']['drill']['patientRef']);
        $this->assertSame('T4', $row['acuity']['tag']['text']);
        $this->assertSame('Today', $row['edd']['tag']['text']);

        // The same admission counts on the discharge-due tile.
        $dcDue = collect($face['kpis'])->firstWhere('key', 'unit.dc_due');
        $this->assertSame(1, $dcDue['value']);
    }

    public function test_unit_face_matches_census_through_the_cad_join_key(): void
    {
        $this->seed(RtdcSeeder::class);

        // Simulate the CAD-taxonomy fork: the unit row carries the CAD code
        // (MICU3), not the branded abbr — the face must still find its census.
        DB::table('prod.units')->where('abbreviation', 'MICU')->update(['abbreviation' => 'MICU3']);

        $face = $this->faces()->build(CockpitScope::unit('MICU', 'Medical ICU'));

        $this->assertSame('face', $face['render']);
        $this->assertNotEmpty($face['kpis']);
    }

    public function test_unit_face_without_census_returns_empty_not_fabricated(): void
    {
        // No RtdcSeeder → no beds → no live census; the face is honest, not stubbed.
        $face = $this->faces()->build(CockpitScope::unit('MICU', 'Medical ICU'));

        $this->assertSame('face', $face['render']);
        $this->assertSame([], $face['kpis']);
        $this->assertSame([], $face['tables']);
    }

    public function test_single_platform_service_line_falls_back_to_its_domain_drill(): void
    {
        // No RtdcSeeder → the emergency line (ED only, no census wards) must render
        // the ED domain drill, not an empty census card (prod prunes ED bed rows).
        $face = $this->faces()->build(CockpitScope::serviceLine('emergency', 'Emergency Medicine'));

        $this->assertSame('face', $face['render']);
        $this->assertSame('service_line:emergency', $face['scope']['token']);
        $this->assertSame('ed', $face['domain']);
        $this->assertNotEmpty($face['kpis']);
    }

    public function test_service_line_face_rolls_up_its_units(): void
    {
        $this->seed(RtdcSeeder::class);

        $face = $this->faces()->build(CockpitScope::serviceLine('critical_care', 'Critical Care'));

        $this->assertSame('face', $face['render']);
        $this->assertSame('service_line:critical_care', $face['scope']['token']);

        $keys = array_column($face['kpis'], 'key');
        $this->assertContains('sl.occupancy', $keys);
        $this->assertNotEmpty($face['tables'][0]['rows']);
    }

    public function test_face_endpoint_resolves_scope_and_requires_auth(): void
    {
        $this->getJson('/api/cockpit/face?scope=department:periop')->assertStatus(401);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/face?scope=department:periop')
            ->assertOk()
            ->assertJsonPath('scope.token', 'department:periop')
            ->assertJsonPath('render', 'face');
    }

    public function test_face_endpoint_falls_back_to_house_without_scope_or_assignment(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/face')
            ->assertOk()
            ->assertJsonPath('render', 'grid')
            ->assertJsonPath('scope.token', 'house');
    }

    public function test_resolver_and_face_compose_end_to_end(): void
    {
        $this->seed(RtdcSeeder::class);
        $resolver = app(CockpitScopeResolver::class);

        $scope = $resolver->resolve('unit:sicu', null);   // lowercase → canonicalized
        $face = $this->faces()->build($scope);

        $this->assertSame('unit:SICU', $face['scope']['token']);
        $this->assertSame('face', $face['render']);
    }

    public function test_resolver_canonicalizes_cad_unit_tokens(): void
    {
        $resolver = app(CockpitScopeResolver::class);

        // Deep links and prod.user_unit rows may carry the CAD join key — the
        // scope token they resolve to is always the branded manifest abbr.
        $this->assertSame('unit:MICU', $resolver->resolve('unit:MICU3', null)->token());
        $this->assertSame('unit:7E', $resolver->resolve('unit:tel7a', null)->token());
    }
}
