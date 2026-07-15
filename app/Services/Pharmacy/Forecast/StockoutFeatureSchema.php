<?php

namespace App\Services\Pharmacy\Forecast;

/**
 * Frozen station/medication operational feature contract for P4-3.
 *
 * No individual, workforce, clinical-result, or controlled-diversion feature
 * is admitted. The model predicts inventory exhaustion only when a governed
 * on-hand/par snapshot exists; observed stockouts remain observed facts.
 */
final class StockoutFeatureSchema
{
    /** @var list<string> */
    public const FEATURES = [
        'inventory_ratio',
        'vend_velocity_norm',
        'refill_velocity_norm',
        'refill_gap_norm',
        'shortage_flag',
        'station_downtime',
        'hour_sin',
        'hour_cos',
        'is_weekend',
        'inventory_pressure',
    ];

    /** @var list<string> */
    public const FORBIDDEN_SUBSTRINGS = [
        'user', 'staff', 'pharmacist', 'technician', 'nurse', 'verifier',
        'employee', 'badge', 'actor', 'performed_by', 'patient', 'diagnosis',
        'result', 'protected', 'demographic', 'controlled', 'diversion', 'rank',
    ];
}
