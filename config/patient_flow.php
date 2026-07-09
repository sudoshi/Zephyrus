<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Replay Source Freshness
    |--------------------------------------------------------------------------
    |
    | Patient Flow events normally arrive continuously. These values describe
    | the expected source cadence; they do not hide older events. The Navigator
    | keeps stale data available as an explicitly historical replay.
    |
    */
    'expected_cadence_seconds' => (int) env('PATIENT_FLOW_EXPECTED_CADENCE_SECONDS', 300),
    'stale_after_seconds' => (int) env('PATIENT_FLOW_STALE_AFTER_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Verified Operational Barriers
    |--------------------------------------------------------------------------
    |
    | Project operator-entered RTDC barriers and active overdue transport
    | requests onto Patient Flow occupancy. The projector uses only explicit
    | encounter, pseudonymous patient, or unit identifiers; free-text locations
    | are never treated as identity keys.
    |
    */
    'operational_barriers' => [
        'enabled' => filter_var(
            env('PATIENT_FLOW_OPERATIONAL_BARRIERS', true),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Patient Flow 4D Demo Barriers
    |--------------------------------------------------------------------------
    |
    | Local demo environments need a rich RTDC pressure picture even when the
    | replay tables are sparse. Production keeps this off unless explicitly
    | enabled. Request query demo=0 disables it for an individual call.
    |
    */
    'demo_barriers_enabled' => env(
        'PATIENT_FLOW_DEMO_BARRIERS',
        env('APP_ENV') !== 'production' && env('APP_ENV') !== 'testing',
    ),

    'demo_barriers_replace_replay' => env(
        'PATIENT_FLOW_DEMO_BARRIERS_REPLACE_REPLAY',
        env('APP_ENV') !== 'production' && env('APP_ENV') !== 'testing',
    ),
];
