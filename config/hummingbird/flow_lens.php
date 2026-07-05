<?php

/*
|--------------------------------------------------------------------------
| Hummingbird Flow Window — per-persona lens configuration
|--------------------------------------------------------------------------
| One lens per Hummingbird role id (all 14 — MobilePersonaCatalog::ROLE_IDS).
| The lens is enforced SERVER-SIDE by FlowLensService on every flow payload
| (mobile /api/mobile/v1/flow/* and the web /api/patient-flow/* surface):
|
|   scope_default    where the window opens (house | floor | unit | patient)
|   scopes_allowed   scopes the role may request; anything else ⇒ 403
|   layers           payload layers the role may request (snapshots, events,
|                    projections, spaces, duties)
|   event_kinds      normalized OperationalTimelineService kinds served
|   projection_kinds ForwardProjectionService kinds served
|   duty_kinds       DutyProjectionService kinds served — the persona's
|                    actionable worklist, spatially anchored + due-dated (the
|                    "what's due for me" layer the native 3D viewer renders)
|   patient_dots     patient-entity depth in payloads — maps 1:1 onto the
|                    MobilePatientContextService access matrix:
|                      full  any operational patient        (bed_manager,
|                            house_supervisor, capacity_lead)
|                      unit  shared-unit patients only      (nurses, hospitalist,
|                            intensivist — checked against prod.user_unit)
|                      task  patients on that role's active requests only
|                            (transport, evs)
|                      none  payload NEVER contains patient entities/ptoks
|                            (executive, pi_lead, staffing, OR roles — they get
|                            aggregate heat only; the same roles 403 on A2P)
|   actions          map-affordances the client may render (labels only —
|                    every action still goes through its governed endpoint)
|   default_zoom_hours  the default visible span (frontline lenses open at ±4h;
|                    the 48h span is capability, not default posture)
|
| Full event-kind vocabulary (OperationalTimelineService):
|   admit, transfer, discharge, bed_status, acuity_changed, ed_arrival,
|   ed_admit_decision, bed_request, placement, transport_status, evs_status,
|   barrier_opened, barrier_resolved, staffing_fill, or_milestone
|
| Full projection-kind vocabulary (ForwardProjectionService):
|   expected_discharge, predicted_census, predicted_arrivals,
|   scheduled_or_case, transport_due, evs_due, staffing_shift_gap,
|   surge_probability
|
| Full duty-kind vocabulary (DutyProjectionService):
|   transport_run, bed_turn, placement, barrier_resolve, staffing_fill,
|   approval, discharge_leverage
*/

