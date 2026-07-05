<?php

namespace Tests\Feature\Arena;

use App\Domain\Arena\ArenaCopilotService;
use App\Domain\Arena\Copilot\CopilotLlm;
use App\Domain\Arena\Copilot\EddyProxyCopilotLlm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Part X (X4) — the LLM-LIVE copilot path (§X.8.2). The deterministic tests
 * (ArenaCopilotGovernanceTest) exercise NullCopilotLlm; these prove the safeguards
 * that only matter once a real model is in the loop:
 *   - the PHI guard fails CLOSED (a PHI-shaped prompt is never sent),
 *   - the LLM can NEVER widen the allow-list (a hallucinated query_id is discarded),
 *   - a narrative the LLM polishes keeps its provenance numbers pinned.
 */
class ArenaCopilotLlmPathTest extends TestCase
{
    use RefreshDatabase;

    /** A fake in-loop model with a fixed output and live=true. */
    private function fakeLlm(?string $output): CopilotLlm
    {
        return new class($output) implements CopilotLlm
        {
            public function __construct(private ?string $output) {}

            public function generate(string $system, string $user, array $options = []): ?string
            {
                return $this->output;
            }

            public function isLive(): bool
            {
                return true;
            }
        };
    }

    private function enableLlm(): void
    {
        config([
            'services.arena.enabled' => true,
            'services.arena.ai_enabled' => true,
            'services.eddy.enabled' => true,
            'services.eddy.url' => 'http://eddy:8000',
        ]);
    }

    public function test_phi_guard_fails_closed_and_never_sends(): void
    {
        $this->enableLlm();
        Http::fake(['eddy:8000/*' => Http::response(['reply' => 'should never be reached'], 200)]);

        // An SSN-shaped token in the prompt must be refused → null, and NOTHING sent.
        $out = (new EddyProxyCopilotLlm)->generate('system', 'review patient SSN 123-45-6789 flow');

        $this->assertNull($out);
        Http::assertNothingSent();
    }

    public function test_clean_prompt_is_sent_and_reply_returned(): void
    {
        $this->enableLlm();
        Http::fake(['eddy:8000/*' => Http::response(['reply' => 'a crisp brief'], 200)]);

        $out = (new EddyProxyCopilotLlm)->generate('system', 'the busiest activity is Safety Check');

        $this->assertSame('a crisp brief', $out);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/eddy/chat') && $req['surface'] === 'arena_copilot');
    }

    public function test_llm_cannot_widen_the_allow_list(): void
    {
        config(['services.arena.enabled' => true, 'services.arena.ai_enabled' => true]);
        // The model hallucinates a query id that is NOT in the catalog.
        $this->app->instance(CopilotLlm::class, $this->fakeLlm(json_encode(['query_id' => 'rm_rf_tables', 'params' => []])));

        $result = app(ArenaCopilotService::class)->query('zzz qqq xyzzy');

        // The bad id is discarded; the deterministic router finds no keyword → no match.
        $this->assertTrue($result['available']);
        $this->assertFalse($result['matched']);
    }

    public function test_llm_routes_only_to_a_valid_catalog_query(): void
    {
        config(['services.arena.enabled' => true, 'services.arena.ai_enabled' => true]);
        $this->app->instance(CopilotLlm::class, $this->fakeLlm(json_encode(['query_id' => 'handoff_bottlenecks', 'params' => []])));

        $result = app(ArenaCopilotService::class)->query('anything at all');

        $this->assertTrue($result['matched']);
        $this->assertSame('handoff_bottlenecks', $result['query_id']);
        $this->assertSame('llm', $result['routed_by']);
    }

    public function test_narrate_polish_preserves_provenance_numbers(): void
    {
        config(['services.arena.enabled' => true, 'services.arena.ai_enabled' => true]);
        DB::table('arena.conformance_signals')->insert([
            'metric_key' => 'quality.sepsis_conformance', 'pathway' => 'sepsis', 'value' => 48.3,
            'cases' => 60, 'deviant' => 31, 'deviations' => json_encode([]),
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CopilotLlm::class, $this->fakeLlm('A polished three-sentence brief that mentions no figures.'));

        $result = app(ArenaCopilotService::class)->narrate();

        $this->assertTrue($result['ai_polished']);
        $this->assertSame('A polished three-sentence brief that mentions no figures.', $result['narrative']);
        // The prose is the model's; the provenance numbers remain pinned to the data.
        $values = implode(' ', array_column($result['provenance'], 'value'));
        $this->assertStringContainsString('48.3', $values);
    }
}
