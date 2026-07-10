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
            CREATE TABLE IF NOT EXISTS hosp_ref.staff_qualifications (
                qualification_code text PRIMARY KEY,
                display_name text NOT NULL,
                qualification_type text NOT NULL,
                issuing_authority text,
                is_regulated boolean NOT NULL DEFAULT false,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_qualifications_type_chk CHECK (
                    qualification_type IN ('role','license','certification','competency','privilege','training')
                )
            );

            CREATE TABLE IF NOT EXISTS hosp_ref.staff_role_qualification_requirements (
                staff_role_qualification_requirement_id bigserial PRIMARY KEY,
                facility_key text,
                unit_id bigint REFERENCES prod.units(unit_id) ON DELETE CASCADE,
                service_line_code text,
                role_code text NOT NULL REFERENCES hosp_ref.staff_roles(role_code),
                qualification_code text NOT NULL REFERENCES hosp_ref.staff_qualifications(qualification_code),
                effective_start date,
                effective_end date,
                is_required boolean NOT NULL DEFAULT true,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_role_qualification_dates_chk CHECK (
                    effective_end IS NULL OR effective_start IS NULL OR effective_end >= effective_start
                )
            );

            CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_role_qualification_scope
                ON hosp_ref.staff_role_qualification_requirements (
                    COALESCE(facility_key, ''), COALESCE(unit_id, 0),
                    COALESCE(service_line_code, ''), role_code, qualification_code,
                    COALESCE(effective_start, DATE '1900-01-01')
                );

            CREATE INDEX IF NOT EXISTS idx_staff_role_qualification_lookup
                ON hosp_ref.staff_role_qualification_requirements(role_code, unit_id, effective_start, effective_end)
                WHERE is_required;

            CREATE TABLE IF NOT EXISTS hosp_org.staff_member_qualifications (
                staff_member_qualification_id bigserial PRIMARY KEY,
                qualification_uuid uuid UNIQUE NOT NULL,
                staff_member_id bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
                staff_assignment_id bigint REFERENCES hosp_org.staff_assignments(staff_assignment_id) ON DELETE SET NULL,
                qualification_code text NOT NULL REFERENCES hosp_ref.staff_qualifications(qualification_code),
                status text NOT NULL DEFAULT 'verified',
                source text NOT NULL,
                verified_at timestamptz,
                effective_start timestamptz NOT NULL,
                effective_end timestamptz,
                expires_at timestamptz,
                identifier_hash char(64),
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_member_qualifications_status_chk CHECK (
                    status IN ('verified','provisional','expired','revoked')
                ),
                CONSTRAINT staff_member_qualifications_dates_chk CHECK (
                    effective_end IS NULL OR effective_end > effective_start
                )
            );

            ALTER TABLE hosp_org.staff_member_qualifications
                ADD COLUMN IF NOT EXISTS staff_assignment_id bigint
                REFERENCES hosp_org.staff_assignments(staff_assignment_id) ON DELETE SET NULL;

            DROP INDEX IF EXISTS hosp_org.uq_staff_member_qualification_effective;

            CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_member_assignment_qualification
                ON hosp_org.staff_member_qualifications(staff_assignment_id, qualification_code, effective_start);

            CREATE INDEX IF NOT EXISTS idx_staff_member_qualification_effective
                ON hosp_org.staff_member_qualifications(staff_member_id, qualification_code, effective_start);

            CREATE INDEX IF NOT EXISTS idx_staff_member_qualification_lookup
                ON hosp_org.staff_member_qualifications(staff_member_id, status, effective_start, effective_end, expires_at);

            CREATE TABLE IF NOT EXISTS prod.staff_availability_windows (
                staff_availability_window_id bigserial PRIMARY KEY,
                availability_uuid uuid UNIQUE NOT NULL,
                external_key text UNIQUE,
                staff_member_id bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
                window_type text NOT NULL,
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                timezone text NOT NULL,
                source text NOT NULL,
                priority integer NOT NULL DEFAULT 100,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_availability_window_type_chk CHECK (
                    window_type IN ('available','unavailable','leave','on_call','preference','conflict')
                ),
                CONSTRAINT staff_availability_window_dates_chk CHECK (ends_at > starts_at)
            );

            CREATE INDEX IF NOT EXISTS idx_staff_availability_overlap
                ON prod.staff_availability_windows(staff_member_id, starts_at, ends_at, window_type);

            CREATE TABLE IF NOT EXISTS prod.staff_shift_assignments (
                staff_shift_assignment_id bigserial PRIMARY KEY,
                shift_assignment_uuid uuid UNIQUE NOT NULL,
                staffing_request_id bigint REFERENCES prod.staffing_requests(staffing_request_id) ON DELETE SET NULL,
                staff_member_id bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE RESTRICT,
                unit_id bigint REFERENCES prod.units(unit_id) ON DELETE SET NULL,
                facility_key text NOT NULL,
                service_line_code text,
                role_code text NOT NULL REFERENCES hosp_ref.staff_roles(role_code),
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                timezone text NOT NULL,
                status text NOT NULL DEFAULT 'offered',
                validation_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_by_user_id bigint,
                updated_by_user_id bigint,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staff_shift_assignment_status_chk CHECK (
                    status IN ('offered','accepted','filled','released','canceled')
                ),
                CONSTRAINT staff_shift_assignment_dates_chk CHECK (ends_at > starts_at)
            );

            CREATE INDEX IF NOT EXISTS idx_staff_shift_assignment_overlap
                ON prod.staff_shift_assignments(staff_member_id, starts_at, ends_at, status);

            CREATE INDEX IF NOT EXISTS idx_staff_shift_assignment_request
                ON prod.staff_shift_assignments(staffing_request_id, status);

            CREATE TABLE IF NOT EXISTS prod.staffing_request_fulfillments (
                staffing_request_fulfillment_id bigserial PRIMARY KEY,
                fulfillment_uuid uuid UNIQUE NOT NULL,
                staffing_request_id bigint NOT NULL REFERENCES prod.staffing_requests(staffing_request_id) ON DELETE CASCADE,
                staff_shift_assignment_id bigint NOT NULL REFERENCES prod.staff_shift_assignments(staff_shift_assignment_id) ON DELETE RESTRICT,
                staff_member_id bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE RESTRICT,
                status text NOT NULL DEFAULT 'offered',
                source text NOT NULL,
                version integer NOT NULL DEFAULT 1,
                offered_at timestamptz NOT NULL,
                accepted_at timestamptz,
                filled_at timestamptz,
                released_at timestamptz,
                canceled_at timestamptz,
                last_actor_user_id bigint,
                metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT staffing_request_fulfillment_status_chk CHECK (
                    status IN ('offered','accepted','filled','released','canceled')
                )
            );

            CREATE UNIQUE INDEX IF NOT EXISTS uq_staffing_active_fulfillment_member
                ON prod.staffing_request_fulfillments(staffing_request_id, staff_member_id)
                WHERE status IN ('offered','accepted','filled');

            CREATE INDEX IF NOT EXISTS idx_staffing_fulfillment_request_status
                ON prod.staffing_request_fulfillments(staffing_request_id, status, offered_at);

            CREATE TABLE IF NOT EXISTS prod.staffing_fulfillment_events (
                staffing_fulfillment_event_id bigserial PRIMARY KEY,
                event_uuid uuid UNIQUE NOT NULL,
                fulfillment_uuid uuid NOT NULL,
                staffing_request_id bigint NOT NULL REFERENCES prod.staffing_requests(staffing_request_id) ON DELETE CASCADE,
                staff_member_id bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE RESTRICT,
                event_type text NOT NULL,
                from_status text,
                to_status text NOT NULL,
                payload jsonb NOT NULL DEFAULT '{}'::jsonb,
                actor_user_id bigint,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX IF NOT EXISTS idx_staffing_fulfillment_event_request
                ON prod.staffing_fulfillment_events(staffing_request_id, occurred_at, staffing_fulfillment_event_id);

            CREATE TABLE IF NOT EXISTS prod.staffing_fulfillment_commands (
                staffing_fulfillment_command_id bigserial PRIMARY KEY,
                command_uuid uuid UNIQUE NOT NULL,
                idempotency_key varchar(200) UNIQUE NOT NULL,
                staffing_request_id bigint NOT NULL REFERENCES prod.staffing_requests(staffing_request_id) ON DELETE CASCADE,
                command_type text NOT NULL,
                request_hash char(64) NOT NULL,
                response_payload jsonb NOT NULL,
                actor_user_id bigint,
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE OR REPLACE FUNCTION prod.reject_staffing_fulfillment_ledger_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'staffing fulfillment ledgers are append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS trg_staffing_fulfillment_events_append_only
                ON prod.staffing_fulfillment_events;
            CREATE TRIGGER trg_staffing_fulfillment_events_append_only
                BEFORE UPDATE OR DELETE ON prod.staffing_fulfillment_events
                FOR EACH ROW EXECUTE FUNCTION prod.reject_staffing_fulfillment_ledger_mutation();

            DROP TRIGGER IF EXISTS trg_staffing_fulfillment_commands_append_only
                ON prod.staffing_fulfillment_commands;
            CREATE TRIGGER trg_staffing_fulfillment_commands_append_only
                BEFORE UPDATE OR DELETE ON prod.staffing_fulfillment_commands
                FOR EACH ROW EXECUTE FUNCTION prod.reject_staffing_fulfillment_ledger_mutation();
        SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_staffing_fulfillment_commands_append_only ON prod.staffing_fulfillment_commands;
            DROP TRIGGER IF EXISTS trg_staffing_fulfillment_events_append_only ON prod.staffing_fulfillment_events;
            DROP TABLE IF EXISTS prod.staffing_fulfillment_commands;
            DROP TABLE IF EXISTS prod.staffing_fulfillment_events;
            DROP TABLE IF EXISTS prod.staffing_request_fulfillments;
            DROP TABLE IF EXISTS prod.staff_shift_assignments;
            DROP TABLE IF EXISTS prod.staff_availability_windows;
            DROP TABLE IF EXISTS hosp_org.staff_member_qualifications;
            DROP TABLE IF EXISTS hosp_ref.staff_role_qualification_requirements;
            DROP TABLE IF EXISTS hosp_ref.staff_qualifications;
            DROP FUNCTION IF EXISTS prod.reject_staffing_fulfillment_ledger_mutation();
        SQL);
    }
};
