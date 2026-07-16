<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FHIR-CORE conformance authority.
 *
 * CapabilityStatement and SMART discovery are runtime evidence, not mutable
 * connector configuration. Each successful discovery appends a normalized,
 * PHI-free observation plus resource-level detail and advances one monotonic
 * pointer on the connection. Historical evidence cannot be updated/deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.fhir_conformance_observations (
                fhir_conformance_observation_id bigserial PRIMARY KEY,
                observation_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                fhir_client_connection_id bigint NOT NULL REFERENCES integration.fhir_client_connections(fhir_client_connection_id) ON DELETE RESTRICT,
                previous_observation_id bigint REFERENCES integration.fhir_conformance_observations(fhir_conformance_observation_id) ON DELETE RESTRICT,
                observation_status varchar(30) NOT NULL,
                capability_document_sha256 char(64),
                smart_document_sha256 char(64),
                fhir_version varchar(40) NOT NULL,
                capability_kind varchar(30),
                capability_status varchar(30),
                capability_date timestamptz,
                software_name varchar(160),
                software_version varchar(80),
                implementation_origin varchar(255),
                format_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                patch_format_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                implementation_guide_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                system_interaction_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                system_operation_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                compartment_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                security_service_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_issuer_url text,
                smart_jwks_url text,
                smart_authorization_url text,
                smart_token_url text,
                smart_registration_url text,
                smart_management_url text,
                smart_introspection_url text,
                smart_grant_type_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_token_auth_method_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_token_signing_algorithm_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_scope_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_capability_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_pkce_method_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                smart_associated_endpoint_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                supports_batch boolean NOT NULL DEFAULT false,
                supports_transaction boolean NOT NULL DEFAULT false,
                supports_system_history boolean NOT NULL DEFAULT false,
                supports_system_search boolean NOT NULL DEFAULT false,
                supports_bulk_data boolean NOT NULL DEFAULT false,
                supports_subscriptions boolean NOT NULL DEFAULT false,
                resource_count integer NOT NULL DEFAULT 0,
                searchable_resource_count integer NOT NULL DEFAULT 0,
                search_parameter_count integer NOT NULL DEFAULT 0,
                operation_count integer NOT NULL DEFAULT 0,
                warning_code_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                observed_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fhir_conformance_observation_status_chk CHECK (
                    observation_status IN ('passed', 'passed_with_warnings', 'legacy_reduced')
                ),
                CONSTRAINT fhir_conformance_capability_hash_chk CHECK (
                    capability_document_sha256 IS NULL OR capability_document_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT fhir_conformance_smart_hash_chk CHECK (
                    smart_document_sha256 IS NULL OR smart_document_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT fhir_conformance_resource_count_chk CHECK (
                    resource_count BETWEEN 0 AND 500
                    AND searchable_resource_count BETWEEN 0 AND resource_count
                    AND search_parameter_count BETWEEN 0 AND 250000
                    AND operation_count BETWEEN 0 AND 50000
                ),
                CONSTRAINT fhir_conformance_array_payloads_chk CHECK (
                    jsonb_typeof(format_payload) = 'array'
                    AND jsonb_typeof(patch_format_payload) = 'array'
                    AND jsonb_typeof(implementation_guide_payload) = 'array'
                    AND jsonb_typeof(system_interaction_payload) = 'array'
                    AND jsonb_typeof(system_operation_payload) = 'array'
                    AND jsonb_typeof(compartment_payload) = 'array'
                    AND jsonb_typeof(security_service_payload) = 'array'
                    AND jsonb_typeof(smart_grant_type_payload) = 'array'
                    AND jsonb_typeof(smart_token_auth_method_payload) = 'array'
                    AND jsonb_typeof(smart_token_signing_algorithm_payload) = 'array'
                    AND jsonb_typeof(smart_scope_payload) = 'array'
                    AND jsonb_typeof(smart_capability_payload) = 'array'
                    AND jsonb_typeof(smart_pkce_method_payload) = 'array'
                    AND jsonb_typeof(smart_associated_endpoint_payload) = 'array'
                    AND jsonb_typeof(warning_code_payload) = 'array'
                )
            );

            CREATE UNIQUE INDEX fhir_conformance_previous_once_idx
                ON integration.fhir_conformance_observations (previous_observation_id)
                WHERE previous_observation_id IS NOT NULL;
            CREATE INDEX fhir_conformance_source_observed_idx
                ON integration.fhir_conformance_observations (source_id, observed_at DESC, fhir_conformance_observation_id DESC);

            CREATE TABLE integration.fhir_conformance_resource_observations (
                fhir_conformance_resource_observation_id bigserial PRIMARY KEY,
                fhir_conformance_observation_id bigint NOT NULL REFERENCES integration.fhir_conformance_observations(fhir_conformance_observation_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                resource_type varchar(80) NOT NULL,
                base_profile_url text,
                supported_profile_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                interaction_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                versioning varchar(30),
                read_history boolean NOT NULL DEFAULT false,
                update_create boolean NOT NULL DEFAULT false,
                conditional_create boolean,
                conditional_read varchar(30),
                conditional_update boolean,
                conditional_delete varchar(30),
                search_include_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                search_revinclude_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                search_parameter_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                operation_payload jsonb NOT NULL DEFAULT '[]'::jsonb,
                search_parameter_count integer NOT NULL DEFAULT 0,
                operation_count integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fhir_conformance_resource_type_chk CHECK (resource_type ~ '^[A-Z][A-Za-z]{1,79}$'),
                CONSTRAINT fhir_conformance_resource_counts_chk CHECK (
                    search_parameter_count BETWEEN 0 AND 500
                    AND operation_count BETWEEN 0 AND 100
                ),
                CONSTRAINT fhir_conformance_resource_array_payloads_chk CHECK (
                    jsonb_typeof(supported_profile_payload) = 'array'
                    AND jsonb_typeof(interaction_payload) = 'array'
                    AND jsonb_typeof(search_include_payload) = 'array'
                    AND jsonb_typeof(search_revinclude_payload) = 'array'
                    AND jsonb_typeof(search_parameter_payload) = 'array'
                    AND jsonb_typeof(operation_payload) = 'array'
                ),
                UNIQUE (fhir_conformance_observation_id, resource_type)
            );

            CREATE INDEX fhir_conformance_resource_source_idx
                ON integration.fhir_conformance_resource_observations (source_id, resource_type, fhir_conformance_observation_id DESC);

            ALTER TABLE integration.fhir_client_connections
                ADD COLUMN current_conformance_observation_id bigint;
            ALTER TABLE integration.fhir_client_connections
                ADD CONSTRAINT fhir_client_current_conformance_fk
                FOREIGN KEY (current_conformance_observation_id)
                REFERENCES integration.fhir_conformance_observations(fhir_conformance_observation_id)
                ON DELETE RESTRICT;

            CREATE OR REPLACE FUNCTION integration.reject_fhir_conformance_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'FHIR conformance observations are append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.validate_fhir_conformance_observation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                connection_source_id bigint;
                connection_current_id bigint;
            BEGIN
                SELECT source_id, current_conformance_observation_id
                  INTO connection_source_id, connection_current_id
                  FROM integration.fhir_client_connections
                 WHERE fhir_client_connection_id = NEW.fhir_client_connection_id
                 FOR UPDATE;

                IF connection_source_id IS NULL OR connection_source_id <> NEW.source_id THEN
                    RAISE EXCEPTION 'FHIR conformance source and connection authority mismatch';
                END IF;
                IF NEW.previous_observation_id IS DISTINCT FROM connection_current_id THEN
                    RAISE EXCEPTION 'FHIR conformance observation must extend the current connection evidence';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.validate_fhir_conformance_resource_observation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                observation_source_id bigint;
                observation_connection_id bigint;
                observation_previous_id bigint;
                connection_current_id bigint;
            BEGIN
                SELECT observation.source_id,
                       observation.fhir_client_connection_id,
                       observation.previous_observation_id,
                       connection.current_conformance_observation_id
                  INTO observation_source_id,
                       observation_connection_id,
                       observation_previous_id,
                       connection_current_id
                  FROM integration.fhir_conformance_observations AS observation
                  JOIN integration.fhir_client_connections AS connection
                    ON connection.fhir_client_connection_id = observation.fhir_client_connection_id
                 WHERE observation.fhir_conformance_observation_id = NEW.fhir_conformance_observation_id
                 FOR UPDATE OF connection;

                IF observation_source_id IS NULL OR observation_source_id <> NEW.source_id THEN
                    RAISE EXCEPTION 'FHIR conformance resource source authority mismatch';
                END IF;
                IF connection_current_id = NEW.fhir_conformance_observation_id THEN
                    RAISE EXCEPTION 'FHIR conformance resource evidence is finalized';
                END IF;
                IF observation_previous_id IS DISTINCT FROM connection_current_id THEN
                    RAISE EXCEPTION 'FHIR conformance resource must belong to the active successor observation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fhir_conformance_observations_validate
                BEFORE INSERT ON integration.fhir_conformance_observations
                FOR EACH ROW EXECUTE FUNCTION integration.validate_fhir_conformance_observation();
            CREATE TRIGGER fhir_conformance_observations_append_only
                BEFORE UPDATE OR DELETE ON integration.fhir_conformance_observations
                FOR EACH ROW EXECUTE FUNCTION integration.reject_fhir_conformance_mutation();
            CREATE TRIGGER fhir_conformance_resources_append_only
                BEFORE UPDATE OR DELETE ON integration.fhir_conformance_resource_observations
                FOR EACH ROW EXECUTE FUNCTION integration.reject_fhir_conformance_mutation();
            CREATE TRIGGER fhir_conformance_resources_validate
                BEFORE INSERT ON integration.fhir_conformance_resource_observations
                FOR EACH ROW EXECUTE FUNCTION integration.validate_fhir_conformance_resource_observation();
            CREATE TRIGGER fhir_conformance_observations_clinical_content_guard
                BEFORE INSERT ON integration.fhir_conformance_observations
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER fhir_conformance_resources_clinical_content_guard
                BEFORE INSERT ON integration.fhir_conformance_resource_observations
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);

        $connections = DB::table('integration.fhir_client_connections')
            ->whereNotNull('capability_checked_at')
            ->orderBy('fhir_client_connection_id')
            ->get();
        foreach ($connections as $connection) {
            $capability = $this->decodeMap($connection->capability_statement ?? null);
            $smart = $this->decodeMap($connection->smart_configuration ?? null);
            $formats = $this->strings($capability['formats'] ?? []);
            $resourceTypes = array_values(array_filter(
                $this->strings($capability['resourceTypes'] ?? []),
                fn (string $type): bool => preg_match('/^[A-Z][A-Za-z]{1,79}$/', $type) === 1,
            ));
            $observationId = (int) DB::table('integration.fhir_conformance_observations')->insertGetId([
                'observation_uuid' => (string) Str::uuid7(),
                'source_id' => (int) $connection->source_id,
                'fhir_client_connection_id' => (int) $connection->fhir_client_connection_id,
                'previous_observation_id' => null,
                'observation_status' => 'legacy_reduced',
                'capability_document_sha256' => hash('sha256', $this->canonicalJson($capability)),
                'smart_document_sha256' => hash('sha256', $this->canonicalJson($smart)),
                'fhir_version' => (string) ($connection->fhir_version ?: ($capability['fhirVersion'] ?? 'unknown')),
                'software_name' => data_get($capability, 'software.name'),
                'software_version' => data_get($capability, 'software.version'),
                'format_payload' => json_encode($formats, JSON_THROW_ON_ERROR),
                'smart_token_url' => $smart['tokenEndpoint'] ?? null,
                'smart_capability_payload' => json_encode($this->strings($smart['capabilities'] ?? []), JSON_THROW_ON_ERROR),
                'resource_count' => count($resourceTypes),
                'searchable_resource_count' => count($resourceTypes),
                'warning_code_payload' => json_encode(['legacy_reduced_snapshot'], JSON_THROW_ON_ERROR),
                'observed_at' => $connection->capability_checked_at,
                'created_at' => $connection->capability_checked_at,
            ], 'fhir_conformance_observation_id');

            foreach ($resourceTypes as $resourceType) {
                DB::table('integration.fhir_conformance_resource_observations')->insert([
                    'fhir_conformance_observation_id' => $observationId,
                    'source_id' => (int) $connection->source_id,
                    'resource_type' => $resourceType,
                    'interaction_payload' => json_encode(['search-type'], JSON_THROW_ON_ERROR),
                    'created_at' => $connection->capability_checked_at,
                ]);
            }

            DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
                ->update(['current_conformance_observation_id' => $observationId]);
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION integration.validate_fhir_conformance_pointer()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                observation_source_id bigint;
                observation_connection_id bigint;
                observation_previous_id bigint;
                declared_resource_count integer;
                declared_searchable_count integer;
                declared_search_parameter_count integer;
                declared_operation_count integer;
                actual_resource_count integer;
                actual_searchable_count integer;
                actual_search_parameter_count integer;
                actual_operation_count integer;
            BEGIN
                IF NEW.current_conformance_observation_id IS NOT DISTINCT FROM OLD.current_conformance_observation_id THEN
                    RETURN NEW;
                END IF;
                IF NEW.current_conformance_observation_id IS NULL THEN
                    RAISE EXCEPTION 'FHIR conformance current evidence cannot be cleared';
                END IF;

                SELECT source_id,
                       fhir_client_connection_id,
                       previous_observation_id,
                       resource_count,
                       searchable_resource_count,
                       search_parameter_count,
                       operation_count
                  INTO observation_source_id,
                       observation_connection_id,
                       observation_previous_id,
                       declared_resource_count,
                       declared_searchable_count,
                       declared_search_parameter_count,
                       declared_operation_count
                  FROM integration.fhir_conformance_observations
                 WHERE fhir_conformance_observation_id = NEW.current_conformance_observation_id;

                IF observation_source_id IS NULL
                    OR observation_source_id <> NEW.source_id
                    OR observation_connection_id <> NEW.fhir_client_connection_id THEN
                    RAISE EXCEPTION 'FHIR conformance pointer authority mismatch';
                END IF;
                IF observation_previous_id IS DISTINCT FROM OLD.current_conformance_observation_id THEN
                    RAISE EXCEPTION 'FHIR conformance pointer must advance one observation';
                END IF;

                SELECT count(*)::integer,
                       count(*) FILTER (WHERE interaction_payload ? 'search-type')::integer,
                       coalesce(sum(search_parameter_count), 0)::integer,
                       coalesce(sum(operation_count), 0)::integer
                  INTO actual_resource_count,
                       actual_searchable_count,
                       actual_search_parameter_count,
                       actual_operation_count
                  FROM integration.fhir_conformance_resource_observations
                 WHERE fhir_conformance_observation_id = NEW.current_conformance_observation_id;
                SELECT actual_operation_count + jsonb_array_length(system_operation_payload)
                  INTO actual_operation_count
                  FROM integration.fhir_conformance_observations
                 WHERE fhir_conformance_observation_id = NEW.current_conformance_observation_id;

                IF declared_resource_count <> actual_resource_count
                    OR declared_searchable_count <> actual_searchable_count
                    OR declared_search_parameter_count <> actual_search_parameter_count
                    OR declared_operation_count <> actual_operation_count THEN
                    RAISE EXCEPTION 'FHIR conformance evidence counts do not match resource observations';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fhir_client_conformance_pointer_guard
                BEFORE UPDATE OF current_conformance_observation_id ON integration.fhir_client_connections
                FOR EACH ROW EXECUTE FUNCTION integration.validate_fhir_conformance_pointer();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fhir_client_conformance_pointer_guard ON integration.fhir_client_connections;
            DROP FUNCTION IF EXISTS integration.validate_fhir_conformance_pointer();
            ALTER TABLE integration.fhir_client_connections DROP CONSTRAINT IF EXISTS fhir_client_current_conformance_fk;
            ALTER TABLE integration.fhir_client_connections DROP COLUMN IF EXISTS current_conformance_observation_id;
            DROP TABLE IF EXISTS integration.fhir_conformance_resource_observations;
            DROP TABLE IF EXISTS integration.fhir_conformance_observations;
            DROP FUNCTION IF EXISTS integration.validate_fhir_conformance_resource_observation();
            DROP FUNCTION IF EXISTS integration.validate_fhir_conformance_observation();
            DROP FUNCTION IF EXISTS integration.reject_fhir_conformance_mutation();
        SQL);
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            is_array($value) ? $value : [],
            fn (mixed $item): bool => is_string($item) && $item !== '',
        )));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
};
