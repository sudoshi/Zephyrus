<?php

/*
|--------------------------------------------------------------------------
| Zephyrus 2.0 — Hospital Operations Cockpit
|--------------------------------------------------------------------------
|
| Serving-layer configuration for the cockpit snapshot (P1). The KPI band
| edges themselves live in ops.metric_definitions (seeded by
| CockpitKpiDefinitionSeeder, editable via the audited PUT endpoint) —
| this file only holds deployment posture and the demo-value catalog for
| the domains whose live fact tables do not exist yet (P7 swaps them).
|
*/

return [

    // D5: mocked domains (Quality / Service Lines / Financial) are VISIBLE by
    // default with a metadata.provenance='demo' badge so the Summit demo wall
    // lights up. A real (non-demo) deployment sets this true to hide any
    // domain whose every tile is demo-provenance until live sources exist.
    // Domains with mixed live/demo tiles ('partial') always stay visible.
    'hide_demo_domains' => filter_var(env('COCKPIT_HIDE_DEMO_DOMAINS', false), FILTER_VALIDATE_BOOL),

    // Retention for the per-snapshot scalars written to ops.metric_values.
    // Aligned to CommandCenterDataService::TREND_DAYS (90) — the history is
    // what retires the synthetic sparklines; keeping more buys nothing yet.
    // The daily PruneCockpitMetricValues job deletes ONLY grain='snapshot'
    // rows older than this; other grains are never touched.
    'metric_values_retention_days' => (int) env('COCKPIT_METRIC_VALUES_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------
    | Demo values for mocked metrics (per plan P1 workstream 5)
    |--------------------------------------------------------------------
    | Every value here surfaces with metadata.provenance='demo' and is
    | tuned so the Summit demo fires multiple simultaneous alerts
    | (NEDOCS 142 severe, staffing pressure, EVS slow) without lighting
    | the whole wall red — Earned-Red discipline applies to demo data too.
    | ed.nedocs is here because its input columns don't land on
    | prod.ed_visits until P7 (Part II.1 #7).
    */
    'demo_values' => [
        'ed.nedocs' => 142.0,          // severe band (141–180) — the marquee crit
        'ed.los_admit' => 302.0,       // minutes; just past the 300 warn edge

        'staffing.overtime' => 5.2,    // % — warn (target ≤4)
        'staffing.agency' => 14.0,     // active agency/contract RNs
        'staffing.callouts' => 9.0,    // warn
        'staffing.sitters' => 7.0,
        'staffing.productivity' => 96.0,

        'flow.discharge_lounge' => 6.0, // occupied of 10

        'quality.sepsis_3hr' => 87.0,  // warn (<90)
        'quality.sepsis_6hr' => 92.0,
        'quality.hand_hygiene' => 91.0, // ok (rationed green — explicitly on target)
        'quality.falls_rate' => 2.7,
        'quality.rapid_response' => 4.0,
        'quality.med_rec' => 96.0,
        'quality.clabsi' => 1.0,
        'quality.cauti' => 0.0,
        'quality.cdiff' => 2.0,        // warn
        'quality.ssi' => 1.0,
        'quality.mrsa' => 0.0,
        'quality.vap' => 0.0,
        'quality.hapi' => 1.0,         // warn — stage 3+ pressure injury MTD

        'service.cmi' => 1.62,
        'service.observation_rate' => 12.4,
        'service.discharges_mtd' => 1284.0,

        'financial.worked_per_uos' => 1.04,  // warn (>1.00)
        'financial.premium_pay' => 182.0,    // $k today
        'financial.productivity' => 97.0,
        'financial.cost_per_case' => 11.9,   // $k vs budget
        'financial.contract_labor' => 96.0,  // $k today
        'financial.overtime' => 5.2,

        'okr.sepsis_3hr' => 87.0,
        'okr.hand_hygiene' => 91.0,
        'okr.worked_per_uos' => 1.04,
        'okr.hcahps' => 74.0,          // warn (<76) — realistic pressure point
    ],
];
