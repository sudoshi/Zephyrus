<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer 1 (IDN geography) + Layer 3 (facility-service capability matrix) + the
 * first-class interfacility transfer graph. Creates the hosp_org schema and its
 * five tables (organizations, markets, facilities, facility_service_capabilities,
 * transfer_relationships).
 *
 * Additive and non-breaking: brand-new schema, no collision with existing objects.
 * FKs onto hosp_ref.* land validated because these tables are empty at creation
 * (the NOT VALID -> backfill -> VALIDATE dance is only needed in Phase 2 where
 * facility_spaces already holds data). Depends on 2026_07_04_000110 (hosp_ref
 * registry + vocab) having run first.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.2, Phase 1)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS hosp_org;

            CREATE TABLE IF NOT EXISTS hosp_org.organizations (
                organization_id    bigserial PRIMARY KEY,
                organization_key   text UNIQUE NOT NULL,
                name               text NOT NULL,
                short_name         text,
                kind               text NOT NULL DEFAULT 'idn',
                headquarters_state text,
                metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE IF NOT EXISTS hosp_org.markets (
                market_id       bigserial PRIMARY KEY,
                organization_id bigint NOT NULL REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
                market_key      text NOT NULL,
                name            text NOT NULL,
                region          text,
                state           text,
                metadata        jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT markets_org_key_unique UNIQUE (organization_id, market_key)
            );

            CREATE TABLE IF NOT EXISTS hosp_org.facilities (
                facility_id       bigserial PRIMARY KEY,
                organization_id   bigint NOT NULL REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
                market_id         bigint REFERENCES hosp_org.markets(market_id) ON DELETE SET NULL,
                facility_key      text UNIQUE NOT NULL,
                facility_name     text NOT NULL,
                short_name        text,
                parent_system     text,
                market            text,
                region            text,
                state             text,
                county            text,
                lat               numeric(9,6),
                lng               numeric(9,6),
                idn_role          text NOT NULL REFERENCES hosp_ref.idn_roles(code),
                campus_type       text,
                license_type      text,
                teaching_status   text,
                licensed_beds     integer,
                trauma_level_adult       text,
                trauma_level_pediatric   text,
                stroke_level             text,
                maternal_level           text,
                neonatal_level           text,
                burn_center_status       text,
                transplant_center_status text,
                transplant_programs      text[] NOT NULL DEFAULT '{}',
                pediatric_capability          text,
                behavioral_health_capability  text,
                ambulatory_surgery_capability text,
                home_hospital_capability      text,
                cad_facility_code text,
                review_status     text NOT NULL DEFAULT 'assumed',
                source_evidence   jsonb NOT NULL DEFAULT '{}'::jsonb,
                is_active         boolean NOT NULL DEFAULT true,
                metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT facilities_review_status_chk CHECK (review_status IN ('source_verified','client_verified','assumed','unknown'))
            );

            CREATE INDEX IF NOT EXISTS idx_facilities_org_role ON hosp_org.facilities(organization_id, idn_role);
            CREATE INDEX IF NOT EXISTS idx_facilities_state_county ON hosp_org.facilities(state, county);

            CREATE TABLE IF NOT EXISTS hosp_org.facility_service_capabilities (
                facility_service_capability_id bigserial PRIMARY KEY,
                facility_id        bigint NOT NULL REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
                facility_key       text   NOT NULL,
                service_line_code  text   NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
                capability_level   text   NOT NULL REFERENCES hosp_ref.capability_levels(code),
                programs_present    text[] NOT NULL DEFAULT '{}',
                departments_present text[] NOT NULL DEFAULT '{}',
                coverage_model     text,
                hours              text,
                telehealth_support boolean NOT NULL DEFAULT false,
                transfer_out_targets text[] NOT NULL DEFAULT '{}',
                transfer_in_sources  text[] NOT NULL DEFAULT '{}',
                source_evidence_url  text,
                source_evidence_type text REFERENCES hosp_ref.evidence_classes(code),
                review_status      text NOT NULL DEFAULT 'assumed',
                notes              text,
                effective_start    date,
                effective_end      date,
                metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fsc_facility_service_unique UNIQUE (facility_id, service_line_code),
                CONSTRAINT fsc_review_status_chk CHECK (review_status IN ('source_verified','client_verified','assumed','unknown'))
            );

            CREATE INDEX IF NOT EXISTS idx_fsc_service_capability ON hosp_org.facility_service_capabilities(service_line_code, capability_level);

            CREATE TABLE IF NOT EXISTS hosp_org.transfer_relationships (
                transfer_relationship_id bigserial PRIMARY KEY,
                source_facility_id       bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
                source_facility_key      text,
                destination_facility_id  bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
                destination_facility_key text,
                destination_external_name text,
                service_line_code text REFERENCES hosp_ref.service_lines(service_line_code),
                program_code      text REFERENCES hosp_ref.programs(program_code),
                transfer_reason   text,
                transport_mode    text,
                typical_minutes   integer,
                typical_miles     numeric(7,2),
                direction         text NOT NULL DEFAULT 'out',
                acceptance_constraints text,
                escalation_contact text,
                is_external_partner boolean NOT NULL DEFAULT false,
                review_status     text NOT NULL DEFAULT 'assumed',
                source_evidence   jsonb NOT NULL DEFAULT '{}'::jsonb,
                is_active         boolean NOT NULL DEFAULT true,
                metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT transfer_endpoints_chk CHECK (source_facility_id IS NOT NULL OR destination_facility_id IS NOT NULL)
            );

            CREATE INDEX IF NOT EXISTS idx_transfer_service_line ON hosp_org.transfer_relationships(service_line_code, direction);

            COMMENT ON TABLE hosp_org.organizations IS
                'Layer 1: the IDN / health-system that owns markets and facilities.';
            COMMENT ON TABLE hosp_org.facilities IS
                'Layer 1: a physical facility with its idn_role and regulated designations (trauma/stroke/perinatal/NICU/burn/transplant).';
            COMMENT ON TABLE hosp_org.facility_service_capabilities IS
                'Layer 3: one row per facility x service line x capability_level, with evidence and transfer targets.';
            COMMENT ON TABLE hosp_org.transfer_relationships IS
                'First-class interfacility transfer edges (internal and external partners); projected into the ops graph in Phase 4.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS hosp_org.transfer_relationships;
            DROP TABLE IF EXISTS hosp_org.facility_service_capabilities;
            DROP TABLE IF EXISTS hosp_org.facilities;
            DROP TABLE IF EXISTS hosp_org.markets;
            DROP TABLE IF EXISTS hosp_org.organizations;
            DROP SCHEMA IF EXISTS hosp_org;
        SQL);
    }
};
