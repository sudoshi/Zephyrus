<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FHIR-CORE 1: separate discovered server capability from Zephyrus polling
 * authorization. A CapabilityStatement proves what the server advertises; this
 * registry proves which resource profiles Zephyrus is configured to poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.fhir_resource_profiles (
                fhir_resource_profile_id bigserial PRIMARY KEY,
                profile_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                configuration_version_id bigint REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                resource_type varchar(80) NOT NULL,
                canonical_profile_url varchar(500),
                canonical_profile_version varchar(80),
                profile_status varchar(30) NOT NULL,
                poll_enabled boolean NOT NULL DEFAULT false,
                polling_interaction varchar(30) NOT NULL DEFAULT 'search',
                cadence_minutes integer NOT NULL DEFAULT 15,
                page_size integer NOT NULL DEFAULT 100,
                page_limit integer NOT NULL DEFAULT 10,
                resource_limit integer NOT NULL DEFAULT 1000,
                version_number integer NOT NULL DEFAULT 1,
                reason_code varchar(80) NOT NULL,
                change_reason varchar(500) NOT NULL,
                configured_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                correlation_uuid uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fhir_resource_profiles_source_type_uniq UNIQUE (source_id, resource_type),
                CONSTRAINT fhir_resource_profiles_type_chk CHECK (resource_type ~ '^[A-Z][A-Za-z]{1,79}$'),
                CONSTRAINT fhir_resource_profiles_status_chk CHECK (profile_status IN ('configured', 'enabled', 'suspended', 'retired')),
                CONSTRAINT fhir_resource_profiles_interaction_chk CHECK (polling_interaction IN ('search')),
                CONSTRAINT fhir_resource_profiles_cadence_chk CHECK (cadence_minutes BETWEEN 1 AND 10080),
                CONSTRAINT fhir_resource_profiles_page_size_chk CHECK (page_size BETWEEN 1 AND 1000),
                CONSTRAINT fhir_resource_profiles_page_limit_chk CHECK (page_limit BETWEEN 1 AND 100),
                CONSTRAINT fhir_resource_profiles_resource_limit_chk CHECK (resource_limit BETWEEN 1 AND 100000),
                CONSTRAINT fhir_resource_profiles_version_chk CHECK (version_number > 0),
                CONSTRAINT fhir_resource_profiles_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT fhir_resource_profiles_change_reason_chk CHECK (length(btrim(change_reason)) BETWEEN 10 AND 500),
                CONSTRAINT fhir_resource_profiles_poll_state_chk CHECK (NOT poll_enabled OR profile_status <> 'retired')
            );

            CREATE INDEX fhir_resource_profiles_poll_idx
                ON integration.fhir_resource_profiles (source_id, profile_status, poll_enabled, resource_type);

            CREATE TABLE integration.fhir_resource_profile_events (
                fhir_resource_profile_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                fhir_resource_profile_id bigint NOT NULL REFERENCES integration.fhir_resource_profiles(fhir_resource_profile_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                resource_type varchar(80) NOT NULL,
                version_number integer NOT NULL,
                from_status varchar(30),
                to_status varchar(30) NOT NULL,
                reason_code varchar(80) NOT NULL,
                change_reason varchar(500) NOT NULL,
                configured_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                correlation_uuid uuid,
                profile_snapshot jsonb NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fhir_resource_profile_events_type_chk CHECK (resource_type ~ '^[A-Z][A-Za-z]{1,79}$'),
                CONSTRAINT fhir_resource_profile_events_version_chk CHECK (version_number > 0),
                CONSTRAINT fhir_resource_profile_events_from_chk CHECK (from_status IS NULL OR from_status IN ('configured', 'enabled', 'suspended', 'retired')),
                CONSTRAINT fhir_resource_profile_events_to_chk CHECK (to_status IN ('configured', 'enabled', 'suspended', 'retired')),
                CONSTRAINT fhir_resource_profile_events_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT fhir_resource_profile_events_change_reason_chk CHECK (length(btrim(change_reason)) BETWEEN 10 AND 500),
                CONSTRAINT fhir_resource_profile_events_snapshot_chk CHECK (jsonb_typeof(profile_snapshot) = 'object')
            );

            CREATE INDEX fhir_resource_profile_events_profile_idx
                ON integration.fhir_resource_profile_events (fhir_resource_profile_id, version_number DESC);
            CREATE INDEX fhir_resource_profile_events_source_idx
                ON integration.fhir_resource_profile_events (source_id, occurred_at DESC);

            CREATE OR REPLACE FUNCTION integration.enforce_fhir_resource_profile_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                configuration_source_id bigint;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.profile_uuid IS DISTINCT FROM OLD.profile_uuid
                       OR NEW.source_id IS DISTINCT FROM OLD.source_id
                       OR NEW.resource_type IS DISTINCT FROM OLD.resource_type THEN
                        RAISE EXCEPTION 'FHIR resource profile identity is immutable';
                    END IF;
                    IF NEW.version_number <> OLD.version_number + 1 THEN
                        RAISE EXCEPTION 'FHIR resource profile version must advance exactly once';
                    END IF;
                END IF;

                IF NEW.configuration_version_id IS NOT NULL THEN
                    SELECT source_id INTO configuration_source_id
                    FROM integration.source_configuration_versions
                    WHERE source_configuration_version_id = NEW.configuration_version_id;
                    IF configuration_source_id IS NULL OR configuration_source_id <> NEW.source_id THEN
                        RAISE EXCEPTION 'FHIR resource profile configuration authority mismatch';
                    END IF;
                END IF;

                IF NEW.profile_status = 'enabled' AND NOT EXISTS (
                    SELECT 1
                    FROM integration.source_capabilities AS capability
                    WHERE capability.source_id = NEW.source_id
                      AND capability.capability_type = 'fhir_resource'
                      AND capability.resource_type = NEW.resource_type
                      AND capability.supported = true
                ) THEN
                    RAISE EXCEPTION 'FHIR resource profile cannot be enabled without discovered capability';
                END IF;

                IF NEW.profile_status = 'enabled' AND NOT EXISTS (
                    SELECT 1
                    FROM integration.smart_backend_credentials AS credential
                    CROSS JOIN LATERAL jsonb_array_elements_text(
                        CASE
                            WHEN jsonb_typeof(credential.scope_payload) = 'array' THEN credential.scope_payload
                            ELSE '[]'::jsonb
                        END
                    ) AS smart_scope(scope_value)
                    WHERE credential.source_id = NEW.source_id
                      AND smart_scope.scope_value ~ '^system/(\*|[A-Z][A-Za-z]{1,79})\.(read|[cruds]+)$'
                      AND split_part(split_part(smart_scope.scope_value, '.', 1), '/', 2) IN ('*', NEW.resource_type)
                      AND (
                          split_part(smart_scope.scope_value, '.', 2) = 'read'
                          OR strpos(split_part(smart_scope.scope_value, '.', 2), 'r') > 0
                      )
                ) THEN
                    RAISE EXCEPTION 'FHIR resource profile cannot be enabled without SMART system read scope';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.record_fhir_resource_profile_event()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO integration.fhir_resource_profile_events (
                    event_uuid,
                    fhir_resource_profile_id,
                    source_id,
                    resource_type,
                    version_number,
                    from_status,
                    to_status,
                    reason_code,
                    change_reason,
                    configured_by_user_id,
                    correlation_uuid,
                    profile_snapshot,
                    occurred_at
                ) VALUES (
                    gen_random_uuid(),
                    NEW.fhir_resource_profile_id,
                    NEW.source_id,
                    NEW.resource_type,
                    NEW.version_number,
                    CASE WHEN TG_OP = 'UPDATE' THEN OLD.profile_status ELSE NULL END,
                    NEW.profile_status,
                    NEW.reason_code,
                    NEW.change_reason,
                    NEW.configured_by_user_id,
                    NEW.correlation_uuid,
                    jsonb_build_object(
                        'configuration_version_id', NEW.configuration_version_id,
                        'canonical_profile_url', NEW.canonical_profile_url,
                        'canonical_profile_version', NEW.canonical_profile_version,
                        'poll_enabled', NEW.poll_enabled,
                        'polling_interaction', NEW.polling_interaction,
                        'cadence_minutes', NEW.cadence_minutes,
                        'page_size', NEW.page_size,
                        'page_limit', NEW.page_limit,
                        'resource_limit', NEW.resource_limit
                    ),
                    NEW.updated_at
                );
                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.reject_fhir_resource_profile_event_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'FHIR resource profile events are append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.reject_fhir_resource_profile_delete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'FHIR resource profiles must be retired, not deleted';
            END;
            $$;

            CREATE TRIGGER fhir_resource_profile_authority
                BEFORE INSERT OR UPDATE ON integration.fhir_resource_profiles
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_fhir_resource_profile_authority();
            CREATE TRIGGER fhir_resource_profile_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.fhir_resource_profiles
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER fhir_resource_profile_history
                AFTER INSERT OR UPDATE ON integration.fhir_resource_profiles
                FOR EACH ROW EXECUTE FUNCTION integration.record_fhir_resource_profile_event();
            CREATE TRIGGER fhir_resource_profile_delete_guard
                BEFORE DELETE ON integration.fhir_resource_profiles
                FOR EACH ROW EXECUTE FUNCTION integration.reject_fhir_resource_profile_delete();
            CREATE TRIGGER fhir_resource_profile_event_append_only
                BEFORE UPDATE OR DELETE ON integration.fhir_resource_profile_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_fhir_resource_profile_event_mutation();
            CREATE TRIGGER fhir_resource_profile_event_clinical_content_guard
                BEFORE INSERT ON integration.fhir_resource_profile_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();

            INSERT INTO integration.fhir_resource_profiles (
                profile_uuid,
                source_id,
                configuration_version_id,
                resource_type,
                profile_status,
                poll_enabled,
                cadence_minutes,
                page_size,
                page_limit,
                resource_limit,
                reason_code,
                change_reason,
                created_at,
                updated_at
            )
            SELECT gen_random_uuid(),
                   connection.source_id,
                   source.current_configuration_version_id,
                   resource.resource_type,
                   'configured',
                   true,
                   CASE
                       WHEN connection.polling_payload->>'cadence_minutes' ~ '^[0-9]{1,5}$'
                           THEN LEAST(10080, GREATEST(1, (connection.polling_payload->>'cadence_minutes')::integer))
                       ELSE 15
                   END,
                   100,
                   10,
                   1000,
                   'legacy_polling_profile_backfill',
                   'Adopt the legacy FHIR polling resource into the governed profile registry.',
                   now(),
                   now()
            FROM integration.fhir_client_connections AS connection
            JOIN integration.sources AS source ON source.source_id = connection.source_id
            CROSS JOIN LATERAL jsonb_array_elements_text(
                CASE
                    WHEN jsonb_typeof(connection.polling_payload->'resource_types') = 'array'
                        THEN connection.polling_payload->'resource_types'
                    ELSE '[]'::jsonb
                END
            ) AS resource(resource_type)
            WHERE resource.resource_type ~ '^[A-Z][A-Za-z]{1,79}$'
            ON CONFLICT (source_id, resource_type) DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integration.fhir_resource_profile_events;
            DROP TABLE IF EXISTS integration.fhir_resource_profiles;
            DROP FUNCTION IF EXISTS integration.reject_fhir_resource_profile_delete();
            DROP FUNCTION IF EXISTS integration.reject_fhir_resource_profile_event_mutation();
            DROP FUNCTION IF EXISTS integration.record_fhir_resource_profile_event();
            DROP FUNCTION IF EXISTS integration.enforce_fhir_resource_profile_authority();
        SQL);
    }
};
