<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ancillary_orders_reconciliation_key_idx (2026_07_11_000400) carries a
        // WHERE jsonb_exists(metadata, 'reconciliation_key') predicate that the
        // planner cannot prove from the metadata->>'reconciliation_key' = ?
        // clause every reconciliation lookup emits, so it is never chosen and
        // those probes filter a department-index range row-by-row (D1 finding 2,
        // docs/audits/D1-ancillary-refresh-profile-2026-07-25.md). An IS NOT
        // NULL predicate on the indexed expression is provable from the strict
        // ->> = ? operator, making the probes point index scans.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_orders_reconciliation_lookup_idx
            ON prod.ancillary_orders (department, (metadata->>'reconciliation_key'))
            WHERE (metadata->>'reconciliation_key') IS NOT NULL
        SQL);

        // canonical_event_id is an unindexed FK: the owned-row provenance
        // delete drives on canonical_event_id = ANY(...) but could only use
        // provenance_target_idx's (target_schema, target_table) prefix,
        // filtering ~635 unrelated rows per call (D1 finding 4).
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS provenance_canonical_event_idx
            ON integration.provenance_records (canonical_event_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS prod.ancillary_orders_reconciliation_lookup_idx');
        DB::statement('DROP INDEX IF EXISTS integration.provenance_canonical_event_idx');
    }
};
