<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INT-OBS 3 corrective authority.
 *
 * Runtime-pressure transitions answer "is the partner throttled / is our
 * circuit open?" but do not prove how many queue attempts actually ran. This
 * append-only ledger records the bounded execution lifecycle for governed
 * integration jobs so retry budgets are measured rather than inferred from a
 * mutable terminal run status.
 *
 * The database independently verifies source/connector ownership, rejects
 * mutation, and applies the clinical-content tripwire on every insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.source_runtime_execution_events (
                source_runtime_execution_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                event_key char(64) NOT NULL UNIQUE,
                correlation_uuid uuid,
                source_id bigint REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                ingest_run_id bigint REFERENCES raw.ingest_runs(ingest_run_id) ON DELETE RESTRICT,
                event_replay_job_id bigint REFERENCES integration.event_replay_jobs(event_replay_job_id) ON DELETE RESTRICT,
                connector_key varchar(160) NOT NULL,
                job_type varchar(60) NOT NULL,
                event_type varchar(30) NOT NULL,
                attempt_number integer NOT NULL,
                max_attempts integer NOT NULL,
                retry_after_seconds integer,
                available_at timestamptz,
                reason_code varchar(80) NOT NULL,
                duration_ms integer,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                observed_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_runtime_execution_target_chk CHECK (
                    (ingest_run_id IS NOT NULL AND event_replay_job_id IS NULL)
                    OR (ingest_run_id IS NULL AND event_replay_job_id IS NOT NULL)
                ),
                CONSTRAINT source_runtime_execution_event_key_chk CHECK (event_key ~ '^[0-9a-f]{64}$'),
                CONSTRAINT source_runtime_execution_connector_chk CHECK (connector_key ~ '^[a-zA-Z0-9._:-]{1,160}$'),
                CONSTRAINT source_runtime_execution_job_chk CHECK (job_type IN ('fhir_poll', 'protocol_health', 'canonical_replay')),
                CONSTRAINT source_runtime_execution_event_chk CHECK (event_type IN (
                    'queued', 'attempt_started', 'retry_scheduled', 'throttled',
                    'circuit_rejected', 'succeeded', 'terminal_failed'
                )),
                CONSTRAINT source_runtime_execution_attempt_chk CHECK (
                    (event_type = 'queued' AND attempt_number = 0)
                    OR (event_type <> 'queued' AND attempt_number BETWEEN 1 AND 100)
                ),
                CONSTRAINT source_runtime_execution_max_attempts_chk CHECK (max_attempts BETWEEN 1 AND 100),
                CONSTRAINT source_runtime_execution_retry_chk CHECK (retry_after_seconds IS NULL OR retry_after_seconds BETWEEN 0 AND 604800),
                CONSTRAINT source_runtime_execution_available_chk CHECK (
                    (retry_after_seconds IS NULL AND available_at IS NULL)
                    OR (retry_after_seconds IS NOT NULL AND available_at IS NOT NULL)
                ),
                CONSTRAINT source_runtime_execution_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT source_runtime_execution_duration_chk CHECK (duration_ms IS NULL OR duration_ms BETWEEN 0 AND 86400000),
                CONSTRAINT source_runtime_execution_metadata_chk CHECK (jsonb_typeof(metadata) = 'object')
            );

            CREATE INDEX source_runtime_execution_source_idx
                ON integration.source_runtime_execution_events
                (source_id, observed_at DESC, source_runtime_execution_event_id DESC);
            CREATE INDEX source_runtime_execution_run_idx
                ON integration.source_runtime_execution_events
                (ingest_run_id, attempt_number, source_runtime_execution_event_id);
            CREATE INDEX source_runtime_execution_replay_idx
                ON integration.source_runtime_execution_events
                (event_replay_job_id, attempt_number, source_runtime_execution_event_id);

            CREATE OR REPLACE FUNCTION integration.enforce_source_runtime_execution_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                runtime_source_id bigint;
                runtime_connector_key varchar(160);
                replay_source_id bigint;
            BEGIN
                IF NEW.ingest_run_id IS NOT NULL THEN
                    SELECT source_id, connector_key
                      INTO runtime_source_id, runtime_connector_key
                    FROM raw.ingest_runs
                    WHERE ingest_run_id = NEW.ingest_run_id;

                    IF runtime_source_id IS NULL
                       OR NEW.source_id IS DISTINCT FROM runtime_source_id
                       OR NEW.connector_key IS DISTINCT FROM runtime_connector_key THEN
                        RAISE EXCEPTION 'runtime execution event does not match its ingest run authority';
                    END IF;
                ELSE
                    SELECT source_id INTO replay_source_id
                    FROM integration.event_replay_jobs
                    WHERE event_replay_job_id = NEW.event_replay_job_id;

                    IF NOT FOUND OR NEW.connector_key <> 'integration.canonical-replay' THEN
                        RAISE EXCEPTION 'runtime execution event does not match its replay authority';
                    END IF;
                    IF replay_source_id IS NOT NULL AND NEW.source_id IS DISTINCT FROM replay_source_id THEN
                        RAISE EXCEPTION 'runtime execution replay source does not match its replay authority';
                    END IF;
                    IF replay_source_id IS NULL AND NEW.source_id IS NOT NULL THEN
                        RAISE EXCEPTION 'aggregate replay runtime event cannot claim an exact source';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.reject_source_runtime_execution_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'source runtime execution events are append-only';
            END;
            $$;

            CREATE TRIGGER source_runtime_execution_authority
                BEFORE INSERT ON integration.source_runtime_execution_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_runtime_execution_authority();
            CREATE TRIGGER source_runtime_execution_append_only
                BEFORE UPDATE OR DELETE ON integration.source_runtime_execution_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_runtime_execution_mutation();
            CREATE TRIGGER source_runtime_execution_clinical_content_guard
                BEFORE INSERT ON integration.source_runtime_execution_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integration.source_runtime_execution_events;
            DROP FUNCTION IF EXISTS integration.reject_source_runtime_execution_mutation();
            DROP FUNCTION IF EXISTS integration.enforce_source_runtime_execution_authority();
        SQL);
    }
};
