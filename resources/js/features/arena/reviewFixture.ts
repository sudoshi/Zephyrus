// resources/js/features/arena/reviewFixture.ts
//
// A single valid Flow-Review artifact so the movement renders real shapes before
// the backend loop (FlowReviewService + /api/arena/review) ships. It is parsed
// through arenaReviewResponseSchema exactly like a live response, so when the
// endpoint lands, flipping USE_REVIEW_FIXTURE (FlowReviewMovement) to false is
// the only change. Data mirrors the Summit Regional synthetic dataset.
import type { ArenaReviewResponse } from './reviewSchema';

export const REVIEW_FIXTURE: ArenaReviewResponse = {
  available: true,
  cached: false,
  stale: false,
  window: { from: '2026-07-08T14:00:00Z', to: '2026-07-10T14:00:00Z', label: 'Window ending Fri 10 Jul 14:00' },
  prior_window_label: 'Wed 08 Jul 12:00',
  generated_at: '2026-07-10T14:02:00Z',
  stats: {
    open_barriers: 7,
    new_barriers: 3,
    actions_pending: 2,
    worst_handoff: { label: 'Bed-assign → transport (4W)', value_label: '4.6h', delta_pct: 38 },
    worst_pathway: { label: 'Sepsis (SEP-3)', rate: 0.85, delta_pt: 2 },
  },
  map: {
    object_types: ['Bed', 'Encounter', 'Patient', 'Transport Job'],
    nodes: [
      { id: 'ed_arrival', activity: 'ED arrival', frequency: 412, object_types: ['Encounter', 'Patient'] },
      { id: 'direct_add', activity: 'Direct admission', frequency: 96, object_types: ['Encounter', 'Patient'] },
      { id: 'bed_request', activity: 'Bed request', frequency: 508, object_types: ['Encounter', 'Bed'] },
      { id: 'assign_bed', activity: 'Assign bed', frequency: 471, object_types: ['Bed', 'Encounter'] },
      { id: 'transport', activity: 'Transport', frequency: 433, object_types: ['Transport Job', 'Patient'] },
      { id: 'occupied', activity: 'Bed occupied', frequency: 455, object_types: ['Bed'] },
    ],
    edges: [
      { source: 'ed_arrival', target: 'bed_request', object_type: 'Encounter', frequency: 392 },
      { source: 'direct_add', target: 'bed_request', object_type: 'Encounter', frequency: 92 },
      { source: 'bed_request', target: 'assign_bed', object_type: 'Bed', frequency: 468 },
      { source: 'assign_bed', target: 'transport', object_type: 'Transport Job', frequency: 431 },
      { source: 'transport', target: 'occupied', object_type: 'Bed', frequency: 428 },
    ],
  },
  performance_index: [
    { object_type: 'Transport Job', source: 'assign_bed', target: 'transport', count: 22, median_sec: 16560, p90_sec: 28440, mean_sec: 18120 },
    { object_type: 'Bed', source: 'bed_request', target: 'assign_bed', count: 468, median_sec: 6300, p90_sec: 12600, mean_sec: 7020 },
    { object_type: 'Encounter', source: 'ed_arrival', target: 'bed_request', count: 392, median_sec: 3600, p90_sec: 8100, mean_sec: 4260 },
    { object_type: 'Bed', source: 'transport', target: 'occupied', count: 428, median_sec: 1200, p90_sec: 2400, mean_sec: 1380 },
  ],
  barriers: [
    {
      id: 'flow-4w-assign-transport',
      kind: 'flow',
      severity: 'critical',
      title: 'Bed-assign → transport hand-off breaching',
      subtitle: '4W · median 4.6h vs 2.8h target · 22 encounters waited',
      location: { unit_id: 41, unit_label: '4 West' },
      encounter_ref: null,
      opened_at: '2026-07-10T09:40:00Z',
      metric: { value_label: '4.6h', value_sec: 16560, delta_pct: 38, direction: 'up' },
      provenance: { source: 'arena.performance', note: 'sync-wait · observed' },
      map_focus: { node_ids: ['assign_bed', 'transport'], edge_ids: ['assign_bed transport'] },
      corrective_action: {
        draft: {
          action_uuid: '8f1c2a90-3b4d-4e21-9c7a-1a2b3c4d5e6f',
          action_type: 'propose_pdsa_cycle',
          tier: 'T2',
          risk: 'medium',
          title: 'Pre-page transport on bed_assigned (4W)',
          status: 'pending',
          approved: false,
          approval_uuid: 'a1b2c3d4-0000-4444-8888-abcdefabcdef',
        },
        prior_outcome: { label: 'last action moved median', moved_sec: -3240 },
      },
    },
    {
      id: 'care-ed-sepsis-abx',
      kind: 'care',
      severity: 'warning',
      title: 'Sepsis antibiotic late vs SEP-3',
      subtitle: 'ED · 6 of 41 pathways deviated · step: abx_within_3h',
      location: { unit_id: 12, unit_label: 'ED' },
      encounter_ref: null,
      opened_at: '2026-07-09T22:15:00Z',
      metric: { value_label: '85%', value_sec: null, delta_pct: -2, direction: 'down' },
      provenance: { source: 'arena.conformance', note: 'observed deviation' },
      map_focus: { node_ids: ['ed_arrival'], edge_ids: [] },
      deviations: [
        { code: 'abx_within_3h', label: 'Antibiotic later than 3h', count: 6 },
        { code: 'lactate_repeat', label: 'Repeat lactate missing', count: 2 },
      ],
      sample_cases: [
        { case_id: 'enc-3d9f21', deviations: ['abx_within_3h'] },
        { case_id: 'enc-77ab04', deviations: ['abx_within_3h', 'lactate_repeat'] },
        { case_id: 'enc-b1c8e5', deviations: ['abx_within_3h'] },
      ],
      corrective_action: {
        draft: {
          action_uuid: '2c9d4e11-7a6b-4c33-8def-9012ab34cd56',
          action_type: 'propose_pathway_correction',
          tier: 'T2',
          risk: 'medium',
          title: 'Sepsis order-set: abx pre-selected at triage',
          status: 'pending',
          approved: false,
        },
        prior_outcome: null,
      },
    },
    {
      id: 'human-iso-bed-shortage',
      kind: 'human',
      severity: 'watch',
      title: 'Isolation bed shortage — placement',
      subtitle: 'House · flagged 09:12 by C. Ramos · 3 encounters held',
      location: { unit_id: null, unit_label: 'House' },
      encounter_ref: null,
      opened_at: '2026-07-10T09:12:00Z',
      metric: { value_label: '3 held', value_sec: null, delta_pct: null, direction: 'flat' },
      provenance: { source: 'prod.barriers', note: 'open · owner: bed mgmt' },
      map_focus: { node_ids: ['bed_request', 'assign_bed'], edge_ids: ['bed_request assign_bed'] },
    },
  ],
};
