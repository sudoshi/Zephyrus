<?php

namespace App\Services\Radiology\BreachRisk;

/**
 * The frozen, reviewed operational feature schema for the Radiology breach-risk
 * planning aid (P4-1).
 *
 * Every feature listed here is available at prediction time and describes only
 * operational load and configuration state. NO clinical feature, diagnosis,
 * result value, or protected demographic attribute may be added here without an
 * explicit governance review — `patient_class` is the single admitted
 * operational patient descriptor (§13 predictive-safety rule). Because the
 * worklist scores an OPEN order against an as-yet-undetermined outcome, none of
 * these features can encode whether the order eventually breaches; the leakage
 * guard test asserts this list stays operational-only.
 */
final class BreachRiskFeatureSchema
{
    /**
     * Ordered feature identifiers. The artifact's coefficient vector is keyed by
     * these names, so the order here is stable and additive-only across versions.
     *
     * @var list<string>
     */
    public const FEATURES = [
        'hour_sin',              // cyclical hour-of-day (sin) — arrival/staffing rhythm
        'hour_cos',              // cyclical hour-of-day (cos)
        'is_weekend',            // day-of-week weekend indicator
        'is_off_hours',          // outside staffed daytime window (night/weekend proxy)
        'modality_ct',           // modality one-hot (CT)
        'modality_mri',          // modality one-hot (MRI)
        'modality_us',           // modality one-hot (US)
        'modality_other',        // modality one-hot (all remaining / unknown)
        'priority_stat',         // ordering priority one-hot (stat)
        'priority_urgent',       // ordering priority one-hot (urgent)
        'patient_emergency',     // operational patient class — emergency
        'patient_inpatient',     // operational patient class — inpatient
        'queue_depth_norm',      // open same-modality queue depth, scaled
        'stage_age_norm',        // current-stage age in minutes, scaled
        'scanner_down',          // any operational downtime on the assigned/modality scanner
        'staffing_pressure',     // off-hours × queue interaction (staffing-proxy stress)
    ];

    /**
     * Fields whose freshness/availability gates a confident score. When any of
     * these is missing or stale at prediction time the score degrades to
     * unavailable/low-confidence rather than fabricating a value.
     *
     * @var list<string>
     */
    public const FRESHNESS_GATED = [
        'queue_depth_norm',
        'scanner_down',
        'staffing_pressure',
    ];

    /**
     * Feature identifiers that are strictly forbidden — the leakage/ethics guard
     * asserts none of these ever enter FEATURES.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SUBSTRINGS = [
        'breach', 'outcome', 'label', 'final', 'completed', 'cleared', 'elapsed_at',
        'diagnosis', 'icd', 'cpt', 'result', 'age_years', 'sex', 'gender', 'race',
        'ethnicity', 'religion', 'insurance', 'zip', 'language', 'name',
    ];

    /** @return list<string> */
    public static function features(): array
    {
        return self::FEATURES;
    }
}
