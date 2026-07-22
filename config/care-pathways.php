<?php

/**
 * Care-pathway catalog controls.
 *
 * Every serving feature remains disabled. Governance reads are separately
 * gated and expose the checksum-pinned inactive catalog only to authorized
 * staff; adoption is not clinical approval or activation.
 */

return [
    'governance_enabled' => filter_var(env('CARE_PATHWAYS_GOVERNANCE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'catalog_enabled' => filter_var(env('CARE_PATHWAYS_CATALOG_ENABLED', false), FILTER_VALIDATE_BOOL),
    'assignment_enabled' => filter_var(env('CARE_PATHWAYS_ASSIGNMENT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'rounds_enabled' => filter_var(env('CARE_PATHWAYS_ROUNDS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'staff_mobile_enabled' => filter_var(env('CARE_PATHWAYS_STAFF_MOBILE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'patient_enabled' => filter_var(env('CARE_PATHWAYS_PATIENT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'eddy_reference_enabled' => filter_var(env('CARE_PATHWAYS_EDDY_REFERENCE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'eddy_instance_enabled' => filter_var(env('CARE_PATHWAYS_EDDY_INSTANCE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'writeback_enabled' => filter_var(env('CARE_PATHWAYS_WRITEBACK_ENABLED', false), FILTER_VALIDATE_BOOL),

    'source_release' => [
        'raw_release_id' => 1,
        'dataset_key' => 'drg-care-pathways-verification-package-v43.1-20260721',
        'source_csv_sha256' => '2e3ac28238cdb8d7e1002117de6ad824d71882dae54df77fe4abd214b268a6ae',
        'verification_workbook_sha256' => '42cadf84dce297c5a839784148ebd2c5375320350394c0d143411008ed5bd171',
        'declared_baseline_sha256' => '6819c1e111985da1fc62f38cdd85dd2a34b69308f4cf9be9f8941fbce62bf8fd',
        'grouper_version' => '43.1',
        'grouper_effective_start' => '2026-04-01',
        'grouper_effective_end' => '2026-09-30',
        'semantic_version' => '43.1-source.1',
    ],

    'expected_controls' => [
        'pathways' => 250,
        'pathway_drg_associations' => 802,
        'unique_drg_codes' => 770,
        'overlapping_drg_codes' => 32,
        'claims' => 10123,
        'sources' => 811,
        'changes' => 324,
        'evidence_verified' => 96,
        'evidence_limitations' => 154,
        'signoff_queue' => 96,
        'specialist_review' => 148,
        'redesign' => 6,
        'clinical_signoff' => 0,
        'volume_total' => 32967000,
        'coverage_percent' => 99.0,
        'residual_unclassified_absence' => 0,
        'source_enrichment_resolution_records' => 16,
        'source_enrichment_field_facts' => 20,
        'completeness_audit_rows' => 7,
    ],

    'status_labels' => [
        'evidence_verified' => 'Evidence verified — automated independent review complete',
        'evidence_limitations' => 'Verification complete with limitations — clinician signoff required',
        'signoff_queue' => 'Ready for institutional clinician signoff',
        'specialist_review' => 'Ready for specialist review with documented limitations',
        'redesign' => 'Needs pathway redesign or explicit non-protocol status',
        'not_clinically_approved' => 'Not clinically approved — institutional SME signoff required',
    ],

    'raw_tables' => [
        'manifest' => 'raw.drg_care_pathway_verification_imports',
        'pathways' => 'raw.drg_cp_verified_pathways_v43_1_20260721',
        'ledger' => 'raw.drg_cp_verification_ledger_v43_1_20260721',
        'claims' => 'raw.drg_cp_claim_audit_v43_1_20260721',
        'sources' => 'raw.drg_cp_source_index_complete_v43_1_20260721',
        'source_index_raw' => 'raw.drg_cp_source_index_v43_1_20260721',
        'source_enrichments' => 'raw.drg_cp_source_enrichment_v43_1_20260721',
        'changes' => 'raw.drg_cp_change_log_v43_1_20260721',
        'codebook' => 'raw.drg_cp_ms_drg_codebook_v43_1_20260721',
        'completeness' => 'raw.drg_cp_completeness_audit_v43_1_20260721',
        'qa' => 'raw.drg_cp_qa_summary_v43_1_20260721',
        'methodology' => 'raw.drg_cp_methodology_v43_1_20260721',
    ],

    // Source prose is retained as typed, staff-reference-only sections. It is
    // never promoted to approved, executable, patient, or Eddy content here.
    'source_section_fields' => [
        'admission_criteria',
        'risk_stratification',
        'initial_workup_labs',
        'initial_imaging_dx',
        'time_critical_interventions',
        'initial_management',
        'day1_milestones',
        'day2_milestones',
        'day3plus_milestones',
        'consults_multidisciplinary',
        'monitoring_level',
        'nutrition_mobility_vte',
        'discharge_criteria',
        'discharge_planning',
        'expected_los',
        'target_los',
        'quality_metrics',
        'common_complications',
        'readmission_drivers',
        'guideline_source',
        'key_citations',
        'evidence_grade',
        'severity_cc_mcc_notes',
        'pathway_pearls',
        'verification_notes',
        'scope_and_volume_notes',
        'clinical_verification_basis',
        'data_quality_notes',
    ],
];
