<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F4 (Staffing Alignment Wizard write API): the wizard is a multi-request,
 * server-stateful flow — POST /imports stages + resolves, GET /imports/{run} reads
 * the buckets back, PATCH .../reviews records a per-member decision, and
 * POST .../commit writes the approved assignments. That review loop needs the
 * staged snapshot (each person's resolver proposals, bucket, and the source record
 * fields commit needs — fte / coverage / facility) to survive between requests.
 *
 * The orchestrator's in-memory ImportResult already persists staff_members and the
 * run's counts; this adds one `staged` jsonb column to hold the per-member staging
 * payload + review decisions on the run row, mirroring the existing `counts` jsonb
 * precedent (App\Services\Staffing\StaffImportStore reads/writes it). Additive and
 * non-breaking — the CLI sync path (deployment:staffing-sync) never reads it.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§7, §8)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE hosp_org.staff_import_runs
                ADD COLUMN IF NOT EXISTS staged jsonb NOT NULL DEFAULT '{}'::jsonb;

            COMMENT ON COLUMN hosp_org.staff_import_runs.staged IS
                'Phase F4 wizard staging snapshot: {facility_key, items:[{staff_member_id, bucket, proposed[], record, decision}]}. Read/written by StaffImportStore so the review->commit loop is stateless across requests.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE hosp_org.staff_import_runs DROP COLUMN IF EXISTS staged;
        SQL);
    }
};
