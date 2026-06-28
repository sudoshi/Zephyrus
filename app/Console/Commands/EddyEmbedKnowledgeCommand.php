<?php

namespace App\Console\Commands;

use App\Models\Eddy\EddyKnowledge;
use App\Services\Eddy\EddyEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill pgvector embeddings for eddy_knowledge (Phase 6 hybrid RAG).
 *
 * Embeds title + body via the local model and writes the vector column. Runs even
 * when retrieval gating (EDDY_EMBEDDINGS_ENABLED) is off — this is the admin act
 * that PREPARES the store; flipping the flag then turns on hybrid retrieval. Rows
 * that won't embed are skipped (reported), never written empty.
 */
class EddyEmbedKnowledgeCommand extends Command
{
    protected $signature = 'eddy:embed-knowledge
        {--all : re-embed every row, not only those missing an embedding}
        {--limit=500 : max rows to process this run}';

    protected $description = 'Backfill pgvector embeddings for eddy_knowledge rows (Phase 6 RAG).';

    public function handle(EddyEmbeddingService $embeddings): int
    {
        if (! $embeddings->columnPresent()) {
            $this->warn('eddy_knowledge.embedding is absent (pgvector not installed) — nothing to do.');

            return self::SUCCESS;
        }

        $query = EddyKnowledge::query()
            ->where('is_active', true)
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('embedding'))
            ->limit((int) $this->option('limit'))
            ->get(['eddy_knowledge_id', 'title', 'body']);

        if ($query->isEmpty()) {
            $this->info('No eddy_knowledge rows need embedding.');

            return self::SUCCESS;
        }

        $embedded = 0;
        $skipped = 0;
        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        foreach ($query as $row) {
            $vector = $embeddings->embed(trim($row->title."\n".$row->body));

            if ($vector === null) {
                $skipped++;
            } else {
                try {
                    DB::update(
                        'UPDATE eddy.eddy_knowledge SET embedding = ?::vector, updated_at = now() WHERE eddy_knowledge_id = ?',
                        [$embeddings->toVectorLiteral($vector), $row->eddy_knowledge_id],
                    );
                    $embedded++;
                } catch (\Throwable $e) {
                    // Most likely a dimension mismatch (model ≠ column width). Report once.
                    $this->newLine();
                    $this->error("Write failed for #{$row->eddy_knowledge_id}: {$e->getMessage()}");
                    $skipped++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Embedded {$embedded}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
