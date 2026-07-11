<?php

return [
    'enabled' => filter_var(env('DEMO_DATA_ENABLED', false), FILTER_VALIDATE_BOOL),
    'facility_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DEMO_DATA_FACILITY_ALLOWLIST', 'SUMMIT_REGIONAL')),
    ))),
    'schedule_enabled' => filter_var(env('DEMO_DATA_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'schedule_time' => env('DEMO_DATA_SCHEDULE_TIME', '05:15'),
    'workforce' => [
        // Planning assumptions for the synthetic Summit roster. These are not
        // regulatory staffing ratios and must remain visibly traceable in data.
        'annual_coverage_days' => 365,
        'shift_hours' => 8,
        'productive_hours_per_fte' => 1664,
        'relief_factor' => 1.18,
        'roster_window_days' => 28,
        'inactive_records' => 12,
    ],
];
