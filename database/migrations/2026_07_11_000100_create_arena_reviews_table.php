<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. The 48-Hour Flow Review's result
 * cache (sibling to arena.maps).
 *
 * FlowReviewService::run() folds one OCPM mining pass (discover + performance +
 * conformance) together with the open prod.barriers into ONE ranked artifact and
 * stashes it here, keyed by (window ref, source signature). GET /api/arena/review
 * is then a pure cache read; POST /review/run (and the scheduled command)
 * rebuild it — the "trigger = both" decision. A change in the underlying OCEL log
 * changes the source signature, so a re-projection naturally supersedes a review.
 *
 * Additive + reversible: the review artifact is a disposable projection of the
 * OCEL log + prod.barriers; nothing here is a system of record.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS arena');

        if (! Schema::hasTable('arena.reviews')) {
            Schema::create('arena.reviews', function (Blueprint $table) {
                $table->id();
                $table->string('cache_key', 80)->unique();      // sha1(window_ref|signature)
                $table->string('window_ref', 60)->index();       // the window's `to` ISO — the review's identity
                $table->timestampTz('window_from');
                $table->timestampTz('window_to');
                $table->string('source_signature', 80);          // ocel log fingerprint the review was built from
                $table->jsonb('payload')->default(DB::raw("'{}'::jsonb")); // the available:true artifact
                $table->unsignedInteger('barrier_count')->default(0);
                $table->timestampTz('generated_at');
                $table->timestamps();

                // "Latest review" is the hot read path (GET /review with no window).
                $table->index('generated_at', 'arena_reviews_generated_idx');
            });
        }
    }

    public function down(): void
    {
        // Drop only this table — arena.maps shares the schema, so leave it be.
        Schema::dropIfExists('arena.reviews');
    }
};
