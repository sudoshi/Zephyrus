<?php

namespace Tests\Support;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\CarePathways\PathwayVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CarePathwayRawFixture
{
    protected function configureCarePathwayFixture(): void
    {
        config([
            'care-pathways.source_release' => [
                'raw_release_id' => 1,
                'dataset_key' => 'care-pathway-test-release',
                'source_csv_sha256' => str_repeat('a', 64),
                'verification_workbook_sha256' => str_repeat('b', 64),
                'declared_baseline_sha256' => str_repeat('c', 64),
                'grouper_version' => '43.1-test',
                'grouper_effective_start' => '2026-04-01',
                'grouper_effective_end' => '2026-09-30',
                'semantic_version' => '43.1-test-source.1',
            ],
            'care-pathways.expected_controls' => [
                'pathways' => 2,
                'pathway_drg_associations' => 3,
                'unique_drg_codes' => 2,
                'overlapping_drg_codes' => 1,
                'claims' => 2,
                'sources' => 2,
                'changes' => 1,
                'evidence_verified' => 1,
                'evidence_limitations' => 1,
                'signoff_queue' => 1,
                'specialist_review' => 1,
                'redesign' => 0,
                'clinical_signoff' => 0,
                'volume_total' => 300,
                'coverage_percent' => 99.0,
                'residual_unclassified_absence' => 0,
                'source_enrichment_resolution_records' => 2,
                'source_enrichment_field_facts' => 6,
                'completeness_audit_rows' => 2,
            ],
            'care-pathways.raw_tables' => [
                'manifest' => 'raw.cp_test_manifest',
                'pathways' => 'raw.cp_test_pathways',
                'ledger' => 'raw.cp_test_ledger',
                'claims' => 'raw.cp_test_claims',
                'sources' => 'raw.cp_test_sources',
                'source_index_raw' => 'raw.cp_test_sources',
                'source_enrichments' => 'raw.cp_test_enrichments',
                'changes' => 'raw.cp_test_changes',
                'codebook' => 'raw.cp_test_codebook',
                'completeness' => 'raw.cp_test_completeness',
                'qa' => 'raw.cp_test_qa',
                'methodology' => 'raw.cp_test_methodology',
            ],
            'care-pathways.source_section_fields' => ['admission_criteria'],
        ]);
    }

