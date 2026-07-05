<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 — Part X (X0), follow-up. Makes ocel.object_changes idempotent
 * under re-projection: without a unique key, insertOrIgnore had nothing to
 * conflict on, so every OcelProjector run appended the full change history again
 * (bed status / OR phase). A deterministic object-attribute change is uniquely
 * identified by (object_id, attr, changed_at); constrain on that so re-runs
 * converge. Additive; dedupes any pre-existing duplicates first.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('ocel.object_changes')) {
            return;
        }

        // Collapse any duplicates a pre-constraint projection produced, keeping
        // the earliest row of each identical change.
        DB::statement('
            DELETE FROM ocel.object_changes a
            USING ocel.object_changes b
            WHERE a.id > b.id
              AND a.object_id = b.object_id
              AND a.attr = b.attr
              AND a.changed_at = b.changed_at
        ');

        if (! $this->constraintExists('ocel.object_changes', 'ocel_object_changes_unique')) {
            Schema::table('ocel.object_changes', function (Blueprint $table) {
                $table->unique(['object_id', 'attr', 'changed_at'], 'ocel_object_changes_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ocel.object_changes') && $this->constraintExists('ocel.object_changes', 'ocel_object_changes_unique')) {
            Schema::table('ocel.object_changes', function (Blueprint $table) {
                $table->dropUnique('ocel_object_changes_unique');
            });
        }
    }
};
