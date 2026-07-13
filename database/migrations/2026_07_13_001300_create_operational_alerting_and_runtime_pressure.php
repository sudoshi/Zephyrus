<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INT-OBS 3/5 + ADM-HEALTH 6 — additive, append-only authorities for:
 *   - per-source runtime pressure evidence (rate-limit/backoff from 429s,
 *     circuit-breaker transitions) that the observability collector reads;
 *   - PHI-free operational-alert delivery attempts (the shared on-call
 *     delivery ledger for SLO breaches and critical system-health);
 *   - system-health acknowledgements (operator triage of critical components).
 *
 * The append-only trigger style and clinical-content guards are copied from
 * 2026_07_13_001000_create_source_observability_control_plane.php. Nothing here
 * mutates an existing table; every authority is insert-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.source_runtime_pressure_events (
                source_runtime_pressure_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                correlation_uuid uuid,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                connector_key varchar(160),
                pressure_kind varchar(30) NOT NULL,
                pressure_state varchar(20) NOT NULL,
                http_status integer,
                retry_after_seconds integer,
                consecutive_failures integer,
                reason_code varchar(80) NOT NULL,
                cleared_at timestamptz,
                observed_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_runtime_pressure_kind_chk CHECK (pressure_kind IN ('rate_limit', 'circuit_breaker', 'backoff')),
                CONSTRAINT source_runtime_pressure_state_chk CHECK (pressure_state IN ('normal', 'throttled', 'open', 'half_open', 'tripped')),
                CONSTRAINT source_runtime_pressure_status_chk CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599),
                CONSTRAINT source_runtime_pressure_retry_chk CHECK (retry_after_seconds IS NULL OR retry_after_seconds BETWEEN 0 AND 604800),
                CONSTRAINT source_runtime_pressure_failures_chk CHECK (consecutive_failures IS NULL OR consecutive_failures >= 0),
                CONSTRAINT source_runtime_pressure_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT source_runtime_pressure_connector_chk CHECK (connector_key IS NULL OR connector_key ~ '^[a-zA-Z0-9._:-]{1,160}$')
            );

            CREATE INDEX source_runtime_pressure_source_idx
                ON integration.source_runtime_pressure_events (source_id, pressure_kind, observed_at DESC, source_runtime_pressure_event_id DESC);

            CREATE TABLE integration.operational_alert_deliveries (
                operational_alert_delivery_id bigserial PRIMARY KEY,
                delivery_uuid uuid NOT NULL UNIQUE,
                alert_domain varchar(30) NOT NULL,
                alert_code varchar(80) NOT NULL,
                severity varchar(10) NOT NULL,
                subject_type varchar(40) NOT NULL,
                subject_reference varchar(120) NOT NULL,
                channel varchar(40) NOT NULL,
                outcome varchar(20) NOT NULL,
                recipient_count integer NOT NULL DEFAULT 0,
                reason_code varchar(80) NOT NULL,
                correlation_uuid uuid,
                dispatched_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT operational_alert_delivery_domain_chk CHECK (alert_domain IN ('integration', 'system_health')),
                CONSTRAINT operational_alert_delivery_severity_chk CHECK (severity IN ('crit', 'warn')),
                CONSTRAINT operational_alert_delivery_subject_chk CHECK (subject_type IN ('slo_breach', 'system_health_component')),
                CONSTRAINT operational_alert_delivery_outcome_chk CHECK (outcome IN ('delivered', 'suppressed', 'inert', 'failed')),
                CONSTRAINT operational_alert_delivery_recipients_chk CHECK (recipient_count >= 0),
                CONSTRAINT operational_alert_delivery_code_chk CHECK (alert_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT operational_alert_delivery_channel_chk CHECK (channel ~ '^[a-z][a-z0-9_]{0,39}$'),
                CONSTRAINT operational_alert_delivery_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT operational_alert_delivery_subject_ref_chk CHECK (subject_reference ~ '^[a-zA-Z0-9._:-]{1,120}$')
            );

            CREATE INDEX operational_alert_deliveries_subject_idx
                ON integration.operational_alert_deliveries (subject_type, subject_reference, dispatched_at DESC);

            CREATE TABLE governance.system_health_acknowledgements (
                system_health_acknowledgement_id bigserial PRIMARY KEY,
                acknowledgement_uuid uuid NOT NULL UNIQUE,
                component_key varchar(80) NOT NULL,
                acknowledged_status varchar(20) NOT NULL,
                system_health_observation_id bigint REFERENCES governance.system_health_observations(system_health_observation_id) ON DELETE RESTRICT,
                acknowledged_by_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                reason varchar(500) NOT NULL,
                correlation_uuid uuid,
                acknowledged_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT system_health_ack_component_chk CHECK (component_key ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT system_health_ack_status_chk CHECK (acknowledged_status IN ('critical', 'warning', 'unknown', 'disabled')),
                CONSTRAINT system_health_ack_reason_chk CHECK (char_length(reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX system_health_acknowledgements_component_idx
                ON governance.system_health_acknowledgements (component_key, acknowledged_at DESC, system_health_acknowledgement_id DESC);

            CREATE OR REPLACE FUNCTION integration.reject_operational_alerting_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'operational alerting authorities are append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION governance.reject_system_health_acknowledgement_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'system health acknowledgements are append-only';
            END;
            $$;

            CREATE TRIGGER source_runtime_pressure_append_only
                BEFORE UPDATE OR DELETE ON integration.source_runtime_pressure_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_operational_alerting_mutation();
            CREATE TRIGGER operational_alert_deliveries_append_only
                BEFORE UPDATE OR DELETE ON integration.operational_alert_deliveries
                FOR EACH ROW EXECUTE FUNCTION integration.reject_operational_alerting_mutation();
            CREATE TRIGGER system_health_acknowledgements_append_only
                BEFORE UPDATE OR DELETE ON governance.system_health_acknowledgements
                FOR EACH ROW EXECUTE FUNCTION governance.reject_system_health_acknowledgement_mutation();

            CREATE TRIGGER source_runtime_pressure_clinical_content_guard
                BEFORE INSERT ON integration.source_runtime_pressure_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER operational_alert_deliveries_clinical_content_guard
                BEFORE INSERT ON integration.operational_alert_deliveries
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER system_health_acknowledgements_clinical_content_guard
                BEFORE INSERT ON governance.system_health_acknowledgements
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS governance.system_health_acknowledgements;
            DROP TABLE IF EXISTS integration.operational_alert_deliveries;
            DROP TABLE IF EXISTS integration.source_runtime_pressure_events;
            DROP FUNCTION IF EXISTS governance.reject_system_health_acknowledgement_mutation();
            DROP FUNCTION IF EXISTS integration.reject_operational_alerting_mutation();
        SQL);
    }
};
