<?php

/**
 * Canonical care-pathway catalog and governance foundation.
 *
 * The raw CSV/workbook release remains immutable evidence. This schema adopts
 * a reconciled release into searchable, explicitly inactive source versions;
 * it does not make source prose clinically executable or patient-visible.
 *
 * Plan: docs/superpowers/plans/2026-07-21-drg-care-pathways-zephyrus-eddy-hummingbird-integration-plan.md
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
CREATE SCHEMA IF NOT EXISTS care_pathways;

CREATE TABLE IF NOT EXISTS care_pathways.catalog_releases (
    catalog_release_id              bigserial PRIMARY KEY,
    catalog_release_uuid            uuid NOT NULL UNIQUE,
    dataset_key                     text NOT NULL UNIQUE,
    raw_verification_release_id     bigint,
    raw_source_import_id            bigint,
    source_csv_sha256               char(64) NOT NULL,
    verification_workbook_sha256    char(64) NOT NULL,
    declared_baseline_sha256        char(64),
    grouper_version                 text NOT NULL,
    grouper_effective_start         date,
    grouper_effective_end           date,
    pathway_count                   integer NOT NULL CHECK (pathway_count > 0),
    pathway_drg_association_count   integer NOT NULL CHECK (pathway_drg_association_count > 0),
    unique_drg_code_count           integer NOT NULL CHECK (unique_drg_code_count > 0),
    claim_count                     integer NOT NULL CHECK (claim_count >= 0),
    source_count                    integer NOT NULL CHECK (source_count >= 0),
    change_count                    integer NOT NULL CHECK (change_count >= 0),
    evidence_verified_count         integer NOT NULL CHECK (evidence_verified_count >= 0),
    evidence_limitations_count      integer NOT NULL CHECK (evidence_limitations_count >= 0),
    signoff_queue_count             integer NOT NULL CHECK (signoff_queue_count >= 0),
    specialist_review_count         integer NOT NULL CHECK (specialist_review_count >= 0),
    redesign_count                  integer NOT NULL CHECK (redesign_count >= 0),
    clinical_signoff_count          integer NOT NULL DEFAULT 0 CHECK (clinical_signoff_count >= 0),
    volume_control_total            bigint NOT NULL CHECK (volume_control_total >= 0),
    coverage_control_percent        numeric(6,3) NOT NULL CHECK (coverage_control_percent BETWEEN 0 AND 100),
    state                           text NOT NULL DEFAULT 'inactive'
                                    CHECK (state IN ('inactive', 'under_review', 'approved', 'active', 'withdrawn', 'rejected')),
    clinical_signoff_complete       boolean NOT NULL DEFAULT false,
    source_controls                 jsonb NOT NULL DEFAULT '{}'::jsonb,
    adopted_by                     text NOT NULL,
    adopted_at                     timestamptz NOT NULL DEFAULT now(),
    activated_by_user_id           bigint,
    activated_at                   timestamptz,
    withdrawn_at                   timestamptz,
    created_at                     timestamptz NOT NULL DEFAULT now(),
    updated_at                     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT catalog_release_source_csv_hash_chk
        CHECK (source_csv_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT catalog_release_workbook_hash_chk
        CHECK (verification_workbook_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT catalog_release_baseline_hash_chk
        CHECK (declared_baseline_sha256 IS NULL OR declared_baseline_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT catalog_release_evidence_partition_chk
        CHECK (evidence_verified_count + evidence_limitations_count = pathway_count),
    CONSTRAINT catalog_release_disposition_partition_chk
        CHECK (signoff_queue_count + specialist_review_count + redesign_count = pathway_count),
    CONSTRAINT catalog_release_signoff_chk
        CHECK (clinical_signoff_count <= pathway_count),
    CONSTRAINT catalog_release_activation_chk CHECK (
        state <> 'active'
        OR (
            clinical_signoff_complete
            AND clinical_signoff_count = pathway_count
            AND activated_at IS NOT NULL
            AND activated_by_user_id IS NOT NULL
        )
    ),
    CONSTRAINT catalog_release_source_controls_json_chk CHECK (jsonb_typeof(source_controls) = 'object')
);

COMMENT ON TABLE care_pathways.catalog_releases IS
    'Reconciled source releases adopted for governance. Adoption is not clinical activation; new releases are inactive by default.';

CREATE TABLE IF NOT EXISTS care_pathways.catalog_release_controls (
    catalog_release_control_id  bigserial PRIMARY KEY,
    control_uuid                uuid NOT NULL UNIQUE,
    catalog_release_id          bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    control_key                 text NOT NULL,
    observed_value              jsonb NOT NULL,
    reference_value             jsonb,
    status                      text NOT NULL
                                CHECK (status IN ('passed', 'accepted_discrepancy', 'failed', 'not_applicable')),
    rationale                   text NOT NULL,
    evidence                    jsonb NOT NULL DEFAULT '{}'::jsonb,
    recorded_at                 timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, control_key),
    CONSTRAINT care_pathway_release_control_observed_json_chk
        CHECK (jsonb_typeof(observed_value) IS NOT NULL),
    CONSTRAINT care_pathway_release_control_evidence_json_chk
        CHECK (jsonb_typeof(evidence) = 'object')
);

CREATE TABLE IF NOT EXISTS care_pathways.definitions (
    pathway_definition_id  bigserial PRIMARY KEY,
    pathway_uuid           uuid NOT NULL UNIQUE,
    pathway_key            text NOT NULL UNIQUE,
    canonical_name         text NOT NULL,
    mdc_label              text,
    care_type              text,
    source_service_line    text,
    service_line_code      text REFERENCES hosp_ref.service_lines(service_line_code) ON DELETE RESTRICT,
    lifecycle_state        text NOT NULL DEFAULT 'candidate'
                           CHECK (lifecycle_state IN ('candidate', 'under_review', 'approved', 'active', 'retired', 'non_protocol')),
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_definitions_name
    ON care_pathways.definitions(canonical_name);
CREATE INDEX IF NOT EXISTS idx_care_pathway_definitions_service_line
    ON care_pathways.definitions(service_line_code, lifecycle_state);

CREATE TABLE IF NOT EXISTS care_pathways.versions (
    pathway_version_id             bigserial PRIMARY KEY,
    pathway_version_uuid           uuid NOT NULL UNIQUE,
    pathway_definition_id          bigint NOT NULL REFERENCES care_pathways.definitions(pathway_definition_id) ON DELETE RESTRICT,
    catalog_release_id             bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    semantic_version               text NOT NULL,
    source_rank                    integer NOT NULL CHECK (source_rank > 0),
    evidence_status                text NOT NULL,
    verification_confidence        text,
    source_specificity             text,
    unresolved_flags               jsonb NOT NULL DEFAULT '[]'::jsonb,
    release_disposition            text NOT NULL,
    clinical_signoff_status        text NOT NULL,
    institutional_approval_status  text NOT NULL DEFAULT 'not_reviewed'
                                   CHECK (institutional_approval_status IN ('not_reviewed', 'in_review', 'approved', 'rejected', 'withdrawn')),
    activation_status              text NOT NULL DEFAULT 'inactive'
                                   CHECK (activation_status IN ('inactive', 'active', 'withdrawn')),
    source_digest                  char(64) NOT NULL,
    content_digest                 char(64) NOT NULL,
    effective_start                date,
    effective_end                  date,
    supersedes_version_id          bigint REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    raw_snapshot                   jsonb NOT NULL,
    created_at                     timestamptz NOT NULL DEFAULT now(),
    updated_at                     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT care_pathway_version_source_digest_chk CHECK (source_digest ~ '^[0-9a-f]{64}$'),
    CONSTRAINT care_pathway_version_content_digest_chk CHECK (content_digest ~ '^[0-9a-f]{64}$'),
    CONSTRAINT care_pathway_version_unresolved_flags_json_chk CHECK (jsonb_typeof(unresolved_flags) = 'array'),
    CONSTRAINT care_pathway_version_raw_snapshot_json_chk CHECK (jsonb_typeof(raw_snapshot) = 'object'),
    CONSTRAINT care_pathway_version_activation_chk CHECK (
        activation_status <> 'active'
        OR institutional_approval_status = 'approved'
    ),
    CONSTRAINT care_pathway_version_effective_period_chk CHECK (
        effective_end IS NULL OR effective_start IS NULL OR effective_end >= effective_start
    ),
    UNIQUE (pathway_definition_id, catalog_release_id),
    UNIQUE (pathway_definition_id, semantic_version)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_versions_release_rank
    ON care_pathways.versions(catalog_release_id, source_rank);
CREATE INDEX IF NOT EXISTS idx_care_pathway_versions_states
    ON care_pathways.versions(activation_status, institutional_approval_status, evidence_status);

COMMENT ON TABLE care_pathways.versions IS
    'Immutable source-content versions. Evidence verification, clinical signoff, institutional approval, and activation are intentionally separate dimensions.';

CREATE TABLE IF NOT EXISTS care_pathways.drg_codebook_entries (
    drg_codebook_entry_id  bigserial PRIMARY KEY,
    entry_uuid             uuid NOT NULL UNIQUE,
    catalog_release_id     bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    ms_drg                 char(3) NOT NULL CHECK (ms_drg ~ '^[0-9]{3}$'),
    title                  text NOT NULL,
    mdc                    text,
    type_code              text,
    type_label             text,
    source_url             text,
    verified_date          date,
    entry_digest           char(64) NOT NULL CHECK (entry_digest ~ '^[0-9a-f]{64}$'),
    created_at             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, ms_drg)
);

CREATE TABLE IF NOT EXISTS care_pathways.drg_mappings (
    drg_mapping_id       bigserial PRIMARY KEY,
    pathway_version_id  bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    drg_codebook_entry_id bigint NOT NULL REFERENCES care_pathways.drg_codebook_entries(drg_codebook_entry_id) ON DELETE RESTRICT,
    mapping_role         text NOT NULL DEFAULT 'candidate'
                         CHECK (mapping_role IN ('candidate', 'supporting', 'retrospective_reconciliation', 'excluded')),
    ambiguity_note       text,
    effective_start      date,
    effective_end        date,
    created_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, drg_codebook_entry_id),
    CONSTRAINT care_pathway_drg_effective_period_chk CHECK (
        effective_end IS NULL OR effective_start IS NULL OR effective_end >= effective_start
    )
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_drg_lookup
    ON care_pathways.drg_mappings(drg_codebook_entry_id, pathway_version_id);

COMMENT ON TABLE care_pathways.drg_mappings IS
    'Many-to-many candidate/reconciliation links. A DRG is never sufficient by itself to assign an encounter pathway.';

CREATE TABLE IF NOT EXISTS care_pathways.sections (
    pathway_section_id   bigserial PRIMARY KEY,
    section_uuid         uuid NOT NULL UNIQUE,
    pathway_version_id  bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    section_code         text NOT NULL,
    audience             text NOT NULL DEFAULT 'staff_reference'
                         CHECK (audience IN ('staff_reference', 'staff_workflow', 'patient', 'caregiver')),
    language_code        text NOT NULL DEFAULT 'en',
    source_text          text NOT NULL,
    approved_text        text,
    content_mode         text NOT NULL DEFAULT 'source'
                         CHECK (content_mode IN ('source', 'draft', 'approved', 'released', 'withdrawn')),
    review_state         text NOT NULL DEFAULT 'source_candidate'
                         CHECK (review_state IN ('source_candidate', 'draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    source_digest        char(64) NOT NULL CHECK (source_digest ~ '^[0-9a-f]{64}$'),
    approved_digest      char(64),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, section_code, audience, language_code),
    CONSTRAINT care_pathway_section_approved_digest_chk
        CHECK (approved_digest IS NULL OR approved_digest ~ '^[0-9a-f]{64}$'),
    CONSTRAINT care_pathway_section_release_chk CHECK (
        content_mode NOT IN ('approved', 'released')
        OR (review_state = 'approved' AND approved_text IS NOT NULL AND approved_digest IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_sections_version
    ON care_pathways.sections(pathway_version_id, section_code);

CREATE TABLE IF NOT EXISTS care_pathways.milestone_definitions (
    milestone_definition_id  bigserial PRIMARY KEY,
    milestone_uuid           uuid NOT NULL UNIQUE,
    pathway_version_id       bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    stable_key               text NOT NULL,
    title                    text NOT NULL,
    phase                    text,
    sequence                 integer,
    predecessor_keys         jsonb NOT NULL DEFAULT '[]'::jsonb,
    expected_range           jsonb NOT NULL DEFAULT '{}'::jsonb,
    applicability_ref        text,
    completion_evidence_ref  text,
    review_state             text NOT NULL DEFAULT 'draft'
                             CHECK (review_state IN ('draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, stable_key)
    ,CONSTRAINT care_pathway_milestone_predecessors_json_chk CHECK (jsonb_typeof(predecessor_keys) = 'array')
    ,CONSTRAINT care_pathway_milestone_expected_range_json_chk CHECK (jsonb_typeof(expected_range) = 'object')
);

CREATE TABLE IF NOT EXISTS care_pathways.activity_definitions (
    activity_definition_id  bigserial PRIMARY KEY,
    activity_uuid           uuid NOT NULL UNIQUE,
    pathway_version_id      bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    stable_key              text NOT NULL,
    activity_type           text NOT NULL,
    title                   text NOT NULL,
    performer_role          text,
    timing                  jsonb NOT NULL DEFAULT '{}'::jsonb,
    preconditions           jsonb NOT NULL DEFAULT '[]'::jsonb,
    executable              boolean NOT NULL DEFAULT false,
    fhir_canonical_ref      text,
    review_state            text NOT NULL DEFAULT 'draft'
                            CHECK (review_state IN ('draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, stable_key),
    CONSTRAINT care_pathway_activity_timing_json_chk CHECK (jsonb_typeof(timing) = 'object'),
    CONSTRAINT care_pathway_activity_preconditions_json_chk CHECK (jsonb_typeof(preconditions) = 'array'),
    CONSTRAINT care_pathway_activity_executable_chk CHECK (
        NOT executable OR review_state = 'approved'
    )
);

CREATE TABLE IF NOT EXISTS care_pathways.goal_definitions (
    goal_definition_id         bigserial PRIMARY KEY,
    goal_uuid                  uuid NOT NULL UNIQUE,
    pathway_version_id         bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    stable_key                 text NOT NULL,
    goal_code                  text,
    goal_text                  text NOT NULL,
    author_type                text NOT NULL DEFAULT 'care_team',
    target                     jsonb NOT NULL DEFAULT '{}'::jsonb,
    patient_visible_explanation text,
    review_state               text NOT NULL DEFAULT 'draft'
                               CHECK (review_state IN ('draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    created_at                 timestamptz NOT NULL DEFAULT now(),
    updated_at                 timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, stable_key)
    ,CONSTRAINT care_pathway_goal_target_json_chk CHECK (jsonb_typeof(target) = 'object')
);

CREATE TABLE IF NOT EXISTS care_pathways.education_definitions (
    education_definition_id  bigserial PRIMARY KEY,
    education_uuid           uuid NOT NULL UNIQUE,
    pathway_version_id       bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    stable_key               text NOT NULL,
    audience                 text NOT NULL CHECK (audience IN ('patient', 'caregiver', 'patient_and_caregiver')),
    language_code            text NOT NULL DEFAULT 'en',
    reading_level            text,
    title                    text NOT NULL,
    approved_content         text,
    teach_back_prompt        text,
    required_reviewer_role   text,
    content_digest           char(64),
    review_state             text NOT NULL DEFAULT 'draft'
                             CHECK (review_state IN ('draft', 'in_review', 'approved', 'rejected', 'withdrawn')),
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_version_id, stable_key, audience, language_code),
    CONSTRAINT care_pathway_education_digest_chk
        CHECK (content_digest IS NULL OR content_digest ~ '^[0-9a-f]{64}$'),
    CONSTRAINT care_pathway_education_approval_chk CHECK (
        review_state <> 'approved'
        OR (approved_content IS NOT NULL AND teach_back_prompt IS NOT NULL AND content_digest IS NOT NULL)
    )
);

CREATE TABLE IF NOT EXISTS care_pathways.sources (
    source_id              bigserial PRIMARY KEY,
    source_uuid            uuid NOT NULL UNIQUE,
    pmid                   text,
    doi                    text,
    source_url             text,
    title                  text,
    first_author           text,
    organization           text,
    journal                text,
    publication_date       text,
    publication_types      jsonb NOT NULL DEFAULT '[]'::jsonb,
    source_type            text NOT NULL DEFAULT 'bibliographic',
    retraction_indicator   text,
    supersession_state     text NOT NULL DEFAULT 'current'
                           CHECK (supersession_state IN ('current', 'superseded', 'retracted', 'unknown')),
    verified_date          date,
    content_digest         char(64) NOT NULL CHECK (content_digest ~ '^[0-9a-f]{64}$'),
    provenance             jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
    ,CONSTRAINT care_pathway_source_publication_types_json_chk CHECK (jsonb_typeof(publication_types) = 'array')
    ,CONSTRAINT care_pathway_source_provenance_json_chk CHECK (jsonb_typeof(provenance) = 'object')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_sources_pmid_digest
    ON care_pathways.sources(pmid, content_digest) WHERE pmid IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_sources_doi
    ON care_pathways.sources(doi) WHERE doi IS NOT NULL;

CREATE TABLE IF NOT EXISTS care_pathways.evidence_claims (
    evidence_claim_id       bigserial PRIMARY KEY,
    claim_uuid              uuid NOT NULL UNIQUE,
    catalog_release_id      bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    pathway_version_id      bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    pathway_section_id      bigint REFERENCES care_pathways.sections(pathway_section_id) ON DELETE RESTRICT,
    source_rank             integer NOT NULL CHECK (source_rank > 0),
    source_field            text NOT NULL,
    claim_type              text,
    claim_excerpt           text NOT NULL,
    automated_pass_1        text,
    automated_pass_2        text,
    clinical_adjudication   text,
    verification_date      date,
    claim_digest            char(64) NOT NULL CHECK (claim_digest ~ '^[0-9a-f]{64}$'),
    created_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, claim_digest)
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_evidence_claims_version
    ON care_pathways.evidence_claims(pathway_version_id, source_field);

CREATE TABLE IF NOT EXISTS care_pathways.claim_sources (
    claim_source_id         bigserial PRIMARY KEY,
    evidence_claim_id       bigint NOT NULL REFERENCES care_pathways.evidence_claims(evidence_claim_id) ON DELETE RESTRICT,
    source_id               bigint REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    evidence_grade          text,
    applicability_note      text,
    provenance              jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (evidence_claim_id, source_id)
    ,CONSTRAINT care_pathway_claim_source_provenance_json_chk CHECK (jsonb_typeof(provenance) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_claim_sources_source
    ON care_pathways.claim_sources(source_id, evidence_claim_id);

CREATE TABLE IF NOT EXISTS care_pathways.section_sources (
    section_source_id      bigserial PRIMARY KEY,
    pathway_section_id     bigint NOT NULL REFERENCES care_pathways.sections(pathway_section_id) ON DELETE RESTRICT,
    source_id              bigint NOT NULL REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    claim_summary          text,
    evidence_grade         text,
    applicability_note     text,
    provenance             jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (pathway_section_id, source_id),
    CONSTRAINT care_pathway_section_source_provenance_json_chk CHECK (jsonb_typeof(provenance) = 'object')
);

CREATE TABLE IF NOT EXISTS care_pathways.source_changes (
    source_change_id       bigserial PRIMARY KEY,
    change_uuid            uuid NOT NULL UNIQUE,
    catalog_release_id     bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    source_rank            integer NOT NULL CHECK (source_rank > 0),
    condition              text NOT NULL,
    source_field           text NOT NULL,
    old_value              text,
    new_value              text,
    reason                 text,
    source_reference       text,
    changed_on             date,
    change_digest          char(64) NOT NULL CHECK (change_digest ~ '^[0-9a-f]{64}$'),
    created_at             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, change_digest)
);

CREATE TABLE IF NOT EXISTS care_pathways.completeness_resolutions (
    completeness_resolution_id bigserial PRIMARY KEY,
    resolution_uuid             uuid NOT NULL UNIQUE,
    catalog_release_id          bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    source_id                   bigint REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    source_rank                 integer,
    source_field                text NOT NULL,
    resolution_type             text NOT NULL
                                CHECK (resolution_type IN ('complete', 'enriched', 'not_listed', 'not_applicable', 'optional_not_recorded', 'source_blank_preserved', 'unresolved')),
    source_blank_count          integer CHECK (source_blank_count IS NULL OR source_blank_count >= 0),
    residual_unknown_count      integer CHECK (residual_unknown_count IS NULL OR residual_unknown_count >= 0),
    source_classification       text,
    resolved_value              text,
    rationale                   text NOT NULL,
    corrective_action           text,
    evidence                    jsonb NOT NULL DEFAULT '{}'::jsonb,
    raw_record                  jsonb NOT NULL DEFAULT '{}'::jsonb,
    resolution_digest           char(64),
    audited_at                  timestamptz,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, source_id, source_rank, source_field)
    ,CONSTRAINT care_pathway_completeness_evidence_json_chk CHECK (jsonb_typeof(evidence) = 'object')
    ,CONSTRAINT care_pathway_completeness_raw_record_json_chk CHECK (jsonb_typeof(raw_record) = 'object')
    ,CONSTRAINT care_pathway_completeness_digest_chk CHECK (resolution_digest IS NULL OR resolution_digest ~ '^[0-9a-f]{64}$')
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_completeness_digest
    ON care_pathways.completeness_resolutions(catalog_release_id, resolution_digest)
    WHERE resolution_digest IS NOT NULL;

CREATE TABLE IF NOT EXISTS care_pathways.source_enrichments (
    source_enrichment_id  bigserial PRIMARY KEY,
    enrichment_uuid       uuid NOT NULL UNIQUE,
    catalog_release_id    bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    source_id             bigint NOT NULL REFERENCES care_pathways.sources(source_id) ON DELETE RESTRICT,
    source_field          text NOT NULL,
    original_value        text,
    enriched_value        text NOT NULL,
    resolution_class      text,
    resolution_note       text,
    enrichment_source     text NOT NULL,
    authoritative_source_url text,
    secondary_source_url  text,
    source_record         jsonb NOT NULL DEFAULT '{}'::jsonb,
    enrichment_digest     char(64) NOT NULL CHECK (enrichment_digest ~ '^[0-9a-f]{64}$'),
    verified_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, source_id, source_field, enrichment_digest),
    CONSTRAINT care_pathway_source_enrichment_record_json_chk CHECK (jsonb_typeof(source_record) = 'object')
);

CREATE TABLE IF NOT EXISTS care_pathways.service_line_mappings (
    service_line_mapping_id  bigserial PRIMARY KEY,
    catalog_release_id       bigint NOT NULL REFERENCES care_pathways.catalog_releases(catalog_release_id) ON DELETE RESTRICT,
    source_service_line      text NOT NULL,
    normalized_candidate     text,
    service_line_code        text REFERENCES hosp_ref.service_lines(service_line_code) ON DELETE RESTRICT,
    mapping_status           text NOT NULL DEFAULT 'pending'
                             CHECK (mapping_status IN ('mapped', 'pending', 'rejected')),
    mapped_by_user_id        bigint,
    mapped_at                timestamptz,
    notes                    text,
    created_at               timestamptz NOT NULL DEFAULT now(),
    updated_at               timestamptz NOT NULL DEFAULT now(),
    UNIQUE (catalog_release_id, source_service_line),
    CONSTRAINT care_pathway_service_line_mapping_chk CHECK (
        (mapping_status = 'mapped' AND service_line_code IS NOT NULL)
        OR (mapping_status <> 'mapped')
    )
);

CREATE TABLE IF NOT EXISTS care_pathways.reviews (
    pathway_review_id        bigserial PRIMARY KEY,
    review_uuid              uuid NOT NULL UNIQUE,
    pathway_version_id       bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    reviewer_role            text NOT NULL,
    reviewer_user_id         bigint,
    reviewer_external_ref    text,
    review_scope             text NOT NULL,
    decision                 text NOT NULL CHECK (decision IN ('approved', 'changes_requested', 'rejected', 'abstained')),
    reason                   text NOT NULL,
    issues                   jsonb NOT NULL DEFAULT '[]'::jsonb,
    reviewed_at              timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_reviews_version
    ON care_pathways.reviews(pathway_version_id, review_scope, reviewed_at DESC);

CREATE TABLE IF NOT EXISTS care_pathways.approvals (
    pathway_approval_id      bigserial PRIMARY KEY,
    approval_uuid            uuid NOT NULL UNIQUE,
    pathway_version_id       bigint NOT NULL REFERENCES care_pathways.versions(pathway_version_id) ON DELETE RESTRICT,
    approval_type            text NOT NULL,
    actor_user_id            bigint,
    actor_external_ref       text,
    decision                 text NOT NULL CHECK (decision IN ('approved', 'rejected', 'withdrawn')),
    conditions               text,
    effective_start          timestamptz,
    effective_end            timestamptz,
    decided_at               timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT care_pathway_approval_actor_chk CHECK (
        num_nonnulls(actor_user_id, actor_external_ref) = 1
    ),
    CONSTRAINT care_pathway_approval_effective_period_chk CHECK (
        effective_end IS NULL OR effective_start IS NULL OR effective_end >= effective_start
    )
);

CREATE INDEX IF NOT EXISTS idx_care_pathway_approvals_version
    ON care_pathways.approvals(pathway_version_id, approval_type, decided_at DESC);

CREATE TABLE IF NOT EXISTS care_pathways.events (
    pathway_event_id       bigserial PRIMARY KEY,
    event_uuid             uuid NOT NULL UNIQUE,
    aggregate_type         text NOT NULL,
    aggregate_id           bigint NOT NULL,
    aggregate_uuid         uuid,
    aggregate_version      integer,
    event_type             text NOT NULL,
    actor_user_id          bigint,
    actor_ref              text,
    correlation_id         uuid,
    idempotency_key        text,
    metadata               jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at            timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_care_pathway_events_idempotency
    ON care_pathways.events(idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_care_pathway_events_aggregate
    ON care_pathways.events(aggregate_type, aggregate_id, occurred_at DESC);

CREATE OR REPLACE FUNCTION care_pathways.reject_append_only_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'care_pathways.% is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION care_pathways.protect_release_source_controls()
RETURNS trigger AS $$
BEGIN
    IF ROW(
        OLD.dataset_key,
        OLD.raw_verification_release_id,
        OLD.raw_source_import_id,
        OLD.source_csv_sha256,
        OLD.verification_workbook_sha256,
        OLD.declared_baseline_sha256,
        OLD.grouper_version,
        OLD.pathway_count,
        OLD.pathway_drg_association_count,
        OLD.unique_drg_code_count,
        OLD.claim_count,
        OLD.source_count,
        OLD.change_count,
        OLD.evidence_verified_count,
        OLD.evidence_limitations_count,
        OLD.signoff_queue_count,
        OLD.specialist_review_count,
        OLD.redesign_count,
        OLD.volume_control_total,
        OLD.coverage_control_percent,
        OLD.source_controls
    ) IS DISTINCT FROM ROW(
        NEW.dataset_key,
        NEW.raw_verification_release_id,
        NEW.raw_source_import_id,
        NEW.source_csv_sha256,
        NEW.verification_workbook_sha256,
        NEW.declared_baseline_sha256,
        NEW.grouper_version,
        NEW.pathway_count,
        NEW.pathway_drg_association_count,
        NEW.unique_drg_code_count,
        NEW.claim_count,
        NEW.source_count,
        NEW.change_count,
        NEW.evidence_verified_count,
        NEW.evidence_limitations_count,
        NEW.signoff_queue_count,
        NEW.specialist_review_count,
        NEW.redesign_count,
        NEW.volume_control_total,
        NEW.coverage_control_percent,
        NEW.source_controls
    ) THEN
        RAISE EXCEPTION 'catalog release source controls are immutable; adopt a new release';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION care_pathways.protect_section_source_content()
RETURNS trigger AS $$
BEGIN
    IF ROW(
        OLD.pathway_version_id,
        OLD.section_code,
        OLD.audience,
        OLD.language_code,
        OLD.source_text,
        OLD.source_digest
    ) IS DISTINCT FROM ROW(
        NEW.pathway_version_id,
        NEW.section_code,
        NEW.audience,
        NEW.language_code,
        NEW.source_text,
        NEW.source_digest
    ) THEN
        RAISE EXCEPTION 'care pathway source section is immutable; create a superseding version';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION care_pathways.protect_version_content()
RETURNS trigger AS $$
BEGIN
    IF ROW(
        OLD.pathway_definition_id,
        OLD.catalog_release_id,
        OLD.semantic_version,
        OLD.source_rank,
        OLD.evidence_status,
        OLD.verification_confidence,
        OLD.source_specificity,
        OLD.unresolved_flags,
        OLD.release_disposition,
        OLD.clinical_signoff_status,
        OLD.source_digest,
        OLD.content_digest,
        OLD.effective_start,
        OLD.effective_end,
        OLD.supersedes_version_id,
        OLD.raw_snapshot
    ) IS DISTINCT FROM ROW(
        NEW.pathway_definition_id,
        NEW.catalog_release_id,
        NEW.semantic_version,
        NEW.source_rank,
        NEW.evidence_status,
        NEW.verification_confidence,
        NEW.source_specificity,
        NEW.unresolved_flags,
        NEW.release_disposition,
        NEW.clinical_signoff_status,
        NEW.source_digest,
        NEW.content_digest,
        NEW.effective_start,
        NEW.effective_end,
        NEW.supersedes_version_id,
        NEW.raw_snapshot
    ) THEN
        RAISE EXCEPTION 'care pathway version content is immutable; create a superseding version';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION care_pathways.enforce_catalog_activation_gate()
RETURNS trigger AS $$
BEGIN
    IF NEW.state = 'active' AND (
        NOT NEW.clinical_signoff_complete
        OR NEW.clinical_signoff_count <> NEW.pathway_count
        OR (
            SELECT count(*)
            FROM care_pathways.versions versions
            WHERE versions.catalog_release_id = NEW.catalog_release_id
        ) <> NEW.pathway_count
        OR EXISTS (
            SELECT 1
            FROM care_pathways.versions versions
            WHERE versions.catalog_release_id = NEW.catalog_release_id
              AND (
                  versions.institutional_approval_status <> 'approved'
                  OR versions.activation_status <> 'active'
              )
        )
    ) THEN
        RAISE EXCEPTION 'catalog release cannot activate before every version is institutionally approved and active';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS care_pathway_release_source_controls_immutable
    ON care_pathways.catalog_releases;
CREATE TRIGGER care_pathway_release_source_controls_immutable
BEFORE UPDATE ON care_pathways.catalog_releases
FOR EACH ROW EXECUTE FUNCTION care_pathways.protect_release_source_controls();

DROP TRIGGER IF EXISTS care_pathway_release_activation_gate
    ON care_pathways.catalog_releases;
CREATE TRIGGER care_pathway_release_activation_gate
BEFORE INSERT OR UPDATE ON care_pathways.catalog_releases
FOR EACH ROW EXECUTE FUNCTION care_pathways.enforce_catalog_activation_gate();

DROP TRIGGER IF EXISTS care_pathway_version_content_immutable
    ON care_pathways.versions;
CREATE TRIGGER care_pathway_version_content_immutable
BEFORE UPDATE ON care_pathways.versions
FOR EACH ROW EXECUTE FUNCTION care_pathways.protect_version_content();

DROP TRIGGER IF EXISTS care_pathway_section_source_content_immutable
    ON care_pathways.sections;
CREATE TRIGGER care_pathway_section_source_content_immutable
BEFORE UPDATE ON care_pathways.sections
FOR EACH ROW EXECUTE FUNCTION care_pathways.protect_section_source_content();

DROP TRIGGER IF EXISTS care_pathway_reviews_append_only ON care_pathways.reviews;
CREATE TRIGGER care_pathway_reviews_append_only
BEFORE UPDATE OR DELETE ON care_pathways.reviews
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_approvals_append_only ON care_pathways.approvals;
CREATE TRIGGER care_pathway_approvals_append_only
BEFORE UPDATE OR DELETE ON care_pathways.approvals
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_events_append_only ON care_pathways.events;
CREATE TRIGGER care_pathway_events_append_only
BEFORE UPDATE OR DELETE ON care_pathways.events
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_release_controls_append_only ON care_pathways.catalog_release_controls;
CREATE TRIGGER care_pathway_release_controls_append_only
BEFORE UPDATE OR DELETE ON care_pathways.catalog_release_controls
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_evidence_claims_append_only ON care_pathways.evidence_claims;
CREATE TRIGGER care_pathway_evidence_claims_append_only
BEFORE UPDATE OR DELETE ON care_pathways.evidence_claims
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_claim_sources_append_only ON care_pathways.claim_sources;
CREATE TRIGGER care_pathway_claim_sources_append_only
BEFORE UPDATE OR DELETE ON care_pathways.claim_sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_codebook_append_only ON care_pathways.drg_codebook_entries;
CREATE TRIGGER care_pathway_codebook_append_only
BEFORE UPDATE OR DELETE ON care_pathways.drg_codebook_entries
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_drg_mappings_append_only ON care_pathways.drg_mappings;
CREATE TRIGGER care_pathway_drg_mappings_append_only
BEFORE UPDATE OR DELETE ON care_pathways.drg_mappings
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_sources_append_only ON care_pathways.sources;
CREATE TRIGGER care_pathway_sources_append_only
BEFORE UPDATE OR DELETE ON care_pathways.sources
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_source_changes_append_only ON care_pathways.source_changes;
CREATE TRIGGER care_pathway_source_changes_append_only
BEFORE UPDATE OR DELETE ON care_pathways.source_changes
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_source_enrichments_append_only ON care_pathways.source_enrichments;
CREATE TRIGGER care_pathway_source_enrichments_append_only
BEFORE UPDATE OR DELETE ON care_pathways.source_enrichments
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

DROP TRIGGER IF EXISTS care_pathway_completeness_append_only ON care_pathways.completeness_resolutions;
CREATE TRIGGER care_pathway_completeness_append_only
BEFORE UPDATE OR DELETE ON care_pathways.completeness_resolutions
FOR EACH ROW EXECUTE FUNCTION care_pathways.reject_append_only_mutation();

COMMENT ON TABLE care_pathways.events IS
    'PHI-safe append-only pathway audit. Patient identifiers and raw clinical prose are forbidden in metadata.';
SQL);
    }

    public function down(): void
    {
        if (! $this->isLocalEnvironment()) {
            return;
        }

        $this->safeDropSchema('care_pathways');
    }
};