return [

    // P3 · Charge Nurse — "My unit, my shift" (unit board + start-of-shift replay)
    'charge_nurse' => [
        'scope_default' => 'unit',
        'scopes_allowed' => ['unit', 'floor', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'bed_status', 'acuity_changed',
            'bed_request', 'placement', 'transport_status', 'evs_status',
            'barrier_opened', 'barrier_resolved',
        ],
        'projection_kinds' => ['expected_discharge', 'predicted_census', 'evs_due', 'transport_due', 'staffing_shift_gap'],
        'duty_kinds' => ['barrier_resolve', 'discharge_leverage'],
        'patient_dots' => 'unit',
        'actions' => ['resolve_barrier', 'acknowledge_inbound', 'open_patient'],
        'default_zoom_hours' => 12, // opens at the last shift boundary
    ],

    'bedside_nurse' => [
        'scope_default' => 'unit',
        'scopes_allowed' => ['unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'bed_status', 'acuity_changed',
            'transport_status', 'evs_status', 'barrier_opened', 'barrier_resolved',
        ],
        'projection_kinds' => ['expected_discharge', 'evs_due', 'transport_due'],
        'duty_kinds' => ['barrier_resolve', 'discharge_leverage'],
        'patient_dots' => 'unit',
        'actions' => ['open_patient'],
        'default_zoom_hours' => 8,
    ],

    // P5 · Bed Manager / House Supervisor — "The whole board, in time"
    'bed_manager' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'bed_status', 'acuity_changed',
            'ed_arrival', 'ed_admit_decision', 'bed_request', 'placement',
            'transport_status', 'evs_status', 'barrier_opened', 'barrier_resolved',
        ],
        'projection_kinds' => [
            'expected_discharge', 'predicted_census', 'predicted_arrivals',
            'scheduled_or_case', 'transport_due', 'evs_due', 'surge_probability',
        ],
        'duty_kinds' => ['placement', 'barrier_resolve'],
        'patient_dots' => 'full',
        'actions' => ['place_bed', 'open_patient'],
        'default_zoom_hours' => 48,
    ],

    'house_supervisor' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'bed_status', 'acuity_changed',
            'ed_arrival', 'ed_admit_decision', 'bed_request', 'placement',
            'transport_status', 'evs_status', 'barrier_opened', 'barrier_resolved',
            'staffing_fill',
        ],
        'projection_kinds' => [
            'expected_discharge', 'predicted_census', 'predicted_arrivals',
            'scheduled_or_case', 'transport_due', 'evs_due', 'staffing_shift_gap',
            'surge_probability',
        ],
        'duty_kinds' => ['placement', 'barrier_resolve', 'staffing_fill'],
        'patient_dots' => 'full',
        'actions' => ['place_bed', 'resolve_barrier', 'open_patient'],
        'default_zoom_hours' => 48,
    ],

    // Hospitalist / Intensivist — "My patients across the house"
    'hospitalist' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['admit', 'transfer', 'discharge', 'acuity_changed', 'barrier_opened', 'barrier_resolved'],
        'projection_kinds' => ['expected_discharge', 'predicted_census'],
        'duty_kinds' => ['discharge_leverage'],
        'patient_dots' => 'unit',
        'actions' => ['open_patient'],
        'default_zoom_hours' => 24,
    ],

    'intensivist' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['admit', 'transfer', 'discharge', 'acuity_changed', 'barrier_opened', 'barrier_resolved'],
        'projection_kinds' => ['expected_discharge', 'predicted_census'],
        'duty_kinds' => ['discharge_leverage'],
        'patient_dots' => 'unit',
        'actions' => ['open_patient'],
        'default_zoom_hours' => 24,
    ],

    // P1 · Transporter — "My day in the building"
    'transport' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['transport_status', 'discharge'],
        'projection_kinds' => ['transport_due', 'expected_discharge'],
        'duty_kinds' => ['transport_run'],
        'patient_dots' => 'task',
        'actions' => ['claim_trip', 'open_patient'],
        'default_zoom_hours' => 8, // ±4h default zoom
    ],

    // P2 · EVS Technician — "The turn map"
    'evs' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['evs_status', 'bed_status', 'discharge'],
        'projection_kinds' => ['evs_due', 'expected_discharge'],
        'duty_kinds' => ['bed_turn'],
        'patient_dots' => 'task',
        'actions' => ['claim_turn', 'start_turn', 'complete_turn'],
        'default_zoom_hours' => 8,
    ],

    // P4 · OR Circulating Nurse — "My room's day" (no patient depth; OR roles 403 on A2P)
    'or_nurse' => [
        'scope_default' => 'floor',
        'scopes_allowed' => ['floor'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['or_milestone'],
        'projection_kinds' => ['scheduled_or_case'],
        'duty_kinds' => [], // OR milestones ride the event timeline; no patient-identified duties
        'patient_dots' => 'none',
        'actions' => [],
        'default_zoom_hours' => 16, // today's schedule span
    ],

    // P7 · Periop Manager — "All rooms, the cascade"
    'periop_manager' => [
        'scope_default' => 'floor',
        'scopes_allowed' => ['floor', 'house'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['or_milestone'],
        'projection_kinds' => ['scheduled_or_case', 'predicted_census'],
        'duty_kinds' => [],
        'patient_dots' => 'none',
        'actions' => [],
        'default_zoom_hours' => 16,
    ],

    // P6 · Capacity Lead — "Strain over time" (curve-first, map-second)
    'capacity_lead' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit', 'patient'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'ed_arrival', 'ed_admit_decision',
            'bed_request', 'placement', 'barrier_opened', 'barrier_resolved',
        ],
        'projection_kinds' => [
            'expected_discharge', 'predicted_census', 'predicted_arrivals',
            'staffing_shift_gap', 'surge_probability',
        ],
        'duty_kinds' => ['approval', 'placement', 'barrier_resolve'],
        'patient_dots' => 'full',
        'actions' => ['decide_approval', 'open_patient'],
        'default_zoom_hours' => 48,
    ],

    // P9 · Executive — "Is the hospital OK — and will it be?" (aggregate only, never dots)
    'executive' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house'],
        'layers' => ['snapshots', 'projections', 'spaces', 'duties'],
        'event_kinds' => [],
        'projection_kinds' => ['predicted_census', 'predicted_arrivals', 'surge_probability'],
        'duty_kinds' => [], // aggregate posture only — no patient-identified worklist
        'patient_dots' => 'none',
        'actions' => [],
        'default_zoom_hours' => 48,
    ],

    // P10 · Staffing Coordinator — "Coverage vs the curve"
    'staffing_coordinator' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => ['staffing_fill', 'admit', 'discharge'],
        'projection_kinds' => ['predicted_census', 'predicted_arrivals', 'staffing_shift_gap'],
        'duty_kinds' => ['staffing_fill'],
        'patient_dots' => 'none',
        'actions' => ['fill_request'],
        'default_zoom_hours' => 24,
    ],

    // P8 · PI / Quality Lead — "The pattern, not the patient" (fully de-identified)
    'pi_lead' => [
        'scope_default' => 'house',
        'scopes_allowed' => ['house', 'floor', 'unit'],
        'layers' => ['snapshots', 'events', 'projections', 'spaces', 'duties'],
        'event_kinds' => [
            'admit', 'transfer', 'discharge', 'bed_status', 'ed_arrival',
            'ed_admit_decision', 'transport_status', 'evs_status',
            'barrier_opened', 'barrier_resolved',
        ],
        'projection_kinds' => ['predicted_census', 'predicted_arrivals'],
        'duty_kinds' => [], // de-identified pattern work, not individual duties
        'patient_dots' => 'none',
        'actions' => ['clip_window'],
        'default_zoom_hours' => 48,
    ],
];
