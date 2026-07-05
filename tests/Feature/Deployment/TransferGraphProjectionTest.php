<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use App\Services\Ops\OperationsGraphProjector;
use Database\Seeders\GeisingerDeploymentSeeder;
use Database\Seeders\VirtuaDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 acceptance: hosp_org facilities + transfer_relationships project into the
 * ops graph (facility nodes + transfers_to edges weighted by typical_minutes), the
 * projector stays idempotent under the partial-unique edge index, and the
 * /api/deployment/transfers endpoint serves per-service-line subgraphs.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 4)
 */
class TransferGraphProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function transferEdge(string $fromKey, string $toKey): ?object
    {
        return DB::table('ops.edges as e')
            ->join('ops.nodes as fn', 'fn.graph_node_id', '=', 'e.from_node_id')
            ->join('ops.nodes as tn', 'tn.graph_node_id', '=', 'e.to_node_id')
            ->where('e.edge_type', 'transfers_to')
            ->where('e.is_active', true)
            ->where('fn.canonical_key', $fromKey)
            ->where('tn.canonical_key', $toKey)
            ->select('e.*')
            ->first();
    }

    public function test_projector_creates_one_facility_node_per_row_and_weighted_transfer_edges(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);
        app(OperationsGraphProjector::class)->rebuild();

        $facilityCount = DB::table('hosp_org.facilities')->count();
        $this->assertGreaterThan(0, $facilityCount);
        $this->assertSame(
            $facilityCount,
            DB::table('ops.nodes')->where('node_type', 'facility')->where('is_active', true)->count()
        );
        $this->assertDatabaseHas('ops.nodes', ['canonical_key' => 'facility:GEISINGER_GMC', 'node_type' => 'facility']);

        // Level IV spoke -> Level I hub transfers_to edge, weight = typical_minutes.
        $edge = $this->transferEdge('facility:GEISINGER_MUNCY', 'facility:GEISINGER_GMC');
        $this->assertNotNull($edge);

        $expectedMinutes = (int) DB::table('hosp_org.transfer_relationships')
            ->where('source_facility_key', 'GEISINGER_MUNCY')
            ->where('destination_facility_key', 'GEISINGER_GMC')
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->value('typical_minutes');
        $this->assertGreaterThan(0, $expectedMinutes);
        $this->assertSame($expectedMinutes, (int) round((float) $edge->weight));

        $meta = json_decode($edge->metadata, true);
        $this->assertContains('trauma_acute_care_surgery', $meta['service_lines']);
    }

    public function test_rebuild_is_idempotent_for_transfer_edges(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);
        $projector = app(OperationsGraphProjector::class);

        $projector->rebuild();
        $before = DB::table('ops.edges')->where('edge_type', 'transfers_to')->where('is_active', true)->count();
        $this->assertGreaterThan(0, $before);

        // A second rebuild must not duplicate edges (partial-unique index holds).
        $projector->rebuild();
        $after = DB::table('ops.edges')->where('edge_type', 'transfers_to')->where('is_active', true)->count();
        $this->assertSame($before, $after);
    }

    public function test_multiple_service_lines_between_a_pair_aggregate_into_one_edge(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);

        $muncyId = DB::table('hosp_org.facilities')->where('facility_key', 'GEISINGER_MUNCY')->value('facility_id');
        $gmcId = DB::table('hosp_org.facilities')->where('facility_key', 'GEISINGER_GMC')->value('facility_id');

        // Add a second service line for the same facility pair.
        DB::table('hosp_org.transfer_relationships')->insert([
            'source_facility_id' => $muncyId,
            'source_facility_key' => 'GEISINGER_MUNCY',
            'destination_facility_id' => $gmcId,
            'destination_facility_key' => 'GEISINGER_GMC',
            'service_line_code' => 'cardiovascular',
            'transport_mode' => 'ground',
            'typical_minutes' => 999,
            'direction' => 'out',
            'is_external_partner' => false,
            'is_active' => true,
            'source_evidence' => '{}',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OperationsGraphProjector::class)->rebuild();

        $edges = DB::table('ops.edges as e')
            ->join('ops.nodes as fn', 'fn.graph_node_id', '=', 'e.from_node_id')
            ->join('ops.nodes as tn', 'tn.graph_node_id', '=', 'e.to_node_id')
            ->where('e.edge_type', 'transfers_to')
            ->where('e.is_active', true)
            ->where('fn.canonical_key', 'facility:GEISINGER_MUNCY')
            ->where('tn.canonical_key', 'facility:GEISINGER_GMC')
            ->select('e.*')
            ->get();

        $this->assertCount(1, $edges, 'the pair collapses to a single transfers_to edge');

        $meta = json_decode($edges->first()->metadata, true);
        $this->assertEqualsCanonicalizing(['trauma_acute_care_surgery', 'cardiovascular'], $meta['service_lines']);
        // weight = the fastest of the two service lines (never the 999 outlier).
        $this->assertLessThan(999, (int) round((float) $edges->first()->weight));
    }

    public function test_external_partner_projects_external_node_and_flagged_edge(): void
    {
        $this->seed(VirtuaDeploymentSeeder::class);
        app(OperationsGraphProjector::class)->rebuild();

        $external = DB::table('ops.nodes')
            ->where('node_type', 'external_facility')
            ->where('display_name', 'Cooper University Hospital')
            ->first();
        $this->assertNotNull($external);

        $edge = DB::table('ops.edges')
            ->where('edge_type', 'transfers_to')
            ->where('to_node_id', $external->graph_node_id)
            ->where('is_active', true)
            ->first();
        $this->assertNotNull($edge);
        $this->assertTrue(json_decode($edge->metadata, true)['is_external_partner']);

        // facility:* node count still equals the facilities table (external nodes are a distinct type).
        $this->assertSame(
            DB::table('hosp_org.facilities')->count(),
            DB::table('ops.nodes')->where('node_type', 'facility')->where('is_active', true)->count()
        );
    }

    public function test_transfers_api_filters_by_service_line(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);
        // /api/deployment/* is gated by the viewDeploymentConsole ability (Phase 6).
        $user = User::factory()->create(['role' => 'superuser']);

        $data = $this->actingAs($user)
            ->getJson('/api/deployment/transfers?service_line=trauma_acute_care_surgery')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertSame('trauma_acute_care_surgery', $row['service_line_code']);
            $this->assertArrayHasKey('weight', $row);
        }

        $pairs = array_map(
            fn (array $r): string => $r['source_facility_key'].'->'.$r['destination_facility_key'],
            $data
        );
        $this->assertContains('GEISINGER_MUNCY->GEISINGER_GMC', $pairs);
        $this->assertContains('GEISINGER_LEWISTOWN->GEISINGER_GMC', $pairs);
    }

    public function test_transfers_api_requires_authentication(): void
    {
        $this->getJson('/api/deployment/transfers')->assertUnauthorized();
    }
}
