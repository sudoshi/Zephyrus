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

        if (Schema::hasTable('regional.facilities')) {
            Schema::table('regional.facilities', function (Blueprint $table) {
                if (! Schema::hasColumn('regional.facilities', 'building_key')) {
                    $table->string('building_key', 120)->nullable();
                }
                if (! Schema::hasColumn('regional.facilities', 'service_area_key')) {
                    $table->string('service_area_key', 120)->nullable();
                }
                if (! Schema::hasColumn('regional.facilities', 'is_external')) {
                    $table->boolean('is_external')->default(false);
                }
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
        Schema::dropIfExists('regional.network_model_versions');
    }
};
