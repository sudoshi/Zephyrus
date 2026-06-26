<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! Schema::hasTable('ops.interventions')) {
            Schema::create('ops.interventions', function (Blueprint $table) {
                $table->id('intervention_id');
                $table->uuid('intervention_uuid')->unique();
                $table->foreignId('recommendation_id')
                    ->nullable()
                    ->constrained('ops.recommendations', 'recommendation_id')
                    ->nullOnDelete();
                $table->foreignId('action_id')
                    ->nullable()
                    ->constrained('ops.actions', 'action_id')
                    ->nullOnDelete();
                $table->foreignId('pdsa_cycle_id')
                    ->nullable()
                    ->constrained('prod.pdsa_cycles', 'pdsa_cycle_id')
                    ->nullOnDelete();
                $table->foreignId('simulation_scenario_id')
                    ->nullable()
                    ->constrained('ops.simulation_scenarios', 'simulation_scenario_id')
                    ->nullOnDelete();
                $table->string('intervention_type', 120);
                $table->string('scope_type', 80)->default('hospital');
                $table->string('scope_key', 160)->nullable();
                $table->string('title');
                $table->string('status', 40)->default('measuring');
                $table->string('owner_name', 160)->nullable();
                $table->text('hypothesis')->nullable();
                $table->string('attribution_method', 80)->default('before_after');
                $table->string('comparison_strategy', 80)->default('before_after');
                $table->string('confidence_level', 40)->default('directional');
                $table->text('confidence_language')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('baseline_started_at')->nullable();
                $table->timestamp('baseline_ended_at')->nullable();
                $table->timestamp('followup_started_at')->nullable();
                $table->timestamp('followup_ended_at')->nullable();
                $table->jsonb('evidence_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('stratification_payload')->default(DB::raw("'{}'::jsonb"));
                $table->string('created_by_source', 120)->default('rules:intervention_attribution_service');
                $table->timestamps();

                $table->unique('action_id', 'ops_interventions_action_unique');
                $table->index(['status', 'completed_at']);
                $table->index(['intervention_type', 'status']);
                $table->index(['scope_type', 'scope_key']);
                $table->index('pdsa_cycle_id');
                $table->index('recommendation_id');
            });
        }

        if (! Schema::hasTable('ops.intervention_metrics')) {
            Schema::create('ops.intervention_metrics', function (Blueprint $table) {
                $table->id('intervention_metric_id');
                $table->foreignId('intervention_id')
                    ->constrained('ops.interventions', 'intervention_id')
                    ->cascadeOnDelete();
                $table->string('metric_key', 120);
                $table->string('label', 160);
                $table->string('measure_type', 40)->default('outcome');
                $table->string('unit', 40)->default('count');
                $table->string('direction', 20)->default('down');
                $table->decimal('baseline_value', 18, 4)->nullable();
                $table->decimal('followup_value', 18, 4)->nullable();
                $table->decimal('delta_value', 18, 4)->nullable();
                $table->decimal('delta_pct', 10, 4)->nullable();
                $table->string('status', 40)->default('neutral');
                $table->boolean('is_primary')->default(false);
                $table->timestamp('baseline_started_at')->nullable();
                $table->timestamp('baseline_ended_at')->nullable();
                $table->timestamp('followup_started_at')->nullable();
                $table->timestamp('followup_ended_at')->nullable();
                $table->jsonb('source_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['intervention_id', 'metric_key'], 'ops_intervention_metric_unique');
                $table->index(['metric_key', 'measure_type']);
                $table->index(['status', 'is_primary']);
            });
        }

        if (! Schema::hasTable('ops.outcome_attribution')) {
            Schema::create('ops.outcome_attribution', function (Blueprint $table) {
                $table->id('outcome_attribution_id');
                $table->foreignId('intervention_id')
                    ->constrained('ops.interventions', 'intervention_id')
                    ->cascadeOnDelete();
                $table->string('attribution_method', 80)->default('before_after');
                $table->string('comparison_strategy', 80)->default('before_after');
                $table->string('confidence_level', 40)->default('directional');
                $table->decimal('confidence_score', 5, 2)->default(0);
                $table->text('confidence_language');
                $table->unsignedInteger('sample_size')->default(0);
                $table->jsonb('balancing_summary')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('caveats')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('comparison_options')->default(DB::raw("'[]'::jsonb"));
                $table->text('executive_summary');
                $table->timestamp('calculated_at')->useCurrent();
                $table->timestamps();

                $table->unique('intervention_id', 'ops_outcome_attribution_intervention_unique');
                $table->index(['confidence_level', 'calculated_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.outcome_attribution');
        Schema::dropIfExists('ops.intervention_metrics');
        Schema::dropIfExists('ops.interventions');
    }
};
