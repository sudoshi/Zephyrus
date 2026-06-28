<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Eddy — Phase 6: institutional-knowledge learning loop.
 *
 *  - `status`/`curated_from`: a human-review gate for AUTO-curated knowledge.
 *    Existing rows default to 'approved' so seeded doctrine stays live; rows the
 *    curator proposes land 'proposed' and only surface in RAG once approved.
 *  - `embedding vector(N)`: hybrid (vector + keyword) retrieval — added ONLY when
 *    pgvector is present. Absent → retrieval stays on the Phase 2 keyword path.
 *
 * Non-destructive + idempotent (IF NOT EXISTS throughout) so it is safe to re-run
 * and degrades gracefully where pgvector is unavailable (e.g. a locked-down prod).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eddy.eddy_knowledge')) {
            return;
        }

        // --- review-gate columns (no pgvector dependency) ---
        if (! Schema::hasColumn('eddy.eddy_knowledge', 'status')) {
            Schema::table('eddy.eddy_knowledge', function (Blueprint $table) {
                // proposed | approved | retired — only 'approved' is RAG-eligible.
                $table->string('status', 24)->default('approved')->index();
                // Provenance for auto-curated rows: {origin, source_id, signal, …}.
                $table->jsonb('curated_from')->nullable();
            });
        }

        // --- pgvector embedding column (conditional) ---
        if ($this->ensurePgvector()) {
            $dim = max(1, (int) config('eddy.embeddings.dimensions', 768));

            // Eloquent has no vector type — raw DDL. IF NOT EXISTS keeps it idempotent.
            DB::statement("ALTER TABLE eddy.eddy_knowledge ADD COLUMN IF NOT EXISTS embedding vector({$dim})");
            DB::statement('CREATE INDEX IF NOT EXISTS eddy_knowledge_embedding_hnsw '
                .'ON eddy.eddy_knowledge USING hnsw (embedding vector_cosine_ops)');
        } else {
            Log::info('eddy.migration.pgvector_absent', [
                'note' => 'eddy_knowledge.embedding skipped; retrieval stays on the keyword path.',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('eddy.eddy_knowledge')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS eddy.eddy_knowledge_embedding_hnsw');
        DB::statement('ALTER TABLE eddy.eddy_knowledge DROP COLUMN IF EXISTS embedding');

        Schema::table('eddy.eddy_knowledge', function (Blueprint $table) {
            if (Schema::hasColumn('eddy.eddy_knowledge', 'status')) {
                $table->dropColumn(['status', 'curated_from']);
            }
        });
    }

    /**
     * Install pgvector when it is available but not yet created. Returns whether the
     * extension is installed afterward. CREATE EXTENSION needs elevated rights, so a
     * permission failure on a hardened role is swallowed (degrade to keyword RAG).
     */
    private function ensurePgvector(): bool
    {
        try {
            $available = DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'vector'");
            if ($available) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            }
        } catch (\Throwable $e) {
            Log::warning('eddy.migration.pgvector_install_failed', ['error' => $e->getMessage()]);
        }

        return (bool) DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'");
    }
};
