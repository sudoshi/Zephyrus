<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS patient_communications;

-- Staff routing configuration deliberately lives outside patient_experience:
-- patient principals remain isolated from prod.users, while this operational
-- bridge can use explicit staff accounts and unit assignments.
CREATE TABLE IF NOT EXISTS patient_communications.responsibility_pools (
    responsibility_pool_id    bigserial PRIMARY KEY,
    pool_uuid                  uuid NOT NULL UNIQUE,
    pool_key_digest            varchar(128) NOT NULL,
    topic_code                 varchar(80) NOT NULL,
    display_name               varchar(120) NOT NULL,
    routing_policy_version     varchar(120) NOT NULL,
    scope_type                 text NOT NULL
                               CHECK (scope_type IN ('unit', 'facility', 'enterprise')),
    facility_key               varchar(120),
    unit_id                    bigint REFERENCES prod.units(unit_id) ON DELETE RESTRICT,
    status                     text NOT NULL DEFAULT 'active'
                               CHECK (status IN ('active', 'paused', 'retired')),
    response_target_minutes    integer NOT NULL,
    escalation_target_minutes  integer NOT NULL,
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_communications_pool_topic_check
        CHECK (topic_code ~ '^[a-z][a-z0-9_]{1,78}[a-z0-9]$'),
    CONSTRAINT patient_communications_pool_copy_check
        CHECK (btrim(display_name) <> '' AND btrim(routing_policy_version) <> '' AND btrim(pool_key_digest) <> ''),
    CONSTRAINT patient_communications_pool_targets_check
        CHECK (response_target_minutes BETWEEN 1 AND 10080
            AND escalation_target_minutes BETWEEN 1 AND 10080
            AND escalation_target_minutes >= response_target_minutes),
    CONSTRAINT patient_communications_pool_scope_check CHECK (
        (scope_type = 'unit' AND unit_id IS NOT NULL AND facility_key IS NULL)
        OR (scope_type = 'facility' AND unit_id IS NULL AND facility_key IS NOT NULL AND btrim(facility_key) <> '')
        OR (scope_type = 'enterprise' AND unit_id IS NULL AND facility_key IS NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_communications_pool_scope
    ON patient_communications.responsibility_pools (
        routing_policy_version,
        pool_key_digest,
        topic_code,
        scope_type,
        COALESCE(unit_id, 0),
        COALESCE(facility_key, '')
    );
CREATE INDEX IF NOT EXISTS idx_patient_communications_pool_resolution
    ON patient_communications.responsibility_pools (
        routing_policy_version,
        pool_key_digest,
        status,
        unit_id,
        facility_key
    );

CREATE TABLE IF NOT EXISTS patient_communications.pool_memberships (
    pool_membership_id       bigserial PRIMARY KEY,
    membership_uuid          uuid NOT NULL UNIQUE,
    responsibility_pool_id   bigint NOT NULL
                              REFERENCES patient_communications.responsibility_pools(responsibility_pool_id)
                              ON DELETE RESTRICT,
    staff_user_id            bigint NOT NULL REFERENCES prod.users(id) ON DELETE RESTRICT,
    membership_role          text NOT NULL
                              CHECK (membership_role IN ('responder', 'triage', 'supervisor')),
    availability_state       text NOT NULL DEFAULT 'active'
                              CHECK (availability_state IN ('active', 'away', 'suspended', 'ended')),
    can_claim                boolean NOT NULL DEFAULT true,
    can_reply                boolean NOT NULL DEFAULT true,
    can_reroute              boolean NOT NULL DEFAULT false,
    can_close                boolean NOT NULL DEFAULT false,
    effective_from           timestamptz NOT NULL DEFAULT now(),
    effective_until          timestamptz,
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_communications_membership_window_check
        CHECK (effective_until IS NULL OR effective_until > effective_from),
    CONSTRAINT patient_communications_membership_permissions_check
        CHECK (can_claim OR can_reply OR can_reroute OR can_close)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_communications_membership_effective
    ON patient_communications.pool_memberships(responsibility_pool_id, staff_user_id)
    WHERE availability_state IN ('active', 'away', 'suspended');
CREATE INDEX IF NOT EXISTS idx_patient_communications_membership_staff
    ON patient_communications.pool_memberships(staff_user_id, availability_state, effective_from, effective_until);

-- Mutable, versioned staff work projection. All historical decisions remain in
-- immutable staff_action_events and patient_experience routing/receipt ledgers.
CREATE TABLE IF NOT EXISTS patient_communications.thread_work_items (
    thread_work_item_id       bigserial PRIMARY KEY,
    work_item_uuid            uuid NOT NULL UNIQUE,
    message_thread_id         bigint NOT NULL UNIQUE
                              REFERENCES patient_experience.message_threads(message_thread_id)
                              ON DELETE RESTRICT,
    access_grant_id           bigint NOT NULL
                              REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                              ON DELETE RESTRICT,
    responsibility_pool_id    bigint NOT NULL
                              REFERENCES patient_communications.responsibility_pools(responsibility_pool_id)
                              ON DELETE RESTRICT,
    assigned_user_id          bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
    status                    text NOT NULL DEFAULT 'open'
                              CHECK (status IN ('open', 'closed')),
    ownership_state           text NOT NULL DEFAULT 'pool_owned'
                              CHECK (ownership_state IN (
                                  'pool_owned',
                                  'assigned',
                                  'acknowledged',
                                  'responded',
                                  'rerouted',
                                  'escalated',
                                  'closed'
                              )),
    source_thread_version     integer NOT NULL,
    row_version               integer NOT NULL DEFAULT 1,
    last_outbox_id            bigint NOT NULL
                              REFERENCES patient_experience.notification_outbox(notification_outbox_id)
                              ON DELETE RESTRICT,
    first_routed_at           timestamptz NOT NULL,
    due_at                    timestamptz NOT NULL,
    escalate_at               timestamptz NOT NULL,
    last_message_at           timestamptz NOT NULL,
    acknowledged_at           timestamptz,
    responded_at              timestamptz,
    closed_at                 timestamptz,
    close_reason_code         varchar(120),
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_communications_work_versions_check
        CHECK (source_thread_version > 0 AND row_version > 0),
    CONSTRAINT patient_communications_work_targets_check
        CHECK (due_at >= first_routed_at AND escalate_at >= due_at),
    CONSTRAINT patient_communications_work_assignment_check CHECK (
        ownership_state NOT IN ('assigned', 'acknowledged', 'responded')
        OR assigned_user_id IS NOT NULL
    ),
    CONSTRAINT patient_communications_work_closed_check CHECK (
        (status = 'closed' AND ownership_state = 'closed' AND closed_at IS NOT NULL AND close_reason_code IS NOT NULL)
        OR (status = 'open' AND ownership_state <> 'closed' AND closed_at IS NULL AND close_reason_code IS NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_patient_communications_work_queue
    ON patient_communications.thread_work_items (
        responsibility_pool_id,
        status,
        ownership_state,
        escalate_at,
        last_message_at DESC
    );
CREATE INDEX IF NOT EXISTS idx_patient_communications_work_assignee
    ON patient_communications.thread_work_items(assigned_user_id, status, last_message_at DESC)
    WHERE assigned_user_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS patient_communications.staff_action_events (
    staff_action_event_id    bigserial PRIMARY KEY,
    event_uuid               uuid NOT NULL UNIQUE,
    thread_work_item_id      bigint NOT NULL
                             REFERENCES patient_communications.thread_work_items(thread_work_item_id)
                             ON DELETE RESTRICT,
    event_type               text NOT NULL
                             CHECK (event_type IN (
                                 'outbox_consumed',
                                 'pool_routed',
                                 'claimed',
                                 'released',
                                 'reassigned',
                                 'acknowledged',
                                 'replied',
                                 'rerouted',
                                 'escalated',
                                 'closed',
                                 'patient_closed'
                             )),
    actor_user_id            bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
    from_pool_id             bigint
                             REFERENCES patient_communications.responsibility_pools(responsibility_pool_id)
                             ON DELETE RESTRICT,
    to_pool_id               bigint
                             REFERENCES patient_communications.responsibility_pools(responsibility_pool_id)
                             ON DELETE RESTRICT,
    from_user_id             bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
    to_user_id               bigint REFERENCES prod.users(id) ON DELETE RESTRICT,
    message_id               bigint
                             REFERENCES patient_experience.messages(message_id)
                             ON DELETE RESTRICT,
    reason_code              varchar(120) NOT NULL,
    patient_visible_state    text NOT NULL
                             CHECK (patient_visible_state IN (
                                 'awaiting_team',
                                 'assigned',
                                 'acknowledged',
                                 'responded',
                                 'rerouted',
                                 'escalated',
                                 'closed'
                             )),
    idempotency_key_digest   varchar(128) NOT NULL UNIQUE,
    request_payload_digest   varchar(128) NOT NULL,
    metadata                 jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at              timestamptz NOT NULL,
    recorded_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_communications_event_reason_check
        CHECK (btrim(reason_code) <> '' AND btrim(idempotency_key_digest) <> '' AND btrim(request_payload_digest) <> ''),
    CONSTRAINT patient_communications_event_metadata_check
        CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_patient_communications_event_thread_time
    ON patient_communications.staff_action_events(thread_work_item_id, occurred_at, staff_action_event_id);

CREATE TABLE IF NOT EXISTS patient_communications.consumer_heartbeats (
    consumer_key             varchar(120) PRIMARY KEY,
    routing_policy_version   varchar(120) NOT NULL,
    worker_ref_digest        varchar(128) NOT NULL,
    status                   text NOT NULL
                             CHECK (status IN ('ready', 'degraded', 'stopped')),
    last_seen_at             timestamptz NOT NULL,
    metadata                 jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_communications_heartbeat_copy_check
        CHECK (btrim(consumer_key) <> '' AND btrim(routing_policy_version) <> '' AND btrim(worker_ref_digest) <> ''),
    CONSTRAINT patient_communications_heartbeat_metadata_check
        CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE OR REPLACE FUNCTION patient_communications.reject_staff_action_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'patient_communications.staff_action_events is append-only';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_communications_staff_actions_append_only
    ON patient_communications.staff_action_events;
CREATE TRIGGER patient_communications_staff_actions_append_only
BEFORE UPDATE OR DELETE ON patient_communications.staff_action_events
FOR EACH ROW EXECUTE FUNCTION patient_communications.reject_staff_action_mutation();

COMMENT ON SCHEMA patient_communications IS
    'Staff-authorized operational bridge for accountable patient-message routing; separate from patient authentication subjects.';
COMMENT ON TABLE patient_communications.thread_work_items IS
    'Current staff inbox projection. Content remains in the dedicated-key encrypted append-only patient message ledger.';
COMMENT ON TABLE patient_communications.consumer_heartbeats IS
    'Fresh worker health is a production-enablement prerequisite, not an observability-only signal.';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS patient_communications_staff_actions_append_only
    ON patient_communications.staff_action_events;
DROP FUNCTION IF EXISTS patient_communications.reject_staff_action_mutation();
DROP TABLE IF EXISTS patient_communications.consumer_heartbeats;
DROP TABLE IF EXISTS patient_communications.staff_action_events;
DROP TABLE IF EXISTS patient_communications.thread_work_items;
DROP TABLE IF EXISTS patient_communications.pool_memberships;
DROP TABLE IF EXISTS patient_communications.responsibility_pools;
DROP SCHEMA IF EXISTS patient_communications;
SQL);
    }
};
