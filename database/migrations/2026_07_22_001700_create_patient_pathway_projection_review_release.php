<?php

/**
 * Independent clinical review and release execution for a patient-pathway
 * draft. A clinical approver and a catalog release manager are deliberately
 * separate actors; neither the draft nor a released projection is ever
 * updated in place.
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
CREATE TABLE IF NOT EXISTS patient_experience.pathway_projection_reviews (
    pathway_projection_review_id bigserial PRIMARY KEY,
    review_uuid                  uuid NOT NULL UNIQUE,
    draft_projection_id          bigint NOT NULL
                               REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                               ON DELETE RESTRICT,
    release_policy_version_id    bigint NOT NULL
                               REFERENCES patient_experience.release_policy_versions(release_policy_version_id)
                               ON DELETE RESTRICT,
    reviewer_actor_digest        char(64) NOT NULL CHECK (reviewer_actor_digest ~ '^[0-9a-f]{64}$'),
    decision                     text NOT NULL CHECK (decision IN ('approved', 'withheld')),
    reason_code                  varchar(120) NOT NULL,
    review_digest                char(64) NOT NULL CHECK (review_digest ~ '^[0-9a-f]{64}$'),
    reviewed_at                  timestamptz NOT NULL,
    recorded_at                  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_pathway_projection_review_reason_check
        CHECK (reason_code ~ '^[a-z][a-z0-9_]{2,119}$'),
    UNIQUE (draft_projection_id)
);

CREATE INDEX IF NOT EXISTS idx_patient_pathway_projection_reviews_policy
    ON patient_experience.pathway_projection_reviews(release_policy_version_id, reviewed_at DESC);

COMMENT ON TABLE patient_experience.pathway_projection_reviews IS
    'Append-only clinical decisions over one draft-only pathway projection. It stores no patient content or free-text review note.';

CREATE TABLE IF NOT EXISTS patient_experience.pathway_projection_release_executions (
    pathway_projection_release_execution_id bigserial PRIMARY KEY,
    release_execution_uuid                  uuid NOT NULL UNIQUE,
    pathway_projection_review_id            bigint NOT NULL
                                           REFERENCES patient_experience.pathway_projection_reviews(pathway_projection_review_id)
                                           ON DELETE RESTRICT,
    released_projection_id                  bigint NOT NULL
                                           REFERENCES patient_experience.encounter_projections(encounter_projection_id)
                                           ON DELETE RESTRICT,
    release_manager_actor_digest            char(64) NOT NULL CHECK (release_manager_actor_digest ~ '^[0-9a-f]{64}$'),
    release_digest                          char(64) NOT NULL CHECK (release_digest ~ '^[0-9a-f]{64}$'),
    released_at                             timestamptz NOT NULL,
    recorded_at                             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_projection_review_id),
    UNIQUE (released_projection_id)
);

CREATE INDEX IF NOT EXISTS idx_patient_pathway_projection_release_manager
    ON patient_experience.pathway_projection_release_executions(release_manager_actor_digest, released_at DESC);

COMMENT ON TABLE patient_experience.pathway_projection_release_executions IS
    'Append-only, second-person release execution for an approved pathway draft. The linked projection release remains immutable and publishes through the existing outbox trigger.';

CREATE OR REPLACE FUNCTION patient_experience.validate_pathway_projection_review()
RETURNS trigger AS $$
DECLARE
    draft_kind text;
    draft_state text;
    draft_policy_id bigint;
BEGIN
    SELECT projection_kind, release_state, release_policy_version_id
      INTO draft_kind, draft_state, draft_policy_id
      FROM patient_experience.encounter_projections
     WHERE encounter_projection_id = NEW.draft_projection_id;

    IF draft_kind IS DISTINCT FROM 'pathway'
       OR draft_state IS DISTINCT FROM 'draft'
       OR draft_policy_id IS DISTINCT FROM NEW.release_policy_version_id THEN
        RAISE EXCEPTION 'pathway projection review requires a pathway draft with its matching release policy';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_pathway_projection_review_valid
    ON patient_experience.pathway_projection_reviews;
CREATE TRIGGER patient_pathway_projection_review_valid
BEFORE INSERT OR UPDATE ON patient_experience.pathway_projection_reviews
FOR EACH ROW EXECUTE FUNCTION patient_experience.validate_pathway_projection_review();

CREATE OR REPLACE FUNCTION patient_experience.validate_pathway_projection_release_execution()
RETURNS trigger AS $$
DECLARE
    review_decision text;
    reviewer_actor_digest varchar(64);
    review_policy_id bigint;
    draft_projection_id bigint;
    draft_grant_id bigint;
    draft_content_digest varchar(128);
    released_kind text;
    released_state text;
    released_policy_id bigint;
    released_grant_id bigint;
    released_content_digest varchar(128);
BEGIN
    SELECT reviews.decision,
           reviews.reviewer_actor_digest,
           reviews.release_policy_version_id,
           reviews.draft_projection_id
      INTO review_decision, reviewer_actor_digest, review_policy_id, draft_projection_id
      FROM patient_experience.pathway_projection_reviews AS reviews
     WHERE reviews.pathway_projection_review_id = NEW.pathway_projection_review_id;

    SELECT access_grant_id, content_digest
      INTO draft_grant_id, draft_content_digest
      FROM patient_experience.encounter_projections
     WHERE encounter_projection_id = draft_projection_id;

    SELECT projection_kind, release_state, release_policy_version_id, access_grant_id, content_digest
      INTO released_kind, released_state, released_policy_id, released_grant_id, released_content_digest
      FROM patient_experience.encounter_projections
     WHERE encounter_projection_id = NEW.released_projection_id;

    IF review_decision IS DISTINCT FROM 'approved'
       OR reviewer_actor_digest IS NULL
       OR reviewer_actor_digest = NEW.release_manager_actor_digest
       OR released_kind IS DISTINCT FROM 'pathway'
       OR released_state IS DISTINCT FROM 'released'
       OR review_policy_id IS DISTINCT FROM released_policy_id
       OR draft_grant_id IS DISTINCT FROM released_grant_id
       OR draft_content_digest IS DISTINCT FROM released_content_digest THEN
        RAISE EXCEPTION 'pathway projection release requires an independently approved matching released projection';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_pathway_projection_release_execution_valid
    ON patient_experience.pathway_projection_release_executions;
CREATE TRIGGER patient_pathway_projection_release_execution_valid
BEFORE INSERT OR UPDATE ON patient_experience.pathway_projection_release_executions
FOR EACH ROW EXECUTE FUNCTION patient_experience.validate_pathway_projection_release_execution();

CREATE OR REPLACE FUNCTION patient_experience.require_pathway_release_execution()
RETURNS trigger AS $$
BEGIN
    IF NEW.projection_kind = 'pathway'
       AND NEW.release_state = 'released'
       AND NEW.provenance->>'projection_method' = 'version_pinned_pathway_history_clinical_release'
       AND NOT EXISTS (
           SELECT 1
             FROM patient_experience.pathway_projection_release_executions releases
            WHERE releases.released_projection_id = NEW.encounter_projection_id
       ) THEN
        RAISE EXCEPTION 'patient pathway clinical release requires a release execution';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_pathway_release_execution_required
    ON patient_experience.encounter_projections;
CREATE CONSTRAINT TRIGGER patient_pathway_release_execution_required
AFTER INSERT ON patient_experience.encounter_projections
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION patient_experience.require_pathway_release_execution();

DROP TRIGGER IF EXISTS patient_pathway_projection_reviews_append_only
    ON patient_experience.pathway_projection_reviews;
CREATE TRIGGER patient_pathway_projection_reviews_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_projection_reviews
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();

DROP TRIGGER IF EXISTS patient_pathway_projection_release_executions_append_only
    ON patient_experience.pathway_projection_release_executions;
CREATE TRIGGER patient_pathway_projection_release_executions_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_projection_release_executions
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_projection_kernel_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS patient_pathway_release_execution_required ON patient_experience.encounter_projections;
DROP FUNCTION IF EXISTS patient_experience.require_pathway_release_execution();
DROP TABLE IF EXISTS patient_experience.pathway_projection_release_executions;
DROP TABLE IF EXISTS patient_experience.pathway_projection_reviews;
DROP FUNCTION IF EXISTS patient_experience.validate_pathway_projection_release_execution();
DROP FUNCTION IF EXISTS patient_experience.validate_pathway_projection_review();
SQL);
    }
};
