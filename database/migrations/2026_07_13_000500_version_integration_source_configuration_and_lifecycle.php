<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONFIGURATION_COLUMNS = [
        'organization_id',
        'facility_id',
        'tenant_key',
        'facility_key',
        'source_name',
        'vendor',
        'system_class',
        'environment',
        'base_url',
        'interface_type',
        'fhir_version',
        'us_core_version',
        'smart_supported',
        'bulk_supported',
        'subscriptions_supported',
        'contract_status',
        'baa_status',
        'phi_allowed',
        'metadata',
    ];

    public function up(): void
    {
        Schema::table('integration.sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration.sources', 'lifecycle_state')) {
                $table->string('lifecycle_state', 40)->default('draft')->after('go_live_status');
            }
            if (! Schema::hasColumn('integration.sources', 'current_configuration_version_id')) {
                $table->unsignedBigInteger('current_configuration_version_id')->nullable()->after('lifecycle_state');
            }
            if (! Schema::hasColumn('integration.sources', 'lifecycle_changed_at')) {
                $table->timestampTz('lifecycle_changed_at')->nullable()->after('current_configuration_version_id');
            }
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_check;
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_chk;
            ALTER TABLE governance.change_requests
                ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                    'activate_production_source',
                    'apply_source_configuration',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy',
                    'purge_user_identity'
                ));

            CREATE TABLE IF NOT EXISTS integration.source_configuration_versions (
                source_configuration_version_id bigserial PRIMARY KEY,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_version_id bigint REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                configuration jsonb NOT NULL,
                configuration_sha256 char(64) NOT NULL CHECK (configuration_sha256 ~ '^[0-9a-f]{64}$'),
                change_kind text NOT NULL CHECK (change_kind IN ('initial', 'backfill', 'direct_update', 'proposal', 'governed_application')),
                change_reason text NOT NULL CHECK (length(change_reason) BETWEEN 10 AND 500),
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                correlation_id uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_configuration_versions_number_uniq UNIQUE (source_id, version_number)
            );

            CREATE INDEX IF NOT EXISTS source_configuration_versions_source_created_idx
                ON integration.source_configuration_versions (source_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS source_configuration_versions_hash_idx
                ON integration.source_configuration_versions (source_id, configuration_sha256);

            CREATE TABLE IF NOT EXISTS integration.source_lifecycle_events (
                source_lifecycle_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                configuration_version_id bigint NOT NULL REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                from_state text,
                to_state text NOT NULL,
                reason text NOT NULL CHECK (length(reason) BETWEEN 10 AND 500),
                changed_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_lifecycle_events_from_state_chk CHECK (
                    from_state IS NULL OR from_state IN ('draft', 'discovery', 'configured', 'validating', 'approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired')
                ),
                CONSTRAINT source_lifecycle_events_to_state_chk CHECK (
                    to_state IN ('draft', 'discovery', 'configured', 'validating', 'approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired')
                )
            );

            CREATE INDEX IF NOT EXISTS source_lifecycle_events_source_occurred_idx
                ON integration.source_lifecycle_events (source_id, occurred_at DESC, source_lifecycle_event_id DESC);
            CREATE INDEX IF NOT EXISTS source_lifecycle_events_governed_change_idx
                ON integration.source_lifecycle_events (governed_change_request_uuid)
                WHERE governed_change_request_uuid IS NOT NULL;
        SQL);

        $this->backfillConfigurationVersions();

        DB::unprepared(<<<'SQL'
            UPDATE integration.sources
            SET lifecycle_state = CASE
                    WHEN go_live_status = 'retired' THEN 'retired'
                    WHEN active_status = 'degraded' THEN 'degraded'
                    WHEN active_status = 'active' OR go_live_status = 'live' THEN 'live'
                    WHEN active_status = 'disabled' OR go_live_status = 'paused' THEN 'suspended'
                    WHEN go_live_status = 'ready' THEN 'approved'
                    WHEN active_status = 'testing' OR go_live_status = 'testing' THEN 'validating'
                    WHEN go_live_status = 'planning' THEN 'discovery'
                    ELSE 'draft'
                END,
                lifecycle_changed_at = COALESCE(updated_at, created_at, now());

            INSERT INTO integration.source_lifecycle_events (
                event_uuid,
                source_id,
                configuration_version_id,
                from_state,
                to_state,
                reason,
                metadata,
                occurred_at
            )
            SELECT gen_random_uuid(),
                   source.source_id,
                   source.current_configuration_version_id,
                   NULL,
                   source.lifecycle_state,
                   'Backfilled from the legacy source status projection.',
                   jsonb_build_object('migration', '2026_07_13_000500'),
                   COALESCE(source.updated_at, source.created_at, now())
            FROM integration.sources AS source
            WHERE source.current_configuration_version_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM integration.source_lifecycle_events AS event
                  WHERE event.source_id = source.source_id
              );

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'integration_sources_current_configuration_fk'
                      AND conrelid = 'integration.sources'::regclass
                ) THEN
                    ALTER TABLE integration.sources
                    ADD CONSTRAINT integration_sources_current_configuration_fk
                    FOREIGN KEY (current_configuration_version_id)
                    REFERENCES integration.source_configuration_versions(source_configuration_version_id)
                    ON DELETE RESTRICT;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'integration_sources_lifecycle_state_chk'
                      AND conrelid = 'integration.sources'::regclass
                ) THEN
                    ALTER TABLE integration.sources
                    ADD CONSTRAINT integration_sources_lifecycle_state_chk
                    CHECK (lifecycle_state IN ('draft', 'discovery', 'configured', 'validating', 'approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired'));
                END IF;
            END;
            $$;

            CREATE INDEX IF NOT EXISTS integration_sources_current_configuration_idx
                ON integration.sources (current_configuration_version_id);
            CREATE INDEX IF NOT EXISTS integration_sources_lifecycle_idx
                ON integration.sources (lifecycle_state, facility_id);

            CREATE OR REPLACE FUNCTION integration.reject_source_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'integration source configuration and lifecycle ledgers are append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS source_configuration_versions_append_only ON integration.source_configuration_versions;
            CREATE TRIGGER source_configuration_versions_append_only
                BEFORE UPDATE OR DELETE ON integration.source_configuration_versions
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_ledger_mutation();

            DROP TRIGGER IF EXISTS source_lifecycle_events_append_only ON integration.source_lifecycle_events;
            CREATE TRIGGER source_lifecycle_events_append_only
                BEFORE UPDATE OR DELETE ON integration.source_lifecycle_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_ledger_mutation();

            CREATE OR REPLACE FUNCTION integration.enforce_source_configuration_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE authoritative_configuration jsonb;
            DECLARE projected_configuration jsonb;
            BEGIN
                IF NEW.source_uuid IS DISTINCT FROM OLD.source_uuid
                   OR NEW.source_key IS DISTINCT FROM OLD.source_key THEN
                    RAISE EXCEPTION 'integration source identity is immutable';
                END IF;

                IF OLD.current_configuration_version_id IS NULL
                   AND NEW.current_configuration_version_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NEW.current_configuration_version_id IS NULL THEN
                    RAISE EXCEPTION 'a versioned integration source cannot be returned to an unversioned state';
                END IF;

                SELECT version.configuration
                INTO authoritative_configuration
                FROM integration.source_configuration_versions AS version
                WHERE version.source_configuration_version_id = NEW.current_configuration_version_id
                  AND version.source_id = NEW.source_id;

                IF authoritative_configuration IS NULL THEN
                    RAISE EXCEPTION 'current integration source configuration version does not belong to the source';
                END IF;

                projected_configuration := jsonb_build_object(
                    'organization_id', NEW.organization_id,
                    'facility_id', NEW.facility_id,
                    'tenant_key', NEW.tenant_key,
                    'facility_key', NEW.facility_key,
                    'source_name', NEW.source_name,
                    'vendor', NEW.vendor,
                    'system_class', NEW.system_class,
                    'environment', NEW.environment,
                    'base_url', NEW.base_url,
                    'interface_type', NEW.interface_type,
                    'fhir_version', NEW.fhir_version,
                    'us_core_version', NEW.us_core_version,
                    'smart_supported', NEW.smart_supported,
                    'bulk_supported', NEW.bulk_supported,
                    'subscriptions_supported', NEW.subscriptions_supported,
                    'contract_status', NEW.contract_status,
                    'baa_status', NEW.baa_status,
                    'phi_allowed', NEW.phi_allowed,
                    'metadata', NEW.metadata
                );

                IF projected_configuration IS DISTINCT FROM authoritative_configuration THEN
                    RAISE EXCEPTION 'integration source projection must exactly match its immutable configuration version';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS integration_sources_configuration_projection ON integration.sources;
            CREATE TRIGGER integration_sources_configuration_projection
                BEFORE UPDATE OF
                    source_uuid, source_key, organization_id, facility_id, tenant_key, facility_key,
                    source_name, vendor, system_class, environment, base_url, interface_type,
                    fhir_version, us_core_version, smart_supported, bulk_supported,
                    subscriptions_supported, contract_status, baa_status, phi_allowed, metadata,
                    current_configuration_version_id
                ON integration.sources
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_configuration_projection();

            CREATE OR REPLACE FUNCTION integration.enforce_source_lifecycle_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE lifecycle_event integration.source_lifecycle_events%ROWTYPE;
            DECLARE expected_active_status text;
            DECLARE expected_go_live_status text;
            BEGIN
                SELECT event.*
                INTO lifecycle_event
                FROM integration.source_lifecycle_events AS event
                WHERE event.source_id = NEW.source_id
                ORDER BY event.source_lifecycle_event_id DESC
                LIMIT 1;

                IF lifecycle_event.source_lifecycle_event_id IS NULL
                   OR lifecycle_event.to_state <> NEW.lifecycle_state THEN
                    RAISE EXCEPTION 'integration source lifecycle projection must match its append-only event stream';
                END IF;
                IF lifecycle_event.configuration_version_id <> NEW.current_configuration_version_id THEN
                    RAISE EXCEPTION 'integration source lifecycle event must reference the effective configuration version';
                END IF;

                expected_active_status := CASE NEW.lifecycle_state
                    WHEN 'validating' THEN 'testing'
                    WHEN 'approved' THEN 'testing'
                    WHEN 'scheduled' THEN 'testing'
                    WHEN 'live' THEN 'active'
                    WHEN 'degraded' THEN 'degraded'
                    WHEN 'suspended' THEN 'disabled'
                    WHEN 'retired' THEN 'disabled'
                    ELSE 'inactive'
                END;
                expected_go_live_status := CASE NEW.lifecycle_state
                    WHEN 'discovery' THEN 'planning'
                    WHEN 'configured' THEN 'planning'
                    WHEN 'validating' THEN 'testing'
                    WHEN 'approved' THEN 'ready'
                    WHEN 'scheduled' THEN 'ready'
                    WHEN 'live' THEN 'live'
                    WHEN 'degraded' THEN 'live'
                    WHEN 'suspended' THEN 'paused'
                    WHEN 'retired' THEN 'retired'
                    ELSE 'not_started'
                END;

                IF NEW.active_status <> expected_active_status
                   OR NEW.go_live_status <> expected_go_live_status THEN
                    RAISE EXCEPTION 'legacy integration source statuses must be derived from lifecycle state';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS integration_sources_lifecycle_projection ON integration.sources;
            CREATE TRIGGER integration_sources_lifecycle_projection
                BEFORE UPDATE OF lifecycle_state, active_status, go_live_status
                ON integration.sources
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_lifecycle_projection();

            CREATE OR REPLACE FUNCTION integration.require_governed_source_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.lifecycle_state IN ('approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired')
                   OR NEW.active_status = 'active'
                   OR NEW.go_live_status = 'live' THEN
                    IF NEW.current_configuration_version_id IS NULL THEN
                        RAISE EXCEPTION 'governed integration source states require an immutable configuration version';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS integration_sources_governed_authority ON integration.sources;
            CREATE TRIGGER integration_sources_governed_authority
                BEFORE INSERT OR UPDATE OF lifecycle_state, active_status, go_live_status, current_configuration_version_id
                ON integration.sources
                FOR EACH ROW EXECUTE FUNCTION integration.require_governed_source_authority();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS integration_sources_governed_authority ON integration.sources;
            DROP TRIGGER IF EXISTS integration_sources_lifecycle_projection ON integration.sources;
            DROP TRIGGER IF EXISTS integration_sources_configuration_projection ON integration.sources;
            DROP FUNCTION IF EXISTS integration.require_governed_source_authority();
            DROP FUNCTION IF EXISTS integration.enforce_source_lifecycle_projection();
            DROP FUNCTION IF EXISTS integration.enforce_source_configuration_projection();
            DROP TRIGGER IF EXISTS source_lifecycle_events_append_only ON integration.source_lifecycle_events;
            DROP TRIGGER IF EXISTS source_configuration_versions_append_only ON integration.source_configuration_versions;
            DROP FUNCTION IF EXISTS integration.reject_source_ledger_mutation();
            ALTER TABLE integration.sources DROP CONSTRAINT IF EXISTS integration_sources_lifecycle_state_chk;
            ALTER TABLE integration.sources DROP CONSTRAINT IF EXISTS integration_sources_current_configuration_fk;
            DROP INDEX IF EXISTS integration.integration_sources_lifecycle_idx;
            DROP INDEX IF EXISTS integration.integration_sources_current_configuration_idx;
            DROP TABLE IF EXISTS integration.source_lifecycle_events;
            DROP TABLE IF EXISTS integration.source_configuration_versions;
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_check;
            ALTER TABLE governance.change_requests
                DROP CONSTRAINT IF EXISTS change_requests_action_type_chk;
            ALTER TABLE governance.change_requests
                ADD CONSTRAINT change_requests_action_type_chk CHECK (action_type IN (
                    'activate_production_source',
                    'apply_source_configuration',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy',
                    'purge_user_identity'
                ));
        SQL);

        Schema::table('integration.sources', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['lifecycle_changed_at', 'current_configuration_version_id', 'lifecycle_state'],
                fn (string $column): bool => Schema::hasColumn('integration.sources', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function backfillConfigurationVersions(): void
    {
        DB::table('integration.sources')->orderBy('source_id')->get()->each(function (object $source): void {
            $configuration = [];
            foreach (self::CONFIGURATION_COLUMNS as $column) {
                $value = $source->{$column};
                if ($column === 'metadata') {
                    $value = (object) (is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : (array) $value);
                }
                $configuration[$column] = $value;
            }

            $encoded = json_encode($this->canonicalize($configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $versionId = (int) DB::table('integration.source_configuration_versions')->insertGetId([
                'source_id' => $source->source_id,
                'version_number' => 1,
                'previous_version_id' => null,
                'configuration' => $encoded,
                'configuration_sha256' => hash('sha256', $encoded),
                'change_kind' => 'backfill',
                'change_reason' => 'Backfilled from the pre-versioned integration source registry.',
                'created_by_user_id' => null,
                'correlation_id' => null,
                'created_at' => $source->updated_at ?? $source->created_at ?? now(),
            ], 'source_configuration_version_id');

            DB::table('integration.sources')->where('source_id', $source->source_id)->update([
                'current_configuration_version_id' => $versionId,
            ]);
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }
};
