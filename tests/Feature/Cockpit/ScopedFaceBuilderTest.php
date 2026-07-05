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

        // The capacity table uses the shared board column set.
        $table = $face['tables'][0];
        $this->assertSame('unit', $table['columns'][0]['key']);
        $this->assertNotEmpty($table['rows']);
    }

    public function test_unit_face_without_census_returns_empty_not_fabricated(): void
    {
        // No RtdcSeeder → no beds → no live census; the face is honest, not stubbed.
        $face = $this->faces()->build(CockpitScope::unit('MICU', 'Medical ICU'));

        $this->assertSame('face', $face['render']);
        $this->assertSame([], $face['kpis']);
        $this->assertSame([], $face['tables']);
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
}
