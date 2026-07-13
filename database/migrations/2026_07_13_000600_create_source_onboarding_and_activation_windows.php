<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                    'purge_user_identity'
                ));

            CREATE TABLE integration.source_onboarding_versions (
                source_onboarding_version_id bigserial PRIMARY KEY,
                onboarding_version_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                version_number integer NOT NULL CHECK (version_number > 0),
                previous_version_id bigint REFERENCES integration.source_onboarding_versions(source_onboarding_version_id) ON DELETE RESTRICT,
                system_version varchar(120),
                protocol_profile varchar(160),
                owner_name varchar(160),
                steward_name varchar(160),
                network_route_key varchar(160),
                data_classification varchar(40) NOT NULL DEFAULT 'unknown',
                permitted_purpose varchar(500),
                phi_permission_basis varchar(160),
                retention_policy_key varchar(120),
                retention_days integer,
                credential_strategy varchar(120),
                conformance_status varchar(40) NOT NULL DEFAULT 'not_tested',
                support_entitlement varchar(40) NOT NULL DEFAULT 'unknown',
                vendor_support_identifier varchar(160),
                maintenance_timezone varchar(64),
                contacts jsonb NOT NULL DEFAULT '[]'::jsonb,
                maintenance_windows jsonb NOT NULL DEFAULT '[]'::jsonb,
                slo_definition jsonb NOT NULL DEFAULT '{}'::jsonb,
                profile_sha256 char(64) NOT NULL,
                change_reason text NOT NULL,
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_onboarding_versions_number_uniq UNIQUE (source_id, version_number),
                CONSTRAINT source_onboarding_versions_classification_chk CHECK (
                    data_classification IN ('unknown', 'public', 'internal', 'confidential', 'restricted_phi')
                ),
                CONSTRAINT source_onboarding_versions_conformance_chk CHECK (
                    conformance_status IN ('not_tested', 'planned', 'testing', 'passed', 'failed', 'expired')
                ),
                CONSTRAINT source_onboarding_versions_support_chk CHECK (
                    support_entitlement IN ('unknown', 'none', 'standard', 'premium', 'critical')
                ),
                CONSTRAINT source_onboarding_versions_retention_chk CHECK (
                    retention_days IS NULL OR retention_days BETWEEN 1 AND 36500
                ),
                CONSTRAINT source_onboarding_versions_contacts_chk CHECK (jsonb_typeof(contacts) = 'array'),
                CONSTRAINT source_onboarding_versions_maintenance_chk CHECK (jsonb_typeof(maintenance_windows) = 'array'),
                CONSTRAINT source_onboarding_versions_slo_chk CHECK (jsonb_typeof(slo_definition) = 'object'),
                CONSTRAINT source_onboarding_versions_hash_chk CHECK (profile_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT source_onboarding_versions_reason_chk CHECK (length(change_reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX source_onboarding_versions_source_created_idx
                ON integration.source_onboarding_versions (source_id, version_number DESC);

            CREATE TABLE integration.source_evidence_records (
                source_evidence_record_id bigserial PRIMARY KEY,
                evidence_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                evidence_type varchar(60) NOT NULL,
                evidence_status varchar(30) NOT NULL,
                display_label varchar(190) NOT NULL,
                reference_uri text NOT NULL,
                reference_sha256 char(64) NOT NULL,
                artifact_sha256 char(64),
                issued_at timestamptz,
                expires_at timestamptz,
                supersedes_evidence_id bigint REFERENCES integration.source_evidence_records(source_evidence_record_id) ON DELETE RESTRICT,
                recorded_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                reason text NOT NULL,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_evidence_records_type_chk CHECK (evidence_type IN (
                    'contract', 'baa', 'dua', 'conformance_report', 'vendor_approval',
                    'customer_uat', 'test_results', 'security_review', 'change_ticket',
                    'cutover_plan', 'rollback_plan'
                )),
                CONSTRAINT source_evidence_records_status_chk CHECK (
                    evidence_status IN ('pending', 'verified', 'not_required', 'failed', 'expired', 'revoked')
                ),
                CONSTRAINT source_evidence_records_reference_hash_chk CHECK (reference_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT source_evidence_records_artifact_hash_chk CHECK (
                    artifact_sha256 IS NULL OR artifact_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT source_evidence_records_expiry_chk CHECK (
                    expires_at IS NULL OR issued_at IS NULL OR expires_at > issued_at
                ),
                CONSTRAINT source_evidence_records_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT source_evidence_records_metadata_chk CHECK (jsonb_typeof(metadata) = 'object')
            );

            CREATE INDEX source_evidence_records_current_idx
                ON integration.source_evidence_records (source_id, evidence_type, source_evidence_record_id DESC);
            CREATE INDEX source_evidence_records_expiry_idx
                ON integration.source_evidence_records (expires_at)
                WHERE evidence_status = 'verified' AND expires_at IS NOT NULL;
            CREATE UNIQUE INDEX source_evidence_records_superseded_once_idx
                ON integration.source_evidence_records (supersedes_evidence_id)
                WHERE supersedes_evidence_id IS NOT NULL;

            CREATE TABLE integration.source_readiness_assessments (
                source_readiness_assessment_id bigserial PRIMARY KEY,
                assessment_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                configuration_version_id bigint NOT NULL REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                onboarding_version_id bigint NOT NULL REFERENCES integration.source_onboarding_versions(source_onboarding_version_id) ON DELETE RESTRICT,
                readiness_status varchar(20) NOT NULL,
                readiness_score smallint NOT NULL,
                requirement_results jsonb NOT NULL,
                input_sha256 char(64) NOT NULL,
                evaluated_for_at timestamptz NOT NULL,
                evaluated_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                evaluated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_readiness_assessments_status_chk CHECK (readiness_status IN ('ready', 'not_ready')),
                CONSTRAINT source_readiness_assessments_score_chk CHECK (readiness_score BETWEEN 0 AND 100),
                CONSTRAINT source_readiness_assessments_results_chk CHECK (jsonb_typeof(requirement_results) = 'array'),
                CONSTRAINT source_readiness_assessments_hash_chk CHECK (input_sha256 ~ '^[0-9a-f]{64}$')
            );

            CREATE INDEX source_readiness_assessments_source_idx
                ON integration.source_readiness_assessments (source_id, evaluated_at DESC, source_readiness_assessment_id DESC);

            CREATE TABLE integration.source_activation_windows (
                source_activation_window_id bigserial PRIMARY KEY,
                activation_window_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                configuration_version_id bigint NOT NULL REFERENCES integration.source_configuration_versions(source_configuration_version_id) ON DELETE RESTRICT,
                onboarding_version_id bigint NOT NULL REFERENCES integration.source_onboarding_versions(source_onboarding_version_id) ON DELETE RESTRICT,
                readiness_assessment_id bigint NOT NULL REFERENCES integration.source_readiness_assessments(source_readiness_assessment_id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid NOT NULL UNIQUE REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                status varchar(30) NOT NULL DEFAULT 'pending_approval',
                activate_at timestamptz NOT NULL,
                window_ends_at timestamptz NOT NULL,
                requested_timezone varchar(64) NOT NULL,
                lease_owner varchar(190),
                lease_expires_at timestamptz,
                attempt_count smallint NOT NULL DEFAULT 0,
                max_attempts smallint NOT NULL DEFAULT 3,
                last_error_code varchar(80),
                last_error_summary varchar(500),
                reason text NOT NULL,
                requested_by_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                scheduled_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                scheduled_at timestamptz,
                activated_at timestamptz,
                failed_at timestamptz,
                cancelled_at timestamptz,
                cancelled_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                cancellation_reason text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_activation_windows_status_chk CHECK (
                    status IN ('pending_approval', 'scheduled', 'leased', 'activated', 'failed', 'cancelled', 'expired')
                ),
                CONSTRAINT source_activation_windows_time_chk CHECK (window_ends_at > activate_at),
                CONSTRAINT source_activation_windows_attempts_chk CHECK (
                    attempt_count BETWEEN 0 AND max_attempts AND max_attempts BETWEEN 1 AND 10
                ),
                CONSTRAINT source_activation_windows_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT source_activation_windows_cancellation_chk CHECK (
                    (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL
                        AND length(cancellation_reason) BETWEEN 10 AND 500)
                    OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL
                        AND cancellation_reason IS NULL)
                ),
                CONSTRAINT source_activation_windows_lease_chk CHECK (
                    (status = 'leased' AND lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)
                    OR (status <> 'leased' AND lease_owner IS NULL AND lease_expires_at IS NULL)
                )
            );

            CREATE UNIQUE INDEX source_activation_windows_one_open_source_idx
                ON integration.source_activation_windows (source_id)
                WHERE status IN ('pending_approval', 'scheduled', 'leased');
            CREATE INDEX source_activation_windows_due_idx
                ON integration.source_activation_windows (activate_at, source_activation_window_id)
                WHERE status IN ('scheduled', 'leased');

            CREATE OR REPLACE FUNCTION integration.reject_source_onboarding_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'integration source onboarding, evidence, and readiness ledgers are append-only';
            END;
            $$;

            CREATE TRIGGER source_onboarding_versions_append_only
                BEFORE UPDATE OR DELETE ON integration.source_onboarding_versions
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_onboarding_ledger_mutation();
            CREATE TRIGGER source_evidence_records_append_only
                BEFORE UPDATE OR DELETE ON integration.source_evidence_records
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_onboarding_ledger_mutation();
            CREATE TRIGGER source_readiness_assessments_append_only
                BEFORE UPDATE OR DELETE ON integration.source_readiness_assessments
                FOR EACH ROW EXECUTE FUNCTION integration.reject_source_onboarding_ledger_mutation();

            CREATE OR REPLACE FUNCTION integration.enforce_source_activation_window()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE governed_action text;
            DECLARE governed_subject_type text;
            DECLARE governed_subject_id text;
            DECLARE assessed_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'source activation windows cannot be deleted';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending_approval' THEN
                        RAISE EXCEPTION 'a source activation window must begin pending approval';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM integration.source_configuration_versions
                        WHERE source_configuration_version_id = NEW.configuration_version_id
                          AND source_id = NEW.source_id
                    ) THEN
                        RAISE EXCEPTION 'activation window configuration version does not belong to the source';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM integration.source_onboarding_versions
                        WHERE source_onboarding_version_id = NEW.onboarding_version_id
                          AND source_id = NEW.source_id
                    ) THEN
                        RAISE EXCEPTION 'activation window onboarding version does not belong to the source';
                    END IF;
                    SELECT readiness_status INTO assessed_status
                    FROM integration.source_readiness_assessments
                    WHERE source_readiness_assessment_id = NEW.readiness_assessment_id
                      AND source_id = NEW.source_id
                      AND configuration_version_id = NEW.configuration_version_id
                      AND onboarding_version_id = NEW.onboarding_version_id;
                    IF assessed_status IS DISTINCT FROM 'ready' THEN
                        RAISE EXCEPTION 'activation window requires a matching ready assessment';
                    END IF;
                    SELECT action_type, subject_type, subject_id
                    INTO governed_action, governed_subject_type, governed_subject_id
                    FROM governance.change_requests
                    WHERE change_request_uuid = NEW.governed_change_request_uuid;
                    IF governed_action IS DISTINCT FROM 'schedule_production_source_activation'
                       OR governed_subject_type IS DISTINCT FROM 'source_activation_window'
                       OR governed_subject_id IS DISTINCT FROM NEW.activation_window_uuid::text THEN
                        RAISE EXCEPTION 'activation window requires its exact governed scheduling request';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.activation_window_uuid IS DISTINCT FROM OLD.activation_window_uuid
                   OR NEW.source_id IS DISTINCT FROM OLD.source_id
                   OR NEW.configuration_version_id IS DISTINCT FROM OLD.configuration_version_id
                   OR NEW.onboarding_version_id IS DISTINCT FROM OLD.onboarding_version_id
                   OR NEW.readiness_assessment_id IS DISTINCT FROM OLD.readiness_assessment_id
                   OR NEW.governed_change_request_uuid IS DISTINCT FROM OLD.governed_change_request_uuid
                   OR NEW.activate_at IS DISTINCT FROM OLD.activate_at
                   OR NEW.window_ends_at IS DISTINCT FROM OLD.window_ends_at
                   OR NEW.requested_timezone IS DISTINCT FROM OLD.requested_timezone
                   OR NEW.reason IS DISTINCT FROM OLD.reason
                   OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id
                   OR NEW.max_attempts IS DISTINCT FROM OLD.max_attempts
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'source activation window authority is immutable';
                END IF;

                IF OLD.status IN ('activated', 'failed', 'cancelled', 'expired') THEN
                    RAISE EXCEPTION 'terminal source activation windows are immutable';
                END IF;
                IF OLD.status = 'pending_approval' AND NEW.status NOT IN ('scheduled', 'cancelled', 'expired') THEN
                    RAISE EXCEPTION 'invalid pending activation window transition';
                END IF;
                IF OLD.status = 'scheduled' AND NEW.status NOT IN ('leased', 'cancelled', 'failed', 'expired') THEN
                    RAISE EXCEPTION 'invalid scheduled activation window transition';
                END IF;
                IF NEW.updated_at > now() + interval '1 day' THEN
                    RAISE EXCEPTION 'activation window updates cannot use a future authority timestamp';
                END IF;
                IF OLD.status = 'leased' AND NEW.status = 'leased' AND OLD.lease_expires_at > NEW.updated_at THEN
                    RAISE EXCEPTION 'an unexpired activation lease cannot be replaced';
                END IF;
                IF OLD.status = 'leased' AND NEW.status NOT IN ('leased', 'activated', 'failed', 'cancelled', 'expired') THEN
                    RAISE EXCEPTION 'invalid leased activation window transition';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER source_activation_windows_authority
                BEFORE INSERT OR UPDATE OR DELETE ON integration.source_activation_windows
                FOR EACH ROW EXECUTE FUNCTION integration.enforce_source_activation_window();
        SQL);

        $this->backfillOnboardingVersions();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS source_activation_windows_authority ON integration.source_activation_windows;
            DROP FUNCTION IF EXISTS integration.enforce_source_activation_window();
            DROP TABLE IF EXISTS integration.source_activation_windows;

            DROP TRIGGER IF EXISTS source_readiness_assessments_append_only ON integration.source_readiness_assessments;
            DROP TRIGGER IF EXISTS source_evidence_records_append_only ON integration.source_evidence_records;
            DROP TRIGGER IF EXISTS source_onboarding_versions_append_only ON integration.source_onboarding_versions;
            DROP TABLE IF EXISTS integration.source_readiness_assessments;
            DROP TABLE IF EXISTS integration.source_evidence_records;
            DROP TABLE IF EXISTS integration.source_onboarding_versions;
            DROP FUNCTION IF EXISTS integration.reject_source_onboarding_ledger_mutation();

            -- The expanded action constraint intentionally remains. Governance rows are
            -- append-only, so a safe rollback cannot reject an action already recorded.
        SQL);
    }

    private function backfillOnboardingVersions(): void
    {
        DB::table('integration.sources')->orderBy('source_id')->get()->each(function (object $source): void {
            $metadata = $this->decodeMap($source->metadata ?? null);
            $profile = [
                'system_version' => null,
                'protocol_profile' => null,
                'owner_name' => $this->nullableString($metadata['owner'] ?? null),
                'steward_name' => null,
                'network_route_key' => null,
                'data_classification' => (bool) $source->phi_allowed ? 'restricted_phi' : 'confidential',
                'permitted_purpose' => null,
                'phi_permission_basis' => null,
                'retention_policy_key' => null,
                'retention_days' => null,
                'credential_strategy' => null,
                'conformance_status' => 'not_tested',
                'support_entitlement' => 'unknown',
                'vendor_support_identifier' => null,
                'maintenance_timezone' => null,
                'contacts' => [],
                'maintenance_windows' => [],
                'slo_definition' => (object) [],
            ];

            DB::table('integration.source_onboarding_versions')->insert([
                'onboarding_version_uuid' => (string) \Illuminate\Support\Str::uuid7(),
                'source_id' => $source->source_id,
                'version_number' => 1,
                'previous_version_id' => null,
                ...$profile,
                'contacts' => '[]',
                'maintenance_windows' => '[]',
                'slo_definition' => '{}',
                'profile_sha256' => $this->hash($profile),
                'change_reason' => 'Backfilled incomplete onboarding profile for explicit review.',
                'created_by_user_id' => null,
                'created_at' => $source->created_at ?? now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        ksort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
};
