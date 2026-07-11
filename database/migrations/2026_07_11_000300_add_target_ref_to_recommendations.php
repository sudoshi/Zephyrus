<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation, P4: link a governed corrective
 * action to the REVIEW barrier it answers. Seam 3's `barrier_id` (numeric) links
 * only the human kind (a real prod.barriers row); the review also ranks flow
 * (sync-wait) and care (conformance) barriers, which are engine-derived and have
 * no prod.barriers row. `target_ref` is the review-barrier id verbatim
 * (`flow-<src>-<tgt>` / `care-<slug>` / `human-<id>`), so FlowReviewService can
 * fold each barrier's pending draft + prior outcome back onto it for every kind.
 *
 * A plain nullable indexed string (no FK — the referent is a synthetic artifact
 * id, not a table row), additive + idempotent, safe on the canonical DB.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (Schema::hasTable('ops.recommendations') && ! Schema::hasColumn('ops.recommendations', 'target_ref')) {
            Schema::table('ops.recommendations', function (Blueprint $table) {
                $table->string('target_ref', 80)->nullable()->after('barrier_id');
                $table->index('target_ref', 'idx_recommendations_target_ref');
            });
        }
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        if (Schema::hasColumn('ops.recommendations', 'target_ref')) {
            Schema::table('ops.recommendations', function (Blueprint $table) {
                $table->dropIndex('idx_recommendations_target_ref');
                $table->dropColumn('target_ref');
            });
        }
    }
};
