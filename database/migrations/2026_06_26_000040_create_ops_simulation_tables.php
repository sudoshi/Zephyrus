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

        if (! Schema::hasTable('ops.simulation_runs')) {
            Schema::create('ops.simulation_runs', function (Blueprint $table) {
                $table->id('simulation_run_id');
                $table->uuid('simulation_run_uuid')->unique();
                $table->foreignId('baseline_snapshot_id')
                    ->nullable()
                    ->constrained('ops.state_snapshots', 'state_snapshot_id')
                    ->nullOnDelete();
                $table->string('scope_type', 80)->default('hospital');
                $table->string('scope_key', 160)->nullable();
                $table->string('status', 40)->default('completed');
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->jsonb('baseline_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('summary_payload')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['scope_type', 'scope_key']);
                $table->index(['status', 'started_at']);
            });
        }

        if (! Schema::hasTable('ops.simulation_scenarios')) {
            Schema::create('ops.simulation_scenarios', function (Blueprint $table) {
                $table->id('simulation_scenario_id');
                $table->uuid('simulation_scenario_uuid')->unique();
                $table->foreignId('simulation_run_id')
                    ->constrained('ops.simulation_runs', 'simulation_run_id')
                    ->cascadeOnDelete();
                $table->string('scenario_key', 120);
                $table->string('title');
                $table->text('assumption');
                $table->string('status', 40)->default('modeled');
                $table->jsonb('intervention_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamp('promoted_at')->nullable();
                $table->foreignId('promoted_recommendation_id')
                    ->nullable()
                    ->constrained('ops.recommendations', 'recommendation_id')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['simulation_run_id', 'scenario_key'], 'simulation_scenario_run_key_unique');
                $table->index(['scenario_key', 'status']);
                $table->index('promoted_recommendation_id');
            });
        }

        if (! Schema::hasTable('ops.simulation_results')) {
            Schema::create('ops.simulation_results', function (Blueprint $table) {
                $table->id('simulation_result_id');
                $table->foreignId('simulation_scenario_id')
                    ->constrained('ops.simulation_scenarios', 'simulation_scenario_id')
                    ->cascadeOnDelete();
                $table->string('metric_key', 120);
                $table->decimal('baseline_value', 18, 4)->nullable();
                $table->decimal('projected_value', 18, 4)->nullable();
                $table->decimal('delta_value', 18, 4)->nullable();
                $table->string('unit', 40)->default('count');
                $table->string('status', 40)->default('neutral');
                $table->jsonb('result_payload')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['simulation_scenario_id', 'metric_key'], 'simulation_result_scenario_metric_unique');
                $table->index(['metric_key', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ops.simulation_results');
        Schema::dropIfExists('ops.simulation_scenarios');
        Schema::dropIfExists('ops.simulation_runs');
    }
};
