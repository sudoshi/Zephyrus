<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\FlowReviewService;
use App\Models\Barrier;
use App\Models\User;
use App\Services\Eddy\EddyActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. The orchestrator half of the
 * 48-Hour Flow Review: it must fold ONE faked OCPM pass + the open prod.barriers
 * into a persisted artifact, serve it read-only from the cache, degrade (not
 * persist) when the sidecar is down, and stay invisible behind ARENA_ENABLED.
 * The ranking/severity detail is pinned separately in FlowReviewComposerTest.
 */
class FlowReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config(['services.arena.enabled' => true]);
    }

    private function fakeSidecar(): void
    {
        Http::fake([
            'arena:8100/discover' => Http::response([
                'object_types' => ['Bed', 'Transport Job'],
                'nodes' => [
                    ['id' => 'bed_request', 'activity' => 'Bed request', 'frequency' => 110, 'object_types' => ['Bed']],
                    ['id' => 'assign_bed', 'activity' => 'Assign bed', 'frequency' => 100, 'object_types' => ['Bed']],
                    ['id' => 'transport', 'activity' => 'Transport', 'frequency' => 90, 'object_types' => ['Transport Job']],
                ],
                'edges' => [
                    ['source' => 'bed_request', 'target' => 'assign_bed', 'object_type' => 'Bed', 'frequency' => 95],
                    ['source' => 'assign_bed', 'target' => 'transport', 'object_type' => 'Transport Job', 'frequency' => 88],
                ],
                'stats' => ['nodes' => 3, 'edges' => 2],
            ], 200),
            'arena:8100/performance' => Http::response([
                'handoffs' => [
                    ['object_type' => 'Transport Job', 'source' => 'assign_bed', 'target' => 'transport', 'count' => 22, 'median_sec' => 16560, 'p90_sec' => 28440, 'mean_sec' => 18120],
                ],
                'synchronization' => [],
            ], 200),
            'arena:8100/conformance' => Http::response([
                [
                    'pathway' => 'sepsis', 'label' => 'Sepsis (SEP-3)', 'version' => 3, 'owner' => 'ED', 'case_type' => 'sepsis',
                    'cases' => 41, 'conformant' => 35, 'deviant' => 6, 'conformance_rate' => 0.85,
                    'deviations' => [['code' => 'abx_within_3h', 'label' => 'Antibiotic later than 3h', 'count' => 6]],
                    'sample_deviant_cases' => [['case_id' => 'enc-3d9f21', 'deviations' => ['abx_within_3h']]],
                ],
            ], 200),
        ]);
    }

    private function seedOpenBarrier(): void
    {
        Barrier::create([
            'category' => 'placement',
            'status' => 'open',
            'owner' => 'C. Ramos',
            'description' => 'Isolation bed shortage',
            'opened_at' => now()->subHours(5),
            'is_deleted' => false,
        ]);
    }

    public function test_run_folds_all_three_kinds_into_a_persisted_artifact(): void
    {
        $this->enable();
        $this->fakeSidecar();
        $this->seedOpenBarrier();

        $out = app(FlowReviewService::class)->run();

        $this->assertTrue($out['available']);
        $this->assertFalse($out['cached']);
        $this->assertFalse($out['stale']);

        $kinds = array_column($out['barriers'], 'kind');
        $this->assertContains('flow', $kinds);
        $this->assertContains('care', $kinds);
        $this->assertContains('human', $kinds);

        $flow = collect($out['barriers'])->firstWhere('id', 'flow-assign_bed-transport');
        $this->assertSame('critical', $flow['severity']);

        // Persisted exactly once, with the barrier count recorded.
        $this->assertSame(1, DB::table('arena.reviews')->count());
        $this->assertSame(count($out['barriers']), (int) DB::table('arena.reviews')->value('barrier_count'));
    }

    public function test_run_counts_pending_corrective_actions(): void
    {
        $this->enable();
        $this->fakeSidecar();
        $this->seedOpenBarrier();

        $actor = User::factory()->create();
        // A pending copilot corrective-action draft counts…
        app(EddyActionService::class)->propose($actor, [
            'action_type' => 'propose_pdsa_cycle', 'title' => 'Cut the wait', 'rationale' => 'slow', 'surface' => 'arena',
            'params' => ['pdsa' => [], 'focus' => 'bottleneck'],
        ], approve: false);
        // …a low-risk barrier flag (not a materializing corrective action) does NOT.
        app(EddyActionService::class)->propose($actor, [
            'action_type' => 'flag_barrier', 'title' => 'Stuck', 'surface' => 'house', 'params' => [],
        ], approve: false);

        $out = app(FlowReviewService::class)->run();

        $this->assertSame(1, $out['stats']['actions_pending']);
    }

    public function test_get_serves_the_cached_artifact(): void
    {
        $this->enable();
        $this->fakeSidecar();
        $this->seedOpenBarrier();

        app(FlowReviewService::class)->run();
        $out = app(FlowReviewService::class)->get();

        $this->assertTrue($out['available']);
        $this->assertTrue($out['cached']);
        $this->assertFalse($out['stale']);
    }

    public function test_get_reports_none_before_any_review_is_built(): void
    {
        $out = app(FlowReviewService::class)->get();

        $this->assertFalse($out['available']);
        $this->assertSame('no_review', $out['reason']);
    }

    public function test_run_degrades_without_persisting_when_the_sidecar_is_down(): void
    {
        $this->enable();
        Http::fake(['arena:8100/discover' => Http::response('boom', 500)]);

        $out = app(FlowReviewService::class)->run();

        $this->assertFalse($out['available']);
        $this->assertSame('sidecar_unavailable', $out['reason']);
        $this->assertSame(0, DB::table('arena.reviews')->count());
    }

    public function test_route_404s_while_disabled_and_serves_once_enabled(): void
    {
        $this->actingAs(User::factory()->create());

        // Invisible while ARENA is off.
        $this->getJson('/api/arena/review')->assertNotFound();

        $this->enable();
        $this->fakeSidecar();
        $this->seedOpenBarrier();

        app(FlowReviewService::class)->run();

        $this->getJson('/api/arena/review')
            ->assertOk()
            ->assertJson(['available' => true, 'cached' => true]);

        $this->postJson('/api/arena/review/run')
            ->assertOk()
            ->assertJson(['available' => true]);
    }
}
