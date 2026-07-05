<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer 4 (physical location mapping): a many-to-many bridge so one physical space
 * (a CT scanner, a hybrid OR, a flex ICU bed) can serve several service lines at
 * once, plus the facility-space extension columns (location_role, program_code,
 * capability_tags, facility_key) that tie a space to Layer 1 geography and the
 * capability vocabulary.
 *
 * Additive and non-breaking:
 *  - The new facility_spaces columns are nullable / defaulted, so existing rows and
 *    every existing writer keep working untouched.
 *  - facility_space_service_lines is a brand-new empty table, so its FKs onto
 *    hosp_ref.service_lines / .programs / .location_roles land validated immediately
 *    (same reasoning as the Phase 1 hosp_org tables).
 *  - The FKs on facility_spaces.service_line_code and .location_role are deliberately
 *    NOT added here — facility_spaces already holds data in real deployments, so they
 *    are added NOT VALID -> VALIDATE only after normalization in
 *    2026_07_04_000160_validate_facility_space_service_line_fk.php.
 *
 * Depends on 2026_07_04_000110 (hosp_ref registry + vocab) and the existing
 * 2026_06_25_000010 (hosp_space.facility_spaces) having run first.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.3, Phase 2)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS location_role   text;
            ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS program_code    text;
            ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';
            ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS facility_key    text;

            CREATE INDEX IF NOT EXISTS idx_facility_spaces_facility_key
                ON hosp_space.facility_spaces(facility_key);
            CREATE INDEX IF NOT EXISTS idx_facility_spaces_capability_tags
                ON hosp_space.facility_spaces USING gin(capability_tags);

            CREATE TABLE IF NOT EXISTS hosp_space.facility_space_service_lines (
                facility_space_service_line_id bigserial PRIMARY KEY,
                facility_space_id bigint NOT NULL
                    REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE CASCADE,
                service_line_code text NOT NULL
                    REFERENCES hosp_ref.service_lines(service_line_code),
                program_code      text REFERENCES hosp_ref.programs(program_code),
                location_role     text REFERENCES hosp_ref.location_roles(code),
                primary_flag      boolean NOT NULL DEFAULT false,
                capability_tags   text[] NOT NULL DEFAULT '{}',
                effective_start   date,
                effective_end     date,
                evidence          jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            -- One row per (space, service line, program). program_code NULL collapses to ''
            -- so a space cannot have two "no specific program" rows for the same line.
            -- Postgres forbids expressions in a UNIQUE table constraint, so this is a unique index.
            CREATE UNIQUE INDEX IF NOT EXISTS uq_fssl_space_line_program
                ON hosp_space.facility_space_service_lines(facility_space_id, service_line_code, COALESCE(program_code, ''));
            CREATE UNIQUE INDEX IF NOT EXISTS uq_fssl_one_primary
                ON hosp_space.facility_space_service_lines(facility_space_id) WHERE primary_flag;
            CREATE INDEX IF NOT EXISTS idx_fssl_service_line
                ON hosp_space.facility_space_service_lines(service_line_code);

            COMMENT ON TABLE hosp_space.facility_space_service_lines IS
                'Layer 4 many-to-many: one physical space can serve several service lines (shared CT, hybrid OR, flex bed); exactly one primary_flag row per space.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS hosp_space.facility_space_service_lines;

            DROP INDEX IF EXISTS hosp_space.idx_facility_spaces_capability_tags;
            DROP INDEX IF EXISTS hosp_space.idx_facility_spaces_facility_key;

            ALTER TABLE hosp_space.facility_spaces DROP COLUMN IF EXISTS facility_key;
            ALTER TABLE hosp_space.facility_spaces DROP COLUMN IF EXISTS capability_tags;
            ALTER TABLE hosp_space.facility_spaces DROP COLUMN IF EXISTS program_code;
            ALTER TABLE hosp_space.facility_spaces DROP COLUMN IF EXISTS location_role;
        SQL);
    }
};
