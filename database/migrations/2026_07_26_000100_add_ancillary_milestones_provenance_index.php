<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ancillary_milestones.provenance_record_id carries an ON DELETE SET
        // NULL foreign key to integration.provenance_records but no index, so
        // every provenance-record delete pays a referencing-side scan of
        // ancillary_milestones per deleted row inside the RI trigger —
        // EXPLAIN ANALYZE attributes 476 of a 481 ms owned-rows delete to
        // prod_ancillary_milestones_provenance_record_id_foreign (357 calls).
        // The demo refresh deletes provenance in every replace cycle, so this
        // dominates removeOwnedRows despite the driving side being indexed
        // (provenance_canonical_event_idx, 2026_07_25_000100).
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_milestones_provenance_record_idx
            ON prod.ancillary_milestones (provenance_record_id)
            WHERE provenance_record_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prod.ancillary_milestones_provenance_record_idx');
    }
};
