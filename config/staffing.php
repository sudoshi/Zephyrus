<?php

return [
    'default_facility_key' => env('STAFFING_DEFAULT_FACILITY_KEY', 'SUMMIT_REGIONAL'),
    'default_timezone' => env('STAFFING_DEFAULT_TIMEZONE', 'America/New_York'),

    'shifts' => [
        'day' => ['starts_at' => '07:00', 'ends_at' => '15:00'],
        'evening' => ['starts_at' => '15:00', 'ends_at' => '23:00'],
        'night' => ['starts_at' => '23:00', 'ends_at' => '07:00'],
    ],

    /*
     * Compatibility only: operational requests still carry the seven legacy
     * role values. Eligibility is always resolved against the canonical
     * hosp_ref.staff_roles taxonomy before a person can be offered or filled.
     */
    'legacy_role_map' => [
        'rn' => [
            'staff_nurse', 'critical_care_nurse', 'emergency_nurse',
            'perioperative_nurse', 'pacu_nurse', 'neonatal_nurse',
            'pediatric_nurse', 'behavioral_health_nurse', 'float_pool_nurse',
        ],
        'lpn' => ['licensed_practical_nurse'],
        'tech' => [
            'patient_care_technician', 'nursing_assistant',
            'emergency_department_technician', 'surgical_technologist',
        ],
        'charge' => ['charge_nurse', 'clinical_coordinator'],
        'provider' => [
            'hospitalist', 'intensivist', 'emergency_physician', 'physician',
            'nurse_practitioner', 'physician_assistant',
        ],
        'respiratory' => ['respiratory_therapist'],
        'unit_secretary' => ['unit_clerk'],
    ],

    'candidate_page_size' => 25,
    'materialization_days' => (int) env('STAFFING_MATERIALIZATION_DAYS', 28),
    'materialization_schedule_enabled' => (bool) env('STAFFING_MATERIALIZATION_SCHEDULE_ENABLED', true),
    'materialization_schedule_time' => env('STAFFING_MATERIALIZATION_SCHEDULE_TIME', '04:10'),
];
