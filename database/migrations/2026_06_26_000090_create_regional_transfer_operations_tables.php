<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS regional');

        if (! Schema::hasTable('regional.facilities')) {
            Schema::create('regional.facilities', function (Blueprint $table) {
                $table->id('regional_facility_id');
                $table->uuid('facility_uuid')->unique();
                $table->string('facility_code', 120)->unique();
                $table->string('facility_name', 180);
                $table->string('organization_key', 120)->default('zephyrus-network');
                $table->string('campus_key', 120)->nullable();
                $table->string('building_key', 120)->nullable();
                $table->string('service_area_key', 120)->nullable();
                $table->string('facility_type', 80);
                $table->string('status', 40)->default('active');
                $table->boolean('is_external')->default(false);
                $table->integer('staffed_beds')->default(0);
                $table->integer('available_beds')->default(0);
                $table->integer('icu_available_beds')->default(0);
                $table->integer('ed_boarders')->default(0);
                $table->integer('transport_minutes')->default(0);
                $table->boolean('accepts_transfers')->default(true);
                $table->jsonb('capacity_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index(['organization_key', 'status']);
                $table->index(['organization_key', 'campus_key', 'building_key'], 'regional_facility_scope_idx');
                $table->index(['facility_type', 'accepts_transfers']);
                $table->index(['is_external', 'accepts_transfers']);
            });
        }

        if (! Schema::hasTable('regional.network_model_versions')) {
            Schema::create('regional.network_model_versions', function (Blueprint $table) {
                $table->id('network_model_version_id');
                $table->uuid('model_version_uuid')->unique();
                $table->string('version_key', 120)->unique();
                $table->string('label', 180);
                $table->string('status', 40)->default('draft');
                $table->jsonb('assumptions_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('facility_payload')->default(DB::raw("'[]'::jsonb"));
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'approved_at']);
            });
        }

        if (! Schema::hasTable('regional.facility_capabilities')) {
            Schema::create('regional.facility_capabilities', function (Blueprint $table) {
                $table->id('facility_capability_id');
                $table->foreignId('regional_facility_id')
                    ->constrained('regional.facilities', 'regional_facility_id')
                    ->cascadeOnDelete();
                $table->string('capability_key', 120);
                $table->string('capability_type', 80);
                $table->string('status', 40)->default('available');
                $table->integer('score_weight')->default(0);
                $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->unique(['regional_facility_id', 'capability_key'], 'regional_facility_capability_unique');
                $table->index(['capability_type', 'status']);
            });
        }

        if (! Schema::hasTable('regional.transfer_decisions')) {
            Schema::create('regional.transfer_decisions', function (Blueprint $table) {
                $table->id('transfer_decision_id');
                $table->uuid('decision_uuid')->unique();
                $table->foreignId('transport_request_id')
                    ->constrained('prod.transport_requests', 'transport_request_id')
                    ->cascadeOnDelete();
                $table->foreignId('selected_facility_id')
                    ->nullable()
                    ->constrained('regional.facilities', 'regional_facility_id')
                    ->nullOnDelete();
                $table->string('decision_status', 40)->default('draft');
                $table->integer('selected_score')->nullable();
                $table->jsonb('candidate_payload')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('constraint_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('opportunity_cost_payload')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('decision_rationale')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['transport_request_id', 'decision_status']);
                $table->index(['selected_facility_id', 'decision_status']);
            });
        }

        if (! Schema::hasTable('regional.route_simulation_runs')) {
            Schema::create('regional.route_simulation_runs', function (Blueprint $table) {
                $table->id('route_simulation_run_id');
                $table->uuid('route_simulation_run_uuid')->unique();
                $table->string('model_version_key', 120);
                $table->jsonb('scenario_payload')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('results_payload')->default(DB::raw("'{}'::jsonb"));
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index(['model_version_key', 'executed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regional.route_simulation_runs');
        Schema::dropIfExists('regional.transfer_decisions');
        Schema::dropIfExists('regional.facility_capabilities');
        Schema::dropIfExists('regional.network_model_versions');
        Schema::dropIfExists('regional.facilities');
    }
};
