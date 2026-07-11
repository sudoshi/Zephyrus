<?php

/**
 * Virtual Rounds kernel — Phase 1 of the Virtual Rounds / 4D / Eddy plan.
 *
 * Plan: docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md (§6)
 *
 * Creates the `rounds` schema and the Phase-1 workflow tables: templates, runs,
 * patients, participants, contributions, questions, tasks, and the append-only
 * event stream. Later phases add attendance, contact_preferences, notifications,
 * interpreter_requests, consult_requests, evaluations, and tours in their own
 * migrations.
 *
 * Conventions (matching flow_core / hosp_* migrations):
 * - bigserial surrogate PKs named `<table>_id`; uuid business keys `<table>_uuid`.
 * - encounter_ref is a soft text ref: the flow_core.encounters ref when the
 *   census row is bridged, else a deterministic `prodenc:{id}` fallback —
 *   enrollment must not depend on the flow spine being populated. Soft bigint
 *   refs to prod.users (never constrain the auth table).
 * - Location/service-line enrollment snapshots are soft refs — historical truth
 *   must survive reference-data changes.
 * - Postgres rejects expressions in inline UNIQUE; partial uniques use
 *   CREATE UNIQUE INDEX ... WHERE.
 * - Destructive rollback is local-only via SafeMigration.
 */

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS rounds;

