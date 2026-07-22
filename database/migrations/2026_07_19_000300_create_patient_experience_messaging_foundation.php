<?php

/**
 * Hummingbird Patient secure-communication foundation.
 *
 * Message bodies are application-encrypted and never copied into routing,
 * notification, or audit metadata. Messages, receipts, and routing decisions
 * are immutable facts. The thread row is only the versioned current-state
 * projection used for optimistic concurrency and efficient inbox queries.
 *
 * This migration does not enable messaging. Runtime access remains protected
 * by the disabled-by-default product/feature flags and by an independently
 * approved facility communication policy.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS patient_experience.message_threads (
    message_thread_id                  bigserial PRIMARY KEY,
    thread_uuid                        uuid NOT NULL UNIQUE,
    access_grant_id                    bigint NOT NULL
                                       REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                                       ON DELETE RESTRICT,
    opened_by_principal_id             bigint NOT NULL
                                       REFERENCES patient_experience.principals(principal_id)
                                       ON DELETE RESTRICT,
    topic_code                         varchar(80) NOT NULL,
    topic_label                        varchar(120) NOT NULL,
    topic_description                  varchar(300) NOT NULL,
    status                             text NOT NULL DEFAULT 'open'
                                       CHECK (status IN ('open', 'closed')),
    ownership_state                    text NOT NULL DEFAULT 'awaiting_team'
                                       CHECK (ownership_state IN (
                                           'awaiting_team',
                                           'assigned',
                                           'acknowledged',
                                           'responded',
                                           'rerouted',
                                           'escalated',
                                           'closed'
                                       )),
    routing_policy_version             varchar(120) NOT NULL,
    expected_response_window           varchar(240) NOT NULL,
    urgent_guidance_version            varchar(120) NOT NULL,
    responsibility_pool_ref_digest     varchar(128) NOT NULL,
    creation_idempotency_key_digest    varchar(128) NOT NULL UNIQUE,
    creation_request_payload_digest    varchar(128) NOT NULL,
    version                            integer NOT NULL DEFAULT 1,
    last_message_at                    timestamptz NOT NULL,
    closed_at                          timestamptz,
    close_reason_code                  varchar(80),
    created_at                         timestamptz NOT NULL DEFAULT now(),
    updated_at                         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_message_threads_topic_code_check
        CHECK (topic_code ~ '^[a-z][a-z0-9_]{1,78}[a-z0-9]$'),
    CONSTRAINT patient_message_threads_topic_copy_not_blank_check
        CHECK (btrim(topic_label) <> '' AND btrim(topic_description) <> ''),
    CONSTRAINT patient_message_threads_policy_not_blank_check
        CHECK (btrim(routing_policy_version) <> '' AND btrim(urgent_guidance_version) <> ''),
    CONSTRAINT patient_message_threads_response_window_not_blank_check
        CHECK (btrim(expected_response_window) <> ''),
    CONSTRAINT patient_message_threads_pool_digest_not_blank_check
        CHECK (btrim(responsibility_pool_ref_digest) <> ''),
    CONSTRAINT patient_message_threads_idempotency_not_blank_check
        CHECK (btrim(creation_idempotency_key_digest) <> '' AND btrim(creation_request_payload_digest) <> ''),
    CONSTRAINT patient_message_threads_version_check CHECK (version > 0),
    CONSTRAINT patient_message_threads_closed_check CHECK (
        (
            status = 'closed'
            AND ownership_state = 'closed'
            AND closed_at IS NOT NULL
            AND close_reason_code IS NOT NULL
        )
        OR (
            status = 'open'
            AND ownership_state <> 'closed'
            AND closed_at IS NULL
            AND close_reason_code IS NULL
        )
    )
);

CREATE INDEX IF NOT EXISTS idx_patient_message_threads_grant_activity
    ON patient_experience.message_threads(access_grant_id, last_message_at DESC, message_thread_id DESC);
CREATE INDEX IF NOT EXISTS idx_patient_message_threads_open_ownership
    ON patient_experience.message_threads(status, ownership_state, last_message_at)
    WHERE status = 'open';

COMMENT ON TABLE patient_experience.message_threads IS
    'Versioned current-state projection for an encounter-scoped patient communication thread. Internal routing references are keyed digests and are never patient-visible.';

CREATE TABLE IF NOT EXISTS patient_experience.messages (
    message_id                       bigserial PRIMARY KEY,
    message_uuid                     uuid NOT NULL UNIQUE,
    message_thread_id                bigint NOT NULL
                                     REFERENCES patient_experience.message_threads(message_thread_id)
                                     ON DELETE RESTRICT,
    sender_type                      text NOT NULL
                                     CHECK (sender_type IN ('patient', 'representative', 'staff', 'system')),
    sender_principal_id              bigint
                                     REFERENCES patient_experience.principals(principal_id)
                                     ON DELETE RESTRICT,
    sender_actor_ref_digest          varchar(128),
    visibility                       text NOT NULL DEFAULT 'patient_visible'
                                     CHECK (visibility IN ('patient_visible', 'staff_internal')),
    message_kind                     text NOT NULL DEFAULT 'message'
                                     CHECK (message_kind IN ('message', 'correction', 'retraction', 'system_status')),
    relates_to_message_id            bigint
                                     REFERENCES patient_experience.messages(message_id)
                                     ON DELETE RESTRICT,
    encrypted_body                   text,
    encryption_key_version           varchar(80),
    body_digest                      varchar(128),
    body_character_count             integer NOT NULL DEFAULT 0,
    client_message_uuid              uuid,
    idempotency_key_digest           varchar(128) UNIQUE,
    request_payload_digest           varchar(128),
    delivery_state                   text NOT NULL DEFAULT 'accepted'
                                     CHECK (delivery_state IN ('accepted', 'routed', 'acknowledged', 'delivered')),
    sent_at                          timestamptz NOT NULL,
    recorded_at                      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_messages_sender_identity_check CHECK (
        (sender_type IN ('patient', 'representative') AND sender_principal_id IS NOT NULL)
        OR (sender_type IN ('staff', 'system') AND sender_principal_id IS NULL)
    ),
    CONSTRAINT patient_messages_sender_ref_check CHECK (
        (sender_type IN ('staff', 'system') AND sender_actor_ref_digest IS NOT NULL)
        OR sender_type IN ('patient', 'representative')
    ),
    CONSTRAINT patient_messages_body_check CHECK (
        (
            message_kind IN ('message', 'correction')
            AND encrypted_body IS NOT NULL
            AND encryption_key_version IS NOT NULL
            AND body_digest IS NOT NULL
            AND body_character_count > 0
        )
        OR (
            message_kind = 'retraction'
            AND encrypted_body IS NULL
            AND encryption_key_version IS NULL
            AND body_digest IS NULL
            AND body_character_count = 0
        )
        OR (
            message_kind = 'system_status'
            AND body_character_count >= 0
        )
    ),
    CONSTRAINT patient_messages_relationship_check CHECK (
        (message_kind IN ('correction', 'retraction') AND relates_to_message_id IS NOT NULL)
        OR (message_kind IN ('message', 'system_status') AND relates_to_message_id IS NULL)
    ),
    CONSTRAINT patient_messages_idempotency_pair_check CHECK (
        (idempotency_key_digest IS NULL AND request_payload_digest IS NULL)
        OR (idempotency_key_digest IS NOT NULL AND request_payload_digest IS NOT NULL)
    ),
    CONSTRAINT patient_messages_character_count_check
        CHECK (body_character_count BETWEEN 0 AND 4000)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_messages_client_uuid
    ON patient_experience.messages(client_message_uuid)
    WHERE client_message_uuid IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_messages_thread_time
    ON patient_experience.messages(message_thread_id, sent_at, message_id);
CREATE INDEX IF NOT EXISTS idx_patient_messages_relationship
    ON patient_experience.messages(relates_to_message_id)
    WHERE relates_to_message_id IS NOT NULL;

COMMENT ON TABLE patient_experience.messages IS
    'Append-only patient communication content. Message bodies are application-encrypted; corrections and retractions are new immutable message facts.';

CREATE TABLE IF NOT EXISTS patient_experience.message_delivery_receipts (
    message_delivery_receipt_id bigserial PRIMARY KEY,
    receipt_uuid                uuid NOT NULL UNIQUE,
    message_id                  bigint NOT NULL
                                REFERENCES patient_experience.messages(message_id)
                                ON DELETE RESTRICT,
    receipt_type                text NOT NULL
                                CHECK (receipt_type IN (
                                    'server_accepted',
                                    'routed_to_pool',
                                    'assigned',
                                    'team_acknowledged',
                                    'team_responded',
                                    'patient_seen',
                                    'escalated',
                                    'closed'
                                )),
    actor_type                  text NOT NULL
                                CHECK (actor_type IN ('patient', 'representative', 'staff', 'system')),
    actor_ref_digest            varchar(128),
    patient_visible_state       text NOT NULL
                                CHECK (patient_visible_state IN (
                                    'sent',
                                    'delivered',
                                    'assigned',
                                    'acknowledged',
                                    'responded',
                                    'escalated',
                                    'closed'
                                )),
    idempotency_key_digest      varchar(128) NOT NULL UNIQUE,
    occurred_at                timestamptz NOT NULL,
    recorded_at                timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_patient_message_receipts_message_time
    ON patient_experience.message_delivery_receipts(message_id, occurred_at, message_delivery_receipt_id);

COMMENT ON TABLE patient_experience.message_delivery_receipts IS
    'Append-only delivery and acknowledgement facts. Patient-visible state is derived from these facts rather than inferred from push delivery.';

CREATE TABLE IF NOT EXISTS patient_experience.message_routing_events (
    message_routing_event_id  bigserial PRIMARY KEY,
    routing_event_uuid        uuid NOT NULL UNIQUE,
    message_thread_id         bigint NOT NULL
                              REFERENCES patient_experience.message_threads(message_thread_id)
                              ON DELETE RESTRICT,
    event_type                text NOT NULL
                              CHECK (event_type IN (
                                  'thread_opened',
                                  'message_submitted',
                                  'assigned',
                                  'acknowledged',
                                  'rerouted',
                                  'escalated',
                                  'responded',
                                  'closed'
                              )),
    from_pool_ref_digest      varchar(128),
    to_pool_ref_digest        varchar(128),
    actor_type                text NOT NULL
                              CHECK (actor_type IN ('patient', 'representative', 'staff', 'system')),
    actor_ref_digest          varchar(128),
    reason_code               varchar(120) NOT NULL,
    patient_visible_state     text NOT NULL
                              CHECK (patient_visible_state IN (
                                  'awaiting_team',
                                  'assigned',
                                  'acknowledged',
                                  'responded',
                                  'rerouted',
                                  'escalated',
                                  'closed'
                              )),
    routing_policy_version    varchar(120) NOT NULL,
    idempotency_key_digest    varchar(128) NOT NULL UNIQUE,
    request_payload_digest    varchar(128) NOT NULL,
    metadata                  jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at              timestamptz NOT NULL,
    recorded_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_message_routing_reason_not_blank_check CHECK (btrim(reason_code) <> ''),
    CONSTRAINT patient_message_routing_policy_not_blank_check CHECK (btrim(routing_policy_version) <> ''),
    CONSTRAINT patient_message_routing_metadata_object_check CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_patient_message_routing_thread_time
    ON patient_experience.message_routing_events(message_thread_id, occurred_at, message_routing_event_id);

COMMENT ON TABLE patient_experience.message_routing_events IS
    'Append-only accountable routing and handoff facts. Staff-private routing metadata is never serialized to the patient API.';

CREATE OR REPLACE FUNCTION patient_experience.enforce_message_relationship_scope()
RETURNS trigger AS $$
BEGIN
    IF NEW.relates_to_message_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM patient_experience.messages related
        WHERE related.message_id = NEW.relates_to_message_id
          AND related.message_thread_id = NEW.message_thread_id
    ) THEN
        RAISE EXCEPTION 'patient message relationship must remain within one thread'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_messages_relationship_scope
    ON patient_experience.messages;
CREATE TRIGGER patient_messages_relationship_scope
BEFORE INSERT ON patient_experience.messages
FOR EACH ROW EXECUTE FUNCTION patient_experience.enforce_message_relationship_scope();

DROP TRIGGER IF EXISTS patient_messages_append_only
    ON patient_experience.messages;
CREATE TRIGGER patient_messages_append_only
BEFORE UPDATE OR DELETE ON patient_experience.messages
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_message_delivery_receipts_append_only
    ON patient_experience.message_delivery_receipts;
CREATE TRIGGER patient_message_delivery_receipts_append_only
BEFORE UPDATE OR DELETE ON patient_experience.message_delivery_receipts
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_message_routing_events_append_only
    ON patient_experience.message_routing_events;
CREATE TRIGGER patient_message_routing_events_append_only
BEFORE UPDATE OR DELETE ON patient_experience.message_routing_events
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS patient_experience.message_routing_events;
DROP TABLE IF EXISTS patient_experience.message_delivery_receipts;
DROP TABLE IF EXISTS patient_experience.messages;
DROP TABLE IF EXISTS patient_experience.message_threads;
SQL);
    }
};
