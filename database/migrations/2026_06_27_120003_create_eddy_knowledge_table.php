<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eddy — Phase 0: institutional operational-knowledge / RAG store.
 *
 * Operational doctrine Eddy retrieves over: huddle SOPs, escalation policies,
 * capacity/surge playbooks, GMLOS notes, EVS isolation protocols, the Two-System
 * design rules. Phase 2 ships keyword/ILIKE + tag retrieval (no vector dep);
 * Phase 6 adds an `embedding vector(384)` column + HNSW index iff pgvector is
 * present. Only `is_phi_free = true` rows are cloud-eligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS eddy');

        if (! Schema::hasTable('eddy.eddy_knowledge')) {
            Schema::create('eddy.eddy_knowledge', function (Blueprint $table) {
                $table->id('eddy_knowledge_id');
                $table->uuid('eddy_knowledge_uuid')->unique();
                $table->string('surface', 80)->index();                  // which surface(s); 'global' allowed
                $table->string('category', 80);                          // policy|sop|benchmark|playbook|glossary|escalation
                $table->string('title', 300);
                $table->text('body');                                    // markdown
                $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));
                $table->string('source', 200)->nullable();               // 'CMS GMLOS' | 'IHI RTDC' | 'internal-policy-v3'
                $table->boolean('is_phi_free')->default(true);           // gate: only is_phi_free content is cloud-eligible
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['category', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('eddy.eddy_knowledge');
    }
};
