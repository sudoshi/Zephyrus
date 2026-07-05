<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X (X2 cockpit promotion). The cockpit-facing cache of the
 * OPerA object-centric performance signal — the worst object-side wait at a
 * shared hand-off (the synchronization constraint). RefreshArenaPerformance
 * writes the latest value here (keyed by the cockpit metric it feeds);
 * MaterializedMetricsReader unions it into the snapshot value map, so a bottleneck
 * bands through the ONE StatusEngine and rides the existing cockpit exactly like
 * every other flow metric — no bespoke path.
 *
 * Sibling to arena.conformance_signals: same bridge pattern, but performance
 * semantics (a context string + an evidence roll-up of the top hand-offs) rather
 * than pathway/deviation columns. Empty when the Arena is off, so the tile simply
 * doesn't appear (no regression to the existing cockpit).
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS arena');

        if (! Schema::hasTable('arena.performance_signals')) {
            Schema::create('arena.performance_signals', function (Blueprint $table) {
                $table->id();
                $table->string('metric_key', 160)->unique();  // the cockpit metric this feeds
                $table->decimal('value', 10, 2);               // the signal value in the metric's unit (minutes)
                $table->string('context', 200)->nullable();    // human label for the winning hand-off ("Bed at discharge")
                $table->jsonb('evidence')->default(DB::raw("'[]'::jsonb")); // top offending hand-offs for drill/Eddy
                $table->timestampTz('computed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('arena.performance_signals');
    }
};
