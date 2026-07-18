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

    /*
    |--------------------------------------------------------------------------
    | HEWS — Home Early Warning Score (strategy §6.1)
    |--------------------------------------------------------------------------
    | OPERATIONAL TRIAGE, NOT DIAGNOSIS. Deterministic modified-NEWS2:
    | absolute NEWS2-style banding per vital, plus a baseline-deviation
    | component (vs the enrollment's first-24h calibration), a short-window
    | trend component, and a monitoring-adherence signal. Escalation authority
    | always rests with the clinical team (FDA CDS posture, brief §4.2).
    | Bands are config here (metric-definition style); an ML sidecar is a
    | later upgrade path, never a silent swap.
    */
    'hews' => [
        'lookback_hours' => 12,        // observations older than this don't score
        'trend_window_hours' => 6,     // slope window for the trend component
        'baseline_window_hours' => 24, // enrollment calibration window
        'adherence_window_hours' => 6, // expected-vs-received cadence window
        'bands' => [
            'low' => 0,     // 0–4
            'medium' => 5,  // 5–6
            'high' => 7,    // ≥7 — drives command-grid sort + visit intensity
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default vital thresholds (LOINC-keyed)
    |--------------------------------------------------------------------------
    | Global defaults for breach alerting; per-patient overrides live in
    | rpm_enrollments.monitoring_plan.thresholds[<loinc>]. Personalized
    | thresholds are the alarm-fatigue guardrail (§8): alerts fire on the
    | patient's numbers, not the population's.
    */
    'vital_thresholds' => [
        '8867-4' => ['critical_low' => 40, 'warning_low' => 50, 'warning_high' => 110, 'critical_high' => 130],  // heart rate
        '59408-5' => ['critical_low' => 88, 'warning_low' => 92],                                                // SpO2
        '8480-6' => ['critical_low' => 85, 'warning_low' => 95, 'warning_high' => 180, 'critical_high' => 200],  // systolic BP
        '8462-4' => ['warning_high' => 110, 'critical_high' => 120],                                             // diastolic BP
        '9279-1' => ['critical_low' => 8, 'warning_low' => 10, 'warning_high' => 22, 'critical_high' => 27],     // respiratory rate
        '8310-5' => ['critical_low' => 34.5, 'warning_low' => 35.5, 'warning_high' => 38.5, 'critical_high' => 39.5], // temperature °C
    ],
];
