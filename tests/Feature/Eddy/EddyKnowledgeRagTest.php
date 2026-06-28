<?php

namespace Tests\Feature\Eddy;

use App\Models\Eddy\EddyKnowledge;
use App\Services\Eddy\EddyEmbeddingService;
use App\Services\Eddy\EddyKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6 hybrid RAG over eddy_knowledge: vector ranking when embeddings are on,
 * graceful degradation to the keyword path otherwise, approved-only gating.
 */
class EddyKnowledgeRagTest extends TestCase
{
    use RefreshDatabase;

    private function knowledge(string $title, string $status = 'approved', string $surface = 'rtdc'): EddyKnowledge
    {
        return EddyKnowledge::create([
            'eddy_knowledge_uuid' => (string) Str::uuid(),
            'surface' => $surface,
            'category' => 'playbook',
            'title' => $title,
            'body' => $title.' body',
            'tags' => [],
            'is_phi_free' => true,
            'is_active' => true,
            'status' => $status,
        ]);
    }

    /** @return array<int, float> a one-hot vector of the configured width. */
    private function oneHot(int $index): array
    {
        $dim = (int) config('eddy.embeddings.dimensions', 768);
        $v = array_fill(0, $dim, 0.0);
        $v[$index] = 1.0;

        return $v;
    }

    private function setEmbedding(EddyKnowledge $k, array $vector): void
    {
        DB::update('UPDATE eddy.eddy_knowledge SET embedding = ?::vector WHERE eddy_knowledge_id = ?', [
            '['.implode(',', $vector).']',
            $k->eddy_knowledge_id,
        ]);
    }

    /** Bind an embedding service whose enabled()/embed() we control; keep the helpers real. */
    private function fakeEmbeddings(?array $queryVector, bool $enabled = true): void
    {
        $fake = new class($queryVector, $enabled) extends EddyEmbeddingService
        {
            public function __construct(private readonly ?array $queryVector, private readonly bool $forceEnabled) {}

            public function enabled(): bool
            {
                return $this->forceEnabled && $this->columnPresent();
            }

            public function embed(string $text): ?array
            {
                return $this->queryVector;
            }
        };

        $this->app->instance(EddyEmbeddingService::class, $fake);
    }

    public function test_vector_path_ranks_by_cosine_similarity(): void
    {
        $alpha = $this->knowledge('Alpha protocol');
        $beta = $this->knowledge('Beta protocol');
        $this->setEmbedding($alpha, $this->oneHot(0));
        $this->setEmbedding($beta, $this->oneHot(5));

        // Query embeds closest to Alpha; the message shares no keywords with either body.
        $this->fakeEmbeddings($this->oneHot(0));

        $out = $this->app->make(EddyKnowledgeService::class)->forSurface('rtdc', 'zzz unrelated tokens', 2);

        $this->assertNotEmpty($out);
        $this->assertSame('Alpha protocol', $out[0]['title']);
    }

    public function test_keyword_path_when_embeddings_disabled(): void
    {
        $this->fakeEmbeddings(null, enabled: false);
        $this->knowledge('Discharge barrier escalation');

        $out = $this->app->make(EddyKnowledgeService::class)->forSurface('rtdc', 'what about discharge escalation', 3);

        $this->assertNotEmpty($out);
        $this->assertSame('Discharge barrier escalation', $out[0]['title']);
    }

    public function test_fails_open_to_keyword_when_query_will_not_embed(): void
    {
        // Embeddings "enabled" but embed() returns null → must fall back, not blow up.
        $this->fakeEmbeddings(null, enabled: true);
        $this->knowledge('Surge plan playbook');

        $out = $this->app->make(EddyKnowledgeService::class)->forSurface('rtdc', 'surge plan needed', 3);

        $this->assertNotEmpty($out);
        $this->assertSame('Surge plan playbook', $out[0]['title']);
    }

    public function test_only_approved_knowledge_surfaces(): void
    {
        $this->fakeEmbeddings(null, enabled: false);
        $this->knowledge('Proposed doctrine', status: 'proposed');

        $out = $this->app->make(EddyKnowledgeService::class)->forSurface('rtdc', 'proposed doctrine', 3);

        $this->assertCount(0, $out);
    }
}
