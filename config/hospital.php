<?php

/**
 * Hospital manifest resolver (Phase 5 — Per-Facility Manifest Generation).
 *
 * Maps a business `facility_key` (hosp_org.facilities.facility_key) to the config
 * file that holds its manifest, so App\Support\Hospital\HospitalManifest can load any
 * deployed facility instead of hardcoding Summit. Summit Regional stays the reference
 * deployment and the default; new facilities are generated with
 * `hospital:generate-manifest {facilityKey} --write=config/hospital/<key>.php` and then
 * registered here.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */

return [
    // The facility loaded when no key is given — every existing HospitalManifest consumer
    // resolves to this, so behaviour is unchanged.
    'default_facility' => env('HOSPITAL_DEFAULT_FACILITY', 'SUMMIT_REGIONAL'),

    // facility_key => path (relative to base_path()) of the manifest file it require()s.
    'manifests' => [
        'SUMMIT_REGIONAL' => 'config/hospital/hospital-1.php',
    ],

    // facility_key => path (relative to base_path()) of the VERIFIED distribution profile
    // (synthetic MIMIC-IV / atlantic_health provenance). Read by App\Services\Demo\DistributionProfile.
    // Before this key existed the JSON was documentation only — cited in a code comment but never
    // loaded at runtime, so the operational seeders used hand-tuned constants instead of these
    // source-derived shapes. See docs/plans/DEMO-DATA-COHERENCE-FEEDBACK-AND-PLAN-2026-07-10.md §3.5.
    'distributions' => [
        'SUMMIT_REGIONAL' => 'config/hospital/hospital-1-distributions.json',
    ],

    // Which prod.units.type values roll up into the licensed INPATIENT house denominator.
    // ED treatment spaces and periop procedure rooms are capacity in their own domain but are
    // NEVER part of house occupancy (plan §8.1 capacity vocabulary; FEEDBACK §3.4a/§5).
    'inpatient_unit_types' => ['icu', 'step_down', 'med_surg'],

    // Clinical-benchmark plausibility bands used by App\Services\Demo\DemoInvariantService (and,
    // later, the regeneration sampler). These are NOT source-derived — they are realistic operating
    // ranges for a busy academic medical center, kept here so they are tuneable per demo scenario.
    // Rationale/citations live beside each band in FEEDBACK §4.
    'plausibility_targets' => [
        // Inpatient occupancy by unit type — ICUs run hotter than med/surg; a flat number across
        // every unit type reads as a global target, not a real house. (min, max) as fractions.
        'occupancy_by_unit_type' => [
            'icu' => [0.80, 0.96],
            'step_down' => [0.76, 0.93],
            'med_surg' => [0.72, 0.90],
        ],
        // ED arrival acuity — a real ED is an ESI-3-dominant pyramid. Shares as (min, max) per level;
        // ESI-3 must also be the modal (largest) class.
        'esi_share' => [
            1 => [0.005, 0.05],
            2 => [0.12, 0.30],
            3 => [0.35, 0.52],
            4 => [0.14, 0.30],
            5 => [0.02, 0.12],
        ],
        // Discharge-before-noon is a metric hospitals fight to hit; >45% is fantasy. (min, max).
        'discharge_before_noon' => [0.18, 0.42],
        // Transport priority mix — routine dominates, stat is a thin slice.
        // Each ceiling sits a realistic margin ABOVE its seeded design target so
        // the demo's own intended mix never flags itself: routine target ~60%
        // (ceiling 85), stat target ~10% (ceiling 15), urgent target ~30%
        // (ceiling 35 — the seeded 3-per-10 urgent pattern lands at ~30%, so a
        // 0.30 ceiling left zero headroom and history accumulation tipped it over).
        'transport_priority_share' => [
            'routine' => [0.55, 0.85],
            'urgent' => [0.10, 0.35],
            'stat' => [0.02, 0.15],
        ],
        // Share of the ACTIVE transport queue allowed to be past its needed_by (SLA breach).
        'transport_overdue_share_max' => 0.20,
        // OR weekday volume sanity, expressed per physical OR room (cases per room per weekday).
        'or_cases_per_room_weekday' => [3.0, 12.0],
    ],
];
