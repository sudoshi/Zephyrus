<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer 2 of the Service Line / Location Deployment Taxonomy: a normalized,
 * FK-able service-line registry plus the fixed vocabulary lookups the report
 * defines (capability levels, IDN roles, location roles, evidence classes).
 *
 * Additive and non-breaking (per the implementation plan §4.1): new tables in
 * the already-existing hosp_ref schema, nothing dropped or altered. The small
 * fixed vocab lookups are seeded inline here (they are immutable report enums,
 * mirroring how 2026_06_25_000010 seeds hosp_ref.facility_object_categories);
 * the larger, deployment-varying tables (service_lines, programs,
 * capability_tags) are left empty for `deployment:seed-registry` to project
 * from config/hospital/service-lines.php, programs.php, capability-tags.php.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§4.1, Phase 0)
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS hosp_ref;

            -- Layer 2 registry: the enterprise service-line catalog (config-authored, DB-projected).
            CREATE TABLE IF NOT EXISTS hosp_ref.service_lines (
                service_line_code            text PRIMARY KEY,
                display_name                 text NOT NULL,
                clinical_domain              text NOT NULL,
                adult_or_pediatric           text NOT NULL DEFAULT 'adult',
                care_setting_default         text NOT NULL DEFAULT 'inpatient',
                hcup_grouping                text,
                requires_24_7                boolean NOT NULL DEFAULT false,
                requires_inpatient_beds      boolean NOT NULL DEFAULT false,
                requires_procedure_platform  boolean NOT NULL DEFAULT false,
                requires_imaging             boolean NOT NULL DEFAULT false,
                requires_lab                 boolean NOT NULL DEFAULT false,
                requires_pharmacy            boolean NOT NULL DEFAULT false,
                requires_transport           boolean NOT NULL DEFAULT false,
                requires_transfer_agreements boolean NOT NULL DEFAULT false,
                certification_or_designation text[]  NOT NULL DEFAULT '{}',
                default_location_roles       text[]  NOT NULL DEFAULT '{}',
                default_workflow             text,
                aliases                      text[]  NOT NULL DEFAULT '{}',
                sort_order                   integer NOT NULL DEFAULT 100,
                is_active                    boolean NOT NULL DEFAULT true,
                metadata                     jsonb   NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_service_lines_domain
                ON hosp_ref.service_lines(clinical_domain);

            CREATE INDEX IF NOT EXISTS idx_service_lines_aliases
                ON hosp_ref.service_lines USING gin(aliases);

            -- Programs: clinically distinct capabilities within a service line.
            CREATE TABLE IF NOT EXISTS hosp_ref.programs (
                program_code             text PRIMARY KEY,
                service_line_code        text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
                display_name             text NOT NULL,
                designation_type         text,
                designation_body         text,
                capability_level_implied text,
                adult_or_pediatric       text NOT NULL DEFAULT 'adult',
                metadata                 jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_programs_service_line
                ON hosp_ref.programs(service_line_code);

            -- Capability tags: structured bed/room/space capability vocabulary.
            CREATE TABLE IF NOT EXISTS hosp_ref.capability_tags (
                tag_code     text PRIMARY KEY,
                tag_category text NOT NULL,
                display_name text NOT NULL,
                description  text,
                applies_to   text[] NOT NULL DEFAULT '{bed,room,facility_space}',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            -- Fixed vocab lookups (FK targets + FE dropdown sources) seeded inline below.
            CREATE TABLE IF NOT EXISTS hosp_ref.capability_levels (
                code         text PRIMARY KEY,
                display_name text NOT NULL,
                rank         integer NOT NULL
            );

            CREATE TABLE IF NOT EXISTS hosp_ref.idn_roles (
                code         text PRIMARY KEY,
                display_name text NOT NULL,
                sort_order   integer NOT NULL DEFAULT 100
            );

            CREATE TABLE IF NOT EXISTS hosp_ref.location_roles (
                code         text PRIMARY KEY,
                display_name text NOT NULL,
                sort_order   integer NOT NULL DEFAULT 100
            );

            CREATE TABLE IF NOT EXISTS hosp_ref.evidence_classes (
                code         text PRIMARY KEY,
                display_name text NOT NULL,
                is_regulated boolean NOT NULL DEFAULT false
            );

            -- Capability levels (report "Capability Level"): rank ascends with capability.
            INSERT INTO hosp_ref.capability_levels (code, display_name, rank)
            VALUES
                ('none',       'None',       0),
                ('screen',     'Screen',     1),
                ('stabilize',  'Stabilize',  2),
                ('routine',    'Routine',    3),
                ('advanced',   'Advanced',   4),
                ('definitive', 'Definitive', 5),
                ('quaternary', 'Quaternary', 6)
            ON CONFLICT (code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                rank         = EXCLUDED.rank;

            -- IDN geography roles (report "IDN Geography Role"): 14 fixed roles.
            INSERT INTO hosp_ref.idn_roles (code, display_name, sort_order)
            VALUES
                ('flagship_quaternary_hub',           'Flagship / Quaternary Hub',          10),
                ('academic_tertiary_hub',             'Academic / Tertiary Hub',            20),
                ('regional_referral_hub',             'Regional Referral Hub',              30),
                ('community_hospital',                'Community Hospital',                 40),
                ('critical_access_or_rural_hospital', 'Critical Access / Rural Hospital',   50),
                ('specialty_hospital',                'Specialty Hospital',                 60),
                ('satellite_ed',                      'Satellite Emergency Department',     70),
                ('ambulatory_campus',                 'Ambulatory Campus',                  80),
                ('ambulatory_surgery_center',         'Ambulatory Surgery Center',          90),
                ('urgent_care',                       'Urgent Care',                       100),
                ('home_hospital',                     'Home Hospital',                     110),
                ('post_acute',                        'Post-Acute',                        120),
                ('behavioral_health_facility',        'Behavioral Health Facility',        130),
                ('virtual_command_center',            'Virtual Command Center',            140)
            ON CONFLICT (code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                sort_order   = EXCLUDED.sort_order;

            -- Location roles (report "Location Role"): 16 fixed roles.
            INSERT INTO hosp_ref.location_roles (code, display_name, sort_order)
            VALUES
                ('arrival',        'Arrival',        10),
                ('triage',         'Triage',         20),
                ('diagnostic',     'Diagnostic',     30),
                ('treatment',      'Treatment',      40),
                ('procedure',      'Procedure',      50),
                ('recovery',       'Recovery',       60),
                ('inpatient',      'Inpatient',      70),
                ('critical_care',  'Critical Care',  80),
                ('observation',    'Observation',    90),
                ('rehabilitation', 'Rehabilitation', 100),
                ('outpatient',     'Outpatient',     110),
                ('support',        'Support',        120),
                ('logistics',      'Logistics',      130),
                ('command',        'Command',        140),
                ('surge',          'Surge',          150),
                ('transfer',       'Transfer',       160)
            ON CONFLICT (code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                sort_order   = EXCLUDED.sort_order;

            -- Evidence classes (report §6): 9 classes; state/accreditation are regulated-grade.
            INSERT INTO hosp_ref.evidence_classes (code, display_name, is_regulated)
            VALUES
                ('state_designation',           'State Designation',            true),
                ('accreditation_body',          'Accreditation Body',           true),
                ('official_health_system_page', 'Official Health-System Page',  false),
                ('public_location_page',        'Public Location Page',         false),
                ('client_roster',               'Client Roster',                false),
                ('EHR_location_master',         'EHR Location Master',          false),
                ('facility_map',                'Facility Map',                 false),
                ('interview',                   'Interview',                    false),
                ('assumption',                  'Assumption',                   false)
            ON CONFLICT (code) DO UPDATE SET
                display_name = EXCLUDED.display_name,
                is_regulated = EXCLUDED.is_regulated;

            COMMENT ON TABLE hosp_ref.service_lines IS
                'Layer 2 registry: normalized enterprise service-line catalog, config-authored and projected by deployment:seed-registry.';

            COMMENT ON TABLE hosp_ref.programs IS
                'Clinically distinct capabilities within a service line (Level I trauma, comprehensive stroke, kidney transplant, ...).';

            COMMENT ON TABLE hosp_ref.capability_tags IS
                'Structured bed/room/facility-space capability vocabulary (ventilator, crrt, negative_pressure, stroke_priority, ...).';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS hosp_ref.programs;
            DROP TABLE IF EXISTS hosp_ref.service_lines;
            DROP TABLE IF EXISTS hosp_ref.capability_tags;
            DROP TABLE IF EXISTS hosp_ref.capability_levels;
            DROP TABLE IF EXISTS hosp_ref.idn_roles;
            DROP TABLE IF EXISTS hosp_ref.location_roles;
            DROP TABLE IF EXISTS hosp_ref.evidence_classes;
        SQL);
    }
};
