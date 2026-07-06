<?php

return [
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
