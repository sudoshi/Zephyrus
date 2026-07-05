<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 FK hardening. facility_spaces predates the service-line registry and holds
 * data in real deployments, so its service_line_code / location_role FKs onto
 * hosp_ref cannot be added inline — they are added NOT VALID, then VALIDATEd, so an
 * existing table with a bad value fails loudly at deploy rather than silently.
 *
 * DEPLOY RUNBOOK — the FK cannot validate against un-normalized data or an empty
 * registry, so this migration MUST run after:
 *   1. php artisan deployment:seed-registry
 *   2. php artisan deployment:normalize-service-lines
 *   3. php artisan deployment:backfill-space-service-lines
 * (On a fresh migrate:fresh, facility_spaces is empty, so VALIDATE is trivially
 * satisfied and the FK simply becomes active for subsequent writes.)
 *
 * A defensive inline fold of the three known legacy aliases runs first so a partially
 * normalized table still validates; it is a harmless no-op when the codes are already
 * canonical.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.3/§5, Phase 2)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Defensive: fold the three known legacy aliases to canonical before validating.
            UPDATE hosp_space.facility_spaces SET service_line_code = 'trauma_acute_care_surgery' WHERE service_line_code = 'trauma_surgery';
            UPDATE hosp_space.facility_spaces SET service_line_code = 'hospital_medicine'         WHERE service_line_code = 'medicine';
            UPDATE hosp_space.facility_spaces SET service_line_code = 'cardiovascular'            WHERE service_line_code = 'cardiology';

            ALTER TABLE hosp_space.facility_spaces
                DROP CONSTRAINT IF EXISTS facility_spaces_service_line_fk;
            ALTER TABLE hosp_space.facility_spaces
                ADD CONSTRAINT facility_spaces_service_line_fk
                FOREIGN KEY (service_line_code) REFERENCES hosp_ref.service_lines(service_line_code) NOT VALID;
            ALTER TABLE hosp_space.facility_spaces
                VALIDATE CONSTRAINT facility_spaces_service_line_fk;

            ALTER TABLE hosp_space.facility_spaces
                DROP CONSTRAINT IF EXISTS facility_spaces_location_role_fk;
            ALTER TABLE hosp_space.facility_spaces
                ADD CONSTRAINT facility_spaces_location_role_fk
                FOREIGN KEY (location_role) REFERENCES hosp_ref.location_roles(code) NOT VALID;
            ALTER TABLE hosp_space.facility_spaces
                VALIDATE CONSTRAINT facility_spaces_location_role_fk;
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE hosp_space.facility_spaces DROP CONSTRAINT IF EXISTS facility_spaces_location_role_fk;
            ALTER TABLE hosp_space.facility_spaces DROP CONSTRAINT IF EXISTS facility_spaces_service_line_fk;
        SQL);
    }
};
