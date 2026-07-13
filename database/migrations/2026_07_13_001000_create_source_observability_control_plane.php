<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLO_KEYS = [
        'availability_percent',
        'freshness_minutes',
        'completeness_percent',
        'latency_ms',
        'error_rate_percent',
        'acknowledgement_seconds',
        'reconciliation_variance_percent',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration.source_slo_definitions (
                source_slo_definition_id bigserial PRIMARY KEY,
                slo_definition_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_onboarding_version_id bigint NOT NULL REFERENCES integration.source_onboarding_versions(source_onboarding_version_id) ON DELETE RESTRICT,
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_definition_id bigint REFERENCES integration.source_slo_definitions(source_slo_definition_id) ON DELETE RESTRICT,
                definition_status varchar(20) NOT NULL,
                evaluation_window_minutes integer NOT NULL DEFAULT 1440,
                availability_percent numeric(7,4),
                freshness_minutes integer,
                completeness_percent numeric(7,4),
                latency_ms integer,
                error_rate_percent numeric(7,4),
                acknowledgement_seconds integer,
                reconciliation_variance_percent numeric(7,4),
                definition_sha256 char(64) NOT NULL,
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_slo_definitions_onboarding_uniq UNIQUE (source_onboarding_version_id),
                CONSTRAINT source_slo_definitions_source_version_uniq UNIQUE (source_id, version_number),
                CONSTRAINT source_slo_definitions_status_chk CHECK (definition_status IN ('complete', 'incomplete')),
                CONSTRAINT source_slo_definitions_window_chk CHECK (evaluation_window_minutes BETWEEN 5 AND 10080),
                CONSTRAINT source_slo_definitions_availability_chk CHECK (availability_percent IS NULL OR availability_percent BETWEEN 90 AND 100),
                CONSTRAINT source_slo_definitions_freshness_chk CHECK (freshness_minutes IS NULL OR freshness_minutes BETWEEN 1 AND 10080),
                CONSTRAINT source_slo_definitions_completeness_chk CHECK (completeness_percent IS NULL OR completeness_percent BETWEEN 0 AND 100),
                CONSTRAINT source_slo_definitions_latency_chk CHECK (latency_ms IS NULL OR latency_ms BETWEEN 1 AND 3600000),
                CONSTRAINT source_slo_definitions_error_rate_chk CHECK (error_rate_percent IS NULL OR error_rate_percent BETWEEN 0 AND 100),
                CONSTRAINT source_slo_definitions_acknowledgement_chk CHECK (acknowledgement_seconds IS NULL OR acknowledgement_seconds BETWEEN 1 AND 86400),
                CONSTRAINT source_slo_definitions_reconciliation_chk CHECK (reconciliation_variance_percent IS NULL OR reconciliation_variance_percent BETWEEN 0 AND 100),
                CONSTRAINT source_slo_definitions_hash_chk CHECK (definition_sha256 ~ '^[0-9a-f]{64}$')
            );

            CREATE UNIQUE INDEX source_slo_definitions_previous_once_idx
                ON integration.source_slo_definitions (previous_definition_id)
                WHERE previous_definition_id IS NOT NULL;
            CREATE INDEX source_slo_definitions_source_current_idx
                ON integration.source_slo_definitions (source_id, version_number DESC, source_slo_definition_id DESC);

            CREATE TABLE integration.health_observations (
                health_observation_id bigserial PRIMARY KEY,
                observation_uuid uuid NOT NULL UNIQUE,
                batch_uuid uuid NOT NULL,
                correlation_uuid uuid NOT NULL,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_configuration_version_id bigint NOT NULL REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                source_onboarding_version_id bigint NOT NULL REFERENCES integration.source_onboarding_versions(source_onboarding_version_id) ON DELETE RESTRICT,
                source_slo_definition_id bigint NOT NULL REFERENCES integration.source_slo_definitions(source_slo_definition_id) ON DELETE RESTRICT,
                observation_status varchar(20) NOT NULL,
                protocol_status varchar(20) NOT NULL,
                protocol_error_code varchar(80),
                maintenance_active boolean NOT NULL DEFAULT false,
                maintenance_window_fingerprint char(64),
                window_started_at timestamptz NOT NULL,
                window_ended_at timestamptz NOT NULL,
                observed_at timestamptz NOT NULL,
                freshness_expires_at timestamptz NOT NULL,
                collector_origin varchar(20) NOT NULL,
                recorded_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                summary_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                queue_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                runtime_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                evidence_sha256 char(64) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT health_observations_status_chk CHECK (observation_status IN ('healthy', 'degraded', 'failed', 'unknown', 'maintenance', 'disabled')),
                CONSTRAINT health_observations_protocol_status_chk CHECK (protocol_status IN ('healthy', 'degraded', 'failed', 'unobserved', 'unsupported', 'disabled')),
                CONSTRAINT health_observations_protocol_error_chk CHECK (protocol_error_code IS NULL OR protocol_error_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT health_observations_maintenance_fingerprint_chk CHECK (
                    (maintenance_active AND maintenance_window_fingerprint ~ '^[0-9a-f]{64}$')
                    OR (NOT maintenance_active AND maintenance_window_fingerprint IS NULL)
                ),
                CONSTRAINT health_observations_window_chk CHECK (window_started_at < window_ended_at AND window_ended_at = observed_at),
                CONSTRAINT health_observations_freshness_chk CHECK (freshness_expires_at > observed_at),
                CONSTRAINT health_observations_origin_chk CHECK (collector_origin IN ('scheduled', 'manual', 'runtime')),
                CONSTRAINT health_observations_summary_chk CHECK (jsonb_typeof(summary_counts) = 'object'),
                CONSTRAINT health_observations_queue_chk CHECK (jsonb_typeof(queue_state) = 'object'),
                CONSTRAINT health_observations_runtime_chk CHECK (jsonb_typeof(runtime_state) = 'object'),
                CONSTRAINT health_observations_evidence_hash_chk CHECK (evidence_sha256 ~ '^[0-9a-f]{64}$')
            );

            CREATE INDEX health_observations_source_observed_idx
                ON integration.health_observations (source_id, observed_at DESC, health_observation_id DESC);
            CREATE INDEX health_observations_batch_idx
                ON integration.health_observations (batch_uuid, source_id);
            CREATE INDEX health_observations_status_idx
                ON integration.health_observations (observation_status, observed_at DESC);

            CREATE TABLE integration.health_observation_metrics (
                health_observation_metric_id bigserial PRIMARY KEY,
                health_observation_id bigint NOT NULL REFERENCES integration.health_observations(health_observation_id) ON DELETE RESTRICT,
                metric_key varchar(50) NOT NULL,
                metric_status varchar(20) NOT NULL,
                measured_value numeric(20,6),
                target_value numeric(20,6),
                comparator varchar(4) NOT NULL,
                unit varchar(24) NOT NULL,
                sample_count bigint NOT NULL DEFAULT 0,
                evidence_code varchar(80) NOT NULL,
                details jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT health_observation_metrics_observation_key_uniq UNIQUE (health_observation_id, metric_key),
                CONSTRAINT health_observation_metrics_key_chk CHECK (metric_key IN (
                    'availability', 'freshness', 'completeness', 'latency', 'error_rate',
                    'acknowledgement', 'reconciliation_variance'
                )),
                CONSTRAINT health_observation_metrics_status_chk CHECK (metric_status IN ('met', 'breached', 'unknown', 'not_applicable')),
                CONSTRAINT health_observation_metrics_comparator_chk CHECK (comparator IN ('gte', 'lte')),
                CONSTRAINT health_observation_metrics_unit_chk CHECK (unit IN ('percent', 'minutes', 'milliseconds', 'seconds')),
                CONSTRAINT health_observation_metrics_sample_chk CHECK (sample_count >= 0),
                CONSTRAINT health_observation_metrics_evidence_chk CHECK (evidence_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT health_observation_metrics_details_chk CHECK (jsonb_typeof(details) = 'object'),
                CONSTRAINT health_observation_metrics_value_chk CHECK (
                    (metric_status IN ('met', 'breached') AND measured_value IS NOT NULL AND target_value IS NOT NULL)
                    OR (metric_status IN ('unknown', 'not_applicable'))
                )
            );

            CREATE INDEX health_observation_metrics_key_status_idx
                ON integration.health_observation_metrics (metric_key, metric_status, health_observation_id DESC);

            CREATE TABLE integration.slo_breaches (
                slo_breach_id bigserial PRIMARY KEY,
                breach_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                source_slo_definition_id bigint NOT NULL REFERENCES integration.source_slo_definitions(source_slo_definition_id) ON DELETE RESTRICT,
                metric_key varchar(50) NOT NULL,
                opened_health_observation_id bigint NOT NULL REFERENCES integration.health_observations(health_observation_id) ON DELETE RESTRICT,
                opened_health_observation_metric_id bigint NOT NULL REFERENCES integration.health_observation_metrics(health_observation_metric_id) ON DELETE RESTRICT,
                opened_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT slo_breaches_metric_chk CHECK (metric_key IN (
                    'availability', 'freshness', 'completeness', 'latency', 'error_rate',
                    'acknowledgement', 'reconciliation_variance'
                ))
            );

            CREATE INDEX slo_breaches_source_metric_idx
                ON integration.slo_breaches (source_id, metric_key, opened_at DESC, slo_breach_id DESC);

            CREATE TABLE integration.slo_breach_events (
                slo_breach_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                slo_breach_id bigint NOT NULL REFERENCES integration.slo_breaches(slo_breach_id) ON DELETE RESTRICT,
                health_observation_id bigint NOT NULL REFERENCES integration.health_observations(health_observation_id) ON DELETE RESTRICT,
                event_type varchar(30) NOT NULL,
                status_after varchar(20) NOT NULL,
                notification_suppressed boolean NOT NULL DEFAULT false,
                reason_code varchar(80) NOT NULL,
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                incident_reference_hash char(64),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT slo_breach_events_type_chk CHECK (event_type IN (
                    'opened', 'continued', 'suppressed', 'resumed', 'acknowledged',
                    'recovered', 'escalated', 'incident_linked', 'reviewed'
                )),
                CONSTRAINT slo_breach_events_status_chk CHECK (status_after IN ('open', 'suppressed', 'acknowledged', 'recovered')),
                CONSTRAINT slo_breach_events_reason_chk CHECK (reason_code ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT slo_breach_events_incident_chk CHECK (incident_reference_hash IS NULL OR incident_reference_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT slo_breach_events_metadata_chk CHECK (jsonb_typeof(metadata) = 'object'),
                CONSTRAINT slo_breach_events_suppression_chk CHECK (
                    notification_suppressed = (status_after = 'suppressed')
                    OR status_after = 'acknowledged'
                )
            );

            CREATE INDEX slo_breach_events_current_idx
                ON integration.slo_breach_events (slo_breach_id, slo_breach_event_id DESC);
            CREATE INDEX slo_breach_events_observation_idx
                ON integration.slo_breach_events (health_observation_id);

            CREATE TABLE integration.source_health_current (
                source_id bigint PRIMARY KEY REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                health_observation_id bigint NOT NULL UNIQUE REFERENCES integration.health_observations(health_observation_id) ON DELETE RESTRICT,
                source_slo_definition_id bigint NOT NULL REFERENCES integration.source_slo_definitions(source_slo_definition_id) ON DELETE RESTRICT,
                observation_status varchar(20) NOT NULL,
                maintenance_active boolean NOT NULL,
                observed_at timestamptz NOT NULL,
                freshness_expires_at timestamptz NOT NULL,
                summary_counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                queue_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                runtime_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                projection_version bigint NOT NULL DEFAULT 1,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_health_current_status_chk CHECK (observation_status IN ('healthy', 'degraded', 'failed', 'unknown', 'maintenance', 'disabled')),
                CONSTRAINT source_health_current_freshness_chk CHECK (freshness_expires_at > observed_at),
                CONSTRAINT source_health_current_summary_chk CHECK (jsonb_typeof(summary_counts) = 'object'),
                CONSTRAINT source_health_current_queue_chk CHECK (jsonb_typeof(queue_state) = 'object'),
                CONSTRAINT source_health_current_runtime_chk CHECK (jsonb_typeof(runtime_state) = 'object'),
                CONSTRAINT source_health_current_version_chk CHECK (projection_version > 0)
            );

            CREATE INDEX source_health_current_status_idx
                ON integration.source_health_current (observation_status, freshness_expires_at);

            CREATE OR REPLACE FUNCTION integration.reject_source_observability_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'source observability authorities are append-only';
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_slo_definition_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE onboarding_source_id bigint;
            DECLARE onboarding_version_number integer;
            DECLARE previous_source_id bigint;
            DECLARE previous_version_number integer;
            BEGIN
                SELECT source_id, version_number
                INTO onboarding_source_id, onboarding_version_number
                FROM integration.source_onboarding_versions
                WHERE source_onboarding_version_id = NEW.source_onboarding_version_id;

                IF onboarding_source_id IS DISTINCT FROM NEW.source_id
                   OR onboarding_version_number IS DISTINCT FROM NEW.version_number THEN
                    RAISE EXCEPTION 'SLO definition onboarding authority does not match its source and version';
                END IF;

                IF NEW.previous_definition_id IS NULL AND NEW.version_number <> 1 THEN
                    RAISE EXCEPTION 'non-initial SLO definitions require the previous definition';
                END IF;
                IF NEW.previous_definition_id IS NOT NULL THEN
                    SELECT source_id, version_number
                    INTO previous_source_id, previous_version_number
                    FROM integration.source_slo_definitions
                    WHERE source_slo_definition_id = NEW.previous_definition_id;
                    IF previous_source_id IS DISTINCT FROM NEW.source_id
                       OR previous_version_number IS DISTINCT FROM NEW.version_number - 1 THEN
                        RAISE EXCEPTION 'SLO definition history must be contiguous within one source';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_health_observation_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM integration.source_configuration_versions
                    WHERE source_configuration_version_id = NEW.source_configuration_version_id
                      AND source_id = NEW.source_id
                ) THEN
                    RAISE EXCEPTION 'health observation configuration version does not belong to the source';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM integration.source_onboarding_versions
                    WHERE source_onboarding_version_id = NEW.source_onboarding_version_id
                      AND source_id = NEW.source_id
                ) THEN
                    RAISE EXCEPTION 'health observation onboarding version does not belong to the source';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM integration.source_slo_definitions
                    WHERE source_slo_definition_id = NEW.source_slo_definition_id
                      AND source_id = NEW.source_id
                      AND source_onboarding_version_id = NEW.source_onboarding_version_id
                ) THEN
                    RAISE EXCEPTION 'health observation SLO definition does not match its source and onboarding version';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_slo_breach_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM integration.health_observations observation
                    JOIN integration.health_observation_metrics metric
                      ON metric.health_observation_id = observation.health_observation_id
                    WHERE observation.health_observation_id = NEW.opened_health_observation_id
                      AND observation.source_id = NEW.source_id
                      AND observation.source_slo_definition_id = NEW.source_slo_definition_id
                      AND metric.health_observation_metric_id = NEW.opened_health_observation_metric_id
                      AND metric.metric_key = NEW.metric_key
                      AND metric.metric_status = 'breached'
                ) THEN
                    RAISE EXCEPTION 'SLO breach opening evidence does not match its source, definition, and metric';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_slo_breach_event_authority()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM integration.slo_breaches breach
                    JOIN integration.health_observations observation
                      ON observation.health_observation_id = NEW.health_observation_id
                    WHERE breach.slo_breach_id = NEW.slo_breach_id
                      AND observation.source_id = breach.source_id
                ) THEN
                    RAISE EXCEPTION 'SLO breach event observation does not belong to the breach source';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE OR REPLACE FUNCTION integration.enforce_source_health_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE observation integration.health_observations%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'source health projection cannot be deleted';
                END IF;
                IF TG_OP = 'UPDATE' AND (
                    NEW.source_id IS DISTINCT FROM OLD.source_id
                    OR NEW.health_observation_id <= OLD.health_observation_id
                    OR NEW.projection_version <> OLD.projection_version + 1
                ) THEN
                    RAISE EXCEPTION 'source health projection must advance monotonically';
                END IF;

                SELECT * INTO observation
                FROM integration.health_observations
                WHERE health_observation_id = NEW.health_observation_id;
                IF observation.source_id IS DISTINCT FROM NEW.source_id
                   OR observation.source_slo_definition_id IS DISTINCT FROM NEW.source_slo_definition_id
                   OR observation.observation_status IS DISTINCT FROM NEW.observation_status
                   OR observation.maintenance_active IS DISTINCT FROM NEW.maintenance_active
                   OR observation.observed_at IS DISTINCT FROM NEW.observed_at
                   OR observation.freshness_expires_at IS DISTINCT FROM NEW.freshness_expires_at
                   OR observation.summary_counts IS DISTINCT FROM NEW.summary_counts
                   OR observation.queue_state IS DISTINCT FROM NEW.queue_state
                   OR observation.runtime_state IS DISTINCT FROM NEW.runtime_state THEN
                    RAISE EXCEPTION 'source health projection must exactly reflect its observation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_slo_definitions_authority
                BEFORE INSERT ON integration.source_slo_definitions
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_slo_definition_authority();
            CREATE TRIGGER source_slo_definitions_append_only
                BEFORE UPDATE OR DELETE ON integration.source_slo_definitions
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_observability_ledger_mutation();
            CREATE TRIGGER health_observations_authority
                BEFORE INSERT ON integration.health_observations
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_health_observation_authority();
            CREATE TRIGGER health_observations_append_only
                BEFORE UPDATE OR DELETE ON integration.health_observations
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_observability_ledger_mutation();
            CREATE TRIGGER health_observation_metrics_append_only
                BEFORE UPDATE OR DELETE ON integration.health_observation_metrics
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_observability_ledger_mutation();
            CREATE TRIGGER slo_breaches_authority
                BEFORE INSERT ON integration.slo_breaches
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_slo_breach_authority();
            CREATE TRIGGER slo_breaches_append_only
                BEFORE UPDATE OR DELETE ON integration.slo_breaches
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_observability_ledger_mutation();
            CREATE TRIGGER slo_breach_events_authority
                BEFORE INSERT ON integration.slo_breach_events
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_slo_breach_event_authority();
            CREATE TRIGGER slo_breach_events_append_only
                BEFORE UPDATE OR DELETE ON integration.slo_breach_events
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_observability_ledger_mutation();
            CREATE TRIGGER source_health_current_authority
                BEFORE INSERT OR UPDATE OR DELETE ON integration.source_health_current
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_health_projection();

            CREATE TRIGGER source_slo_definitions_clinical_content_guard
                BEFORE INSERT ON integration.source_slo_definitions
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER health_observations_clinical_content_guard
                BEFORE INSERT ON integration.health_observations
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER health_observation_metrics_clinical_content_guard
                BEFORE INSERT ON integration.health_observation_metrics
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER slo_breaches_clinical_content_guard
                BEFORE INSERT ON integration.slo_breaches
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER slo_breach_events_clinical_content_guard
                BEFORE INSERT ON integration.slo_breach_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
            CREATE TRIGGER source_health_current_clinical_content_guard
                BEFORE INSERT OR UPDATE ON integration.source_health_current
                FOR EACH ROW EXECUTE FUNCTION raw.reject_clinical_content_diagnostic();
        SQL);

        $this->backfillSloDefinitions();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integration.source_health_current;
            DROP TABLE IF EXISTS integration.slo_breach_events;
            DROP TABLE IF EXISTS integration.slo_breaches;
            DROP TABLE IF EXISTS integration.health_observation_metrics;
            DROP TABLE IF EXISTS integration.health_observations;
            DROP TABLE IF EXISTS integration.source_slo_definitions;
            DROP FUNCTION IF EXISTS integration.enforce_source_health_projection();
            DROP FUNCTION IF EXISTS integration.enforce_slo_breach_event_authority();
            DROP FUNCTION IF EXISTS integration.enforce_slo_breach_authority();
            DROP FUNCTION IF EXISTS integration.enforce_health_observation_authority();
            DROP FUNCTION IF EXISTS integration.enforce_source_slo_definition_authority();
            DROP FUNCTION IF EXISTS integration.reject_source_observability_ledger_mutation();
        SQL);
    }

    private function backfillSloDefinitions(): void
    {
        $previousBySource = [];
        DB::table('integration.source_onboarding_versions')
            ->orderBy('source_id')
            ->orderBy('version_number')
            ->each(function (object $onboarding) use (&$previousBySource): void {
                $sourceId = (int) $onboarding->source_id;
                $definition = $this->normalizeDefinition($onboarding->slo_definition);
                $definitionId = (int) DB::table('integration.source_slo_definitions')->insertGetId([
                    'slo_definition_uuid' => (string) \Illuminate\Support\Str::uuid7(),
                    'source_id' => $sourceId,
                    'source_onboarding_version_id' => (int) $onboarding->source_onboarding_version_id,
                    'version_number' => (int) $onboarding->version_number,
                    'previous_definition_id' => $previousBySource[$sourceId] ?? null,
                    'definition_status' => $definition['definition_status'],
                    'evaluation_window_minutes' => $definition['evaluation_window_minutes'],
                    ...collect($definition)->except(['definition_status', 'evaluation_window_minutes'])->all(),
                    'definition_sha256' => $this->hash($definition),
                    'created_by_user_id' => $onboarding->created_by_user_id,
                    'created_at' => $onboarding->created_at,
                ], 'source_slo_definition_id');
                $previousBySource[$sourceId] = $definitionId;
            });
    }

    /** @return array<string, int|float|string|null> */
    private function normalizeDefinition(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $decoded = is_array($decoded) ? $decoded : [];
        $definition = [
            'evaluation_window_minutes' => $this->integer($decoded['evaluation_window_minutes'] ?? 1440, 5, 10080) ?? 1440,
            'availability_percent' => $this->decimal($decoded['availability_percent'] ?? null, 90, 100),
            'freshness_minutes' => $this->integer($decoded['freshness_minutes'] ?? null, 1, 10080),
            'completeness_percent' => $this->decimal($decoded['completeness_percent'] ?? null, 0, 100),
            'latency_ms' => $this->integer($decoded['latency_ms'] ?? null, 1, 3600000),
            'error_rate_percent' => $this->decimal($decoded['error_rate_percent'] ?? null, 0, 100),
            'acknowledgement_seconds' => $this->integer($decoded['acknowledgement_seconds'] ?? null, 1, 86400),
            'reconciliation_variance_percent' => $this->decimal($decoded['reconciliation_variance_percent'] ?? null, 0, 100),
        ];
        $complete = collect(self::SLO_KEYS)->every(fn (string $key): bool => $definition[$key] !== null);

        return ['definition_status' => $complete ? 'complete' : 'incomplete', ...$definition];
    }

    private function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }
        $value = (int) $value;

        return $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function decimal(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        ksort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
};
