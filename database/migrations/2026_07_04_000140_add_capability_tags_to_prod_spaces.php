<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3: capability tags on the operational spaces (prod.beds, prod.rooms) so the
 * live app can answer "which beds can take a ventilated / negative-pressure / burn
 * patient?" without joining the physical model.
 *
 * Additive and non-breaking: new text[] columns default to '{}' with a GIN index for
 * ANY()/@> membership queries. Tag values are validated against hosp_ref.capability_tags
 * in the application layer (deployment:audit-tags), not a hard FK — Postgres cannot FK
 * an array element to a lookup without a trigger, kept intentionally lightweight.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.4, Phase 3)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE prod.beds  ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';
            ALTER TABLE prod.rooms ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';

            CREATE INDEX IF NOT EXISTS idx_prod_beds_capability_tags
                ON prod.beds USING gin(capability_tags);
            CREATE INDEX IF NOT EXISTS idx_prod_rooms_capability_tags
                ON prod.rooms USING gin(capability_tags);
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS prod.idx_prod_rooms_capability_tags;
            DROP INDEX IF EXISTS prod.idx_prod_beds_capability_tags;
            ALTER TABLE prod.rooms DROP COLUMN IF EXISTS capability_tags;
            ALTER TABLE prod.beds  DROP COLUMN IF EXISTS capability_tags;
        SQL);
    }
};
