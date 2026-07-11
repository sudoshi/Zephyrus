<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling-demo refresh ledger (plan §4.2 / FEEDBACK Wave 1).
 *
 * One row per zephyrus:demo-refresh batch — the auditable answer to "which synthetic
 * moment produced this screen?" without bolting a batch column onto every domain table.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ops');

        if (! Schema::hasTable('ops.demo_refresh_runs')) {
            Schema::create('ops.demo_refresh_runs', function (Blueprint $table) {
                $table->uuid('refresh_id')->primary();
                $table->string('scenario_key', 80);
                $table->string('seed_version', 80)->nullable();
                $table->timestampTz('anchor_at');
                $table->timestampTz('window_start_at');
                $table->timestampTz('window_end_at');
                $table->timestampTz('started_at');
                $table->timestampTz('completed_at')->nullable();
                $table->string('status', 20)->default('running'); // running|passed|failed
                $table->jsonb('domain_results')->default(DB::raw("'{}'::jsonb"));
                $table->jsonb('invariant_results')->default(DB::raw("'{}'::jsonb"));
                $table->text('error_summary')->nullable();
                $table->timestampsTz();

                $table->index(['status', 'anchor_at']);
                $table->index('anchor_at');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('ops.demo_refresh_runs');
    }
};
