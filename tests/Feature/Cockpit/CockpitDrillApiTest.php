<?php

namespace Tests\Feature\Cockpit;

use App\Models\User;
use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $this->assertCount(9, $rows);

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

    public function test_hidden_demo_domains_have_no_drill(): void
    {
        config(['cockpit.hide_demo_domains' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/cockpit/drill/quality')->assertStatus(404);
        $this->actingAs($user)->getJson('/api/cockpit/drill/rtdc')->assertOk();
    }

    public function test_drill_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/drill/rtdc')->assertStatus(401);
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
