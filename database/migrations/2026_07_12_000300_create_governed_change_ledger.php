<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE SCHEMA IF NOT EXISTS governance;

            CREATE TABLE IF NOT EXISTS governance.change_requests (
                change_request_uuid uuid PRIMARY KEY,
                action_type text NOT NULL CHECK (action_type IN (
                    'activate_production_source',
                    'rotate_integration_credential',
                    'execute_destructive_replay',
                    'change_outbound_dispatch_policy',
                    'purge_user_identity'
                )),
                subject_type text NOT NULL,
                subject_id text NOT NULL,
                organization_id bigint REFERENCES hosp_org.organizations(organization_id) ON DELETE RESTRICT,
                facility_id bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE RESTRICT,
                author_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                reason text NOT NULL,
                payload_sha256 char(64) NOT NULL,
                requested_at timestamptz NOT NULL DEFAULT now(),
                expires_at timestamptz NOT NULL,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                CONSTRAINT change_requests_one_scope_chk CHECK (num_nonnulls(organization_id, facility_id) <= 1),
                CONSTRAINT change_requests_subject_type_chk CHECK (subject_type ~ '^[a-z][a-z0-9_]{0,79}$'),
                CONSTRAINT change_requests_subject_id_chk CHECK (subject_id ~ '^[A-Za-z0-9_.:-]{1,190}$'),
                CONSTRAINT change_requests_reason_chk CHECK (length(reason) BETWEEN 10 AND 500),
                CONSTRAINT change_requests_payload_hash_chk CHECK (payload_sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT change_requests_expiry_chk CHECK (expires_at > requested_at)
            );

            CREATE INDEX IF NOT EXISTS change_requests_subject_idx
                ON governance.change_requests (action_type, subject_type, subject_id, requested_at DESC);
            CREATE INDEX IF NOT EXISTS change_requests_author_idx
                ON governance.change_requests (author_user_id, requested_at DESC);

            CREATE TABLE IF NOT EXISTS governance.change_decisions (
                change_decision_id bigserial PRIMARY KEY,
                change_request_uuid uuid NOT NULL UNIQUE REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                decision text NOT NULL CHECK (decision IN ('approved', 'rejected')),
                decided_by_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                reason text NOT NULL,
                decided_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT change_decisions_reason_chk CHECK (length(reason) BETWEEN 10 AND 500)
            );

            CREATE INDEX IF NOT EXISTS change_decisions_actor_idx
                ON governance.change_decisions (decided_by_user_id, decided_at DESC);

            CREATE TABLE IF NOT EXISTS governance.change_executions (
                change_execution_id bigserial PRIMARY KEY,
                change_request_uuid uuid NOT NULL REFERENCES governance.change_requests(change_request_uuid) ON DELETE RESTRICT,
                executed_by_user_id bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
                outcome text NOT NULL CHECK (outcome IN ('success', 'failure')),
                reason text,
                executed_at timestamptz NOT NULL DEFAULT now(),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb
            );

            CREATE UNIQUE INDEX IF NOT EXISTS change_executions_one_success_idx
                ON governance.change_executions (change_request_uuid) WHERE outcome = 'success';

            CREATE OR REPLACE FUNCTION governance.reject_change_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'governance change ledger is append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS change_requests_append_only ON governance.change_requests;
            CREATE TRIGGER change_requests_append_only
                BEFORE UPDATE OR DELETE ON governance.change_requests
                FOR EACH ROW EXECUTE FUNCTION governance.reject_change_ledger_mutation();

            DROP TRIGGER IF EXISTS change_decisions_append_only ON governance.change_decisions;
            CREATE TRIGGER change_decisions_append_only
                BEFORE UPDATE OR DELETE ON governance.change_decisions
                FOR EACH ROW EXECUTE FUNCTION governance.reject_change_ledger_mutation();

            DROP TRIGGER IF EXISTS change_executions_append_only ON governance.change_executions;
            CREATE TRIGGER change_executions_append_only
                BEFORE UPDATE OR DELETE ON governance.change_executions
                FOR EACH ROW EXECUTE FUNCTION governance.reject_change_ledger_mutation();

            CREATE OR REPLACE FUNCTION governance.enforce_separate_change_approver()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE request_author bigint;
            BEGIN
                SELECT author_user_id INTO request_author
                FROM governance.change_requests
                WHERE change_request_uuid = NEW.change_request_uuid;

                IF request_author IS NULL THEN
                    RAISE EXCEPTION 'governed change request not found';
                END IF;
                IF request_author = NEW.decided_by_user_id THEN
                    RAISE EXCEPTION 'governed change author cannot approve or reject the same request';
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS change_decisions_separate_approver ON governance.change_decisions;
            CREATE TRIGGER change_decisions_separate_approver
                BEFORE INSERT ON governance.change_decisions
                FOR EACH ROW EXECUTE FUNCTION governance.enforce_separate_change_approver();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS governance.change_executions;
            DROP TABLE IF EXISTS governance.change_decisions;
            DROP TABLE IF EXISTS governance.change_requests;
            DROP FUNCTION IF EXISTS governance.enforce_separate_change_approver();
            DROP FUNCTION IF EXISTS governance.reject_change_ledger_mutation();
        SQL);
    }
};
