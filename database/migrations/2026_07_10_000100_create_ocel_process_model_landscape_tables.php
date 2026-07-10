<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed reference-model registry for ACUM-OPS-OCEL-001.
 *
 * These rows describe intended bounded models and are deliberately separate
 * from ocel.events/objects (observed facts) and arena.maps (mined evidence).
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ocel');

        if (! Schema::hasTable('ocel.process_models')) {
            Schema::create('ocel.process_models', function (Blueprint $table) {
                $table->string('process_id', 4)->primary();
                $table->char('domain_code', 1);
                $table->unsignedSmallInteger('process_number');
                $table->string('domain_name', 140);
                $table->string('name', 180);
                $table->text('core_interaction');
                $table->jsonb('core_objects')->default(DB::raw("'[]'::jsonb"));
                $table->text('improvement_question');
                $table->string('evidence_grade', 20);
                $table->string('priority', 2);
                $table->string('interaction_pattern', 60);
                $table->string('implementation_wave', 24);
                $table->string('current_readiness', 40);
                $table->text('readiness_note');
                $table->string('source_document', 80);
                $table->unsignedInteger('catalog_version')->default(1);
                $table->timestamps();

                $table->unique(['domain_code', 'process_number'], 'ocel_process_models_domain_number_unique');
                $table->index(['priority', 'implementation_wave'], 'ocel_process_models_priority_wave_idx');
                $table->index('current_readiness', 'ocel_process_models_readiness_idx');
            });
        }

        if (! Schema::hasTable('ocel.process_model_nodes')) {
            Schema::create('ocel.process_model_nodes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('process_id', 4);
                $table->string('node_key', 180);
                $table->string('activity', 140);
                $table->string('label', 180);
                $table->string('node_kind', 24);
                $table->unsignedSmallInteger('ordinal');
                $table->jsonb('object_types')->default(DB::raw("'[]'::jsonb"));
                $table->boolean('required')->default(true);
                $table->string('source_basis', 140);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['process_id', 'node_key'], 'ocel_process_model_nodes_process_key_unique');
                $table->unique(['process_id', 'ordinal'], 'ocel_process_model_nodes_process_ordinal_unique');
                $table->index(['process_id', 'node_kind'], 'ocel_process_model_nodes_kind_idx');
            });
        }

        if (! Schema::hasTable('ocel.process_model_edges')) {
            Schema::create('ocel.process_model_edges', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('process_id', 4);
                $table->string('edge_key', 100);
                $table->string('source_node_key', 180);
                $table->string('target_node_key', 180);
                $table->string('label', 80);
                $table->string('relationship_type', 60);
                $table->unsignedSmallInteger('ordinal');
                $table->boolean('is_exception')->default(false);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['process_id', 'edge_key'], 'ocel_process_model_edges_process_key_unique');
                $table->unique(['process_id', 'ordinal'], 'ocel_process_model_edges_process_ordinal_unique');
                $table->index(['process_id', 'relationship_type'], 'ocel_process_model_edges_type_idx');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('ocel.process_model_edges');
        $this->safeDropIfExists('ocel.process_model_nodes');
        $this->safeDropIfExists('ocel.process_models');
    }
};
