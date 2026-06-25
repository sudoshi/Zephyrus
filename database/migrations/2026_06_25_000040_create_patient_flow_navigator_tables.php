<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS flow_core;
            CREATE SCHEMA IF NOT EXISTS flow_realtime;

            CREATE TABLE IF NOT EXISTS flow_core.patient_identities (
                patient_ref text PRIMARY KEY,
                patient_display_ref text NOT NULL,
                identifier_hash text NOT NULL,
                merged_into_patient_ref text REFERENCES flow_core.patient_identities(patient_ref),
                deidentified boolean NOT NULL DEFAULT true,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_flow_patient_identifier_hash
                ON flow_core.patient_identities(identifier_hash);

            CREATE TABLE IF NOT EXISTS flow_core.encounters (
                encounter_ref text PRIMARY KEY,
                patient_ref text NOT NULL REFERENCES flow_core.patient_identities(patient_ref),
                patient_class text,
                service_line text,
                encounter_status text NOT NULL DEFAULT 'in-progress',
                started_at timestamptz,
                ended_at timestamptz,
                prod_encounter_id bigint REFERENCES prod.encounters(encounter_id) ON DELETE SET NULL,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_flow_encounters_patient_started
                ON flow_core.encounters(patient_ref, started_at);

            CREATE INDEX IF NOT EXISTS idx_flow_encounters_prod
                ON flow_core.encounters(prod_encounter_id)
                WHERE prod_encounter_id IS NOT NULL;

            CREATE TABLE IF NOT EXISTS flow_core.flow_events (
                flow_event_id text PRIMARY KEY,
                source_id bigint REFERENCES integration.sources(source_id) ON DELETE SET NULL,
                inbound_message_id bigint REFERENCES raw.inbound_messages(inbound_message_id) ON DELETE SET NULL,
                canonical_event_id bigint REFERENCES integration.canonical_events(canonical_event_id) ON DELETE SET NULL,
                event_category text NOT NULL,
                event_type text NOT NULL,
                message_type text,
                trigger_event text,
                patient_ref text NOT NULL REFERENCES flow_core.patient_identities(patient_ref),
                patient_display_ref text NOT NULL,
                encounter_ref text REFERENCES flow_core.encounters(encounter_ref) ON DELETE SET NULL,
                occurred_at timestamptz NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT now(),
                from_source_location_code text,
                to_source_location_code text,
                from_facility_space_id bigint REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE SET NULL,
                to_facility_space_id bigint REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE SET NULL,
                point_of_care text,
                room text,
                bed text,
                patient_class text,
                fhir_encounter_status text,
                fhir_encounter_class text,
                service_line text,
                priority text,
                diagnosis_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                order_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                observation_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                medication_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
                cancellation_of_event_id text REFERENCES flow_core.flow_events(flow_event_id) ON DELETE SET NULL,
                raw_message_hash text,
                source_protocol text NOT NULL DEFAULT 'hl7v2',
                deidentified boolean NOT NULL DEFAULT true,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_flow_events_time
                ON flow_core.flow_events(occurred_at);

            CREATE INDEX IF NOT EXISTS idx_flow_events_patient_time
                ON flow_core.flow_events(patient_ref, occurred_at);

            CREATE INDEX IF NOT EXISTS idx_flow_events_encounter_time
                ON flow_core.flow_events(encounter_ref, occurred_at);

            CREATE INDEX IF NOT EXISTS idx_flow_events_to_space_time
                ON flow_core.flow_events(to_facility_space_id, occurred_at);

            CREATE INDEX IF NOT EXISTS idx_flow_events_category_type
                ON flow_core.flow_events(event_category, event_type);

            CREATE INDEX IF NOT EXISTS idx_flow_events_metadata
                ON flow_core.flow_events USING gin(metadata);

            CREATE INDEX IF NOT EXISTS idx_flow_events_inbound_message
                ON flow_core.flow_events(inbound_message_id)
                WHERE inbound_message_id IS NOT NULL;

            CREATE TABLE IF NOT EXISTS flow_core.fhir_bundle_cache (
                fhir_bundle_cache_id bigserial PRIMARY KEY,
                flow_event_id text NOT NULL REFERENCES flow_core.flow_events(flow_event_id) ON DELETE CASCADE,
                bundle_type text NOT NULL,
                generated_at timestamptz NOT NULL DEFAULT now(),
                bundle_json jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (flow_event_id, bundle_type)
            );

            CREATE TABLE IF NOT EXISTS flow_core.occupancy_snapshots (
                occupancy_snapshot_id bigserial PRIMARY KEY,
                snapshot_at timestamptz NOT NULL,
                facility_space_id bigint NOT NULL REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE CASCADE,
                active_patient_count integer NOT NULL CHECK (active_patient_count >= 0),
                service_line_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                acuity_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                generated_from_event_id text REFERENCES flow_core.flow_events(flow_event_id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (snapshot_at, facility_space_id)
            );

            CREATE INDEX IF NOT EXISTS idx_flow_occupancy_space_time
                ON flow_core.occupancy_snapshots(facility_space_id, snapshot_at);

            CREATE TABLE IF NOT EXISTS flow_realtime.subscription_clients (
                subscription_client_id bigserial PRIMARY KEY,
                client_code text NOT NULL UNIQUE,
                client_name text NOT NULL,
                channel_type text NOT NULL CHECK (channel_type IN ('sse', 'websocket', 'webhook', 'fhir_subscription', 'kafka')),
                topic_filter jsonb NOT NULL DEFAULT '{}'::jsonb,
                minimum_role text,
                last_connected_at timestamptz,
                enabled boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE IF NOT EXISTS flow_realtime.delivery_cursors (
                delivery_cursor_id bigserial PRIMARY KEY,
                subscription_client_id bigint NOT NULL REFERENCES flow_realtime.subscription_clients(subscription_client_id) ON DELETE CASCADE,
                last_flow_event_id text REFERENCES flow_core.flow_events(flow_event_id) ON DELETE SET NULL,
                last_delivered_at timestamptz,
                delivery_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (subscription_client_id)
            );

            COMMENT ON TABLE flow_core.patient_identities IS
                'Deidentified patient references for patient-flow replay. Do not store MRNs or demographics.';

            COMMENT ON TABLE flow_core.flow_events IS
                'Navigator-optimized normalized patient-flow projection. Raw payload truth remains in raw.inbound_messages.';
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS flow_realtime.delivery_cursors;
            DROP TABLE IF EXISTS flow_realtime.subscription_clients;
            DROP TABLE IF EXISTS flow_core.occupancy_snapshots;
            DROP TABLE IF EXISTS flow_core.fhir_bundle_cache;
            DROP TABLE IF EXISTS flow_core.flow_events;
            DROP TABLE IF EXISTS flow_core.encounters;
            DROP TABLE IF EXISTS flow_core.patient_identities;
            DROP SCHEMA IF EXISTS flow_realtime;
            DROP SCHEMA IF EXISTS flow_core;
        SQL);
    }
};
