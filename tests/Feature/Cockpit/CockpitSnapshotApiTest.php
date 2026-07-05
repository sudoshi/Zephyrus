<?php

namespace Tests\Feature\Cockpit;

use App\Models\Cockpit\CockpitSnapshot;
use App\Models\Ops\MetricDefinition;
use App\Models\User;
use App\Services\Cockpit\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P1 serving-layer proof: one replaced snapshot row, cached,
 * ETag/304-served, with the kpi-definitions endpoints admin-gated.
 * PHPUnit class syntax only (Pest is excluded on this environment).
 */
class CockpitSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
    }

    public function test_refresh_persists_a_single_replaced_row_and_primes_the_shared_cache(): void
    {
        $builder = app(SnapshotBuilder::class);

        $builder->refresh();
        $builder->refresh();

        $this->assertSame(1, CockpitSnapshot::query()->count());

        $row = CockpitSnapshot::query()->first();
        $this->assertSame('HOSP1', $row->facility_key);
        $this->assertIsArray($row->payload);
        $this->assertSame('HOSP1', $row->payload['facilityKey']);
        $this->assertArrayHasKey('generatedAtIso', $row->payload);
        $this->assertArrayHasKey('capacitySnapshot', $row->payload);

        $cached = Cache::get(SnapshotBuilder::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertSame($row->payload['generatedAtIso'], $cached['generatedAtIso']);
    }

    public function test_snapshot_endpoint_serves_the_row_with_etag_and_honors_if_none_match(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson('/api/cockpit/snapshot');
        $first->assertOk()->assertJsonPath('facilityKey', 'HOSP1');

        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($user)
            ->getJson('/api/cockpit/snapshot', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_snapshot_endpoint_cold_start_computes_once_instead_of_returning_empty(): void
    {
        $this->assertSame(0, CockpitSnapshot::query()->count());

        $this->actingAs(User::factory()->create())
            ->getJson('/api/cockpit/snapshot')
            ->assertOk()
            ->assertJsonPath('facilityKey', 'HOSP1');

        $this->assertSame(1, CockpitSnapshot::query()->count());
    }

    public function test_snapshot_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/cockpit/snapshot')->assertStatus(401);
    }

    public function test_kpi_definitions_endpoint_exposes_direction_aware_edges(): void
    {
        MetricDefinition::query()->create([
            'metric_definition_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'metric_key' => 'ed.nedocs',
            'label' => 'NEDOCS',
            'domain' => 'ed',
            'definition' => 'National ED Overcrowding Score',
            'direction' => 'down',
            'warn_edge' => 101,
            'crit_edge' => 141,
        ]);

        // GET is admin-gated (P8 WS-6b hardened it alongside the audited PUT).
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/cockpit/kpi-definitions');

        $response->assertOk();
        $definition = collect($response->json('definitions'))->firstWhere('key', 'ed.nedocs');
        $this->assertNotNull($definition);
        $this->assertSame('down', $definition['edges']['direction']);
        // JSON has no int/float distinction for whole numbers — compare as floats.
        $this->assertSame(101.0, (float) $definition['edges']['warn']);
        $this->assertSame(141.0, (float) $definition['edges']['crit']);
    }

    public function test_kpi_definition_update_is_admin_gated_and_audited(): void
    {
        MetricDefinition::query()->create([
            'metric_definition_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'metric_key' => 'rtdc.occupancy',
            'label' => 'House occupancy',
            'domain' => 'rtdc',
            'definition' => 'Staffed-bed occupancy',
            'direction' => 'down',
            'warn_edge' => 85,
            'crit_edge' => 95,
        ]);

        $payload = ['warn_edge' => 88, 'crit_edge' => 96];

        // Non-admin: forbidden, nothing changes.
        $this->actingAs(User::factory()->create())
            ->putJson('/api/cockpit/kpi-definitions/rtdc.occupancy', $payload)
            ->assertStatus(403);

        // Admin: edges update.
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->putJson('/api/cockpit/kpi-definitions/rtdc.occupancy', $payload)
            ->assertOk()
            ->assertJsonPath('edges.warn', 88)
            ->assertJsonPath('edges.crit', 96);

        $this->assertSame(
            96.0,
            (float) MetricDefinition::query()->where('metric_key', 'rtdc.occupancy')->value('crit_edge'),
        );
    }

    public function test_eddy_capacity_tool_reads_the_shared_snapshot_cache(): void
    {
        app(SnapshotBuilder::class)->refresh();

        $cached = Cache::get(SnapshotBuilder::CACHE_KEY);
        $this->assertIsArray($cached['capacitySnapshot'] ?? null, 'snapshot must embed the capacity document');

        $user = User::factory()->create();
        $toolResult = app(\App\Services\Ops\Agents\AgentToolRegistry::class)
            ->call('capacity.snapshot', [], $user);

        // Same generatedAtIso ⇒ Eddy's worldview IS the cockpit snapshot,
        // not a second computation (the single-snapshot discipline).
        $this->assertSame($cached['capacitySnapshot']['generatedAtIso'], $toolResult['generatedAtIso']);
    }
}