-- ---------------------------------------------------------------------------
-- Versioned round policy by scope and service.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.templates (
    template_id        bigserial PRIMARY KEY,
    template_uuid      uuid NOT NULL UNIQUE,
    name               text NOT NULL,
    description        text,
    scope_types        text[] NOT NULL DEFAULT '{unit}',
    mode               text NOT NULL DEFAULT 'async'
                       CHECK (mode IN ('async', 'live', 'hybrid', 'eddy_assisted')),
    required_roles     jsonb NOT NULL DEFAULT '[]'::jsonb,
    completion_policy  jsonb NOT NULL DEFAULT '{}'::jsonb,
    priority_policy    jsonb NOT NULL DEFAULT '{}'::jsonb,
    eta_policy         jsonb NOT NULL DEFAULT '{}'::jsonb,
    version            integer NOT NULL DEFAULT 1,
    active             boolean NOT NULL DEFAULT true,
    created_by         bigint,
    metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_rounds_templates_name_version
    ON rounds.templates(name, version);

COMMENT ON TABLE rounds.templates IS
    'Versioned round policy: required roles/sections, completion, priority, and ETA rules. Templates are immutable per version; changes bump version.';

-- ---------------------------------------------------------------------------
-- One execution of a template over one scope.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.runs (
    run_id               bigserial PRIMARY KEY,
    run_uuid             uuid NOT NULL UNIQUE,
    template_id          bigint NOT NULL REFERENCES rounds.templates(template_id),
    template_version     integer NOT NULL DEFAULT 1,
    facility_key         text,
    scope_type           text NOT NULL
                         CHECK (scope_type IN ('facility', 'floor', 'unit', 'department', 'service_line', 'patient')),
    scope_key            text NOT NULL,
    scope_label          text,
    mode                 text NOT NULL DEFAULT 'async'
                         CHECK (mode IN ('async', 'live', 'hybrid', 'eddy_assisted')),
    status               text NOT NULL DEFAULT 'draft'
                         CHECK (status IN ('draft', 'scheduled', 'active', 'paused', 'closing', 'completed', 'cancelled')),
    planned_start_at     timestamptz,
    window_end_at        timestamptz,
    started_at           timestamptz,
    completed_at         timestamptz,
    cancelled_at         timestamptz,
    queue_version        integer NOT NULL DEFAULT 1,
    source_cutoff_at     timestamptz,
    completion_exception jsonb,
    created_by           bigint,
    metadata             jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_rounds_runs_scope
    ON rounds.runs(scope_type, scope_key, status);
CREATE INDEX IF NOT EXISTS idx_rounds_runs_status
    ON rounds.runs(status, planned_start_at);

COMMENT ON TABLE rounds.runs IS
    'One execution of a rounds template. queue_version is the optimistic-concurrency token for the ordered queue; source_cutoff_at freezes the census snapshot moment.';

-- ---------------------------------------------------------------------------
-- Canonical encounter participation in a run. encounter_ref is the round
-- target; snapshot_* columns freeze location/service-line at enrollment.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.patients (
    round_patient_id           bigserial PRIMARY KEY,
    round_patient_uuid         uuid NOT NULL UNIQUE,
    run_id                     bigint NOT NULL REFERENCES rounds.runs(run_id) ON DELETE CASCADE,
    encounter_ref              text NOT NULL,
    prod_encounter_id          bigint,
    patient_ref                text NOT NULL,
    snapshot_unit_id           bigint,
    snapshot_facility_space_id bigint,
    snapshot_service_line_code text,
    snapshot_room              text,
    snapshot_bed               text,
    status                     text NOT NULL DEFAULT 'queued'
                               CHECK (status IN ('queued', 'in_progress', 'awaiting_input', 'ready_for_review', 'rounded', 'deferred', 'skipped')),
    priority_score             numeric(8,2) NOT NULL DEFAULT 0,
    priority_band              smallint NOT NULL DEFAULT 6,
    priority_reasons           jsonb NOT NULL DEFAULT '[]'::jsonb,
    pinned_by                  bigint,
    pinned_at                  timestamptz,
    pin_reason                 text,
    queue_position             integer NOT NULL DEFAULT 0,
    eta_window_start           timestamptz,
    eta_window_end             timestamptz,
    estimated_duration_minutes smallint,
    inclusion                  jsonb NOT NULL DEFAULT '{}'::jsonb,
    version                    integer NOT NULL DEFAULT 1,
    rounded_by                 bigint,
    rounded_at                 timestamptz,
    status_reason              text,
    metadata                   jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_rounds_patients_run_encounter
    ON rounds.patients(run_id, encounter_ref);
CREATE INDEX IF NOT EXISTS idx_rounds_patients_run_status
    ON rounds.patients(run_id, status, queue_position);
CREATE INDEX IF NOT EXISTS idx_rounds_patients_encounter
    ON rounds.patients(encounter_ref);

COMMENT ON TABLE rounds.patients IS
    'Encounter participation in a run. One row per encounter per run (unit and service-line runs project the same canonical patient-round record). priority_reasons is the explainable-score component list.';

-- ---------------------------------------------------------------------------
-- Human/service participants and role requirements.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.participants (
    participant_id     bigserial PRIMARY KEY,
    participant_uuid   uuid NOT NULL UNIQUE,
    run_id             bigint NOT NULL REFERENCES rounds.runs(run_id) ON DELETE CASCADE,
    round_patient_id   bigint REFERENCES rounds.patients(round_patient_id) ON DELETE CASCADE,
    user_id            bigint,
    external_actor_ref text,
    role_code          text NOT NULL,
    required           boolean NOT NULL DEFAULT false,
    status             text NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending', 'invited', 'accepted', 'declined', 'contributed', 'waived')),
    invited_at         timestamptz,
    responded_at       timestamptz,
    joined_at          timestamptz,
    waived_by          bigint,
    waiver_reason      text,
    metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_rounds_participants_run
    ON rounds.participants(run_id, role_code, status);
CREATE INDEX IF NOT EXISTS idx_rounds_participants_patient
    ON rounds.participants(round_patient_id) WHERE round_patient_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_rounds_participants_user
    ON rounds.participants(user_id) WHERE user_id IS NOT NULL;

COMMENT ON TABLE rounds.participants IS
    'Role slots for a run (round_patient_id NULL) or one patient. required + status drive completion-policy evaluation; waivers record who and why.';

-- ---------------------------------------------------------------------------
-- Versioned role-specific clinical/operational input. Immutable after
-- submission — corrections create a superseding row.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.contributions (
    contribution_id   bigserial PRIMARY KEY,
    contribution_uuid uuid NOT NULL UNIQUE,
    round_patient_id  bigint NOT NULL REFERENCES rounds.patients(round_patient_id) ON DELETE CASCADE,
    author_user_id    bigint NOT NULL,
    author_role       text NOT NULL,
    section_code      text NOT NULL,
    status            text NOT NULL DEFAULT 'draft'
                      CHECK (status IN ('draft', 'submitted', 'superseded', 'withdrawn')),
    structured_data   jsonb NOT NULL DEFAULT '{}'::jsonb,
    summary           text,
    source_refs       jsonb NOT NULL DEFAULT '[]'::jsonb,
    authored_at       timestamptz,
    submitted_at      timestamptz,
    supersedes_id     bigint REFERENCES rounds.contributions(contribution_id),
    version           integer NOT NULL DEFAULT 1,
    metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

-- One active submitted contribution per author/role/section per patient;
-- superseding moves the prior row to status='superseded' first.
CREATE UNIQUE INDEX IF NOT EXISTS uq_rounds_contributions_active
    ON rounds.contributions(round_patient_id, author_user_id, author_role, section_code)
    WHERE status = 'submitted';
CREATE UNIQUE INDEX IF NOT EXISTS uq_rounds_contributions_one_draft
    ON rounds.contributions(round_patient_id, author_user_id, author_role, section_code)
    WHERE status = 'draft';
CREATE INDEX IF NOT EXISTS idx_rounds_contributions_patient
    ON rounds.contributions(round_patient_id, section_code, status);

COMMENT ON TABLE rounds.contributions IS
    'Role-attributed round input. Submitted rows are immutable; corrections insert a superseding row and flip the prior to superseded. structured_data must match the allowlisted schema for its section_code (config/rounds.php).';

-- ---------------------------------------------------------------------------
-- Questions for a patient/family or a discipline.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.questions (
    question_id              bigserial PRIMARY KEY,
    question_uuid            uuid NOT NULL UNIQUE,
    round_patient_id         bigint NOT NULL REFERENCES rounds.patients(round_patient_id) ON DELETE CASCADE,
    raised_by                bigint,
    raised_role              text,
    target_role              text,
    target_user_id           bigint,
    question_text            text NOT NULL,
    status                   text NOT NULL DEFAULT 'open'
                             CHECK (status IN ('open', 'answered', 'dismissed', 'expired')),
    response_contribution_id bigint REFERENCES rounds.contributions(contribution_id),
    due_at                   timestamptz,
    answered_at              timestamptz,
    provenance               jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_rounds_questions_patient
    ON rounds.questions(round_patient_id, status);

-- ---------------------------------------------------------------------------
-- Round-local follow-up and the bridge to governed ops actions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.tasks (
    task_id           bigserial PRIMARY KEY,
    task_uuid         uuid NOT NULL UNIQUE,
    run_id            bigint NOT NULL REFERENCES rounds.runs(run_id) ON DELETE CASCADE,
    round_patient_id  bigint REFERENCES rounds.patients(round_patient_id) ON DELETE CASCADE,
    owner_user_id     bigint,
    owner_role        text,
    category          text NOT NULL DEFAULT 'follow_up',
    title             text NOT NULL,
    detail            text,
    due_at            timestamptz,
    status            text NOT NULL DEFAULT 'open'
                      CHECK (status IN ('open', 'in_progress', 'completed', 'cancelled')),
    ops_action_uuid   uuid,
    external_task_ref text,
    created_by        bigint,
    completed_by      bigint,
    completed_at      timestamptz,
    provenance        jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_rounds_tasks_run
    ON rounds.tasks(run_id, status);
CREATE INDEX IF NOT EXISTS idx_rounds_tasks_patient
    ON rounds.tasks(round_patient_id) WHERE round_patient_id IS NOT NULL;

COMMENT ON TABLE rounds.tasks IS
    'Round follow-up items. ops_action_uuid links a task promoted into the governed ops.actions lifecycle; ops owns that state afterwards.';

-- ---------------------------------------------------------------------------
-- Append-only domain audit/event stream. Also the idempotency ledger:
-- commands write their event in the same transaction, and the partial unique
-- on idempotency_key turns a replay into a detectable conflict.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds.events (
    event_id          bigserial PRIMARY KEY,
    event_uuid        uuid NOT NULL UNIQUE,
    aggregate_type    text NOT NULL,
    aggregate_id      bigint NOT NULL,
    aggregate_uuid    uuid,
    aggregate_version integer,
    actor_user_id     bigint,
    actor_type        text NOT NULL DEFAULT 'user'
                      CHECK (actor_type IN ('user', 'system', 'eddy')),
    event_type        text NOT NULL,
    metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
    correlation_key   text,
    idempotency_key   text,
    occurred_at       timestamptz NOT NULL DEFAULT now(),
    created_at        timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_rounds_events_idempotency
    ON rounds.events(idempotency_key)
    WHERE idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_rounds_events_aggregate
    ON rounds.events(aggregate_type, aggregate_id, occurred_at);

COMMENT ON TABLE rounds.events IS
    'Append-only audit stream for the rounds domain. metadata is PHI-safe: opaque refs and reason codes only, never names or clinical text.';
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS rounds.events;
DROP TABLE IF EXISTS rounds.tasks;
DROP TABLE IF EXISTS rounds.questions;
DROP TABLE IF EXISTS rounds.contributions;
DROP TABLE IF EXISTS rounds.participants;
DROP TABLE IF EXISTS rounds.patients;
DROP TABLE IF EXISTS rounds.runs;
DROP TABLE IF EXISTS rounds.templates;
SQL);

        $this->safeDropSchema('rounds');
    }
};
