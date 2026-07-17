<?php

/**
 * Home Hospital (HOME) module configuration.
 *
 * Strategy: docs/home-hospital/Zephyrus_Hospital_at_Home_Strategy_and_Design.md
 * Build brief: docs/home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md (ACUM-PRD-HAH-001)
 *
 * Feature flags gate routes, nav, seeds, and demo refresh. Disabling a flag
 * stops new work without deleting audit records — the middleware answers 404
 * so the module is invisible when off (same doctrine as Virtual Rounds).
 */

return [
    'enabled' => filter_var(env('HOME_HOSPITAL_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Sub-flags for later phases; all inert until their phase ships.
    'rpm_ingest_enabled' => filter_var(env('HOME_HOSPITAL_RPM_INGEST_ENABLED', false), FILTER_VALIDATE_BOOL),
    'eddy_enabled' => filter_var(env('HOME_HOSPITAL_EDDY_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Virtual ward identity
    |--------------------------------------------------------------------------
    | The virtual ward is one more prod.units row (type = virtual_home) whose
    | beds are program slots — census, occupancy, huddles, and the cockpit
    | machinery work unmodified. It is deliberately NOT in the HospitalManifest
    | roster: manifest-derived denominators (licensed beds, staffed-bed totals,
    | occupancy tuning) must not absorb virtual slots. RtdcSeeder exempts
    | virtual_home units from its manifest soft-trim.
    */
    'unit_abbreviation' => env('HOME_HOSPITAL_UNIT_ABBR', 'HOME'),
    'unit_name' => env('HOME_HOSPITAL_UNIT_NAME', 'Summit Home Hospital — Virtual Ward'),
    'slot_count' => (int) env('HOME_HOSPITAL_SLOT_COUNT', 12),

    /*
    |--------------------------------------------------------------------------
    | Initial condition set (§13 Q1 recommended default)
    |--------------------------------------------------------------------------
    | Evidence-anchored starting conditions (Levine 2024 cohort). Surfaced to
    | product/clinical leadership as an open question; adjust per deployment.
    */
    'conditions' => [
        'heart_failure' => 'Heart Failure',
        'copd' => 'COPD Exacerbation',
        'pneumonia' => 'Pneumonia / Respiratory Infection',
        'cellulitis' => 'Cellulitis / Skin & Soft Tissue Infection',
        'uti' => 'Kidney / Urinary Tract Infection',
    ],

    /*
    |--------------------------------------------------------------------------
    | Waiver operating floor (CMS AHCAH / MedPAC 2024)
    |--------------------------------------------------------------------------
    | Compliance telemetry constants, not soft goals: ≥2 in-person clinician
    | visits per day + daily MD evaluation; emergency in-person response
    | within 30 minutes.
    */
    'waiver' => [
        'required_visits_per_day' => 2,
        'emergency_response_minutes' => 30,
    ],
];
