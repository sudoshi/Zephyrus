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
                    'release_quarantined_payload',
                    'apply_clinical_payload_hold',
                    'release_clinical_payload_hold',
                    'purge_clinical_payload',
                    'purge_quarantined_payload',
                    'recover_clinical_payload_integrity',
                    'purge_user_identity'
                ));

            CREATE TABLE raw.payload_objects (
                payload_object_id bigserial PRIMARY KEY,
                payload_uuid uuid NOT NULL UNIQUE,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                organization_id bigint REFERENCES hosp_org.organizations(organization_id) ON DELETE RESTRICT,
                facility_id bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE RESTRICT,
                environment varchar(24) NOT NULL,
                payload_kind varchar(40) NOT NULL,
                data_classification varchar(32) NOT NULL,
                content_type varchar(120) NOT NULL,
                compression varchar(24) NOT NULL,
                cipher varchar(64) NOT NULL,
                storage_disk varchar(80) NOT NULL,
                object_key varchar(500) NOT NULL,
                object_version varchar(190),
                plaintext_sha256 char(64) NOT NULL,
                ciphertext_sha256 char(64) NOT NULL,
                plaintext_bytes bigint NOT NULL CHECK (plaintext_bytes >= 0),
                ciphertext_bytes bigint NOT NULL CHECK (ciphertext_bytes > 0),
                key_reference text NOT NULL,
                key_reference_sha256 char(64) NOT NULL,
                key_provider_scheme varchar(40) NOT NULL,
                key_provider_version varchar(190) NOT NULL,
                wrapped_data_key text NOT NULL,
                key_wrap_nonce varchar(64) NOT NULL,
                retention_policy_key varchar(120) NOT NULL,
                retain_until timestamptz NOT NULL,
                legal_hold boolean NOT NULL DEFAULT false,
                status varchar(32) NOT NULL DEFAULT 'ready',
                last_verified_at timestamptz,
                deleted_at timestamptz,
                created_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_objects_storage_key_uniq UNIQUE (storage_disk, object_key),
                CONSTRAINT payload_objects_environment_chk CHECK (
                    environment IN ('sandbox', 'test', 'testing', 'staging', 'production')
                ),
                CONSTRAINT payload_objects_kind_chk CHECK (
                    payload_kind IN ('raw_message', 'normalized_message', 'fhir_resource', 'canonical_event', 'writeback_draft', 'quarantine_artifact')
                ),
                CONSTRAINT payload_objects_classification_chk CHECK (
                    data_classification IN ('internal', 'confidential', 'restricted_phi')
                ),
                CONSTRAINT payload_objects_compression_chk CHECK (compression IN ('none', 'gzip')),
                CONSTRAINT payload_objects_cipher_chk CHECK (cipher = 'xchacha20-poly1305-ietf'),
                CONSTRAINT payload_objects_status_chk CHECK (
                    status IN ('ready', 'quarantined', 'retention_pending', 'deletion_pending', 'integrity_failed', 'deleted')
                ),
                CONSTRAINT payload_objects_plaintext_hash_chk CHECK (plaintext_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT payload_objects_ciphertext_hash_chk CHECK (ciphertext_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT payload_objects_key_hash_chk CHECK (key_reference_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT payload_objects_retention_chk CHECK (retain_until >= created_at),
                CONSTRAINT payload_objects_deleted_chk CHECK (
                    (status = 'deleted' AND deleted_at IS NOT NULL) OR (status <> 'deleted' AND deleted_at IS NULL)
                )
            );

            CREATE INDEX payload_objects_source_kind_created_idx
                ON raw.payload_objects (source_id, payload_kind, created_at DESC);
            CREATE INDEX payload_objects_retention_idx
                ON raw.payload_objects (retain_until, status)
                WHERE legal_hold = false AND status IN ('ready', 'retention_pending');
            CREATE INDEX payload_objects_status_idx
                ON raw.payload_objects (status, updated_at DESC);

            CREATE OR REPLACE FUNCTION raw.validate_payload_object_scope()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                source_scope integration.sources%ROWTYPE;
            BEGIN
                SELECT * INTO source_scope
                FROM integration.sources
                WHERE source_id = NEW.source_id;

                IF source_scope.source_id IS NULL
                   OR NEW.organization_id IS DISTINCT FROM source_scope.organization_id
                   OR NEW.facility_id IS DISTINCT FROM source_scope.facility_id
                   OR NEW.environment IS DISTINCT FROM source_scope.environment THEN
                    RAISE EXCEPTION 'payload object enterprise scope must exactly match the integration source';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payload_objects_scope_guard
                BEFORE INSERT OR UPDATE OF source_id, organization_id, facility_id, environment
                ON raw.payload_objects
                FOR EACH ROW EXECUTE FUNCTION raw.validate_payload_object_scope();

            CREATE TABLE raw.payload_object_events (
                payload_object_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                payload_object_id bigint NOT NULL REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                event_type varchar(40) NOT NULL,
                from_status varchar(32),
                to_status varchar(32) NOT NULL,
                legal_hold boolean NOT NULL,
                reason_code varchar(120) NOT NULL,
                reason text NOT NULL,
                evidence_sha256 char(64),
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_object_events_type_chk CHECK (
                    event_type IN (
                        'stored', 'verified', 'integrity_failed', 'integrity_recovered', 'quarantined', 'released',
                        'hold_applied', 'hold_released', 'retention_marked', 'deleted',
                        'purge_marked', 'deletion_failed', 'key_rewrapped'
                    )
                ),
                CONSTRAINT payload_object_events_status_chk CHECK (
                    to_status IN ('ready', 'quarantined', 'retention_pending', 'deletion_pending', 'integrity_failed', 'deleted')
                ),
                CONSTRAINT payload_object_events_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT payload_object_events_evidence_chk CHECK (
                    evidence_sha256 IS NULL OR evidence_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT payload_object_events_governance_chk CHECK (
                    event_type NOT IN ('integrity_recovered', 'released', 'hold_applied', 'hold_released', 'purge_marked')
                    OR governed_change_request_uuid IS NOT NULL
                )
            );

            CREATE INDEX payload_object_events_object_time_idx
                ON raw.payload_object_events (payload_object_id, occurred_at DESC, payload_object_event_id DESC);
            CREATE INDEX payload_object_events_source_time_idx
                ON raw.payload_object_events (source_id, occurred_at DESC);

            CREATE TABLE raw.payload_quarantines (
                payload_quarantine_id bigserial PRIMARY KEY,
                quarantine_uuid uuid NOT NULL UNIQUE,
                payload_object_id bigint NOT NULL UNIQUE REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT,
                source_id bigint NOT NULL REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                inbound_message_id bigint REFERENCES raw.inbound_messages(inbound_message_id) ON DELETE RESTRICT,
                reason_category varchar(40) NOT NULL,
                reason_code varchar(120) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'open',
                detected_by varchar(120) NOT NULL,
                details jsonb NOT NULL DEFAULT '{}'::jsonb,
                opened_at timestamptz NOT NULL DEFAULT now(),
                released_at timestamptz,
                purged_at timestamptz,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_quarantines_reason_chk CHECK (
                    reason_category IN ('malware', 'unsafe_content', 'consent', 'policy', 'classification', 'integrity', 'encryption')
                ),
                CONSTRAINT payload_quarantines_status_chk CHECK (status IN ('open', 'released', 'purged')),
                CONSTRAINT payload_quarantines_details_chk CHECK (jsonb_typeof(details) = 'object'),
                CONSTRAINT payload_quarantines_state_time_chk CHECK (
                    (status = 'open' AND released_at IS NULL AND purged_at IS NULL)
                    OR (status = 'released' AND released_at IS NOT NULL AND purged_at IS NULL)
                    OR (status = 'purged' AND purged_at IS NOT NULL)
                )
            );

            CREATE INDEX payload_quarantines_source_status_idx
                ON raw.payload_quarantines (source_id, status, opened_at DESC);

            CREATE TABLE raw.payload_quarantine_events (
                payload_quarantine_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                payload_quarantine_id bigint NOT NULL REFERENCES raw.payload_quarantines(payload_quarantine_id) ON DELETE RESTRICT,
                event_type varchar(24) NOT NULL,
                from_status varchar(24),
                to_status varchar(24) NOT NULL,
                reason text NOT NULL,
                actor_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                governed_change_request_uuid uuid REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_quarantine_events_type_chk CHECK (event_type IN ('opened', 'released', 'purged')),
                CONSTRAINT payload_quarantine_events_status_chk CHECK (to_status IN ('open', 'released', 'purged')),
                CONSTRAINT payload_quarantine_events_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT payload_quarantine_events_governance_chk CHECK (
                    (event_type = 'opened' AND governed_change_request_uuid IS NULL)
                    OR (event_type IN ('released', 'purged') AND governed_change_request_uuid IS NOT NULL)
                )
            );

            CREATE INDEX payload_quarantine_events_quarantine_time_idx
                ON raw.payload_quarantine_events (payload_quarantine_id, occurred_at DESC);

            CREATE TABLE raw.payload_backfill_runs (
                payload_backfill_run_id bigserial PRIMARY KEY,
                run_uuid uuid NOT NULL UNIQUE,
                source_id bigint REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                mode varchar(24) NOT NULL,
                status varchar(24) NOT NULL,
                requested_kinds jsonb NOT NULL DEFAULT '[]'::jsonb,
                scanned_count bigint NOT NULL DEFAULT 0,
                protected_count bigint NOT NULL DEFAULT 0,
                skipped_count bigint NOT NULL DEFAULT 0,
                failed_count bigint NOT NULL DEFAULT 0,
                mismatch_count bigint NOT NULL DEFAULT 0,
                cursor_state jsonb NOT NULL DEFAULT '{}'::jsonb,
                lease_owner varchar(190),
                lease_expires_at timestamptz,
                error_code varchar(120),
                requested_by_user_id bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
                started_at timestamptz,
                completed_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_backfill_runs_mode_chk CHECK (mode IN ('inventory', 'backfill', 'reconcile')),
                CONSTRAINT payload_backfill_runs_status_chk CHECK (status IN ('queued', 'running', 'completed', 'completed_with_errors', 'failed')),
                CONSTRAINT payload_backfill_runs_kinds_chk CHECK (jsonb_typeof(requested_kinds) = 'array'),
                CONSTRAINT payload_backfill_runs_cursor_chk CHECK (jsonb_typeof(cursor_state) = 'object')
            );

            CREATE INDEX payload_backfill_runs_status_idx
                ON raw.payload_backfill_runs (status, created_at DESC);

            CREATE TABLE raw.payload_backfill_items (
                payload_backfill_item_id bigserial PRIMARY KEY,
                source_table varchar(120) NOT NULL,
                source_pk bigint NOT NULL,
                source_column varchar(120) NOT NULL,
                source_id bigint REFERENCES integration.sources(source_id) ON DELETE RESTRICT,
                payload_kind varchar(40) NOT NULL,
                legacy_sha256 char(64) NOT NULL,
                payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT,
                status varchar(24) NOT NULL DEFAULT 'pending',
                attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 25),
                lease_owner varchar(190),
                lease_expires_at timestamptz,
                last_error_code varchar(120),
                first_seen_at timestamptz NOT NULL DEFAULT now(),
                protected_at timestamptz,
                verified_at timestamptz,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_backfill_items_source_uniq UNIQUE (source_table, source_pk, source_column),
                CONSTRAINT payload_backfill_items_kind_chk CHECK (
                    payload_kind IN ('raw_message', 'normalized_message', 'fhir_resource', 'canonical_event', 'writeback_draft')
                ),
                CONSTRAINT payload_backfill_items_status_chk CHECK (
                    status IN ('pending', 'protected', 'verified', 'mismatch', 'failed', 'skipped')
                ),
                CONSTRAINT payload_backfill_items_hash_chk CHECK (legacy_sha256 ~ '^[0-9a-f]{64}$')
            );

            CREATE INDEX payload_backfill_items_status_idx
                ON raw.payload_backfill_items (status, source_table, source_pk);

            CREATE TABLE raw.payload_backfill_events (
                payload_backfill_event_id bigserial PRIMARY KEY,
                event_uuid uuid NOT NULL UNIQUE,
                payload_backfill_run_id bigint NOT NULL REFERENCES raw.payload_backfill_runs(payload_backfill_run_id) ON DELETE RESTRICT,
                payload_backfill_item_id bigint REFERENCES raw.payload_backfill_items(payload_backfill_item_id) ON DELETE RESTRICT,
                event_type varchar(32) NOT NULL,
                status varchar(24) NOT NULL,
                reason_code varchar(120) NOT NULL,
                evidence_sha256 char(64),
                counts jsonb NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT payload_backfill_events_type_chk CHECK (
                    event_type IN ('run_started', 'inventoried', 'lease_acquired', 'protected', 'verified', 'mismatch', 'failed', 'skipped', 'run_completed')
                ),
                CONSTRAINT payload_backfill_events_status_chk CHECK (
                    status IN ('queued', 'running', 'completed', 'completed_with_errors', 'failed', 'pending', 'protected', 'verified', 'mismatch', 'skipped')
                ),
                CONSTRAINT payload_backfill_events_hash_chk CHECK (
                    evidence_sha256 IS NULL OR evidence_sha256 ~ '^[0-9a-f]{64}$'
                ),
                CONSTRAINT payload_backfill_events_counts_chk CHECK (jsonb_typeof(counts) = 'object')
            );

            CREATE INDEX payload_backfill_events_run_time_idx
                ON raw.payload_backfill_events (payload_backfill_run_id, occurred_at DESC, payload_backfill_event_id DESC);

            ALTER TABLE raw.inbound_messages
                ADD COLUMN payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT,
                ADD COLUMN normalized_payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT;
            CREATE INDEX raw_inbound_payload_object_idx ON raw.inbound_messages (payload_object_id);
            CREATE INDEX raw_inbound_normalized_payload_object_idx ON raw.inbound_messages (normalized_payload_object_id);

            ALTER TABLE fhir.resource_versions
                ADD COLUMN payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT;
            CREATE INDEX fhir_resource_payload_object_idx ON fhir.resource_versions (payload_object_id);

            ALTER TABLE integration.canonical_events
                ADD COLUMN payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT;
            CREATE INDEX canonical_events_payload_object_idx ON integration.canonical_events (payload_object_id);

            ALTER TABLE ops.writeback_drafts
                ADD COLUMN payload_object_id bigint REFERENCES raw.payload_objects(payload_object_id) ON DELETE RESTRICT;
            CREATE INDEX writeback_drafts_payload_object_idx ON ops.writeback_drafts (payload_object_id);

            CREATE OR REPLACE FUNCTION raw.reject_payload_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME;
            END;
            $$;

            CREATE TRIGGER payload_object_events_append_only
                BEFORE UPDATE OR DELETE ON raw.payload_object_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_payload_ledger_mutation();
            CREATE TRIGGER payload_quarantine_events_append_only
                BEFORE UPDATE OR DELETE ON raw.payload_quarantine_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_payload_ledger_mutation();
            CREATE TRIGGER payload_backfill_events_append_only
                BEFORE UPDATE OR DELETE ON raw.payload_backfill_events
                FOR EACH ROW EXECUTE FUNCTION raw.reject_payload_ledger_mutation();

            CREATE OR REPLACE FUNCTION raw.guard_payload_quarantine_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'raw.payload_quarantines is a retained lifecycle projection';
                END IF;
                IF ROW(
                    NEW.quarantine_uuid, NEW.payload_object_id, NEW.source_id, NEW.inbound_message_id,
                    NEW.reason_category, NEW.reason_code, NEW.detected_by, NEW.details, NEW.opened_at
                ) IS DISTINCT FROM ROW(
                    OLD.quarantine_uuid, OLD.payload_object_id, OLD.source_id, OLD.inbound_message_id,
                    OLD.reason_category, OLD.reason_code, OLD.detected_by, OLD.details, OLD.opened_at
                ) THEN
                    RAISE EXCEPTION 'payload quarantine authority is immutable';
                END IF;
                IF pg_trigger_depth() < 2
                   AND ROW(NEW.status, NEW.released_at, NEW.purged_at)
                       IS DISTINCT FROM ROW(OLD.status, OLD.released_at, OLD.purged_at) THEN
                    RAISE EXCEPTION 'payload quarantine lifecycle must be changed through an append-only event';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payload_quarantines_projection_guard
                BEFORE UPDATE OR DELETE ON raw.payload_quarantines
                FOR EACH ROW EXECUTE FUNCTION raw.guard_payload_quarantine_projection();

            CREATE OR REPLACE FUNCTION raw.apply_payload_quarantine_event()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                current_row raw.payload_quarantines%ROWTYPE;
                payload_status text;
                governed_action text;
                governed_subject_type text;
                governed_subject_id text;
                governed_expires_at timestamptz;
                governed_decision text;
            BEGIN
                SELECT * INTO current_row FROM raw.payload_quarantines
                    WHERE payload_quarantine_id = NEW.payload_quarantine_id FOR UPDATE;
                IF current_row.payload_quarantine_id IS NULL THEN
                    RAISE EXCEPTION 'payload quarantine event authority is missing';
                END IF;
                IF NOT (NEW.event_type = 'opened' AND NEW.from_status IS NULL AND current_row.status = 'open')
                   AND NEW.from_status IS DISTINCT FROM current_row.status THEN
                    RAISE EXCEPTION 'payload quarantine event from_status mismatch';
                END IF;
                IF NOT (
                    (NEW.event_type = 'opened' AND NEW.from_status IS NULL AND NEW.to_status = 'open')
                    OR (NEW.event_type = 'released' AND NEW.from_status = 'open' AND NEW.to_status = 'released')
                    OR (NEW.event_type = 'purged' AND NEW.from_status = 'open' AND NEW.to_status = 'purged')
                ) THEN
                    RAISE EXCEPTION 'payload quarantine lifecycle transition is invalid';
                END IF;
                IF NEW.event_type IN ('released', 'purged') THEN
                    SELECT request.action_type, request.subject_type, request.subject_id,
                           request.expires_at, decision.decision
                    INTO governed_action, governed_subject_type, governed_subject_id,
                         governed_expires_at, governed_decision
                    FROM governance.change_requests request
                    JOIN governance.change_decisions decision
                      ON decision.change_request_uuid = request.change_request_uuid
                    WHERE request.change_request_uuid = NEW.governed_change_request_uuid;

                    IF governed_decision IS DISTINCT FROM 'approved'
                       OR governed_expires_at <= CURRENT_TIMESTAMP
                       OR governed_subject_type IS DISTINCT FROM 'payload_quarantine'
                       OR governed_subject_id IS DISTINCT FROM current_row.quarantine_uuid::text
                       OR governed_action IS DISTINCT FROM (CASE NEW.event_type
                           WHEN 'released' THEN 'release_quarantined_payload'
                           ELSE 'purge_quarantined_payload'
                       END) THEN
                        RAISE EXCEPTION 'payload quarantine governed authority mismatch';
                    END IF;

                    SELECT status INTO payload_status FROM raw.payload_objects
                    WHERE payload_object_id = current_row.payload_object_id;
                    IF (NEW.event_type = 'released' AND payload_status <> 'quarantined')
                       OR (NEW.event_type = 'purged' AND payload_status <> 'deleted') THEN
                        RAISE EXCEPTION 'payload quarantine object lifecycle mismatch';
                    END IF;
                END IF;
                UPDATE raw.payload_quarantines SET
                    status = NEW.to_status,
                    released_at = CASE WHEN NEW.event_type = 'released' THEN NEW.occurred_at ELSE released_at END,
                    purged_at = CASE WHEN NEW.event_type = 'purged' THEN NEW.occurred_at ELSE purged_at END,
                    updated_at = NEW.occurred_at
                WHERE payload_quarantine_id = NEW.payload_quarantine_id;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payload_quarantine_events_apply_projection
                AFTER INSERT ON raw.payload_quarantine_events
                FOR EACH ROW EXECUTE FUNCTION raw.apply_payload_quarantine_event();

            CREATE OR REPLACE FUNCTION raw.guard_payload_object_projection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'raw.payload_objects is a retained tombstone projection';
                END IF;
                IF ROW(
                    NEW.payload_uuid, NEW.source_id, NEW.organization_id, NEW.facility_id, NEW.environment,
                    NEW.payload_kind, NEW.data_classification, NEW.content_type, NEW.compression, NEW.cipher,
                    NEW.storage_disk, NEW.object_key, NEW.object_version, NEW.plaintext_sha256,
                    NEW.ciphertext_sha256, NEW.plaintext_bytes, NEW.ciphertext_bytes,
                    NEW.key_reference, NEW.key_reference_sha256, NEW.key_provider_scheme,
                    NEW.key_provider_version, NEW.wrapped_data_key, NEW.key_wrap_nonce,
                    NEW.retention_policy_key, NEW.retain_until, NEW.created_by_user_id, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.payload_uuid, OLD.source_id, OLD.organization_id, OLD.facility_id, OLD.environment,
                    OLD.payload_kind, OLD.data_classification, OLD.content_type, OLD.compression, OLD.cipher,
                    OLD.storage_disk, OLD.object_key, OLD.object_version, OLD.plaintext_sha256,
                    OLD.ciphertext_sha256, OLD.plaintext_bytes, OLD.ciphertext_bytes,
                    OLD.key_reference, OLD.key_reference_sha256, OLD.key_provider_scheme,
                    OLD.key_provider_version, OLD.wrapped_data_key, OLD.key_wrap_nonce,
                    OLD.retention_policy_key, OLD.retain_until, OLD.created_by_user_id, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'payload object identity and cryptographic authority are immutable';
                END IF;
                IF pg_trigger_depth() < 2
                   AND ROW(NEW.status, NEW.legal_hold, NEW.last_verified_at, NEW.deleted_at)
                       IS DISTINCT FROM ROW(OLD.status, OLD.legal_hold, OLD.last_verified_at, OLD.deleted_at) THEN
                    RAISE EXCEPTION 'payload object lifecycle must be changed through an append-only event';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payload_objects_projection_guard
                BEFORE UPDATE OR DELETE ON raw.payload_objects
                FOR EACH ROW EXECUTE FUNCTION raw.guard_payload_object_projection();

            CREATE OR REPLACE FUNCTION raw.apply_payload_object_event()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                current_row raw.payload_objects%ROWTYPE;
                governed_action text;
                governed_subject_type text;
                governed_subject_id text;
                governed_expires_at timestamptz;
                governed_decision text;
                governed_quarantine_matches boolean;
            BEGIN
                SELECT * INTO current_row FROM raw.payload_objects
                    WHERE payload_object_id = NEW.payload_object_id FOR UPDATE;
                IF current_row.payload_object_id IS NULL OR current_row.source_id <> NEW.source_id THEN
                    RAISE EXCEPTION 'payload object event source mismatch';
                END IF;
                IF NEW.from_status IS DISTINCT FROM current_row.status THEN
                    RAISE EXCEPTION 'payload object event from_status mismatch';
                END IF;
                IF NOT (
                    (NEW.event_type = 'stored' AND current_row.status = 'ready' AND NEW.to_status = 'ready' AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'verified' AND current_row.status IN ('ready', 'retention_pending') AND NEW.to_status = current_row.status AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'integrity_failed' AND current_row.status IN ('ready', 'retention_pending') AND NEW.to_status = 'integrity_failed' AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'integrity_recovered' AND current_row.status = 'integrity_failed' AND NEW.to_status = 'ready' AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'quarantined' AND current_row.status = 'ready' AND NEW.to_status = 'quarantined' AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'released' AND current_row.status = 'quarantined' AND NEW.to_status = 'ready' AND NEW.legal_hold = current_row.legal_hold)
                    OR (NEW.event_type = 'hold_applied' AND current_row.status NOT IN ('deletion_pending', 'deleted') AND NEW.to_status = current_row.status AND NOT current_row.legal_hold AND NEW.legal_hold)
                    OR (NEW.event_type = 'hold_released' AND current_row.status NOT IN ('deletion_pending', 'deleted') AND NEW.to_status = current_row.status AND current_row.legal_hold AND NOT NEW.legal_hold)
                    OR (NEW.event_type = 'retention_marked' AND current_row.status = 'ready' AND NEW.to_status = 'retention_pending' AND NOT current_row.legal_hold AND NOT NEW.legal_hold)
                    OR (NEW.event_type = 'purge_marked' AND current_row.status IN ('ready', 'retention_pending', 'integrity_failed', 'quarantined') AND NEW.to_status = 'deletion_pending' AND NOT current_row.legal_hold AND NOT NEW.legal_hold)
                    OR (NEW.event_type = 'deletion_failed' AND current_row.status IN ('retention_pending', 'deletion_pending') AND NEW.to_status = current_row.status AND NOT current_row.legal_hold AND NOT NEW.legal_hold)
                    OR (NEW.event_type = 'deleted' AND current_row.status IN ('retention_pending', 'deletion_pending') AND NEW.to_status = 'deleted' AND NOT current_row.legal_hold AND NOT NEW.legal_hold)
                    OR (NEW.event_type = 'key_rewrapped' AND current_row.status NOT IN ('deleted', 'deletion_pending') AND NEW.to_status = current_row.status AND NEW.legal_hold = current_row.legal_hold)
                ) THEN
                    RAISE EXCEPTION 'payload object lifecycle transition is invalid';
                END IF;

                IF NEW.event_type IN ('integrity_recovered', 'released', 'hold_applied', 'hold_released', 'purge_marked')
                   OR (NEW.event_type IN ('deletion_failed', 'deleted') AND current_row.status = 'deletion_pending') THEN
                    SELECT request.action_type, request.subject_type, request.subject_id,
                           request.expires_at, decision.decision
                    INTO governed_action, governed_subject_type, governed_subject_id,
                         governed_expires_at, governed_decision
                    FROM governance.change_requests request
                    JOIN governance.change_decisions decision
                      ON decision.change_request_uuid = request.change_request_uuid
                    WHERE request.change_request_uuid = NEW.governed_change_request_uuid;

                    SELECT EXISTS (
                        SELECT 1 FROM raw.payload_quarantines quarantine
                        WHERE quarantine.payload_object_id = current_row.payload_object_id
                          AND quarantine.quarantine_uuid::text = governed_subject_id
                    ) INTO governed_quarantine_matches;

                    IF governed_decision IS DISTINCT FROM 'approved'
                       OR governed_expires_at <= CURRENT_TIMESTAMP
                       OR NOT (
                           (NEW.event_type = 'integrity_recovered'
                               AND governed_action = 'recover_clinical_payload_integrity'
                               AND governed_subject_type = 'clinical_payload'
                               AND governed_subject_id = current_row.payload_uuid::text)
                           OR (NEW.event_type = 'hold_applied'
                               AND governed_action = 'apply_clinical_payload_hold'
                               AND governed_subject_type = 'clinical_payload'
                               AND governed_subject_id = current_row.payload_uuid::text)
                           OR (NEW.event_type = 'hold_released'
                               AND governed_action = 'release_clinical_payload_hold'
                               AND governed_subject_type = 'clinical_payload'
                               AND governed_subject_id = current_row.payload_uuid::text)
                           OR (NEW.event_type = 'released'
                               AND governed_action = 'release_quarantined_payload'
                               AND governed_subject_type = 'payload_quarantine'
                               AND governed_quarantine_matches)
                           OR (NEW.event_type IN ('purge_marked', 'deletion_failed', 'deleted')
                               AND (
                                   (governed_action = 'purge_clinical_payload'
                                       AND governed_subject_type = 'clinical_payload'
                                       AND governed_subject_id = current_row.payload_uuid::text)
                                   OR (governed_action = 'purge_quarantined_payload'
                                       AND governed_subject_type = 'payload_quarantine'
                                       AND governed_quarantine_matches)
                               ))
                       ) THEN
                        RAISE EXCEPTION 'payload object governed authority mismatch';
                    END IF;
                ELSIF NEW.event_type IN ('deletion_failed', 'deleted')
                      AND current_row.status = 'retention_pending'
                      AND NEW.governed_change_request_uuid IS NOT NULL THEN
                    RAISE EXCEPTION 'retention deletion cannot claim governed purge authority';
                END IF;
                UPDATE raw.payload_objects SET
                    status = NEW.to_status,
                    legal_hold = NEW.legal_hold,
                    last_verified_at = CASE WHEN NEW.event_type IN ('verified', 'integrity_recovered') THEN NEW.occurred_at ELSE last_verified_at END,
                    deleted_at = CASE WHEN NEW.event_type = 'deleted' THEN NEW.occurred_at ELSE deleted_at END,
                    updated_at = NEW.occurred_at
                WHERE payload_object_id = NEW.payload_object_id;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payload_object_events_apply_projection
                AFTER INSERT ON raw.payload_object_events
                FOR EACH ROW EXECUTE FUNCTION raw.apply_payload_object_event();

            CREATE OR REPLACE FUNCTION raw.validate_payload_object_link()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                authority raw.payload_objects%ROWTYPE;
            BEGIN
                IF TG_TABLE_SCHEMA = 'raw' AND TG_TABLE_NAME = 'inbound_messages' THEN
                    IF NEW.payload_object_id IS NOT NULL THEN
                        SELECT * INTO authority FROM raw.payload_objects WHERE payload_object_id = NEW.payload_object_id;
                        IF authority.payload_object_id IS NULL
                           OR authority.source_id IS DISTINCT FROM NEW.source_id
                           OR authority.payload_kind NOT IN ('raw_message', 'fhir_resource')
                           OR authority.status <> 'ready' THEN
                            RAISE EXCEPTION 'raw payload object authority mismatch';
                        END IF;
                        IF NEW.payload IS NOT NULL THEN
                            RAISE EXCEPTION 'protected raw payload cannot remain in JSONB';
                        END IF;
                    END IF;
                    IF NEW.normalized_payload_object_id IS NOT NULL THEN
                        SELECT * INTO authority FROM raw.payload_objects WHERE payload_object_id = NEW.normalized_payload_object_id;
                        IF authority.payload_object_id IS NULL
                           OR authority.source_id IS DISTINCT FROM NEW.source_id
                           OR authority.payload_kind <> 'normalized_message'
                           OR authority.status <> 'ready' THEN
                            RAISE EXCEPTION 'normalized payload object authority mismatch';
                        END IF;
                        IF NEW.normalized_payload IS NOT NULL THEN
                            RAISE EXCEPTION 'protected normalized payload cannot remain in JSONB';
                        END IF;
                    END IF;
                ELSIF TG_TABLE_SCHEMA = 'fhir' AND TG_TABLE_NAME = 'resource_versions' AND NEW.payload_object_id IS NOT NULL THEN
                    SELECT * INTO authority FROM raw.payload_objects WHERE payload_object_id = NEW.payload_object_id;
                    IF authority.payload_object_id IS NULL
                       OR authority.source_id IS DISTINCT FROM NEW.source_id
                       OR authority.payload_kind <> 'fhir_resource'
                       OR authority.status <> 'ready' THEN
                        RAISE EXCEPTION 'FHIR payload object authority mismatch';
                    END IF;
                    IF NEW.resource_data <> '{}'::jsonb THEN
                        RAISE EXCEPTION 'protected FHIR payload cannot remain in JSONB';
                    END IF;
                ELSIF TG_TABLE_SCHEMA = 'integration' AND TG_TABLE_NAME = 'canonical_events' AND NEW.payload_object_id IS NOT NULL THEN
                    SELECT * INTO authority FROM raw.payload_objects WHERE payload_object_id = NEW.payload_object_id;
                    IF authority.payload_object_id IS NULL
                       OR authority.source_id IS DISTINCT FROM NEW.source_id
                       OR authority.payload_kind <> 'canonical_event'
                       OR authority.status <> 'ready' THEN
                        RAISE EXCEPTION 'canonical payload object authority mismatch';
                    END IF;
                    IF NEW.payload <> '{}'::jsonb THEN
                        RAISE EXCEPTION 'protected canonical payload cannot remain in JSONB';
                    END IF;
                ELSIF TG_TABLE_SCHEMA = 'ops' AND TG_TABLE_NAME = 'writeback_drafts' AND NEW.payload_object_id IS NOT NULL THEN
                    SELECT * INTO authority FROM raw.payload_objects WHERE payload_object_id = NEW.payload_object_id;
                    IF authority.payload_object_id IS NULL
                       OR authority.source_id IS DISTINCT FROM NEW.source_id
                       OR authority.payload_kind <> 'writeback_draft'
                       OR authority.status <> 'ready' THEN
                        RAISE EXCEPTION 'writeback payload object authority mismatch';
                    END IF;
                    IF NEW.resource_payload <> '{}'::jsonb THEN
                        RAISE EXCEPTION 'protected writeback payload cannot remain in JSONB';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER raw_inbound_payload_object_guard
                BEFORE INSERT OR UPDATE ON raw.inbound_messages
                FOR EACH ROW EXECUTE FUNCTION raw.validate_payload_object_link();
            CREATE TRIGGER fhir_resource_payload_object_guard
                BEFORE INSERT OR UPDATE ON fhir.resource_versions
                FOR EACH ROW EXECUTE FUNCTION raw.validate_payload_object_link();
            CREATE TRIGGER canonical_event_payload_object_guard
                BEFORE INSERT OR UPDATE ON integration.canonical_events
                FOR EACH ROW EXECUTE FUNCTION raw.validate_payload_object_link();
            CREATE TRIGGER writeback_draft_payload_object_guard
                BEFORE INSERT OR UPDATE ON ops.writeback_drafts
                FOR EACH ROW EXECUTE FUNCTION raw.validate_payload_object_link();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS writeback_draft_payload_object_guard ON ops.writeback_drafts;
            DROP TRIGGER IF EXISTS canonical_event_payload_object_guard ON integration.canonical_events;
            DROP TRIGGER IF EXISTS fhir_resource_payload_object_guard ON fhir.resource_versions;
            DROP TRIGGER IF EXISTS raw_inbound_payload_object_guard ON raw.inbound_messages;
            DROP FUNCTION IF EXISTS raw.validate_payload_object_link();
            DROP TRIGGER IF EXISTS payload_object_events_apply_projection ON raw.payload_object_events;
            DROP FUNCTION IF EXISTS raw.apply_payload_object_event();
            DROP TRIGGER IF EXISTS payload_objects_projection_guard ON raw.payload_objects;
            DROP FUNCTION IF EXISTS raw.guard_payload_object_projection();
            DROP TRIGGER IF EXISTS payload_objects_scope_guard ON raw.payload_objects;
            DROP FUNCTION IF EXISTS raw.validate_payload_object_scope();
            DROP TRIGGER IF EXISTS payload_quarantine_events_apply_projection ON raw.payload_quarantine_events;
            DROP FUNCTION IF EXISTS raw.apply_payload_quarantine_event();
            DROP TRIGGER IF EXISTS payload_quarantines_projection_guard ON raw.payload_quarantines;
            DROP FUNCTION IF EXISTS raw.guard_payload_quarantine_projection();
            DROP TRIGGER IF EXISTS payload_backfill_events_append_only ON raw.payload_backfill_events;
            DROP TRIGGER IF EXISTS payload_quarantine_events_append_only ON raw.payload_quarantine_events;
            DROP TRIGGER IF EXISTS payload_object_events_append_only ON raw.payload_object_events;
            DROP FUNCTION IF EXISTS raw.reject_payload_ledger_mutation();

            ALTER TABLE ops.writeback_drafts DROP COLUMN IF EXISTS payload_object_id;
            ALTER TABLE integration.canonical_events DROP COLUMN IF EXISTS payload_object_id;
            ALTER TABLE fhir.resource_versions DROP COLUMN IF EXISTS payload_object_id;
            ALTER TABLE raw.inbound_messages
                DROP COLUMN IF EXISTS normalized_payload_object_id,
                DROP COLUMN IF EXISTS payload_object_id;

            DROP TABLE IF EXISTS raw.payload_backfill_events;
            DROP TABLE IF EXISTS raw.payload_backfill_items;
            DROP TABLE IF EXISTS raw.payload_backfill_runs;
            DROP TABLE IF EXISTS raw.payload_quarantine_events;
            DROP TABLE IF EXISTS raw.payload_quarantines;
            DROP TABLE IF EXISTS raw.payload_object_events;
            DROP TABLE IF EXISTS raw.payload_objects;

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
        SQL);
    }
};
