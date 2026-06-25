<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! $this->schemaTableExists('ops', 'nodes')) {
            Schema::create('ops.nodes', function (Blueprint $table) {
                $table->id('graph_node_id');
                $table->uuid('node_uuid')->unique();
                $table->string('node_type', 80);
                $table->string('canonical_key', 160)->unique();
                $table->string('display_name');
                $table->string('source_schema', 80)->nullable();
                $table->string('source_table', 120)->nullable();
                $table->string('source_pk', 120)->nullable();
                $table->string('status', 80)->nullable();
                $table->unsignedSmallInteger('source_priority')->default(100);
                $table->jsonb('current_state')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('last_observed_at')->nullable();
                $table->timestamps();
                $table->boolean('is_active')->default(true);

                $table->index(['node_type', 'status']);
                $table->index(['source_schema', 'source_table', 'source_pk'], 'ops_nodes_source_idx');
            });
        }

        if (! $this->schemaTableExists('ops', 'edges')) {
            Schema::create('ops.edges', function (Blueprint $table) {
                $table->id('graph_edge_id');
                $table->foreignId('from_node_id')
                    ->constrained('ops.nodes', 'graph_node_id')
                    ->cascadeOnDelete();
                $table->foreignId('to_node_id')
                    ->constrained('ops.nodes', 'graph_node_id')
                    ->cascadeOnDelete();
                $table->uuid('edge_uuid')->unique();
                $table->string('edge_type', 100);
                $table->decimal('weight', 8, 4)->default(1);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('valid_from')->useCurrent();
                $table->timestamp('valid_to')->nullable();
                $table->timestamps();
                $table->boolean('is_active')->default(true);

                $table->index(['from_node_id', 'edge_type']);
                $table->index(['to_node_id', 'edge_type']);
            });
        }

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS ops_edges_active_unique_idx
            ON ops.edges(from_node_id, to_node_id, edge_type)
            WHERE is_active = true AND valid_to IS NULL
        ');

        if (! $this->schemaTableExists('ops', 'state_snapshots')) {
            Schema::create('ops.state_snapshots', function (Blueprint $table) {
                $table->id('state_snapshot_id');
                $table->uuid('snapshot_uuid')->unique();
                $table->string('scope_type', 80)->default('hospital');
                $table->string('scope_key', 160)->nullable();
                $table->timestamp('captured_at')->useCurrent();
                $table->unsignedInteger('node_count')->default(0);
                $table->unsignedInteger('edge_count')->default(0);
                $table->string('state_hash', 128);
                $table->jsonb('state_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['scope_type', 'scope_key', 'captured_at']);
            });
        }

        if (! $this->schemaTableExists('ops', 'constraints')) {
            Schema::create('ops.constraints', function (Blueprint $table) {
                $table->id('constraint_id');
                $table->uuid('constraint_uuid')->unique();
                $table->string('constraint_type', 100);
                $table->string('scope_type', 80);
                $table->string('scope_key', 160)->nullable();
                $table->string('hard_or_soft', 16)->default('hard');
                $table->string('severity', 40)->default('warning');
                $table->string('status', 40)->default('active');
                $table->jsonb('expression')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['constraint_type', 'status']);
                $table->index(['scope_type', 'scope_key']);
            });
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'ops_constraints_hard_soft_chk'
                ) THEN
                    ALTER TABLE ops.constraints
                    ADD CONSTRAINT ops_constraints_hard_soft_chk CHECK (hard_or_soft IN ('hard','soft'));
                END IF;
            END $$;
        SQL);

        if (! $this->schemaTableExists('ops', 'recommendations')) {
            Schema::create('ops.recommendations', function (Blueprint $table) {
                $table->id('recommendation_id');
                $table->uuid('recommendation_uuid')->unique();
                $table->string('recommendation_type', 100);
                $table->string('scope_type', 80);
                $table->string('scope_key', 160)->nullable();
                $table->string('title');
                $table->text('rationale')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('risk_level', 40)->default('low');
                $table->string('status', 40)->default('draft');
                $table->jsonb('expected_impact')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('evidence')->default(DB::raw("'{}'::jsonb"));
                $table->string('created_by_source', 120)->default('rules');
                $table->timestamps();

                $table->index(['scope_type', 'scope_key']);
                $table->index(['recommendation_type', 'status']);
            });
        }

        if (! $this->schemaTableExists('ops', 'actions')) {
            Schema::create('ops.actions', function (Blueprint $table) {
                $table->id('action_id');
                $table->uuid('action_uuid')->unique();
                $table->foreignId('recommendation_id')
                    ->nullable()
                    ->constrained('ops.recommendations', 'recommendation_id')
                    ->nullOnDelete();
                $table->string('action_type', 100);
                $table->foreignId('subject_node_id')
                    ->nullable()
                    ->constrained('ops.nodes', 'graph_node_id')
                    ->nullOnDelete();
                $table->foreignId('target_node_id')
                    ->nullable()
                    ->constrained('ops.nodes', 'graph_node_id')
                    ->nullOnDelete();
                $table->string('status', 40)->default('draft');
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->unsignedBigInteger('executed_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index(['action_type', 'status']);
                $table->index(['subject_node_id', 'target_node_id']);
            });
        }

        if (! $this->schemaTableExists('ops', 'approvals')) {
            Schema::create('ops.approvals', function (Blueprint $table) {
                $table->id('approval_id');
                $table->uuid('approval_uuid')->unique();
                $table->foreignId('action_id')
                    ->constrained('ops.actions', 'action_id')
                    ->cascadeOnDelete();
                $table->string('status', 40)->default('pending');
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('requested_at')->useCurrent();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'requested_at']);
            });
        }
    }

    public function down(): void
    {
        $this->safeDropSchema('ops');
    }

    private function schemaTableExists(string $schema, string $table): bool
    {
        $row = DB::selectOne(
            'SELECT to_regclass(?) IS NOT NULL AS table_exists',
            ["{$schema}.{$table}"]
        );

        return (bool) ($row?->table_exists ?? false);
    }
};
