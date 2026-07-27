<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLOW-4D plan §8 Phase A2 (finding CF-2) — the per-case conformance cache.
 * RefreshArenaConformance now asks the sidecar for per_case verdicts and lands
 * them here; the 4D Navigator's adherence surface reads THIS table only (the
 * /cockpit/snapshot discipline: browser reads never trigger a mining run).
 * case_oid is the de-identified OCEL case object id (enc-<hash12> /
 * patient-<hash12> / orcase-<id>) — no PHI, additive, droppable.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS arena');

        if (! Schema::hasTable('arena.case_conformance')) {
            Schema::create('arena.case_conformance', function (Blueprint $table) {
                $table->id();
                $table->string('case_oid', 160);
                $table->string('pathway', 80);
                $table->unsignedInteger('pathway_version')->default(1);
                $table->boolean('conformant');
                $table->jsonb('deviations')->default(DB::raw("'[]'::jsonb"));
                $table->jsonb('activity_timeline')->default(DB::raw("'{}'::jsonb"));
                $table->timestampTz('computed_at');
                $table->timestamps();

                $table->unique(['case_oid', 'pathway']);
                $table->index('computed_at');
            });
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('arena.case_conformance');
    }
};
