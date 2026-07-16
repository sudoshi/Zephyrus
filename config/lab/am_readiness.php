<?php

/**
 * Laboratory AM-readiness forecasting policy (P4-2).
 *
 * The morning-rounds cutoff is a POLICY value, deliberately separate from any
 * measurement or SLA definition. The forecast predicts whether explicit
 * decision-class Laboratory work (discharge_gate / ed_disposition, per
 * LabDecisionPendingService) will reach LAB_VERIFIED before this local wall-clock
 * time. Changing the cutoff changes only the planning question the forecast
 * answers; it never alters an observed readiness axis or an SLA clock.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Morning-rounds cutoff (local wall-clock, hospital timezone)
    |--------------------------------------------------------------------------
    | The configured time by which morning rounds expect decision-class results
    | to be verified. Expressed as local hour/minute; the forecast resolves the
    | next occurrence of this time relative to "now" in the facility timezone.
    */
    'rounds_cutoff' => [
        'hour' => (int) env('LAB_AM_ROUNDS_CUTOFF_HOUR', 8),
        'minute' => (int) env('LAB_AM_ROUNDS_CUTOFF_MINUTE', 0),
        'label' => (string) env('LAB_AM_ROUNDS_CUTOFF_LABEL', 'Morning rounds (08:00 local)'),
    ],
];