    protected function seedCarePathwayRawFixture(): void
    {
        DB::unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS raw;

CREATE TABLE raw.cp_test_manifest (
    verification_release_id bigint PRIMARY KEY,
    dataset_key text NOT NULL,
    source_csv_sha256 text NOT NULL,
    verification_workbook_sha256 text NOT NULL,
    source_import_id bigint
);

CREATE TABLE raw.cp_test_pathways (
    rank integer NOT NULL,
    condition text NOT NULL,
    drg_codes text NOT NULL,
    mdc text,
    medical_or_surgical text,
    service_line text,
    approx_annual_discharges bigint NOT NULL,
    cumulative_pct_admissions numeric NOT NULL,
    admission_criteria text,
    verification_status text NOT NULL,
    verification_confidence text,
    source_specificity text,
    unresolved_flags text,
    release_disposition text NOT NULL,
    clinical_signoff_status text NOT NULL
);

CREATE TABLE raw.cp_test_ledger (
    rank integer NOT NULL,
    condition text NOT NULL,
    drg_codes text NOT NULL
);

CREATE TABLE raw.cp_test_claims (
    rank integer NOT NULL,
    field text NOT NULL,
    claim_type text,
    claim_excerpt text NOT NULL,
    evidence_pmids text NOT NULL,
    automated_pass_1 text,
    automated_pass_2 text,
    clinical_adjudication text,
    verification_date date
);

CREATE TABLE raw.cp_test_sources (
    pmid text NOT NULL,
    title text NOT NULL,
    first_author text,
    journal text,
    publication_date text,
    publication_types text,
    retraction_indicator text,
    source_url text NOT NULL,
    verified_date date
);

CREATE TABLE raw.cp_test_changes (
    rank integer NOT NULL,
    condition text NOT NULL,
    field text NOT NULL,
    old_value text,
    new_value text,
    reason text,
    source text,
    date date
);

CREATE TABLE raw.cp_test_enrichments (
    pmid text NOT NULL,
    resolution_class text NOT NULL,
    enriched_title text,
    enriched_first_author text,
    enriched_journal text,
    enriched_publication_date text,
    enriched_publication_types text,
    resolution_note text,
    authoritative_source_url text,
    secondary_source_url text,
    checked_at timestamptz
);

CREATE TABLE raw.cp_test_codebook (
    ms_drg text NOT NULL,
    title text NOT NULL,
    mdc text,
    type_code text,
    type_label text,
    source_url text,
    verified_date date
);

CREATE TABLE raw.cp_test_completeness (
    field_name text NOT NULL,
    source_blank_count integer NOT NULL,
    classification text NOT NULL,
    corrective_action text NOT NULL,
    residual_unknown_count integer NOT NULL,
    evidence text NOT NULL,
    audited_at timestamptz NOT NULL
);

INSERT INTO raw.cp_test_manifest VALUES
    (1, 'care-pathway-test-release', repeat('a', 64), repeat('b', 64), 7);

INSERT INTO raw.cp_test_pathways VALUES
    (
        1, 'Acute Example A', '001, 002', 'MDC 01', 'Medical', 'Neurology', 200, 66.0,
        'Source-only admission criteria A.',
        'Evidence verified — automated independent review complete', 'High', 'High',
        'None from automated evidence review', 'Ready for institutional clinician signoff',
        'Not clinically approved — institutional SME signoff required'
    ),
    (
        2, 'Acute Example B', '002', 'MDC 02', 'Medical', 'Cardiology', 100, 99.0,
        'Source-only admission criteria B.',
        'Verification complete with limitations — clinician signoff required', 'Medium', 'Medium',
        'Specialist review required', 'Ready for specialist review with documented limitations',
        'Not clinically approved — institutional SME signoff required'
    );

INSERT INTO raw.cp_test_ledger VALUES
    (1, 'Acute Example A', '001, 002'),
    (2, 'Acute Example B', '002');

INSERT INTO raw.cp_test_sources VALUES
    ('111', 'Evidence Source One', 'Author One', 'Journal One', '2025', 'Guideline', 'Not retracted', 'https://example.test/111', '2026-07-21'),
    ('222', 'Evidence Source Two', 'Author Two', 'Journal Two', '2024', 'Review', 'Not retracted', 'https://example.test/222', '2026-07-21');

INSERT INTO raw.cp_test_claims VALUES
    (1, 'admission_criteria', 'eligibility', 'Claim A', '111, 222', 'resolved', 'passed', 'Pending institutional SME signoff', '2026-07-21'),
    (2, 'admission_criteria', 'eligibility', 'Claim B', '222', 'resolved', 'limitations', 'Pending institutional SME signoff', '2026-07-21');

INSERT INTO raw.cp_test_changes VALUES
    (2, 'Acute Example B', 'admission_criteria', 'Old', 'New', 'Evidence correction', 'PMID 222', '2026-07-21');

INSERT INTO raw.cp_test_enrichments VALUES
    (
        '111', 'metadata_enriched', 'Evidence Source One', 'Author One', 'Journal One', '2025', 'Guideline',
        'Bibliographic fields were resolved.', 'https://example.test/111', null, '2026-07-21 12:00:00+00'
    ),
    (
        '222', 'no_personal_author_listed', null, null, null, null, null,
        'No personal AuthorList is present; preserve the raw null.', 'https://example.test/222', null, '2026-07-21 12:00:00+00'
    );

INSERT INTO raw.cp_test_codebook VALUES
    ('001', 'DRG One', '01', 'M', 'Medical', 'https://example.test/drg/001', '2026-07-21'),
    ('002', 'DRG Two', '02', 'M', 'Medical', 'https://example.test/drg/002', '2026-07-21');

INSERT INTO raw.cp_test_completeness VALUES
    ('required_pathway_fields', 0, 'complete', 'None required.', 0, 'Every required field is populated.', '2026-07-21 12:00:00+00'),
    ('source_index.first_author', 1, 'one_enriched_fifteen_explicitly_not_listed', 'Resolution recorded.', 0, 'Every blank has explicit semantics.', '2026-07-21 12:00:00+00');
SQL);
    }

    /**
     * The raw catalog fixture intentionally contains only source-grounded
     * catalog content. Patient pathway tests add these governed, patient-safe
     * definitions explicitly so every patient history fixture exercises the
     * same pinned stage and milestone contract.
     */
    protected function seedApprovedPatientPathwayDefinitions(): void
    {
        PathwayVersion::query()->orderBy('source_rank')->each(
            function (PathwayVersion $version, int $index): void {
                PathwayStageDefinition::query()->firstOrCreate(
                    ['pathway_version_id' => $version->getKey(), 'stable_key' => 'arrival_stage'],
                    [
                        'stage_uuid' => (string) Str::uuid(),
                        'display_order' => 1,
                        'approved_label' => 'Arriving and getting settled',
                        'approved_explanation' => 'Your team helps you get settled and reviews the next steps.',
                        'expected_range' => ['display' => 'Today'],
                        'review_state' => 'approved',
                        'content_digest' => hash('sha256', 'arrival-stage-'.$index),
                    ],
                );

                MilestoneDefinition::query()->firstOrCreate(
                    ['pathway_version_id' => $version->getKey(), 'stable_key' => 'first_milestone'],
                    [
                        'milestone_uuid' => (string) Str::uuid(),
                        'title' => 'First steps in your care',
                        'phase' => 'arrival',
                        'sequence' => 1,
                        'predecessor_keys' => [],
                        'expected_range' => ['display' => 'Today'],
                        'review_state' => 'approved',
                    ],
                );
            },
        );
    }
}
