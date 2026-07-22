<?php

/*
|--------------------------------------------------------------------------
| Hummingbird notification urgency registry (plan §6.4)
|--------------------------------------------------------------------------
|
| One server-owned source of truth for notification urgency. Every push a
| native client renders derives its interruption level, importance, sound,
| haptic, acknowledgement, expiry, collapse, quiet-hours, and escalation
| behavior from a T1–T4 tier here — clients never invent urgency. Copy is a
| class, not text: lock-screen copy is always generic and PHI-free by default
| (plan §6.4, §9.6). `NotificationUrgencyRegistry` reads and validates this.
|
*/

return [
    // Ordered most-urgent → least-urgent. Exactly T1–T4.
    'tiers' => [
        'T1' => [
            'label' => 'Critical — immediate safety action',
            'ios_interruption_level' => 'critical',
            'android_importance' => 'HIGH',
            'sound' => 'critical',
            'haptic' => 'critical',
            'requires_ack' => true,
            'ack_timeout_seconds' => 120,
            'escalation' => ['after_seconds' => 120, 'to_tier' => 'T1'],
            'default_expiry_seconds' => 900,
            'collapse_strategy' => 'never',
            'quiet_hours_exempt' => true,
            'copy_class' => 'generic_safety_no_phi',
        ],
        'T2' => [
            'label' => 'Time-sensitive — act soon',
            'ios_interruption_level' => 'time-sensitive',
            'android_importance' => 'HIGH',
            'sound' => 'default',
            'haptic' => 'default',
            'requires_ack' => true,
            'ack_timeout_seconds' => 600,
            'escalation' => ['after_seconds' => 600, 'to_tier' => 'T1'],
            'default_expiry_seconds' => 3600,
            'collapse_strategy' => 'per_entity',
            'quiet_hours_exempt' => true,
            'copy_class' => 'generic_operational_no_phi',
        ],
        'T3' => [
            'label' => 'Standard operational update',
            'ios_interruption_level' => 'active',
            'android_importance' => 'DEFAULT',
            'sound' => null,
            'haptic' => null,
            'requires_ack' => false,
            'ack_timeout_seconds' => null,
            'escalation' => null,
            'default_expiry_seconds' => 21600,
            'collapse_strategy' => 'per_entity',
            'quiet_hours_exempt' => false,
            'copy_class' => 'generic_operational_no_phi',
        ],
        'T4' => [
            'label' => 'Passive — activity feed only',
            'ios_interruption_level' => 'passive',
            'android_importance' => 'LOW',
            'sound' => null,
            'haptic' => null,
            'requires_ack' => false,
            'ack_timeout_seconds' => null,
            'escalation' => null,
            'default_expiry_seconds' => 86400,
            'collapse_strategy' => 'per_domain',
            'quiet_hours_exempt' => false,
            'copy_class' => 'generic_activity_no_phi',
        ],
    ],

    // The tier applied when an event type is recognized but not explicitly mapped.
    'default_tier' => 'T3',

    // Event type → tier. Keys must be recognized PersonaRelayPolicy event types.
    'events' => [
        'alert.escalated' => 'T1',
        'alert.acknowledged' => 'T3',
        'ancillary.sla_breached' => 'T2',
        'ancillary.sla_cleared' => 'T4',
        'bed_request.placed' => 'T2',
        'bed_request.created' => 'T3',
        'barrier.created' => 'T2',
        'barrier.resolved' => 'T4',
        'transport.claimed' => 'T3',
        'transport.progressed' => 'T3',
        'transport.handoff_completed' => 'T3',
        'evs.claimed' => 'T3',
        'evs.started' => 'T3',
        'evs.completed' => 'T3',
        'staffing.request_created' => 'T2',
        'staffing.request_filled' => 'T4',
        'or.case_delayed' => 'T2',
        'or.case_advanced' => 'T4',
        'huddle.action_created' => 'T3',
        'huddle.action_completed' => 'T4',
        'patient.operational_state_changed' => 'T3',
        'recommendation.created' => 'T3',
        'recommendation.approved' => 'T4',
        'recommendation.rejected' => 'T4',
        'recommendation.overridden' => 'T3',
        'action.assigned' => 'T3',
        'action.started' => 'T3',
        'action.completed' => 'T4',
        'action.blocked' => 'T2',
    ],

    // Facility-local quiet hours; non-exempt tiers defer to the activity feed.
    'quiet_hours' => [
        'enabled' => true,
        'start' => '22:00',
        'end' => '07:00',
        'timezone' => 'facility',
    ],
];
