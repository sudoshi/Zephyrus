<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ancillary_orders_reconciliation_key_idx (2026_07_11_000400) is
        // provably dead: its partial predicate jsonb_exists(metadata,
        // 'reconciliation_key') is unprovable from the metadata->>'...' = ?
        // clause every caller issues, so the planner can never choose it —
        // seq scans persisted even under enable_seqscan=off. Superseded by
        // ancillary_orders_reconciliation_lookup_idx (2026_07_25_000100),
        // whose IS NOT NULL predicate is provable from the strict ->>
        // operator. Prod pg_stat_user_indexes at drop time: 0 scans on this
        // index vs 2,303 on the successor. Drop approved [SU] 2026-07-27.
        DB::statement('DROP INDEX IF EXISTS prod.ancillary_orders_reconciliation_key_idx');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS ancillary_orders_reconciliation_key_idx
                ON prod.ancillary_orders (department, (metadata->>'reconciliation_key'))
                WHERE jsonb_exists(metadata, 'reconciliation_key')
        SQL);
    }
};
