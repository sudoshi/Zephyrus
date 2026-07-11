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

    'scenario' => (string) env('DEMO_SCENARIO', 'summit-reference'),

    // Operational window width (±half around the anchor) used by DemoClock.
    'window_hours' => (int) env('DEMO_WINDOW_HOURS', 48),

    // Facility_key whose DistributionProfile / HospitalManifest the demo scenario uses.
    'facility_key' => (string) env('DEMO_FACILITY_KEY', 'SUMMIT_REGIONAL'),
];
