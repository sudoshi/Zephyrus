<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 of the Service Line / Location Deployment Taxonomy: the Staffing
 * Alignment layer. Adds the role taxonomy (hosp_ref.staff_roles) plus the
 * operational staff-assignment graph (hosp_org.staff_*), which resolves each
 * staff member to facility x service line x role x unit with confidence,
 * evidence, and a human-review trail.
 *
 * ADDITIVE and non-breaking, and — critically — it never touches the protected
 * auth system (.claude/rules/auth-system.md). prod.users.role remains the auth
 * role of record; this layer links to prod.users by a *soft* bigint reference
 * (user_id / initiated_by / decided_by / reviewer_id / created_by are plain
 * bigints, NOT enforced FKs) so nothing here can cascade into or constrain the
 * accounts table. Provisioning onto an account is a separate, guarded,
 * additive-only step (App\Services\Staffing\StaffProvisioningService).
 *
 * Follows the raw-SQL DB::unprepared idiom of 2026_07_04_000110 / 2026_06_25_000010:
 * schema-qualified names, CREATE SCHEMA / TABLE / INDEX IF NOT EXISTS. The
 * composite natural key on staff_assignments uses a partial/COALESCE UNIQUE INDEX
 * rather than an inline table UNIQUE (Postgres rejects expressions in a table
 * UNIQUE constraint — the same trap Phase 2's bridge migration hit).
 *
 * Depends on Phase 0 (hosp_ref.service_lines, hosp_ref.programs) and Phase 1
 * (hosp_org.organizations, hosp_org.facilities) having run first.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§3, Phase 7)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS hosp_ref;
            CREATE SCHEMA IF NOT EXISTS hosp_org;

            -- Role taxonomy (reference; authored in config/hospital/staff-roles.php, projected by deployment:seed-staff-roles).
            CREATE TABLE IF NOT EXISTS hosp_ref.staff_roles (
                role_code               text PRIMARY KEY,
                display_name            text NOT NULL,
                role_category           text NOT NULL,             -- physician|apn_pa|nursing|allied_health|ancillary|support|leadership
                is_provider             boolean NOT NULL DEFAULT false,
                is_nursing              boolean NOT NULL DEFAULT false,
                is_clinical             boolean NOT NULL DEFAULT true,
                is_regulated            boolean NOT NULL DEFAULT false,  -- trauma/transplant attending etc. — never auto-approved
                default_workflow        text,                      -- rtdc|ed|periop|emergency|improvement|command|none
                default_app_permissions text[]  NOT NULL DEFAULT '{}',
                sort_order              integer NOT NULL DEFAULT 100,
                metadata                jsonb   NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_staff_roles_category
                ON hosp_ref.staff_roles(role_category);

            -- Connector configuration for external staffing systems. Reuses integration.sources
            -- for transport + secrets (integration_source_id is a soft reference into
            -- integration.sources(source_id) — secrets are NEVER duplicated here).
            CREATE TABLE IF NOT EXISTS hosp_org.staffing_sources (
                staffing_source_id    bigserial PRIMARY KEY,
                organization_id       bigint REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
                integration_source_id bigint,                      -- soft ref -> integration.sources(source_id)
                source_key            text UNIQUE NOT NULL,        -- WORKDAY_PROD|QGENDA|AMION|EPIC_PROVIDER|ENTRA_SCIM|CSV_UPLOAD
                display_name          text,
                connector_type        text NOT NULL,               -- hris|scheduling|credentialing|identity|ehr_master|on_call|manual
                transport             text NOT NULL,               -- rest_api|sftp|scim|hl7_mfn|fhir_practitioner|db_view|file_upload
                mapping_template      jsonb NOT NULL DEFAULT '{}'::jsonb,  -- saved source-field -> canonical-field mapping
                sync_schedule         text,                        -- cron expr; null = manual only
                is_active             boolean NOT NULL DEFAULT true,
                last_synced_at        timestamptz,
                metadata              jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            -- One wizard / import batch (staged -> resolved -> in_review -> committed).
            CREATE TABLE IF NOT EXISTS hosp_org.staff_import_runs (
                staff_import_run_id bigserial PRIMARY KEY,
                staffing_source_id  bigint NOT NULL REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE,
                status              text NOT NULL DEFAULT 'staged',
                mapping_snapshot    jsonb NOT NULL DEFAULT '{}'::jsonb,
                counts              jsonb NOT NULL DEFAULT '{}'::jsonb,  -- {new,updated,departed,auto_approved,needs_review,conflicts,unmatched}
                dry_run             boolean NOT NULL DEFAULT true,
                initiated_by        bigint,                          -- soft ref -> prod.users.id (admin who ran the wizard)
                started_at          timestamptz NOT NULL DEFAULT now(),
                completed_at        timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_import_runs_status_chk
                    CHECK (status IN ('staged','resolved','in_review','committed','failed','cancelled'))
            );

            CREATE INDEX IF NOT EXISTS idx_staff_import_runs_source
                ON hosp_org.staff_import_runs(staffing_source_id, status);

            -- Canonical staff identity (PII-minimized; links to an app account when one exists).
            CREATE TABLE IF NOT EXISTS hosp_org.staff_members (
                staff_member_id   bigserial PRIMARY KEY,
                staff_key         text UNIQUE NOT NULL,             -- stable dedupe key (source_system + external_id)
                source_system     text NOT NULL,
                external_id       text NOT NULL,
                user_id           bigint,                           -- soft ref -> prod.users.id (app account), never required
                npi               text,
                license_no        text,
                display_name      text,
                email             text,
                employee_type     text,                             -- employed|contracted|locum|resident|fellow|student|agency
                employment_status text,                             -- active|leave|terminated|pending
                is_active         boolean NOT NULL DEFAULT true,
                first_seen_at     timestamptz NOT NULL DEFAULT now(),
                last_seen_at      timestamptz NOT NULL DEFAULT now(),
                metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (source_system, external_id)
            );

            CREATE INDEX IF NOT EXISTS idx_staff_members_user ON hosp_org.staff_members(user_id);
            CREATE INDEX IF NOT EXISTS idx_staff_members_npi  ON hosp_org.staff_members(npi);
            CREATE INDEX IF NOT EXISTS idx_staff_members_email ON hosp_org.staff_members(email);

            -- The core output: staff -> facility x service line x role x unit (multi-membership, effective-dated).
            CREATE TABLE IF NOT EXISTS hosp_org.staff_assignments (
                staff_assignment_id bigserial PRIMARY KEY,
                staff_member_id   bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
                facility_key      text NOT NULL,
                service_line_code text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
                role_code         text NOT NULL REFERENCES hosp_ref.staff_roles(role_code),
                program_code      text REFERENCES hosp_ref.programs(program_code),
                unit_id           bigint REFERENCES prod.units(unit_id) ON DELETE SET NULL,
                primary_flag      boolean NOT NULL DEFAULT false,
                coverage_model    text,                             -- in_house|on_call|tele|daytime|float
                fte               numeric(4,2),
                confidence        numeric(5,4),                     -- resolution confidence [0,1]
                resolution_source text,                             -- override|rule|heuristic|imported
                review_status     text NOT NULL DEFAULT 'assumed',  -- assumed|source_verified|client_verified|unknown
                evidence          jsonb NOT NULL DEFAULT '{}'::jsonb,
                effective_start   date,
                effective_end     date,
                is_active         boolean NOT NULL DEFAULT true,
                decided_by        bigint,                           -- soft ref -> prod.users.id (reviewer)
                decided_at        timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_assignments_review_chk
                    CHECK (review_status IN ('assumed','source_verified','client_verified','unknown'))
            );

            -- Composite natural key. Postgres rejects an expression (COALESCE) in an inline
            -- table UNIQUE, so this is a UNIQUE INDEX (same idiom as the Phase 2 bridge).
            CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_assignment_natural
                ON hosp_org.staff_assignments(staff_member_id, facility_key, service_line_code, role_code, COALESCE(unit_id, 0));

            -- Exactly one primary membership per person.
            CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_one_primary
                ON hosp_org.staff_assignments(staff_member_id) WHERE primary_flag;

            CREATE INDEX IF NOT EXISTS idx_staff_assignments_facility_sl
                ON hosp_org.staff_assignments(facility_key, service_line_code, role_code);

            CREATE INDEX IF NOT EXISTS idx_staff_assignments_unit
                ON hosp_org.staff_assignments(unit_id);

            -- Deterministic crosswalk rules the resolver applies (learned from reviewer decisions).
            CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_rules (
                staff_mapping_rule_id bigserial PRIMARY KEY,
                staffing_source_id bigint REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE, -- null = global
                match_field        text NOT NULL,                 -- cost_center|department|specialty|job_code|job_title|home_unit
                match_operator     text NOT NULL DEFAULT 'equals',-- equals|prefix|contains|regex
                match_value        text NOT NULL,
                target_service_line_code text REFERENCES hosp_ref.service_lines(service_line_code),
                target_role_code   text REFERENCES hosp_ref.staff_roles(role_code),
                target_unit_hint   text,
                priority           integer NOT NULL DEFAULT 100,  -- lower runs first
                confidence         numeric(5,4) NOT NULL DEFAULT 0.90,
                is_active          boolean NOT NULL DEFAULT true,
                created_by         bigint,                        -- soft ref -> prod.users.id
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_staff_rules_lookup
                ON hosp_org.staff_mapping_rules(match_field, is_active, priority);

            -- Human decision log (audit + rule promotion).
            CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_reviews (
                staff_mapping_review_id bigserial PRIMARY KEY,
                staff_import_run_id bigint NOT NULL REFERENCES hosp_org.staff_import_runs(staff_import_run_id) ON DELETE CASCADE,
                staff_member_id     bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
                proposed            jsonb NOT NULL DEFAULT '{}'::jsonb,
                final               jsonb NOT NULL DEFAULT '{}'::jsonb,
                action              text NOT NULL,                 -- accept|edit|split|defer|reject|deactivate
                reviewer_id         bigint,                        -- soft ref -> prod.users.id
                note                text,
                promoted_to_rule_id bigint REFERENCES hosp_org.staff_mapping_rules(staff_mapping_rule_id),
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_staff_reviews_run
                ON hosp_org.staff_mapping_reviews(staff_import_run_id);

            COMMENT ON TABLE hosp_ref.staff_roles IS
                'Phase 7 role taxonomy: operational staff roles (intensivist, charge_nurse, ...), config-authored and projected by deployment:seed-staff-roles.';

            COMMENT ON TABLE hosp_org.staff_assignments IS
                'Phase 7 operational assignment graph: staff -> facility x service line x role x unit with confidence + evidence. Additive to prod.users; never the auth role of record.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS hosp_org.staff_mapping_reviews;
            DROP TABLE IF EXISTS hosp_org.staff_mapping_rules;
            DROP TABLE IF EXISTS hosp_org.staff_assignments;
            DROP TABLE IF EXISTS hosp_org.staff_members;
            DROP TABLE IF EXISTS hosp_org.staff_import_runs;
            DROP TABLE IF EXISTS hosp_org.staffing_sources;
            DROP TABLE IF EXISTS hosp_ref.staff_roles;
        SQL);
    }
};
