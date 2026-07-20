<?php

namespace Tests\Feature\Cockpit;

use App\Models\User;
use App\Services\Cockpit\SnapshotBuilder;
use App\Services\Mobile\MobilePatientContextService;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P1 — the per-domain drill endpoint (spec §3.3) emitting the
 * §6.4 Cell grammar, plus the SSE stream fallback. PHPUnit class syntax
 * only (Pest is excluded on this environment).
 */
class CockpitDrillApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
    }

    public function test_rtdc_drill_returns_cell_grammar_tables(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/rtdc');

        $response->assertOk()
            ->assertJsonPath('domain', 'rtdc')
            ->assertJsonPath('title', 'Real-Time Demand & Capacity — Unit Capacity Board');

        $table = $response->json('tables.0');
        $this->assertSame('Unit capacity board', $table['caption']);
        $this->assertSame('unit', $table['columns'][0]['key']);
        $this->assertIsArray($table['rows']);

        $this->assertIsArray($response->json('kpis'));
        $this->assertNotEmpty($response->json('kpis'));
    }

    public function test_okr_drill_builds_the_scorecard_table_with_canon_cells(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/okr');

        $response->assertOk();

        $rows = $response->json('tables.0.rows');
        // 9 core + 4 service-sector OKRs (2026-07-19).
        $this->assertCount(13, $rows);
        $edLos = collect($rows)->firstWhere('keyResult', 'ED LOS (admitted)');
        $this->assertSame('5 hr 0 min 0 sec', $edLos['target']['v']);

        // Cell grammar uses CANON tokens ('critical'/'warning'/…), never the
        // logical 5-state names — the bridge happens server-side.
        foreach ($rows as $row) {
            $this->assertContains(
                $row['status']['chip'],
                ['critical', 'warning', 'success', 'info', 'neutral'],
            );
        }
    }

    public function test_every_registry_domain_drills_and_unknown_domains_404(): void
    {
        $user = User::factory()->create();

        foreach (['rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial', 'okr'] as $domain) {
            $this->actingAs($user)
                ->getJson("/api/cockpit/drill/{$domain}")
                ->assertOk()
                ->assertJsonPath('domain', $domain);
        }

        $this->actingAs($user)->getJson('/api/cockpit/drill/pharmacy')->assertStatus(404);
    }

    public function test_live_domains_keep_their_drill_under_the_hide_demo_flag(): void
    {
        // Post-P7 Quality is live (materialized-view backed), so the D5
        // hide-demo flag no longer hides it — its drill stays reachable.
        config(['cockpit.hide_demo_domains' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/cockpit/drill/quality')->assertOk();
        $this->actingAs($user)->getJson('/api/cockpit/drill/rtdc')->assertOk();
    }

    public function test_flow_drill_preserves_bed_hours_as_a_compound_measure(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/flow')
            ->assertOk();

        $table = collect($response->json('tables'))
            ->firstWhere('caption', 'Transport performance measures');
        $measure = collect($table['rows'])
            ->firstWhere('measure.v', 'Avoidable bed-hours attributed to transport');

        $this->assertSame('0 bed-hr', $measure['value']);
        $this->assertSame('0 sec attributable delay', $measure['context']['v']);
    }

    public function test_drill_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/drill/rtdc')->assertStatus(401);
    }

    public function test_ed_track_board_beds_drill_to_the_patient_lens(): void
    {
        // One ED patient in the treatment cohort (arrived, seen, not departed).
        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'test-ed-drill',
            'arrived_at' => now()->subHours(3),
            'esi_level' => 2,
            'provider_seen_at' => now()->subHours(2),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/drill/ed')
            ->assertOk();

        $board = collect($response->json('tables'))->firstWhere('caption', 'Active track board');
        $this->assertNotNull($board, 'the ED drill must expose the Active track board');

        // The seeded bed row carries a drill cell whose ptok matches the service.
        $drillCell = collect($board['rows'])
            ->pluck('room')
            ->first(fn ($cell): bool => is_array($cell) && isset($cell['drill']));

        $this->assertNotNull($drillCell, 'a bed cell must be drillable to the patient lens');
        $this->assertSame(
            app(MobilePatientContextService::class)->contextRefFor('test-ed-drill'),
            $drillCell['drill']['patientRef'],
        );
        $this->assertStringStartsWith('ptok_', $drillCell['drill']['patientRef']);
    }

    public function test_stream_emits_a_snapshot_ping_when_a_row_exists(): void
    {
        app(SnapshotBuilder::class)->refresh();

        $response = $this->actingAs(User::factory()->create())
            ->get('/api/cockpit/stream?cycles=1');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString(': connected', $content);
        $this->assertStringContainsString('event: cockpit-snapshot', $content);
        $this->assertStringContainsString('"facilityKey":"HOSP1"', $content);
    }

    public function test_stream_heartbeats_without_a_snapshot_row(): void
    {
        $content = $this->actingAs(User::factory()->create())
            ->get('/api/cockpit/stream?cycles=1')
            ->streamedContent();

        $this->assertStringContainsString(': heartbeat', $content);
        $this->assertStringNotContainsString('event: cockpit-snapshot', $content);
    }
}
