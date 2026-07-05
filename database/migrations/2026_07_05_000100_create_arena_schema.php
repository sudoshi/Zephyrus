<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X (X1). The Arena orchestrator's result cache.
 *
 * The Study UI reads a cache, never a live mining run (§X.4.2), mirroring the
 * cockpit's /snapshot pattern: the PHP orchestrator posts the de-identified OCEL
 * log to the OCPM sidecar, and stashes the discovered map here keyed by
 * (scope, object types, min-freq, source signature). A change in the underlying
 * OCEL log changes the source signature, which invalidates the cache naturally.
 *
 * Additive + reversible: the whole Arena serving footprint is this one schema
 * (the mined data itself lives in ocel.*; this only caches sidecar output).
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS arena');

        if (! Schema::hasTable('arena.maps')) {
            Schema::create('arena.maps', function (Blueprint $table) {
                $table->id();
                $table->string('cache_key', 80)->unique();     // sha1(scope|types|min_freq|signature)
                $table->string('scope', 120)->default('house');
                $table->jsonb('object_types')->nullable();      // null = all types
                $table->unsignedInteger('min_freq')->default(1);
                $table->string('source_signature', 80);         // ocel log fingerprint the map was mined from
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb")); // the discover response
                $table->unsignedInteger('node_count')->default(0);
                $table->unsignedInteger('edge_count')->default(0);
                $table->timestampTz('mined_at');
                $table->timestamps();

                $table->index(['scope', 'mined_at'], 'arena_maps_scope_time_idx');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropSchema('arena');
    }
};
