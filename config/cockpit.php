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

    // P2: /dashboard renders the cockpit overview grammar (CommandBar, census
    // strip, alert ticker, 8-domain grid, OKR scorecard). Setting this false —
    // or loading /dashboard?cockpit=0 — falls back to the pre-2.0 four-band
    // layout, kept as the rollback path for one release (plan P2 chief risk).
    'overview_enabled' => filter_var(env('COCKPIT_OVERVIEW_ENABLED', true), FILTER_VALIDATE_BOOL),

    // P4a (D4): the five legacy /dashboard/* overviews are permanent redirects
    // into the cockpit drill layer (?drill={domain}). This flag is the rollback
    // lever only — setting it false re-registers the original overview pages
    // without a code revert. Requires a route-cache rebuild to take effect.
    'overview_redirects_enabled' => filter_var(env('COCKPIT_OVERVIEW_REDIRECTS', true), FILTER_VALIDATE_BOOL),

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

    // P6: flap-damping for the derived alert lifecycle (AlertEngine). An
    // alert candidate must hold for `open_holds` consecutive snapshots to
    // OPEN and be absent for `clear_holds` consecutive snapshots to CLEAR —
    // clears damp harder than opens so a metric hovering on a band edge
    // reads as one continuous alert, not a strobe. min_reconcile_interval
    // (seconds) stops burst rebuilds (scheduled job + serve-path inline
    // refresh) from each counting as a "consecutive snapshot".
    'alerts' => [
        'open_holds' => (int) env('COCKPIT_ALERT_OPEN_HOLDS', 2),
        'clear_holds' => (int) env('COCKPIT_ALERT_CLEAR_HOLDS', 3),
        'min_reconcile_interval' => (int) env('COCKPIT_ALERT_RECONCILE_INTERVAL', 30),
        // TTL re-raise (2026-07 HFE audit): an alert OPEN longer than this is
        // closed to history and re-derived fresh, so the ticker never carries a
        // weeks-old "active" clock. 0 disables.
        'ttl_hours' => (int) env('COCKPIT_ALERT_TTL_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------
    | Demo values for the last remaining mocked metrics
    |--------------------------------------------------------------------
    | As of P7 every operational domain is LIVE. Only two seeded fallbacks
    | remain: ed.los_admit (used when no admitted ED patient has departed
    | in the median window) and okr.hcahps (HCAHPS is an external survey
    | with no operational source). Each still surfaces provenance='demo'.
    */
    'demo_values' => [
        'ed.los_admit' => 302.0,       // minutes; median-admit-LOS fallback only
        'okr.hcahps' => 74.0,          // warn (<76) — external survey, no live feed
        // OKR fallbacks: the scorecard is a fixed 9-card registry, so these
        // three reuse their now-live quality/financial source when present and
        // fall back to these seeds when it is absent (keeps the card, never a
        // hole). Live data always wins.
        'okr.sepsis_3hr' => 87.0,
        'okr.hand_hygiene' => 91.0,
        'okr.worked_per_uos' => 1.04,
    ],
];
