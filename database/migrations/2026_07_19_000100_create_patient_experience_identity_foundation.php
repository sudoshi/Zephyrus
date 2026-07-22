<?php

/**
 * Hummingbird Patient identity and access foundation.
 *
 * This schema is deliberately separate from prod.users and the staff mobile
 * persona model. Source-system patient and encounter identifiers are stored in
 * encrypted application columns plus keyed lookup digests; no MRN, enrollment
 * token, verification code, refresh token, or other bearer secret belongs in
 * plaintext here.
 *
 * Access/disclosure history and notification delivery facts are append-only.
 * Corrections are represented by later events instead of rewriting history.
 * Destructive rollback is local-only via SafeMigration.
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
CREATE SCHEMA IF NOT EXISTS patient_experience;

-- Patient authentication subjects are isolated from prod.users. A principal
-- may use a local password hash or a governed external identity provider.
CREATE TABLE IF NOT EXISTS patient_experience.principals (
    principal_id          bigserial PRIMARY KEY,
    principal_uuid        uuid NOT NULL UNIQUE,
    principal_type        text NOT NULL DEFAULT 'patient'
                          CHECK (principal_type IN ('patient', 'representative')),
    display_name          text,
    email                 varchar(320),
    phone_e164            varchar(32),
    password              varchar(255),
    status                text NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'active', 'locked', 'suspended', 'closed')),
    is_active             boolean NOT NULL DEFAULT false,
    preferences           jsonb NOT NULL DEFAULT '{}'::jsonb,
    locale                varchar(35) NOT NULL DEFAULT 'en-US',
    timezone              varchar(64) NOT NULL DEFAULT 'UTC',
    email_verified_at     timestamptz,
    phone_verified_at     timestamptz,
    last_authenticated_at timestamptz,
    locked_at             timestamptz,
    closed_at             timestamptz,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_principals_status_active_check CHECK (
        (status = 'active' AND is_active = true)
        OR (status <> 'active' AND is_active = false)
    ),
    CONSTRAINT patient_principals_preferences_object_check
        CHECK (jsonb_typeof(preferences) = 'object'),
    CONSTRAINT patient_principals_password_hash_check
        CHECK (password IS NULL OR length(password) >= 40),
    CONSTRAINT patient_principals_email_not_blank_check
        CHECK (email IS NULL OR btrim(email) <> ''),
    CONSTRAINT patient_principals_phone_not_blank_check
        CHECK (phone_e164 IS NULL OR btrim(phone_e164) <> '')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_principals_email
    ON patient_experience.principals(lower(email))
    WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_principals_status
    ON patient_experience.principals(status, principal_type);

COMMENT ON TABLE patient_experience.principals IS
    'Authentication subjects for Hummingbird Patient. This table has no relationship to prod.users or the staff persona catalog.';
COMMENT ON COLUMN patient_experience.principals.password IS
    'One-way password hash only. Plaintext credentials are forbidden.';

-- Governed mapping from a patient principal to an enterprise identity. The
-- encrypted source subject is decrypted only by the identity-link service; its
-- keyed digest supports deterministic lookup without exposing the identifier.
CREATE TABLE IF NOT EXISTS patient_experience.identity_links (
    identity_link_id          bigserial PRIMARY KEY,
    identity_link_uuid        uuid NOT NULL UNIQUE,
    principal_id              bigint NOT NULL
                              REFERENCES patient_experience.principals(principal_id)
                              ON DELETE RESTRICT,
    source_system_key         text NOT NULL,
    encrypted_source_subject text NOT NULL,
    encryption_key_version    text NOT NULL,
    source_subject_digest     varchar(128) NOT NULL,
    digest_algorithm          varchar(32) NOT NULL DEFAULT 'hmac-sha256',
    linkage_method            text NOT NULL
                              CHECK (linkage_method IN ('portal_federation', 'encounter_enrollment', 'manual_review', 'enterprise_match')),
    status                    text NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending', 'verified', 'ambiguous', 'merged', 'revoked')),
    assurance_level           varchar(32),
    provenance                jsonb NOT NULL DEFAULT '{}'::jsonb,
    verified_at               timestamptz,
    revoked_at                timestamptz,
    merged_at                 timestamptz,
    merged_into_identity_link_id bigint
                              REFERENCES patient_experience.identity_links(identity_link_id)
                              ON DELETE RESTRICT,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_identity_links_provenance_object_check
        CHECK (jsonb_typeof(provenance) = 'object'),
    CONSTRAINT patient_identity_links_ciphertext_not_blank_check
        CHECK (btrim(encrypted_source_subject) <> ''),
    CONSTRAINT patient_identity_links_digest_not_blank_check
        CHECK (btrim(source_subject_digest) <> ''),
    CONSTRAINT patient_identity_links_merge_check CHECK (
        (status = 'merged' AND merged_into_identity_link_id IS NOT NULL AND merged_at IS NOT NULL)
        OR (status <> 'merged' AND merged_into_identity_link_id IS NULL)
    ),
    CONSTRAINT patient_identity_links_not_self_merged_check
        CHECK (merged_into_identity_link_id IS NULL OR merged_into_identity_link_id <> identity_link_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_identity_links_active_source
    ON patient_experience.identity_links(source_system_key, source_subject_digest)
    WHERE status IN ('pending', 'verified');
CREATE INDEX IF NOT EXISTS idx_patient_identity_links_principal
    ON patient_experience.identity_links(principal_id, status);

COMMENT ON TABLE patient_experience.identity_links IS
    'Governed patient-identity mappings. Source identifiers are encrypted and indexed only by a keyed digest; raw MRNs and patient refs are forbidden.';

-- A grant is the per-request authorization boundary. encounter_uuid is the
-- external, grant-scoped identifier; source references remain internal and
-- encrypted. Actor references are opaque and deliberately do not FK to staff.
CREATE TABLE IF NOT EXISTS patient_experience.encounter_access_grants (
    access_grant_id                bigserial PRIMARY KEY,
    grant_uuid                    uuid NOT NULL UNIQUE,
    principal_id                  bigint NOT NULL
                                  REFERENCES patient_experience.principals(principal_id)
                                  ON DELETE RESTRICT,
    identity_link_id              bigint
                                  REFERENCES patient_experience.identity_links(identity_link_id)
                                  ON DELETE RESTRICT,
    encounter_uuid                uuid NOT NULL UNIQUE,
    source_encounter_id           bigint,
    encrypted_source_encounter_ref text,
    source_encounter_ref_digest   varchar(128) NOT NULL,
    source_system_key             text NOT NULL,
    relationship                 text NOT NULL DEFAULT 'self'
                                  CHECK (relationship IN ('self', 'legal_representative', 'guardian', 'caregiver', 'proxy', 'other')),
    scopes                        jsonb NOT NULL DEFAULT '["care_pathway", "care_team"]'::jsonb,
    purpose_of_use                text NOT NULL DEFAULT 'patient_access',
    status                        text NOT NULL DEFAULT 'pending'
                                  CHECK (status IN ('pending', 'active', 'suspended', 'revoked', 'expired', 'closed')),
    valid_from                    timestamptz NOT NULL DEFAULT now(),
    expires_at                    timestamptz,
    issued_by_actor_type          text NOT NULL DEFAULT 'system'
                                  CHECK (issued_by_actor_type IN ('patient', 'representative', 'identity_provider', 'support', 'system')),
    issued_by_actor_ref           varchar(190),
    grant_reason                  varchar(500) NOT NULL,
    revoked_at                   timestamptz,
    revoked_by_actor_type         text
                                  CHECK (revoked_by_actor_type IS NULL OR revoked_by_actor_type IN ('patient', 'representative', 'identity_provider', 'support', 'system')),
    revoked_by_actor_ref          varchar(190),
    revocation_reason             varchar(500),
    version                       integer NOT NULL DEFAULT 1,
    metadata                      jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at                    timestamptz NOT NULL DEFAULT now(),
    updated_at                    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_encounter_grants_scopes_array_check
        CHECK (jsonb_typeof(scopes) = 'array'),
    CONSTRAINT patient_encounter_grants_metadata_object_check
        CHECK (jsonb_typeof(metadata) = 'object'),
    CONSTRAINT patient_encounter_grants_digest_not_blank_check
        CHECK (btrim(source_encounter_ref_digest) <> ''),
    CONSTRAINT patient_encounter_grants_time_window_check
        CHECK (expires_at IS NULL OR expires_at > valid_from),
    CONSTRAINT patient_encounter_grants_revocation_check CHECK (
        (status = 'revoked' AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
        OR status <> 'revoked'
    ),
    CONSTRAINT patient_encounter_grants_version_check CHECK (version > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_encounter_grants_active
    ON patient_experience.encounter_access_grants(principal_id, source_system_key, source_encounter_ref_digest)
    WHERE status IN ('pending', 'active', 'suspended');
CREATE INDEX IF NOT EXISTS idx_patient_encounter_grants_principal_effective
    ON patient_experience.encounter_access_grants(principal_id, status, valid_from, expires_at);
CREATE INDEX IF NOT EXISTS idx_patient_encounter_grants_source_encounter
    ON patient_experience.encounter_access_grants(source_encounter_id)
    WHERE source_encounter_id IS NOT NULL;

COMMENT ON TABLE patient_experience.encounter_access_grants IS
    'Effective-dated patient/proxy authorization for one encounter. grant_uuid and encounter_uuid are the only externally addressable identifiers.';

-- Enrollment and recovery secrets are represented only by password hashes.
-- Challenges may be created before a principal is bound after proofing.
CREATE TABLE IF NOT EXISTS patient_experience.enrollment_challenges (
    enrollment_challenge_id bigserial PRIMARY KEY,
    challenge_uuid          uuid NOT NULL UNIQUE,
    principal_id            bigint
                            REFERENCES patient_experience.principals(principal_id)
                            ON DELETE RESTRICT,
    identity_link_id        bigint
                            REFERENCES patient_experience.identity_links(identity_link_id)
                            ON DELETE RESTRICT,
    access_grant_id         bigint
                            REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                            ON DELETE RESTRICT,
    challenge_hash          varchar(255) NOT NULL,
    code_hash               varchar(255),
    purpose                 text NOT NULL DEFAULT 'encounter_enrollment'
                            CHECK (purpose IN ('encounter_enrollment', 'account_recovery', 'identity_link', 'representative_invitation')),
    delivery_method         text NOT NULL DEFAULT 'portal'
                            CHECK (delivery_method IN ('portal', 'qr', 'email', 'sms', 'in_person', 'identity_provider')),
    status                  text NOT NULL DEFAULT 'issued'
                            CHECK (status IN ('issued', 'consumed', 'expired', 'revoked', 'locked')),
    failed_attempts         smallint NOT NULL DEFAULT 0,
    max_attempts            smallint NOT NULL DEFAULT 5,
    expires_at              timestamptz NOT NULL,
    consumed_at             timestamptz,
    revoked_at              timestamptz,
    metadata                jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_enrollment_challenges_attempts_check
        CHECK (failed_attempts >= 0 AND max_attempts > 0 AND failed_attempts <= max_attempts),
    CONSTRAINT patient_enrollment_challenges_hash_check
        CHECK (length(challenge_hash) >= 40 AND (code_hash IS NULL OR length(code_hash) >= 40)),
    CONSTRAINT patient_enrollment_challenges_expiry_check
        CHECK (expires_at > created_at),
    CONSTRAINT patient_enrollment_challenges_consumed_check CHECK (
        (status = 'consumed' AND consumed_at IS NOT NULL) OR status <> 'consumed'
    ),
    CONSTRAINT patient_enrollment_challenges_revoked_check CHECK (
        (status = 'revoked' AND revoked_at IS NOT NULL) OR status <> 'revoked'
    ),
    CONSTRAINT patient_enrollment_challenges_metadata_object_check
        CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_enrollment_challenges_active_hash
    ON patient_experience.enrollment_challenges(challenge_hash)
    WHERE status = 'issued';
CREATE INDEX IF NOT EXISTS idx_patient_enrollment_challenges_principal
    ON patient_experience.enrollment_challenges(principal_id, status, expires_at)
    WHERE principal_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_enrollment_challenges_grant
    ON patient_experience.enrollment_challenges(access_grant_id)
    WHERE access_grant_id IS NOT NULL;

COMMENT ON TABLE patient_experience.enrollment_challenges IS
    'Short-lived enrollment/recovery proof. challenge_hash and code_hash are one-way hashes; bearer values must never be persisted.';

-- Patient sessions are distinct from staff account sessions. The token family
-- UUID groups Sanctum access/refresh tokens but is not itself a bearer secret.
CREATE TABLE IF NOT EXISTS patient_experience.sessions (
    patient_session_id    bigserial PRIMARY KEY,
    session_uuid          uuid NOT NULL UNIQUE,
    principal_id          bigint NOT NULL
                          REFERENCES patient_experience.principals(principal_id)
                          ON DELETE RESTRICT,
    token_family_uuid     uuid NOT NULL UNIQUE,
    refresh_token_id      bigint,
    status                text NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active', 'revoked', 'expired')),
    device_uuid           uuid,
    platform              text CHECK (platform IS NULL OR platform IN ('ios', 'android', 'web')),
    device_name           varchar(190),
    app_version           varchar(80),
    os_version            varchar(80),
    auth_method           varchar(80) NOT NULL,
    assurance_level       varchar(32),
    client_instance_digest varchar(128),
    user_agent_digest     varchar(128),
    ip_address            inet,
    last_authenticated_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at          timestamptz NOT NULL DEFAULT now(),
    expires_at            timestamptz NOT NULL,
    idle_expires_at       timestamptz,
    revoked_at            timestamptz,
    revocation_reason     varchar(500),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_sessions_expiry_check
        CHECK (expires_at > created_at AND (idle_expires_at IS NULL OR idle_expires_at <= expires_at)),
    CONSTRAINT patient_sessions_revocation_check CHECK (
        (status = 'revoked' AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
        OR status <> 'revoked'
    )
);

CREATE INDEX IF NOT EXISTS idx_patient_sessions_principal_active
    ON patient_experience.sessions(principal_id, status, expires_at);
CREATE INDEX IF NOT EXISTS idx_patient_sessions_refresh_token
    ON patient_experience.sessions(refresh_token_id)
    WHERE refresh_token_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_sessions_device
    ON patient_experience.sessions(device_uuid)
    WHERE device_uuid IS NOT NULL;

COMMENT ON TABLE patient_experience.sessions IS
    'Hummingbird Patient session-family projection. Access and refresh bearer tokens remain in the token service and are never stored here.';

-- Immutable authentication, authorization, disclosure, and sensitive-access
-- history. metadata must contain only allowlisted, PHI-minimized reason codes
-- and opaque resource references.
CREATE TABLE IF NOT EXISTS patient_experience.access_audit_events (
    access_audit_event_id bigserial PRIMARY KEY,
    event_uuid            uuid NOT NULL UNIQUE,
    principal_id          bigint
                          REFERENCES patient_experience.principals(principal_id)
                          ON DELETE RESTRICT,
    patient_session_id    bigint
                          REFERENCES patient_experience.sessions(patient_session_id)
                          ON DELETE RESTRICT,
    access_grant_id       bigint
                          REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                          ON DELETE RESTRICT,
    actor_type            text NOT NULL
                          CHECK (actor_type IN ('patient', 'representative', 'identity_provider', 'support', 'system')),
    actor_ref             varchar(190),
    event_type            varchar(120) NOT NULL,
    category              varchar(50) NOT NULL,
    action                varchar(80) NOT NULL,
    outcome               varchar(40) NOT NULL
                          CHECK (outcome IN ('allowed', 'denied', 'succeeded', 'failed', 'recorded')),
    purpose_of_use        varchar(80),
    reason_code           varchar(120),
    resource_type         varchar(120),
    resource_uuid         uuid,
    request_uuid          uuid NOT NULL,
    correlation_uuid      uuid,
    idempotency_key_digest varchar(128),
    ip_address            inet,
    user_agent_digest     varchar(128),
    metadata              jsonb NOT NULL DEFAULT '{}'::jsonb,
    schema_version        smallint NOT NULL DEFAULT 1,
    occurred_at           timestamptz NOT NULL,
    recorded_at           timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_access_events_namespaced_check
        CHECK (event_type ~ '^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$'),
    CONSTRAINT patient_access_events_metadata_object_check
        CHECK (jsonb_typeof(metadata) = 'object'),
    CONSTRAINT patient_access_events_schema_version_check CHECK (schema_version > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_access_events_idempotency
    ON patient_experience.access_audit_events(idempotency_key_digest)
    WHERE idempotency_key_digest IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_access_events_principal_time
    ON patient_experience.access_audit_events(principal_id, occurred_at DESC, access_audit_event_id DESC)
    WHERE principal_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_access_events_grant_time
    ON patient_experience.access_audit_events(access_grant_id, occurred_at DESC)
    WHERE access_grant_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_patient_access_events_request
    ON patient_experience.access_audit_events(request_uuid);
CREATE INDEX IF NOT EXISTS idx_patient_access_events_resource
    ON patient_experience.access_audit_events(resource_type, resource_uuid, occurred_at DESC)
    WHERE resource_type IS NOT NULL;

COMMENT ON TABLE patient_experience.access_audit_events IS
    'Append-only patient authentication, authorization, disclosure, export, enrollment, and sensitive-access history.';

-- Immutable transactional outbox message. Payloads are application-encrypted;
-- routing metadata may contain only generic channel/policy information.
CREATE TABLE IF NOT EXISTS patient_experience.notification_outbox (
    notification_outbox_id bigserial PRIMARY KEY,
    outbox_uuid             uuid NOT NULL UNIQUE,
    principal_id           bigint
                           REFERENCES patient_experience.principals(principal_id)
                           ON DELETE RESTRICT,
    access_grant_id        bigint
                           REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                           ON DELETE RESTRICT,
    aggregate_type         varchar(120) NOT NULL,
    aggregate_uuid         uuid,
    event_type             varchar(120) NOT NULL,
    destination            varchar(50) NOT NULL
                           CHECK (destination IN ('patient_push', 'patient_email', 'patient_sms', 'staff_inbox', 'projection')),
    encrypted_payload      text,
    encryption_key_version varchar(80),
    payload_digest         varchar(128),
    routing_metadata       jsonb NOT NULL DEFAULT '{}'::jsonb,
    idempotency_key_digest varchar(128) NOT NULL UNIQUE,
    available_at           timestamptz NOT NULL DEFAULT now(),
    expires_at             timestamptz,
    occurred_at            timestamptz NOT NULL,
    recorded_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_notification_outbox_namespaced_check
        CHECK (event_type ~ '^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$'),
    CONSTRAINT patient_notification_outbox_routing_object_check
        CHECK (jsonb_typeof(routing_metadata) = 'object'),
    CONSTRAINT patient_notification_outbox_encryption_check CHECK (
        (encrypted_payload IS NULL AND encryption_key_version IS NULL)
        OR (encrypted_payload IS NOT NULL AND encryption_key_version IS NOT NULL)
    ),
    CONSTRAINT patient_notification_outbox_expiry_check
        CHECK (expires_at IS NULL OR expires_at > available_at)
);

CREATE INDEX IF NOT EXISTS idx_patient_notification_outbox_available
    ON patient_experience.notification_outbox(available_at, notification_outbox_id);
CREATE INDEX IF NOT EXISTS idx_patient_notification_outbox_principal
    ON patient_experience.notification_outbox(principal_id, occurred_at DESC)
    WHERE principal_id IS NOT NULL;

COMMENT ON TABLE patient_experience.notification_outbox IS
    'Append-only transactional outbox. Delivery state is derived from immutable delivery attempts; patient content must be encrypted.';

-- Delivery is also event-sourced: workers append claim/result attempts rather
-- than mutating the originating outbox fact.
CREATE TABLE IF NOT EXISTS patient_experience.notification_delivery_attempts (
    notification_delivery_attempt_id bigserial PRIMARY KEY,
    delivery_attempt_uuid             uuid NOT NULL UNIQUE,
    notification_outbox_id            bigint NOT NULL
                                       REFERENCES patient_experience.notification_outbox(notification_outbox_id)
                                       ON DELETE RESTRICT,
    attempt_number                    integer NOT NULL,
    status                            text NOT NULL
                                      CHECK (status IN ('claimed', 'delivered', 'retryable_failure', 'terminal_failure', 'expired')),
    worker_ref                        varchar(190),
    provider_message_ref_digest       varchar(128),
    error_code                        varchar(120),
    next_attempt_at                   timestamptz,
    metadata                          jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at                       timestamptz NOT NULL,
    recorded_at                       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_notification_attempt_number_check CHECK (attempt_number > 0),
    CONSTRAINT patient_notification_attempt_metadata_object_check
        CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_notification_delivery_attempt
    ON patient_experience.notification_delivery_attempts(notification_outbox_id, attempt_number);
CREATE INDEX IF NOT EXISTS idx_patient_notification_delivery_status
    ON patient_experience.notification_delivery_attempts(status, next_attempt_at)
    WHERE status IN ('claimed', 'retryable_failure');

COMMENT ON TABLE patient_experience.notification_delivery_attempts IS
    'Append-only claim and delivery results for patient notification outbox messages.';

-- One database guard protects every immutable patient-experience ledger.
CREATE OR REPLACE FUNCTION patient_experience.reject_append_only_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'patient_experience.% is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_access_audit_events_append_only
    ON patient_experience.access_audit_events;
CREATE TRIGGER patient_access_audit_events_append_only
BEFORE UPDATE OR DELETE ON patient_experience.access_audit_events
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_notification_outbox_append_only
    ON patient_experience.notification_outbox;
CREATE TRIGGER patient_notification_outbox_append_only
BEFORE UPDATE OR DELETE ON patient_experience.notification_outbox
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_notification_delivery_attempts_append_only
    ON patient_experience.notification_delivery_attempts;
CREATE TRIGGER patient_notification_delivery_attempts_append_only
BEFORE UPDATE OR DELETE ON patient_experience.notification_delivery_attempts
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeDropSchema('patient_experience');
    }
};
