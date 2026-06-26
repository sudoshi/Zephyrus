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

        if (! Schema::hasTable('ops.metric_definitions')) {
            Schema::create('ops.metric_definitions', function (Blueprint $table) {
                $table->id('metric_definition_id');
                $table->uuid('metric_definition_uuid')->unique();
                $table->string('metric_key', 160)->unique();
                $table->string('label');
                $table->string('domain', 80);
                $table->text('definition');
                $table->string('owner', 160)->nullable();
                $table->string('unit', 40)->nullable();
                $table->decimal('target_value', 14, 4)->nullable();
                $table->string('target_display', 80)->nullable();
                $table->string('direction', 20)->default('neutral');
                $table->string('cadence', 80)->default('live');
                $table->string('status', 40)->default('active');
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['domain', 'status']);
            });
        }

        if (! Schema::hasTable('ops.metric_lineage')) {
            Schema::create('ops.metric_lineage', function (Blueprint $table) {
                $table->id('metric_lineage_id');
                $table->foreignId('metric_definition_id')
                    ->nullable()
                    ->constrained('ops.metric_definitions', 'metric_definition_id')
                    ->cascadeOnDelete();
                $table->string('metric_key', 160);
                $table->string('source_key', 160);
                $table->string('source_schema', 80);
                $table->string('source_table', 120);
                $table->string('source_column', 120)->nullable();
                $table->string('freshness_column', 120)->nullable();
                $table->string('transform_name', 160);
                $table->unsignedSmallInteger('transform_step')->default(10);
                $table->decimal('confidence_weight', 5, 4)->default(1);
                $table->jsonb('source_filter')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['metric_key', 'source_key', 'transform_name'], 'metric_lineage_unique');
                $table->index(['source_schema', 'source_table']);
            });
        }

        if (! Schema::hasTable('ops.metric_values')) {
            Schema::create('ops.metric_values', function (Blueprint $table) {
                $table->id('metric_value_id');
                $table->foreignId('metric_definition_id')
                    ->nullable()
                    ->constrained('ops.metric_definitions', 'metric_definition_id')
                    ->nullOnDelete();
                $table->string('metric_key', 160);
                $table->timestamp('measured_at')->useCurrent();
                $table->timestamp('period_start')->nullable();
                $table->timestamp('period_end')->nullable();
                $table->string('grain', 80)->default('snapshot');
                $table->decimal('value', 18, 4)->nullable();
                $table->string('display', 80)->nullable();
                $table->string('status', 40)->default('neutral');
                $table->jsonb('dimension_payload')->default(DB::raw("'{}'::jsonb"));
                $table->string('source_hash', 128)->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['metric_key', 'measured_at']);
                $table->index(['grain', 'period_start', 'period_end']);
            });
        }

        if (! Schema::hasTable('ops.source_freshness')) {
            Schema::create('ops.source_freshness', function (Blueprint $table) {
                $table->id('source_freshness_id');
                $table->string('source_key', 160)->unique();
                $table->string('source_label');
                $table->string('source_schema', 80);
                $table->string('source_table', 120);
                $table->string('freshness_column', 120)->nullable();
                $table->timestamp('latest_observed_at')->nullable();
                $table->unsignedInteger('expected_lag_minutes')->default(1440);
                $table->unsignedInteger('warning_lag_minutes')->default(10080);
                $table->unsignedBigInteger('record_count')->default(0);
                $table->string('status', 40)->default('warning');
                $table->timestamp('checked_at')->useCurrent();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['status', 'checked_at']);
                $table->index(['source_schema', 'source_table']);
            });
        }

        if (! Schema::hasTable('ops.data_quality_findings')) {
            Schema::create('ops.data_quality_findings', function (Blueprint $table) {
                $table->id('data_quality_finding_id');
                $table->uuid('finding_uuid')->unique();
                $table->string('check_key', 160);
                $table->string('check_label');
                $table->string('status', 40);
                $table->string('severity', 40)->default('warning');
                $table->string('source_key', 160)->nullable();
                $table->text('detail');
                $table->string('measured_value', 120)->nullable();
                $table->string('threshold_value', 120)->nullable();
                $table->timestamp('opened_at')->useCurrent();
                $table->timestamp('resolved_at')->nullable();
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['check_key', 'status']);
                $table->index(['source_key', 'status']);
                $table->index(['severity', 'opened_at']);
            });
        }

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'ops_metric_definitions_direction_chk'
                ) THEN
                    ALTER TABLE ops.metric_definitions
                    ADD CONSTRAINT ops_metric_definitions_direction_chk
                    CHECK (direction IN ('up','down','neutral'));
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'ops_metric_lineage_weight_chk'
                ) THEN
                    ALTER TABLE ops.metric_lineage
                    ADD CONSTRAINT ops_metric_lineage_weight_chk
                    CHECK (confidence_weight BETWEEN 0 AND 1);
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        Schema::dropIfExists('ops.data_quality_findings');
        Schema::dropIfExists('ops.source_freshness');
        Schema::dropIfExists('ops.metric_values');
        Schema::dropIfExists('ops.metric_lineage');
        Schema::dropIfExists('ops.metric_definitions');
    }
};
