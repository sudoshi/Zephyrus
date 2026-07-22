<?php

/**
 * Governed, read-only patient projection kernel.
 *
 * Patient-facing content is materialized into an isolated, versioned release
 * boundary. Source identifiers, raw FHIR resources, staff records, and
 * unreviewed source payloads never belong in encounter_projections. Every
 * projection and governance fact is append-only; withdrawal and correction
 * are represented by later content_actions.
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
CREATE TABLE IF NOT EXISTS patient_experience.release_policy_versions (
    release_policy_version_id bigserial PRIMARY KEY,
    policy_uuid               uuid NOT NULL UNIQUE,
    version                   varchar(120) NOT NULL UNIQUE,
    status                    text NOT NULL DEFAULT 'draft'
                              CHECK (status IN ('draft', 'active', 'superseded', 'withdrawn')),
    disclosure_matrix_version varchar(120) NOT NULL,
    content_contract_version  varchar(120) NOT NULL,
    rules                     jsonb NOT NULL DEFAULT '{}'::jsonb,
    approved_by_actor_ref     varchar(190),
    approved_at               timestamptz,
    effective_from            timestamptz,
    effective_to              timestamptz,
    recorded_at               timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_release_policy_rules_object_check
        CHECK (jsonb_typeof(rules) = 'object'),
    CONSTRAINT patient_release_policy_active_check CHECK (
        (status = 'active' AND approved_at IS NOT NULL AND effective_from IS NOT NULL)
        OR status <> 'active'
    ),
    CONSTRAINT patient_release_policy_window_check
        CHECK (effective_to IS NULL OR (effective_from IS NOT NULL AND effective_to > effective_from))
);

CREATE INDEX IF NOT EXISTS idx_patient_release_policy_effective
    ON patient_experience.release_policy_versions(status, effective_from, effective_to);

COMMENT ON TABLE patient_experience.release_policy_versions IS
    'Append-only disclosure-policy releases. Only an effective active version may authorize a patient projection response.';

-- Cursor rows are immutable checkpoints, not mutable singleton offsets. A new
-- checkpoint is appended after every successful projection cycle.
CREATE TABLE IF NOT EXISTS patient_experience.source_projection_cursors (
    projection_cursor_id bigserial PRIMARY KEY,
    cursor_uuid          uuid NOT NULL UNIQUE,
    source_system_key    varchar(120) NOT NULL,
    projection_kind      text NOT NULL
                         CHECK (projection_kind IN ('today', 'pathway', 'care_team')),
    cursor_digest        varchar(128) NOT NULL,
    source_version       varchar(190) NOT NULL,
    status               text NOT NULL
                         CHECK (status IN ('observed', 'projected', 'partial', 'paused')),
    source_observed_at   timestamptz NOT NULL,
    projected_at         timestamptz,
    metadata             jsonb NOT NULL DEFAULT '{}'::jsonb,
    recorded_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_projection_cursor_digest_check
        CHECK (length(cursor_digest) >= 40),
    CONSTRAINT patient_projection_cursor_metadata_object_check
        CHECK (jsonb_typeof(metadata) = 'object'),
    CONSTRAINT patient_projection_cursor_status_check CHECK (
        (status IN ('projected', 'partial') AND projected_at IS NOT NULL)
        OR status IN ('observed', 'paused')
    )
);

CREATE INDEX IF NOT EXISTS idx_patient_projection_cursor_latest
    ON patient_experience.source_projection_cursors(
        source_system_key,
        projection_kind,
        source_observed_at DESC,
        projection_cursor_id DESC
    );

COMMENT ON TABLE patient_experience.source_projection_cursors IS
    'Append-only, digest-only source checkpoints. Raw source cursor values and source payloads are forbidden.';

CREATE TABLE IF NOT EXISTS patient_experience.source_projection_failures (
    projection_failure_id bigserial PRIMARY KEY,
    failure_uuid          uuid NOT NULL UNIQUE,
    projection_cursor_id bigint
                         REFERENCES patient_experience.source_projection_cursors(projection_cursor_id)
                         ON DELETE RESTRICT,
    source_system_key    varchar(120) NOT NULL,
    projection_kind      text NOT NULL
                         CHECK (projection_kind IN ('today', 'pathway', 'care_team')),
    failure_code         varchar(120) NOT NULL,
    retryability         text NOT NULL
                         CHECK (retryability IN ('retryable', 'terminal', 'manual_review')),
    attempt_number       integer NOT NULL DEFAULT 1,
    source_observed_at   timestamptz,
    occurred_at          timestamptz NOT NULL,
    context              jsonb NOT NULL DEFAULT '{}'::jsonb,
    recorded_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_projection_failure_attempt_check CHECK (attempt_number > 0),
    CONSTRAINT patient_projection_failure_context_object_check
        CHECK (jsonb_typeof(context) = 'object'),
    CONSTRAINT patient_projection_failure_code_check
        CHECK (failure_code ~ '^[a-z][a-z0-9_]{2,119}$')
);

CREATE INDEX IF NOT EXISTS idx_patient_projection_failure_time
    ON patient_experience.source_projection_failures(
        source_system_key,
        projection_kind,
        occurred_at DESC,
        projection_failure_id DESC
    );

COMMENT ON TABLE patient_experience.source_projection_failures IS
    'Append-only, PHI-minimized projection failures. Exception messages, raw payloads, and source identifiers are forbidden.';

CREATE TABLE IF NOT EXISTS patient_experience.encounter_projections (
    encounter_projection_id    bigserial PRIMARY KEY,
    projection_uuid            uuid NOT NULL UNIQUE,
    access_grant_id            bigint NOT NULL
                               REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                               ON DELETE RESTRICT,
    release_policy_version_id  bigint NOT NULL
                               REFERENCES patient_experience.release_policy_versions(release_policy_version_id)
                               ON DELETE RESTRICT,
    projection_cursor_id       bigint
                               REFERENCES patient_experience.source_projection_cursors(projection_cursor_id)
                               ON DELETE RESTRICT,
    supersedes_projection_id   bigint
                               REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                               ON DELETE RESTRICT,
    projection_kind            text NOT NULL
                               CHECK (projection_kind IN ('today', 'pathway', 'care_team')),
    projection_sequence        integer NOT NULL,
    content                    jsonb NOT NULL,
    content_schema_version     varchar(120) NOT NULL,
    content_digest             varchar(128) NOT NULL,
    source_version             varchar(190) NOT NULL,
    provenance                 jsonb NOT NULL DEFAULT '{}'::jsonb,
    source_observed_at         timestamptz NOT NULL,
    generated_at               timestamptz NOT NULL,
    released_at                timestamptz,
    freshness_class            text NOT NULL
                               CHECK (freshness_class IN ('current', 'aging', 'stale', 'unknown', 'unavailable')),
    uncertainty                jsonb NOT NULL DEFAULT '{}'::jsonb,
    required_scope             varchar(80) NOT NULL,
    permitted_relationships    jsonb NOT NULL DEFAULT '["self"]'::jsonb,
    release_state              text NOT NULL DEFAULT 'draft'
                               CHECK (release_state IN ('draft', 'released', 'withheld')),
    recorded_at                timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_projection_sequence_check CHECK (projection_sequence > 0),
    CONSTRAINT patient_projection_content_object_check CHECK (jsonb_typeof(content) = 'object'),
    CONSTRAINT patient_projection_content_digest_check CHECK (length(content_digest) >= 40),
    CONSTRAINT patient_projection_provenance_object_check CHECK (jsonb_typeof(provenance) = 'object'),
    CONSTRAINT patient_projection_uncertainty_object_check CHECK (jsonb_typeof(uncertainty) = 'object'),
    CONSTRAINT patient_projection_relationships_array_check
        CHECK (jsonb_typeof(permitted_relationships) = 'array' AND jsonb_array_length(permitted_relationships) > 0),
    CONSTRAINT patient_projection_release_check CHECK (
        (release_state = 'released' AND released_at IS NOT NULL)
        OR (release_state <> 'released' AND released_at IS NULL)
    ),
    CONSTRAINT patient_projection_time_order_check CHECK (
        generated_at >= source_observed_at
        AND (released_at IS NULL OR released_at >= generated_at)
    ),
    CONSTRAINT patient_projection_not_self_superseded_check
        CHECK (supersedes_projection_id IS NULL OR supersedes_projection_id <> encounter_projection_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_projection_sequence
    ON patient_experience.encounter_projections(access_grant_id, projection_kind, projection_sequence);
CREATE INDEX IF NOT EXISTS idx_patient_projection_release_lookup
    ON patient_experience.encounter_projections(
        access_grant_id,
        projection_kind,
        release_policy_version_id,
        released_at DESC,
        encounter_projection_id DESC
    )
    WHERE release_state = 'released';
CREATE INDEX IF NOT EXISTS idx_patient_projection_cursor
    ON patient_experience.encounter_projections(projection_cursor_id)
    WHERE projection_cursor_id IS NOT NULL;

COMMENT ON TABLE patient_experience.encounter_projections IS
    'Append-only patient-safe Today, My Path, and Care Team releases. Content is allowlisted projection data, never raw FHIR or staff/source records.';

-- Retractions and corrections are immutable facts. A correction makes the
-- target unavailable and identifies a separately released replacement row.
CREATE TABLE IF NOT EXISTS patient_experience.content_actions (
    content_action_id          bigserial PRIMARY KEY,
    action_uuid               uuid NOT NULL UNIQUE,
    target_projection_id      bigint NOT NULL
                              REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                              ON DELETE RESTRICT,
    replacement_projection_id bigint
                              REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                              ON DELETE RESTRICT,
    release_policy_version_id bigint NOT NULL
                              REFERENCES patient_experience.release_policy_versions(release_policy_version_id)
                              ON DELETE RESTRICT,
    action_type               text NOT NULL CHECK (action_type IN ('retraction', 'correction')),
    reason_code               varchar(120) NOT NULL,
    actor_type                text NOT NULL DEFAULT 'system'
                              CHECK (actor_type IN ('governance', 'clinical_review', 'privacy', 'system')),
    actor_ref                 varchar(190),
    effective_at             timestamptz NOT NULL,
    recorded_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_content_action_reason_check
        CHECK (reason_code ~ '^[a-z][a-z0-9_]{2,119}$'),
    CONSTRAINT patient_content_action_replacement_check CHECK (
        (action_type = 'correction' AND replacement_projection_id IS NOT NULL)
        OR (action_type = 'retraction' AND replacement_projection_id IS NULL)
    ),
    CONSTRAINT patient_content_action_not_self_check
        CHECK (replacement_projection_id IS NULL OR replacement_projection_id <> target_projection_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_content_action_target
    ON patient_experience.content_actions(target_projection_id);
CREATE INDEX IF NOT EXISTS idx_patient_content_action_replacement
    ON patient_experience.content_actions(replacement_projection_id)
    WHERE replacement_projection_id IS NOT NULL;

COMMENT ON TABLE patient_experience.content_actions IS
    'Append-only correction/retraction facts. Released projection rows are never overwritten or deleted.';

CREATE OR REPLACE FUNCTION patient_experience.reject_projection_kernel_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'patient_experience.% is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_release_policy_versions_append_only
    ON patient_experience.release_policy_versions;
CREATE TRIGGER patient_release_policy_versions_append_only
BEFORE UPDATE OR DELETE ON patient_experience.release_policy_versions
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();

DROP TRIGGER IF EXISTS patient_projection_cursors_append_only
    ON patient_experience.source_projection_cursors;
CREATE TRIGGER patient_projection_cursors_append_only
BEFORE UPDATE OR DELETE ON patient_experience.source_projection_cursors
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();

DROP TRIGGER IF EXISTS patient_projection_failures_append_only
    ON patient_experience.source_projection_failures;
CREATE TRIGGER patient_projection_failures_append_only
BEFORE UPDATE OR DELETE ON patient_experience.source_projection_failures
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();

DROP TRIGGER IF EXISTS patient_encounter_projections_append_only
    ON patient_experience.encounter_projections;
CREATE TRIGGER patient_encounter_projections_append_only
BEFORE UPDATE OR DELETE ON patient_experience.encounter_projections
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();

DROP TRIGGER IF EXISTS patient_content_actions_append_only
    ON patient_experience.content_actions;
CREATE TRIGGER patient_content_actions_append_only
BEFORE UPDATE OR DELETE ON patient_experience.content_actions
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->safeDropIfExists('patient_experience.content_actions');
        $this->safeDropIfExists('patient_experience.encounter_projections');
        $this->safeDropIfExists('patient_experience.source_projection_failures');
        $this->safeDropIfExists('patient_experience.source_projection_cursors');
        $this->safeDropIfExists('patient_experience.release_policy_versions');
    }
};
