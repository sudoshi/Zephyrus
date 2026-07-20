<?php

namespace Tests\Feature\Cockpit;

use App\Models\User;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P2 — /dashboard serves the ONE cockpit snapshot: the legacy
 * Zod contract keys AND the additive §3.2 sections in the same Inertia prop,
 * plus the overview feature flag. PHPUnit class syntax only (no Pest here).
 */
class CockpitDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    public function test_dashboard_serves_the_cockpit_snapshot_with_sections_and_flag(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/CommandCenter')
                ->where('cockpitEnabled', true)
                // Legacy contract keys survive untouched (classic rollback path).
                ->has('data.generatedAtIso')
                ->has('data.heroMetrics')
                ->has('data.strain')
                // Additive §3.2 sections ride the same payload.
                ->has('data.asOf')
                ->has('data.capacityStatus.code')
                ->has('data.census')
                ->has('data.alerts')
                ->has('data.okrs', 13)
                ->has('data.domains.rtdc.tiles')
                ->has('data.domains.financial.provenance')
            );
    }

    public function test_overview_flag_off_still_serves_the_full_payload(): void
    {
        config(['cockpit.overview_enabled' => false]);

        // The flag only switches the client render path — the payload stays
        // the single snapshot either way (no second data shape to maintain).
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cockpitEnabled', false)
                ->has('data.generatedAtIso')
                ->has('data.domains')
            );
    }

    public function test_dashboard_serves_from_the_shared_snapshot_cache_and_persists_the_row(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

        // The cold-start page load must have primed the SAME snapshot the API,
        // the drills, and Eddy read — the single-snapshot discipline.
        $cached = Cache::get(SnapshotBuilder::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertSame('HOSP1', $cached['facilityKey']);
        $this->assertDatabaseHas('prod.cockpit_snapshots', ['facility_key' => 'HOSP1']);
    }
}
