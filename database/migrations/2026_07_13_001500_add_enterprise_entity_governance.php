<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENT-REG — make enterprise topology authoritative.
 *
 * Additive-only. Adds effective dating (valid_from/valid_until), source-of-truth,
 * namespaced external identifiers, and ownership (owner/steward) to the enterprise
 * entities (organizations, markets, facilities, service lines, locations/spaces),
 * plus an append-only enterprise change-history ledger and a per-source declared
 * required-topology table so source activation can be gated on missing/unresolved
 * locations and service lines after enterprise import.
 *
 * Everything here is additive: new nullable/defaulted columns on existing tables
 * (so the config-authored ServiceLineRegistrar seeder and DeploymentFacilityImporter
 * keep succeeding without change), one new ledger table, one new gate table, and a
 * widened governance action_type CHECK. No existing enterprise table is mutated,
 * weakened, or dropped; the tenant/facility compatibility keys are left in place.
 *
 * Plan: docs/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md (ENT-REG)
 */
return new class extends Migration
{
    /** Enterprise tables receiving the shared governance columns and their PK/name columns. */
    private const GOVERNED_ENTITIES = [
        'hosp_org.organizations' => 'created_at',
        'hosp_org.markets' => 'created_at',
        'hosp_org.facilities' => 'created_at',
        'hosp_ref.service_lines' => 'created_at',
        'hosp_space.facility_spaces' => null,
    ];

    public function up(): void
    {
        foreach (array_keys(self::GOVERNED_ENTITIES) as $table) {
            $this->addGovernanceColumns($table);
        }
        $this->backfill();

        DB::unprepared(<<<'SQL'
            -- Append-only enterprise change-history ledger. Every governed enterprise
            -- import commit (and any future governed enterprise edit) records the exact
            -- before/after attribute delta per entity here. Non-PHI, but the delta jsonb
            -- is still guarded by the shared clinical-content tripwire as defense in depth.
            CREATE TABLE hosp_org.enterprise_change_history (
                enterprise_change_history_id bigserial PRIMARY KEY,
                change_history_uuid uuid NOT NULL UNIQUE,
                entity_type varchar(40) NOT NULL,
                entity_natural_key varchar(190) NOT NULL,
                entity_id bigint,
                change_kind varchar(20) NOT NULL,
                source_of_truth varchar(40) NOT NULL,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                changed_fields jsonb NOT NULL DEFAULT '[]'::jsonb,
                before_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                after_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                effective_from timestamptz NOT NULL DEFAULT now(),
                recorded_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                recorded_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT enterprise_change_history_entity_type_chk CHECK (
                    entity_type IN ('organization', 'market', 'facility', 'service_line', 'location', 'capability', 'transfer_relationship')
                ),
                CONSTRAINT enterprise_change_history_change_kind_chk CHECK (change_kind IN ('create', 'update', 'no_change')),
                CONSTRAINT enterprise_change_history_natural_key_chk CHECK (entity_natural_key ~ '^[A-Za-z0-9_.:\-]{1,190}$'),
                CONSTRAINT enterprise_change_history_source_of_truth_chk CHECK (source_of_truth ~ '^[a-z][a-z0-9_]{0,39}$'),
                CONSTRAINT enterprise_change_history_changed_fields_chk CHECK (jsonb_typeof(changed_fields) = 'array'),
                CONSTRAINT enterprise_change_history_before_chk CHECK (jsonb_typeof(before_state) = 'object'),
                CONSTRAINT enterprise_change_history_after_chk CHECK (jsonb_typeof(after_state) = 'object')
            );

            CREATE INDEX enterprise_change_history_entity_idx
                ON hosp_org.enterprise_change_history (entity_type, entity_natural_key, recorded_at DESC);
            CREATE INDEX enterprise_change_history_change_idx
                ON hosp_org.enterprise_change_history (governed_change_request_uuid);

            -- Per-source declared required topology. A source may (optionally) declare the
            -- service lines and locations it depends on; readiness then blocks activation
            -- when any declared item is missing or unresolved against the imported registry.
            -- One current row per source; append-only history is the readiness assessments.
            CREATE TABLE integration.source_required_topology (
                source_required_topology_id bigserial PRIMARY KEY,
                source_id bigint NOT NULL UNIQUE REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                required_service_line_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                required_location_space_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                declared_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_required_topology_service_lines_chk CHECK (jsonb_typeof(required_service_line_codes) = 'array'),
                CONSTRAINT source_required_topology_locations_chk CHECK (jsonb_typeof(required_location_space_codes) = 'array')
            );

            CREATE OR REPLACE FUNCTION hosp_org.reject_enterprise_change_history_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'enterprise change history is append-only';
            END;
            $$;

            CREATE TRIGGER enterprise_change_history_append_only
                BEFORE UPDATE OR DELETE ON hosp_org.enterprise_change_history
                FOR EACH ROW EXECUTE FUNCTION hosp_org.reject_enterprise_change_history_mutation();

            CREATE TRIGGER enterprise_change_history_clinical_content_guard
                BEFORE INSERT ON hosp_org.enterprise_change_history
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            CREATE TRIGGER source_required_topology_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.source_required_topology
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            COMMENT ON TABLE hosp_org.enterprise_change_history IS
                'ENT-REG: append-only change history for enterprise entities; one row per governed import delta.';
            COMMENT ON TABLE integration.source_required_topology IS
                'ENT-REG: per-source declared required service lines/locations; readiness gate blocks activation on unresolved items.';

            -- Widen the governed change action_type CHECK for the enterprise registry import.
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_check;
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_chk;
            ALTER TABLE governance.change_requests
                ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                    'activate_production_source',
                    'schedule_production_source_activation',
                    'apply_source_configuration',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy',
                    'release_quarantined_payload',
                    'apply_clinical_payload_hold',
                    'release_clinical_payload_hold',
                    'purge_clinical_payload',
                    'purge_quarantined_payload',
                    'recover_clinical_payload_integrity',
                    'purge_user_identity',
                    'apply_cockpit_threshold_policy',
                    'apply_ai_provider_policy',
                    'apply_enterprise_registry_import'
                ));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integration.source_required_topology;
            DROP TRIGGER IF EXISTS enterprise_change_history_append_only ON hosp_org.enterprise_change_history;
            DROP TRIGGER IF EXISTS enterprise_change_history_clinical_content_guard ON hosp_org.enterprise_change_history;
            DROP TABLE IF EXISTS hosp_org.enterprise_change_history;
            DROP FUNCTION IF EXISTS hosp_org.reject_enterprise_change_history_mutation();
        SQL);

        foreach (array_keys(self::GOVERNED_ENTITIES) as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} DROP COLUMN IF EXISTS valid_from;
                ALTER TABLE {$table} DROP COLUMN IF EXISTS valid_until;
                ALTER TABLE {$table} DROP COLUMN IF EXISTS source_of_truth;
                ALTER TABLE {$table} DROP COLUMN IF EXISTS external_identifiers;
                ALTER TABLE {$table} DROP COLUMN IF EXISTS owner_name;
                ALTER TABLE {$table} DROP COLUMN IF EXISTS steward_name;
            SQL);
        }
    }

    private function addGovernanceColumns(string $table): void
    {
        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS valid_from timestamptz;
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS valid_until timestamptz;
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS source_of_truth varchar(40) NOT NULL DEFAULT 'seed';
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS external_identifiers jsonb NOT NULL DEFAULT '{}'::jsonb;
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS owner_name varchar(190);
            ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS steward_name varchar(190);
            ALTER TABLE {$table}
                DROP CONSTRAINT IF EXISTS {$this->constraintName($table, 'source_of_truth')};
            ALTER TABLE {$table}
                ADD CONSTRAINT {$this->constraintName($table, 'source_of_truth')}
                CHECK (source_of_truth ~ '^[a-z][a-z0-9_]{0,39}$');
            ALTER TABLE {$table}
                DROP CONSTRAINT IF EXISTS {$this->constraintName($table, 'external_ids')};
            ALTER TABLE {$table}
                ADD CONSTRAINT {$this->constraintName($table, 'external_ids')}
                CHECK (jsonb_typeof(external_identifiers) = 'object');
        SQL);
    }

    private function constraintName(string $table, string $suffix): string
    {
        $base = str_replace('.', '_', $table);

        return "{$base}_{$suffix}_chk";
    }

    private function backfill(): void
    {
        // Open-ended effective dating anchored at each row's creation (or now where the
        // table has no created_at), and an explicit source_of_truth for existing rows.
        foreach (self::GOVERNED_ENTITIES as $table => $createdColumn) {
            $anchor = $createdColumn !== null ? "COALESCE({$createdColumn}, now())" : 'now()';
            DB::statement(<<<SQL
                UPDATE {$table}
                SET valid_from = {$anchor}
                WHERE valid_from IS NULL
            SQL);
        }

        // Config-projected registry (service lines) is 'seed'; imported IDN geography
        // and locations default to 'manual' until an authoritative import claims them.
        DB::statement("UPDATE hosp_ref.service_lines SET source_of_truth = 'seed' WHERE source_of_truth = 'seed'");
        foreach (['hosp_org.organizations', 'hosp_org.markets', 'hosp_org.facilities', 'hosp_space.facility_spaces'] as $table) {
            DB::statement("UPDATE {$table} SET source_of_truth = 'manual' WHERE source_of_truth = 'seed'");
        }
    }
};
