<?php

/**
 * Virtual Rounds configuration.
 *
 * Plan: docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md
 *
 * Feature flags gate routes, UI, jobs, and outbound connectors (§12.4).
 * Disabling a flag stops new work without deleting audit records — the
 * middleware answers 404 so the feature is invisible when off.
 *
 * The `sections` map is the structured-contribution allowlist (§6.2):
 * a contribution's structured_data may only contain the fields declared for
 * its section_code. Arbitrary blobs must never become a shadow medical
 * record; anything not allowlisted is rejected at submission.
 */

return [
    'enabled' => filter_var(env('VIRTUAL_ROUNDS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'family_enabled' => filter_var(env('VIRTUAL_ROUNDS_FAMILY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'writeback_enabled' => filter_var(env('VIRTUAL_ROUNDS_WRITEBACK_ENABLED', false), FILTER_VALIDATE_BOOL),
    'eddy_enabled' => filter_var(env('VIRTUAL_ROUNDS_EDDY_ENABLED', false), FILTER_VALIDATE_BOOL),
    'external_notifications_enabled' => filter_var(env('VIRTUAL_ROUNDS_EXTERNAL_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Contributor roles
    |--------------------------------------------------------------------------
    | role_code => display label. prod.user_unit pivot roles map onto these
    | via `unit_role_map`; users.role values map via `app_role_map`.
    */
    'roles' => [
        'attending' => 'Attending / Hospitalist',
        'bedside_nurse' => 'Bedside Nurse',
        'charge_nurse' => 'Charge Nurse / Unit Leader',
        'pharmacist' => 'Pharmacist',
        'case_manager' => 'Case Manager / Social Work',
        'therapist' => 'Therapy / Allied Health',
        'consultant' => 'Consultant',
    ],

    'unit_role_map' => [
        'bedside' => 'bedside_nurse',
        'charge' => 'charge_nurse',
        'manager' => 'charge_nurse',
        'bed_flow' => 'charge_nurse',
    ],

    // Roles allowed to lead a run (start, complete, waive, mark rounded)
    // beyond broad-access app roles and unit charge/manager assignments.
    'lead_unit_roles' => ['charge', 'manager'],
    'broad_access_roles' => ['admin', 'super-admin', 'super_admin', 'superuser', 'ops-leader'],

    /*
    |--------------------------------------------------------------------------
    | Structured-contribution section allowlist
    |--------------------------------------------------------------------------
    | section_code => [label, roles allowed to author it, allowlisted fields].
    | Field types: string | text | enum:a,b,c | boolean
    */
    'sections' => [
        'overnight_events' => [
            'label' => 'Overnight Events',
            'roles' => ['bedside_nurse', 'charge_nurse'],
            'fields' => [
                'events' => 'text',
                'safety_concerns' => 'text',
                'education_needs' => 'text',
                'family_availability' => 'string',
            ],
        ],
        'clinical_plan' => [
            'label' => 'Clinical Plan',
            'roles' => ['attending', 'consultant'],
            'fields' => [
                'assessment' => 'text',
                'plan' => 'text',
                'disposition' => 'enum:continue,discharge_today,discharge_24_48h,transfer,hospice,unknown',
                'clinical_priorities' => 'text',
            ],
        ],
        'medications' => [
            'label' => 'Medication Review',
            'roles' => ['pharmacist'],
            'fields' => [
                'reconciliation_status' => 'enum:complete,in_progress,not_started',
                'discharge_med_barriers' => 'text',
                'interventions' => 'text',
            ],
        ],
        'disposition_planning' => [
            'label' => 'Disposition & Placement',
            'roles' => ['case_manager'],
            'fields' => [
                'placement_status' => 'enum:not_needed,pending,secured,barrier',
                'authorization_status' => 'enum:not_needed,pending,approved,denied',
                'transport_needs' => 'string',
                'social_barriers' => 'text',
            ],
        ],
        'therapy_readiness' => [
            'label' => 'Therapy Readiness',
            'roles' => ['therapist'],
            'fields' => [
                'readiness' => 'enum:ready,progressing,not_ready',
                'recommendations' => 'text',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority engine (deterministic, explainable — §7.2)
    |--------------------------------------------------------------------------
    | Bands are ordered: lower band number rounds earlier. Every component a
    | patient triggers emits a reason {code, band, weight, source, explanation}.
    */
    'priority' => [
        'bands' => [
            1 => 'Pinned urgent',
            2 => 'Time-critical signal',
            3 => 'Discharge-ready with open work',
            4 => 'Coordination window',
            5 => 'Missing or overdue input',
            6 => 'Routine',
        ],
        'weights' => [
            'pinned' => 100,
            'time_critical_acuity' => 50,
            'discharge_ready' => 30,
            'coordination_window' => 20,
            'missing_required_input' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ETA model (§7.3) — transparent static adjustments, minutes
    |--------------------------------------------------------------------------
    */
    'eta' => [
        'default_duration_minutes' => 8,
        'complexity_minutes_per_acuity_step' => 3, // per tier below 3 (1 = sickest)
        'unresolved_input_minutes' => 4,
        'coordination_minutes' => 5,
        'uncertainty_buffer_minutes' => 10,
        'notify_threshold_minutes' => 10, // damping: smaller shifts do not notify
    ],

    /*
    |--------------------------------------------------------------------------
    | Completion policy defaults (§6.4) — templates may override any key
    |--------------------------------------------------------------------------
    */
    'completion' => [
        'freshness_hours' => 24,
        'require_leader_attestation' => true,
        'block_on_open_tasks' => false,
        'exception_reason_required' => true,
    ],
];
