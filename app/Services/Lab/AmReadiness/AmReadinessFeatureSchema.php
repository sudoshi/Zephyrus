<?php

namespace App\Services\Lab\AmReadiness;

/**
 * The frozen, reviewed operational feature schema for the Laboratory
 * AM-readiness planning forecast (P4-2).
 *
 * Every feature is available at the prediction instant and describes only the
 * operational processing state of a decision-class Laboratory order: how far
 * along the collect→receive→result→verify path it is, its test family, the
 * live same-family decision-class queue depth, analyzer downtime context, the
 * facility-time draw shift and clock rhythm, and the minutes remaining until the
 * configured morning-rounds cutoff. NO result value, verification timestamp,
 * diagnosis, or protected demographic attribute may be added without an explicit
 * governance review — `patient_class` is the single admitted operational patient
 * descriptor (§13 predictive-safety rule).
 *
 * Because the forecast scores an as-yet-UNVERIFIED order against an outcome that
 * has not occurred, none of these features can encode whether it eventually
 * verifies before the cutoff; the leakage guard test asserts this list stays
 * operational-only, and the observation object carries no verification field.
 */
final class AmReadinessFeatureSchema
{
    /**
     * Ordered feature identifiers. The artifact's coefficient vector is keyed by
     * these names, so the order here is stable and additive-only across versions.
     *
     * @var list<string>
     */
    public const FEATURES = [
        'stage_ordered',            // current stage one-hot: ordered, not yet collected
        'stage_collected',         // collected / in-transit, not yet received
        'stage_received',          // received / in analytic processing, not yet resulted
        'stage_resulted',          // resulted / preliminary, not yet verified
        'family_chemistry',        // test-family one-hot: chemistry / metabolic panels
        'family_hematology',       // test-family one-hot: hematology / blood count
        'family_coagulation',      // test-family one-hot: coagulation
        'family_other',            // test-family one-hot: all remaining / unknown families
        'patient_emergency',       // operational patient class — emergency
        'patient_inpatient',       // operational patient class — inpatient
        'shift_am_draw',           // order belongs to the tagged AM draw wave
        'hour_sin',                // cyclical hour-of-day (sin) — staffing/instrument rhythm
        'hour_cos',                // cyclical hour-of-day (cos)
        'is_off_hours',            // outside staffed daytime window (night/weekend proxy)
        'queue_depth_norm',        // live same-family decision-class open queue depth, scaled
        'analyzer_downtime',       // assigned analyzer is in operational downtime / rerouted
        'minutes_to_cutoff_norm',  // remaining minutes until the rounds cutoff, scaled
        'past_cutoff',             // the rounds cutoff has already elapsed for this order
        'processing_pressure',     // off-hours × queue interaction (staffing-proxy stress)
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
        'analyzer_downtime',
        'processing_pressure',
    ];

    /**
     * Feature identifiers that are strictly forbidden — the leakage/ethics guard
     * asserts none of these ever enter FEATURES.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SUBSTRINGS = [
        'verified', 'verify', 'outcome', 'label', 'ready_before', 'met_cutoff',
        'result_value', 'diagnosis', 'icd', 'cpt', 'abnormal', 'critical',
        'age_years', 'sex', 'gender', 'race', 'ethnicity', 'religion',
        'insurance', 'zip', 'language', 'name',
    ];

    /** @return list<string> */
    public static function features(): array
    {
        return self::FEATURES;
    }
}
