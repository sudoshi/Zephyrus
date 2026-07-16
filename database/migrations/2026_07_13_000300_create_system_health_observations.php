<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS governance;

            CREATE TABLE IF NOT EXISTS governance.system_health_observations (
                system_health_observation_id bigserial PRIMARY KEY,
                observation_uuid uuid NOT NULL UNIQUE,
                batch_uuid uuid NOT NULL,
                component_key varchar(80) NOT NULL,
                component_label varchar(120) NOT NULL,
                category varchar(80) NOT NULL,
                status varchar(20) NOT NULL,
                summary varchar(300) NOT NULL,
                error_code varchar(80),
                observed_at timestamptz NOT NULL,
                duration_ms integer NOT NULL,
                freshness_expires_at timestamptz NOT NULL,
                required boolean NOT NULL DEFAULT false,
                owner varchar(120) NOT NULL,
                runbook_ref varchar(160) NOT NULL,
                origin varchar(20) NOT NULL,
                details jsonb NOT NULL DEFAULT '{}'::jsonb,
                recorded_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT system_health_component_key_chk CHECK (component_key ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT system_health_status_chk CHECK (status IN ('healthy', 'warning', 'critical', 'unknown', 'disabled')),
                CONSTRAINT system_health_error_code_chk CHECK (error_code IS NULL OR error_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT system_health_duration_chk CHECK (duration_ms >= 0 AND duration_ms <= 300000),
                CONSTRAINT system_health_freshness_chk CHECK (freshness_expires_at >= observed_at),
                CONSTRAINT system_health_origin_chk CHECK (origin IN ('scheduled', 'manual')),
                CONSTRAINT system_health_details_object_chk CHECK (jsonb_typeof(details) = 'object')
            );

            CREATE INDEX IF NOT EXISTS system_health_component_observed_idx
                ON governance.system_health_observations (component_key, observed_at DESC, system_health_observation_id DESC);
            CREATE INDEX IF NOT EXISTS system_health_batch_idx
                ON governance.system_health_observations (batch_uuid);
            CREATE INDEX IF NOT EXISTS system_health_status_observed_idx
                ON governance.system_health_observations (status, observed_at DESC);

            CREATE OR REPLACE FUNCTION governance.reject_system_health_observation_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'system health observations are append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS system_health_observations_append_only
                ON governance.system_health_observations;
            CREATE TRIGGER system_health_observations_append_only
                BEFORE UPDATE OR DELETE ON governance.system_health_observations
                FOR EACH ROW EXECUTE FUNCTION governance.reject_system_health_observation_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS governance.system_health_observations;
            DROP FUNCTION IF EXISTS governance.reject_system_health_observation_mutation();
        SQL);
    }
};
