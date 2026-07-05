<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X (X3 last mile). The cockpit-facing cache of care-pathway
 * conformance rates. RefreshArenaConformance writes the latest per-pathway rate
 * here (keyed by the cockpit metric key it feeds); MaterializedMetricsReader
 * unions it into the snapshot value map, so a conformance deviation bands through
 * the ONE StatusEngine and rides the existing AlertEngine ticker — no bespoke
 * alerting path. Keeping the sidecar call on its own cadence (not the per-minute
 * snapshot) is why this cache exists.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS arena');

        if (! Schema::hasTable('arena.conformance_signals')) {
            Schema::create('arena.conformance_signals', function (Blueprint $table) {
                $table->id();
                $table->string('metric_key', 160)->unique();  // the cockpit metric this feeds
                $table->string('pathway', 80);
                $table->decimal('value', 8, 2);                // conformance rate as a percentage
                $table->unsignedInteger('cases')->default(0);
                $table->unsignedInteger('deviant')->default(0);
                $table->jsonb('deviations')->default(DB::raw("'[]'::jsonb"));
                $table->timestampTz('computed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('arena.conformance_signals');
    }
};
