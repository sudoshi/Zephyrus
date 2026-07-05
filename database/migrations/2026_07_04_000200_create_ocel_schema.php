<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X (Object-Centric Process Intelligence), phase X0.
 *
 * The additive OCEL 2.0 relational store. This is a *projection target*: the
 * OcelProjector (a read-side consumer, the OCPM analogue of SnapshotBuilder)
 * writes the object-centric event log here from assets already on `main`
 * (flow_core.flow_events, prod.care_journey_milestones, prod.transport_requests).
 *
 * Discipline (Part X §X.10.2): additive + reversible (the whole subsystem is
 * removable by dropping this one schema), PHI-safe by construction (object ids
 * for patients/encounters are hashed at projection time — no names/MRNs/notes
 * land here), and it never touches prod.* destructively. Cross-table FKs are
 * intentionally omitted so an idempotent partial re-projection can never wedge
 * on ordering (Part X §X.3, risk "watermark gaps"); integrity is carried by
 * deterministic ids + unique constraints instead.
 *
 * Column names avoid SQL reserved words (event_time, not "timestamp"); the
 * canonical OCEL 2.0 shape (§X.3.1) is preserved semantically and exports
 * losslessly to OCEL-JSON for pm4py / ocpa.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ocel');

        // Reference: the versioned object-type catalog (§X.2.2). Soft-referenced
        // by ocel.objects.type (no hard FK — the catalog grows additively as new
        // object types are ratified, and projection must never fail on a miss).
        if (! Schema::hasTable('ocel.object_types')) {
            Schema::create('ocel.object_types', function (Blueprint $table) {
                $table->string('type', 80)->primary();
                $table->string('lens', 40)->nullable();
                $table->string('source_system', 120)->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->jsonb('attrs_schema')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();
            });
        }

        // Reference: the activity catalog (§X.2.3). Soft-referenced by
        // ocel.events.activity.
        if (! Schema::hasTable('ocel.activities')) {
            Schema::create('ocel.activities', function (Blueprint $table) {
                $table->string('activity', 120)->primary();
                $table->string('domain', 60)->nullable();
                $table->timestamps();
            });
        }

        // Objects: a uniquely identified instance of an object type, with
        // time-varying attributes carried in ocel.object_changes.
        if (! Schema::hasTable('ocel.objects')) {
            Schema::create('ocel.objects', function (Blueprint $table) {
                $table->string('id', 160)->primary();
                $table->string('type', 80);
                $table->jsonb('attrs')->default(DB::raw("'{}'::jsonb"));
                $table->timestamps();

                $table->index('type', 'ocel_objects_type_idx');
            });
        }

        // Events: an execution of an activity at a timestamp. source_system +
        // source_ref carry provenance so the nightly reconcile can diff
        // projected counts against the prod.*/flow_core.* source counts
        // (§X.3.3), and so a re-projection is an idempotent upsert on id.
        if (! Schema::hasTable('ocel.events')) {
            Schema::create('ocel.events', function (Blueprint $table) {
                $table->string('id', 160)->primary();
                $table->string('activity', 120);
                $table->timestampTz('event_time');
                $table->jsonb('attrs')->default(DB::raw("'{}'::jsonb"));
                $table->string('source_system', 120)->nullable();
                $table->string('source_ref', 160)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('activity', 'ocel_events_activity_idx');
                $table->index('event_time', 'ocel_events_time_idx');
                $table->index(['source_system', 'source_ref'], 'ocel_events_source_idx');
            });
        }

        // E2O: links an event to an object it acted on/with — qualified
        // (actor / subject / resource / target / location …). §X.2.1.
        if (! Schema::hasTable('ocel.event_object')) {
            Schema::create('ocel.event_object', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 160);
                $table->string('object_id', 160);
                $table->string('qualifier', 60)->nullable();

                $table->unique(['event_id', 'object_id', 'qualifier'], 'ocel_e2o_unique');
                $table->index('object_id', 'ocel_e2o_object_idx');
            });
        }

        // O2O: links two objects without a shared event — qualified
        // (Encounter *occupies* Bed; Bed *in* Unit; Encounter *of* Patient).
        if (! Schema::hasTable('ocel.object_object')) {
            Schema::create('ocel.object_object', function (Blueprint $table) {
                $table->id();
                $table->string('from_id', 160);
                $table->string('to_id', 160);
                $table->string('qualifier', 60)->nullable();

                $table->unique(['from_id', 'to_id', 'qualifier'], 'ocel_o2o_unique');
                $table->index('to_id', 'ocel_o2o_to_idx');
            });
        }

        // Time-varying object attributes (bed status dirty→cleaning→ready→
        // occupied, OR phase) — the OCEL 2.0 feature OCEL 1.0 could not carry.
        if (! Schema::hasTable('ocel.object_changes')) {
            Schema::create('ocel.object_changes', function (Blueprint $table) {
                $table->id();
                $table->string('object_id', 160);
                $table->string('attr', 80);
                $table->jsonb('value')->nullable();
                $table->timestampTz('changed_at');

                $table->index(['object_id', 'changed_at'], 'ocel_object_changes_idx');
            });
        }
    }

    public function down(): void
    {
        // Additive + reversible: the entire Part X data footprint is one schema.
        $this->safeDropSchema('ocel');
    }
};
