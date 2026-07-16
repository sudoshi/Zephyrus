<?php

/**
 * Rolling-demo configuration (plan §5 / FEEDBACK Wave 1).
 *
 * `enabled` is the master switch: the rolling-demo refresh refuses to mutate data unless
 * this is true (or the command is run with --force), so a real/live deployment can never be
 * silently overwritten by the synthetic pipeline. Set DEMO_MODE=true on the demo host only.
 */

return [
    'enabled' => (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Explicit local/demo session bootstrap
    |--------------------------------------------------------------------------
    |
    | This switch is intentionally independent from DEMO_MODE. DEMO_MODE may
    | drive synthetic operational data on a protected demonstration host, but
    | it must never make that host anonymously accessible. Production is also
    | denied in SessionAuthMiddleware even if this value is misconfigured.
    |
    */
    'auto_login_enabled' => filter_var(
        env('DEMO_AUTO_LOGIN_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    'auto_login_username' => (string) env('DEMO_AUTO_LOGIN_USERNAME', ''),

    'show_credentials' => filter_var(
        env('DEMO_SHOW_CREDENTIALS', false),
        FILTER_VALIDATE_BOOL,
    ),

    'scenario' => (string) env('DEMO_SCENARIO', 'summit-reference'),

    // Operational window width (±half around the anchor) used by DemoClock.
    'window_hours' => (int) env('DEMO_WINDOW_HOURS', 48),

    // Facility_key whose DistributionProfile / HospitalManifest the demo scenario uses.
    'facility_key' => (string) env('DEMO_FACILITY_KEY', 'SUMMIT_REGIONAL'),
];
