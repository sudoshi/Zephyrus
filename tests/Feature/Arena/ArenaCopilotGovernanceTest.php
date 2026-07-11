<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaCopilotService;
use App\Domain\Arena\Copilot\ArenaQueryCatalog;
use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part X (X4) — the governed AI copilot's non-negotiables (§X.8), the four
 * acceptance criteria that must hold before the copilot ships:
 *   1. ARENA_AI_ENABLED off fully suppresses it (no envelope, no route, no draft).
 *   2. Every AI action lands PENDING behind the human gate (never auto-approved).
 *   3. NL query is allow-listed + parameterized — never free-form SQL.
 *   4. Narratives are provenance-pinned and deterministic without a live model.
 * (Map-fitness withholding is proven in the sidecar pytest suite.)
 */
class ArenaCopilotGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function enableCopilot(): void
    {
        config([
            'services.arena.enabled' => true,
            'services.arena.ai_enabled' => true,
            'services.eddy.enabled' => false,   // no live LLM in tests → deterministic path
        ]);
    }

    private function seedConformance(string $pathway, string $metricKey, float $value, int $cases, int $deviant): void
    {
        DB::table('arena.conformance_signals')->insert([
            'metric_key' => $metricKey,
            'pathway' => $pathway,
            'value' => $value,
            'cases' => $cases,
            'deviant' => $deviant,
            'deviations' => json_encode([]),
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBottleneck(): void
    {
        DB::table('arena.performance_signals')->insert([
            'metric_key' => 'flow.worst_handoff_wait',
            'value' => 1146.0,
            'context' => 'Bed at discharge',
            'evidence' => json_encode([]),
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // --- 1. Suppression -------------------------------------------------------

    public function test_copilot_is_fully_suppressed_when_ai_disabled(): void
    {
        config(['services.arena.enabled' => true, 'services.arena.ai_enabled' => false]);
        $svc = app(ArenaCopilotService::class);
        $user = User::factory()->create();

        $this->assertFalse($svc->enabled());
        $this->assertFalse($svc->narrate()['available']);
        $this->assertFalse($svc->query('busiest activities')['available']);

        $draft = $svc->draftPdsa($user, 'sepsis');
        $this->assertFalse($draft['available']);
        // No governance record is written when the copilot is off.
        $this->assertSame(0, Recommendation::count());
        $this->assertSame(0, Approval::count());
    }

    public function test_copilot_route_404s_when_ai_disabled(): void
    {
        config(['services.arena.enabled' => true, 'services.arena.ai_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/arena/copilot/narrative')->assertNotFound();
    }

    // --- 2. Every draft lands pending, never approved -------------------------

    public function test_draft_pdsa_lands_pending_and_is_never_approved(): void
    {
        $this->enableCopilot();
        $this->seedBottleneck();
        $user = User::factory()->create(['role' => 'bed_manager']);

        $result = app(ArenaCopilotService::class)->draftPdsa($user, 'bottleneck');

        $this->assertTrue($result['drafted']);
        $this->assertSame('propose_pdsa_cycle', $result['action']['action_type']);
        $this->assertSame('draft', $result['action']['status']);
        $this->assertFalse($result['action']['approved']);

        $rec = Recommendation::firstOrFail();
        $this->assertSame('draft', $rec->status);
        $this->assertSame('eddy_pdsa_cycle', $rec->recommendation_type);
        $this->assertSame('draft', OperationalAction::firstOrFail()->status);
        $this->assertSame('pending', Approval::firstOrFail()->status);
        $this->assertNull(Approval::firstOrFail()->decided_by_user_id);
    }

    public function test_draft_correction_lands_pending(): void
    {
        $this->enableCopilot();
        $this->seedConformance('sepsis', 'quality.sepsis_conformance', 48.3, 60, 31);
        $user = User::factory()->create(['role' => 'bed_manager']);

        $result = app(ArenaCopilotService::class)->draftCorrection($user, 'sepsis');

        $this->assertTrue($result['drafted']);
        $this->assertSame('propose_pathway_correction', $result['action']['action_type']);
        $this->assertSame('draft', $result['action']['status']);
        $this->assertFalse($result['action']['approved']);
        $this->assertSame('pending', Approval::firstOrFail()->status);
    }

    public function test_draft_with_no_signal_writes_nothing(): void
    {
        $this->enableCopilot();
        $user = User::factory()->create();

        // No surgical_safety conformance row seeded → nothing to draft.
        $result = app(ArenaCopilotService::class)->draftPdsa($user, 'surgical_safety');

        $this->assertFalse($result['drafted']);
        $this->assertSame('no_signal_for_focus', $result['reason']);
        $this->assertSame(0, Recommendation::count());
    }

    // --- 3. NL query is allow-listed, never free-form SQL ---------------------

    public function test_query_catalog_is_a_closed_allow_list(): void
    {
        $catalog = app(ArenaQueryCatalog::class);

        $this->assertTrue($catalog->has('busiest_activities'));
        $this->assertFalse($catalog->has('drop_table_users'));

        // An injection string is not a query id — it is simply rejected.
        $this->expectException(\InvalidArgumentException::class);
        $catalog->run("'; DROP TABLE users; --", []);
    }

    public function test_query_rejects_object_type_not_in_the_log(): void
    {
        $catalog = app(ArenaQueryCatalog::class);

        // The object_type param is whitelisted against ocel.object_types; a
        // fabricated (or injection) value can never reach the query.
        $this->expectException(\InvalidArgumentException::class);
        $catalog->run('activities_for_object_type', ['object_type' => 'Robert"; DROP TABLE']);
    }

    public function test_query_routes_a_question_to_a_whitelisted_query(): void
    {
        $this->enableCopilot();
        $result = app(ArenaCopilotService::class)->query('what are the busiest activities');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['matched']);
        $this->assertSame('busiest_activities', $result['query_id']);
        $this->assertSame('keyword', $result['routed_by']);
    }

    // --- 5. Map authoring withholds below the fitness floor (Laravel side) ----

    public function test_author_map_withholds_a_below_floor_model(): void
    {
        $this->enableCopilot();
        $this->app->instance(\App\Domain\Arena\ArenaSidecarClient::class, new class extends \App\Domain\Arena\ArenaSidecarClient
        {
            public function __construct() {}

            public function discover(array $ocel, ?array $objectTypes = null, ?int $minFreq = null, ?array $filters = null): ?array
            {
                return ['object_types' => ['Encounter'], 'nodes' => [], 'edges' => [['source' => 'a', 'target' => 'b', 'object_type' => 'Encounter', 'frequency' => 1]], 'stats' => []];
            }

            public function modelFitness(array $ocel, array $proposedEdges, ?float $fitnessFloor = null): ?array
            {
                return ['fitness' => 0.4, 'precision' => 0.5, 'published' => false, 'fitness_floor' => 0.8, 'reason' => 'below_fitness_floor', 'invented_edges' => [], 'missing_edges' => []];
            }
        });

        $result = app(ArenaCopilotService::class)->authorMap();

        $this->assertTrue($result['available']);
        $this->assertFalse($result['published']);
        $this->assertNull($result['map']);   // the unvalidated picture is never returned
        $this->assertSame('below_fitness_floor', $result['reason']);
    }

    public function test_author_map_publishes_a_conformant_model(): void
    {
        $this->enableCopilot();
        $this->app->instance(\App\Domain\Arena\ArenaSidecarClient::class, new class extends \App\Domain\Arena\ArenaSidecarClient
        {
            public function __construct() {}

            public function discover(array $ocel, ?array $objectTypes = null, ?int $minFreq = null, ?array $filters = null): ?array
            {
                return ['object_types' => ['Encounter'], 'nodes' => [['id' => 'a', 'activity' => 'a', 'frequency' => 1, 'object_types' => ['Encounter']]], 'edges' => [], 'stats' => []];
            }

            public function modelFitness(array $ocel, array $proposedEdges, ?float $fitnessFloor = null): ?array
            {
                return ['fitness' => 1.0, 'precision' => 1.0, 'published' => true, 'fitness_floor' => 0.8, 'reason' => null, 'invented_edges' => [], 'missing_edges' => []];
            }
        });

        $result = app(ArenaCopilotService::class)->authorMap();

        $this->assertTrue($result['published']);
        $this->assertNotNull($result['map']);
    }

    // --- 4. Narrative is provenance-pinned + deterministic --------------------

    public function test_narrative_is_provenance_pinned_and_deterministic(): void
    {
        $this->enableCopilot();
        $this->seedConformance('sepsis', 'quality.sepsis_conformance', 48.3, 60, 31);

        $result = app(ArenaCopilotService::class)->narrate();

        $this->assertTrue($result['available']);
        $this->assertFalse($result['ai_polished']);   // no live model → deterministic
        $this->assertNotEmpty($result['provenance']);
        foreach ($result['provenance'] as $fact) {
            $this->assertArrayHasKey('claim', $fact);
            $this->assertArrayHasKey('source', $fact);
            $this->assertArrayHasKey('value', $fact);
        }
        // The conformance figure is present in the pinned provenance.
        $values = implode(' ', array_column($result['provenance'], 'value'));
        $this->assertStringContainsString('48.3', $values);
    }
}
