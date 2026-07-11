<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation, seam 3: close the barrier → action
 * loop. Until now the governance chain (Recommendation → OperationalAction →
 * Approval → prod.pdsa_cycles) had NO link back to the prod.barriers row that
 * motivated a corrective action, so an approved PDSA cycle could not be traced to
 * the barrier it answers. Two soft (nullable, un-constrained) pointers wire both
 * ends:
 *
 *   ops.recommendations.barrier_id  — which barrier a corrective-action draft targets
 *                                     (threaded from the copilot draft params).
 *   prod.barriers.pdsa_cycle_id     — the barrier's resolution cycle, stamped by the
 *                                     P3 executor when the draft is approved.
 *
 * Both are plain indexed bigint columns (no DB FK), mirroring how owner_user_id was
 * added to prod.barriers in 2026_06_27_000110 — the referents live in different
 * schemas, the rows are only ever soft-deleted (is_deleted), and the executor
 * enforces the link in application code. Purely additive + idempotent so it is safe
 * on the canonical DB.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (Schema::hasTable('ops.recommendations') && ! Schema::hasColumn('ops.recommendations', 'barrier_id')) {
            Schema::table('ops.recommendations', function (Blueprint $table) {
                $table->unsignedBigInteger('barrier_id')->nullable()->after('scope_key');
                $table->index('barrier_id', 'idx_recommendations_barrier');
            });
        }

        if (Schema::hasTable('prod.barriers') && ! Schema::hasColumn('prod.barriers', 'pdsa_cycle_id')) {
            Schema::table('prod.barriers', function (Blueprint $table) {
                $table->unsignedBigInteger('pdsa_cycle_id')->nullable()->after('resolved_at');
                $table->index('pdsa_cycle_id', 'idx_barriers_pdsa_cycle');
            });
        }
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        if (Schema::hasColumn('ops.recommendations', 'barrier_id')) {
            Schema::table('ops.recommendations', function (Blueprint $table) {
                $table->dropIndex('idx_recommendations_barrier');
                $table->dropColumn('barrier_id');
            });
        }

        if (Schema::hasColumn('prod.barriers', 'pdsa_cycle_id')) {
            Schema::table('prod.barriers', function (Blueprint $table) {
                $table->dropIndex('idx_barriers_pdsa_cycle');
                $table->dropColumn('pdsa_cycle_id');
            });
        }
    }
};
