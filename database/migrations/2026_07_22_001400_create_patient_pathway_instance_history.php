<?php

/**
 * Encounter-bound pathway history.
 *
 * Catalog versions are immutable governed definitions. These tables pin a
 * patient encounter to one exact catalog version and record subsequent stage
 * and milestone state as append-only observations. Nothing here is directly
 * patient-visible: a separately governed projection release is still required.
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
CREATE TABLE IF NOT EXISTS care_pathways.stage_definitions (
    stage_definition_id   bigserial PRIMARY KEY,
    stage_uuid            uuid NOT NULL UNIQUE,
    pathway_version_id    bigint NOT NULL
                          REFERENCES care_pathways.versions(pathway_version_id)
                          ON DELETE RESTRICT,
    stable_key            text NOT NULL,
    display_order         integer NOT NULL CHECK (display_order > 0),
    approved_label        text NOT NULL,
    approved_explanation  text,
    expected_range        jsonb NOT NULL DEFAULT '{}'::jsonb,
    review_state          text NOT NULL DEFAULT 'draft'
                          CHECK (review_state IN ('draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    content_digest        char(64) NOT NULL CHECK (content_digest ~ '^[0-9a-f]{64}$'),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT care_pathway_stage_definition_key_check
        CHECK (stable_key ~ '^[a-z][a-z0-9_]{1,118}[a-z0-9]$'),
    CONSTRAINT care_pathway_stage_definition_copy_check
        CHECK (btrim(approved_label) <> ''),
    CONSTRAINT care_pathway_stage_definition_range_object_check
        CHECK (jsonb_typeof(expected_range) = 'object'),
    UNIQUE (pathway_version_id, stable_key),
    UNIQUE (pathway_version_id, display_order)
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_stage_definitions_version
    ON care_pathways.stage_definitions(pathway_version_id, display_order);

COMMENT ON TABLE care_pathways.stage_definitions IS
    'Version-bound governed stage definitions. Source content is immutable; a changed stage requires a new pathway version.';

CREATE OR REPLACE FUNCTION care_pathways.protect_stage_definition_content()
RETURNS trigger AS $$
BEGIN
    IF ROW(
        OLD.pathway_version_id,
        OLD.stable_key,
        OLD.display_order,
        OLD.approved_label,
        OLD.approved_explanation,
        OLD.expected_range,
        OLD.content_digest
    ) IS DISTINCT FROM ROW(
        NEW.pathway_version_id,
        NEW.stable_key,
        NEW.display_order,
        NEW.approved_label,
        NEW.approved_explanation,
        NEW.expected_range,
        NEW.content_digest
    ) THEN
        RAISE EXCEPTION 'care pathway stage definition content is immutable; create a superseding pathway version';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS care_pathway_stage_definition_content_immutable
    ON care_pathways.stage_definitions;
CREATE TRIGGER care_pathway_stage_definition_content_immutable
BEFORE UPDATE ON care_pathways.stage_definitions
FOR EACH ROW EXECUTE FUNCTION care_pathways.protect_stage_definition_content();

CREATE TABLE IF NOT EXISTS patient_experience.pathway_instances (
    pathway_instance_id       bigserial PRIMARY KEY,
    pathway_instance_uuid     uuid NOT NULL UNIQUE,
    access_grant_id           bigint NOT NULL
                             REFERENCES patient_experience.encounter_access_grants(access_grant_id)
                             ON DELETE RESTRICT,
    pathway_version_id        bigint NOT NULL
                             REFERENCES care_pathways.versions(pathway_version_id)
                             ON DELETE RESTRICT,
    source_system_key         varchar(120) NOT NULL,
    source_assignment_digest  char(64) NOT NULL CHECK (source_assignment_digest ~ '^[0-9a-f]{64}$'),
    source_observed_at        timestamptz NOT NULL,
    instantiated_at           timestamptz NOT NULL,
    recorded_at               timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT patient_pathway_instance_source_key_check
        CHECK (source_system_key ~ '^[a-z][a-z0-9._-]{1,119}$'),
    CONSTRAINT patient_pathway_instance_time_order_check
        CHECK (instantiated_at >= source_observed_at),
    UNIQUE (access_grant_id, pathway_version_id, source_assignment_digest)
);

CREATE INDEX IF NOT EXISTS idx_patient_pathway_instances_grant
    ON patient_experience.pathway_instances(access_grant_id, instantiated_at DESC);
CREATE INDEX IF NOT EXISTS idx_patient_pathway_instances_version
    ON patient_experience.pathway_instances(pathway_version_id, instantiated_at DESC);

COMMENT ON TABLE patient_experience.pathway_instances IS
    'Append-only, encounter-scoped assignments to one exact governed pathway version. Source identifiers are represented only by keyed digests.';

CREATE TABLE IF NOT EXISTS patient_experience.pathway_stage_instances (
    pathway_stage_instance_id  bigserial PRIMARY KEY,
    stage_instance_uuid        uuid NOT NULL UNIQUE,
    pathway_instance_id        bigint NOT NULL
                               REFERENCES patient_experience.pathway_instances(pathway_instance_id)
                               ON DELETE RESTRICT,
    stage_definition_id        bigint NOT NULL
                               REFERENCES care_pathways.stage_definitions(stage_definition_id)
                               ON DELETE RESTRICT,
    instantiated_at            timestamptz NOT NULL,
    recorded_at                timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_instance_id, stage_definition_id)
);

CREATE TABLE IF NOT EXISTS patient_experience.pathway_milestone_instances (
    pathway_milestone_instance_id bigserial PRIMARY KEY,
    milestone_instance_uuid       uuid NOT NULL UNIQUE,
    pathway_instance_id           bigint NOT NULL
                                  REFERENCES patient_experience.pathway_instances(pathway_instance_id)
                                  ON DELETE RESTRICT,
    milestone_definition_id       bigint NOT NULL
                                  REFERENCES care_pathways.milestone_definitions(milestone_definition_id)
                                  ON DELETE RESTRICT,
    instantiated_at               timestamptz NOT NULL,
    recorded_at                   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_instance_id, milestone_definition_id)
);

CREATE OR REPLACE FUNCTION patient_experience.enforce_pathway_instance_definition_membership()
RETURNS trigger AS $$
DECLARE
    instance_version_id bigint;
    definition_version_id bigint;
BEGIN
    SELECT pathway_version_id
      INTO instance_version_id
      FROM patient_experience.pathway_instances
     WHERE pathway_instance_id = NEW.pathway_instance_id;

    IF TG_TABLE_NAME = 'pathway_stage_instances' THEN
        SELECT pathway_version_id
          INTO definition_version_id
          FROM care_pathways.stage_definitions
         WHERE stage_definition_id = NEW.stage_definition_id;
    ELSIF TG_TABLE_NAME = 'pathway_milestone_instances' THEN
        SELECT pathway_version_id
          INTO definition_version_id
          FROM care_pathways.milestone_definitions
         WHERE milestone_definition_id = NEW.milestone_definition_id;
    ELSE
        RAISE EXCEPTION 'unsupported pathway instance definition table %', TG_TABLE_NAME;
    END IF;

    IF instance_version_id IS NULL OR definition_version_id IS NULL
       OR instance_version_id <> definition_version_id THEN
        RAISE EXCEPTION 'pathway instance definition must belong to its pinned pathway version';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS patient_pathway_stage_instance_definition_membership
    ON patient_experience.pathway_stage_instances;
CREATE TRIGGER patient_pathway_stage_instance_definition_membership
BEFORE INSERT OR UPDATE ON patient_experience.pathway_stage_instances
FOR EACH ROW EXECUTE FUNCTION patient_experience.enforce_pathway_instance_definition_membership();

DROP TRIGGER IF EXISTS patient_pathway_milestone_instance_definition_membership
    ON patient_experience.pathway_milestone_instances;
CREATE TRIGGER patient_pathway_milestone_instance_definition_membership
BEFORE INSERT OR UPDATE ON patient_experience.pathway_milestone_instances
FOR EACH ROW EXECUTE FUNCTION patient_experience.enforce_pathway_instance_definition_membership();

CREATE TABLE IF NOT EXISTS patient_experience.pathway_stage_status_events (
    pathway_stage_status_event_id bigserial PRIMARY KEY,
    stage_status_event_uuid       uuid NOT NULL UNIQUE,
    pathway_stage_instance_id     bigint NOT NULL
                                  REFERENCES patient_experience.pathway_stage_instances(pathway_stage_instance_id)
                                  ON DELETE RESTRICT,
    status                        text NOT NULL
                                  CHECK (status IN ('planned', 'current', 'completed', 'delayed', 'canceled')),
    source_event_digest           char(64) NOT NULL CHECK (source_event_digest ~ '^[0-9a-f]{64}$'),
    source_observed_at            timestamptz NOT NULL,
    recorded_at                   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_stage_instance_id, source_event_digest)
);

CREATE INDEX IF NOT EXISTS idx_patient_pathway_stage_status_current
    ON patient_experience.pathway_stage_status_events(pathway_stage_instance_id, source_observed_at DESC, pathway_stage_status_event_id DESC);

CREATE TABLE IF NOT EXISTS patient_experience.pathway_milestone_status_events (
    pathway_milestone_status_event_id bigserial PRIMARY KEY,
    milestone_status_event_uuid       uuid NOT NULL UNIQUE,
    pathway_milestone_instance_id     bigint NOT NULL
                                      REFERENCES patient_experience.pathway_milestone_instances(pathway_milestone_instance_id)
                                      ON DELETE RESTRICT,
    status                            text NOT NULL
                                      CHECK (status IN ('planned', 'current', 'completed', 'delayed', 'canceled')),
    source_event_digest               char(64) NOT NULL CHECK (source_event_digest ~ '^[0-9a-f]{64}$'),
    source_observed_at                timestamptz NOT NULL,
    recorded_at                       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_milestone_instance_id, source_event_digest)
);

CREATE INDEX IF NOT EXISTS idx_patient_pathway_milestone_status_current
    ON patient_experience.pathway_milestone_status_events(pathway_milestone_instance_id, source_observed_at DESC, pathway_milestone_status_event_id DESC);

CREATE OR REPLACE VIEW patient_experience.current_pathway_stage_statuses AS
SELECT DISTINCT ON (events.pathway_stage_instance_id) events.*
FROM patient_experience.pathway_stage_status_events events
ORDER BY events.pathway_stage_instance_id, events.source_observed_at DESC, events.pathway_stage_status_event_id DESC;

CREATE OR REPLACE VIEW patient_experience.current_pathway_milestone_statuses AS
SELECT DISTINCT ON (events.pathway_milestone_instance_id) events.*
FROM patient_experience.pathway_milestone_status_events events
ORDER BY events.pathway_milestone_instance_id, events.source_observed_at DESC, events.pathway_milestone_status_event_id DESC;

COMMENT ON VIEW patient_experience.current_pathway_stage_statuses IS
    'Latest stage observation for operational projection only. Base status facts remain immutable historical evidence.';
COMMENT ON VIEW patient_experience.current_pathway_milestone_statuses IS
    'Latest milestone observation for operational projection only. Base status facts remain immutable historical evidence.';

DROP TRIGGER IF EXISTS patient_pathway_instances_append_only
    ON patient_experience.pathway_instances;
CREATE TRIGGER patient_pathway_instances_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_instances
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_pathway_stage_instances_append_only
    ON patient_experience.pathway_stage_instances;
CREATE TRIGGER patient_pathway_stage_instances_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_stage_instances
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_pathway_milestone_instances_append_only
    ON patient_experience.pathway_milestone_instances;
CREATE TRIGGER patient_pathway_milestone_instances_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_milestone_instances
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_pathway_stage_status_events_append_only
    ON patient_experience.pathway_stage_status_events;
CREATE TRIGGER patient_pathway_stage_status_events_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_stage_status_events
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();

DROP TRIGGER IF EXISTS patient_pathway_milestone_status_events_append_only
    ON patient_experience.pathway_milestone_status_events;
CREATE TRIGGER patient_pathway_milestone_status_events_append_only
BEFORE UPDATE OR DELETE ON patient_experience.pathway_milestone_status_events
FOR EACH ROW EXECUTE FUNCTION patient_experience.reject_append_only_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP VIEW IF EXISTS patient_experience.current_pathway_milestone_statuses;
DROP VIEW IF EXISTS patient_experience.current_pathway_stage_statuses;
DROP TABLE IF EXISTS patient_experience.pathway_milestone_status_events;
DROP TABLE IF EXISTS patient_experience.pathway_stage_status_events;
DROP TABLE IF EXISTS patient_experience.pathway_milestone_instances;
DROP TABLE IF EXISTS patient_experience.pathway_stage_instances;
DROP TABLE IF EXISTS patient_experience.pathway_instances;
DROP TABLE IF EXISTS care_pathways.stage_definitions;
SQL);
    }
};
