<?php

return [
    'defaults' => [
        'owner_role' => 'bed_manager',
        'category' => 'capacity',
        'watch_within_minutes' => 30,
        'delay_after_minutes' => 0,
        'rtdc_metrics' => ['capacity_risk'],
        'eddy_summary' => 'An operational barrier is affecting patient flow. Confirm ownership, current timer status, and the next human-reviewed action.',
        'recommended_focus' => 'Confirm owner, source, and next action before drafting recommendations.',
    ],

    'barriers' => [
        'transport_oxygen_team_delayed' => [
            'label' => 'Oxygen-capable transport delay',
            'category' => 'transport',
            'owner_role' => 'transport',
            'timer_kind' => 'arrival_transport',
            'rtdc_metrics' => ['ed_boarding_hours', 'icu_admission_latency', 'transport_queue_delay'],
            'eddy_summary' => 'Oxygen-capable transport is overdue for an ICU admission, keeping ED and ICU capacity coupled.',
            'recommended_focus' => 'Re-sequence transport capacity and confirm the receiving ICU can accept the patient now.',
        ],

        'icu_handoff_staffing_pending' => [
            'label' => 'ICU handoff staffing pending',
            'category' => 'staffing',
            'owner_role' => 'charge_nurse',
            'timer_kind' => 'readiness',
            'rtdc_metrics' => ['icu_admission_latency', 'critical_care_capacity', 'handoff_delay_minutes'],
            'eddy_summary' => 'ICU acceptance is waiting on staffing confirmation, delaying critical-care absorption.',
            'recommended_focus' => 'Confirm receiving nurse coverage and escalate staffing variance if acceptance cannot occur.',
        ],

        'evs_isolation_clean_delayed' => [
            'label' => 'Isolation clean delayed',
            'category' => 'evs',
            'owner_role' => 'evs',
            'timer_kind' => 'evs',
            'rtdc_metrics' => ['pacu_hold_minutes', 'bed_turnaround_minutes', 'or_recovery_capacity'],
            'eddy_summary' => 'An isolation clean has exceeded target and is blocking PACU-to-floor movement.',
            'recommended_focus' => 'Confirm EVS ETA, isolation requirements, and whether a comparable clean bed can be substituted.',
        ],

        'pacu_floor_transport_at_risk' => [
            'label' => 'PACU floor move at risk',
            'category' => 'transport',
            'owner_role' => 'transport',
            'timer_kind' => 'next_transport',
            'rtdc_metrics' => ['pacu_hold_minutes', 'transport_slot_risk', 'or_recovery_capacity'],
            'eddy_summary' => 'A PACU-to-floor move is approaching its transport window and depends on bed readiness.',
            'recommended_focus' => 'Hold the transport slot only if bed readiness is credible; otherwise reassign before the slot is lost.',
        ],

        'discharge_dme_authorization_pending' => [
            'label' => 'DME authorization pending',
            'category' => 'discharge',
            'owner_role' => 'hospitalist',
            'timer_kind' => 'readiness',
            'rtdc_metrics' => ['avoidable_bed_day_risk', 'ed_admit_capacity', 'discharge_delay_minutes'],
            'eddy_summary' => 'A discharge-ready bed is still occupied because DME authorization and delivery confirmation are incomplete.',
            'recommended_focus' => 'Confirm DME authorization owner and expected release time; escalate if it threatens same-day bed release.',
        ],

        'stepdown_staffing_variance' => [
            'label' => 'Stepdown staffing variance',
            'category' => 'staffing',
            'owner_role' => 'bed_manager',
            'timer_kind' => 'next_transport',
            'rtdc_metrics' => ['critical_care_capacity', 'stepdown_acceptance_delay', 'icu_outflow_delay'],
            'eddy_summary' => 'A stepdown room is assigned but staffing variance is delaying ICU outflow.',
            'recommended_focus' => 'Validate stepdown staffing coverage and identify whether a different staffed bed can accept.',
        ],

        'cath_pickup_handoff_pending' => [
            'label' => 'Cath pickup handoff pending',
            'category' => 'procedure',
            'owner_role' => 'transport',
            'timer_kind' => 'next_transport',
            'rtdc_metrics' => ['procedure_on_time_start', 'transport_queue_delay', 'recovery_capacity_risk'],
            'eddy_summary' => 'Cath lab pickup is approaching while handoff and consent readiness are still pending.',
            'recommended_focus' => 'Confirm consent packet, nurse handoff, and pickup sequencing before the cath window closes.',
        ],

        'post_acute_packet_reconciliation_pending' => [
            'label' => 'Post-acute packet reconciliation pending',
            'category' => 'discharge',
            'owner_role' => 'case_management',
            'timer_kind' => 'readiness',
            'rtdc_metrics' => ['avoidable_bed_day_risk', 'discharge_conversion_rate', 'post_acute_acceptance_risk'],
            'eddy_summary' => 'Post-acute acceptance may expire unless the placement packet is reconciled.',
            'recommended_focus' => 'Confirm packet owner, missing documents, and whether acceptance must be extended.',
        ],

        'long_stay_capacity_risk' => [
            'label' => 'Long-stay capacity risk',
            'category' => 'capacity',
            'owner_role' => 'bed_manager',
            'timer_kind' => 'stay',
            'watch_within_minutes' => 480,
            'delay_after_minutes' => 1080,
            'rtdc_metrics' => ['length_of_stay_pressure', 'bed_availability_risk'],
            'eddy_summary' => 'Elapsed occupancy has crossed the RTDC stay-duration threshold and may be compounding capacity pressure.',
            'recommended_focus' => 'Check whether a specific dependency is blocking progression before drafting escalation.',
        ],
    ],
];
