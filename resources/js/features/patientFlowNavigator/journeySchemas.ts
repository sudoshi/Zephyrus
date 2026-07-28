import { z } from 'zod';

/**
 * Zod contract for GET /api/patient-flow/journey (FLOW-4D plan §8 Phase A1;
 * consumed by the Phase B Patient Journey Drawer). Tolerant on purpose —
 * every field the drawer renders is nullable/optional so a server addition
 * never white-screens the wall (the safeParse discipline).
 */

const isoString = z.string().min(1);

export const journeyEventSchema = z.object({
  event_id: z.string().nullish(),
  event_category: z.string().nullish(),
  event_type: z.string().nullish(),
  trigger_event: z.string().nullish(),
  activity: z.string().nullish(),
  occurred_at: isoString.nullish(),
  from_location: z.string().nullish(),
  to_location: z.string().nullish(),
  location_name: z.string().nullish(),
  location_floor: z.number().nullish(),
  unit_code: z.string().nullish(),
  unit_id: z.number().nullish(),
  bed: z.string().nullish(),
  service_line: z.string().nullish(),
  patient_class: z.string().nullish(),
  code_counts: z.object({
    diagnosis: z.number(),
    order: z.number(),
    observation: z.number(),
    medication: z.number(),
  }).partial().nullish(),
});

export const journeySegmentSchema = z.object({
  kind: z.string(),
  location: z.string().nullish(),
  location_name: z.string().nullish(),
  floor: z.number().nullish(),
  unit_code: z.string().nullish(),
  unit_id: z.number().nullish(),
  started_at: isoString,
  ended_at: isoString.nullish(),
  dwell_minutes: z.number().nullish(),
  open: z.boolean().nullish(),
});

export const journeyPhaseSchema = z.object({
  phase: z.string(),
  case_id: z.number().nullish(),
  started_at: isoString,
  ended_at: isoString.nullish(),
  minutes: z.number().nullish(),
  open: z.boolean().nullish(),
});

export const journeyMilestoneSchema = z.object({
  source: z.string(),
  case_id: z.number().nullish(),
  milestone_type: z.string(),
  status: z.string(),
  required: z.boolean().nullish(),
  completed_at: isoString.nullish(),
});

export const journeyLogisticsSchema = z.object({
  domain: z.string(),
  status: z.string(),
  priority: z.string().nullish(),
  origin: z.string().nullish(),
  destination: z.string().nullish(),
  service: z.string().nullish(),
  required_unit_type: z.string().nullish(),
  isolation_required: z.boolean().nullish(),
  location_label: z.string().nullish(),
  turn_type: z.string().nullish(),
  requested_at: isoString.nullish(),
  needed_at: isoString.nullish(),
  completed_at: isoString.nullish(),
});

export const journeyHomeSchema = z.object({
  domain: z.string().nullish(),
  status: z.string(),
  disposition: z.string().nullish(),
  condition_label: z.string().nullish(),
  acuity_tier: z.union([z.string(), z.number()]).nullish(),
  service_zone: z.string().nullish(),
  started_at: isoString.nullish(),
  ended_at: isoString.nullish(),
  expected_discharge_date: z.string().nullish(),
});

export const journeyBarrierSchema = z.object({
  barrier_id: z.number(),
  unit_id: z.number().nullish(),
  category: z.string(),
  description: z.string().nullish(),
  opened_at: isoString.nullish(),
  attribution: z.string(),
});

// FLOW-4D Phase D: governed-pathway progress (dark serving path + synthetic
// demo overlay). Present only when the serving gate produced a real assignment
// or the demo overlay applies; tolerant-nullish so its absence never white-screens.
export const journeyPathwayMilestoneSchema = z.object({
  stable_key: z.string(),
  title: z.string(),
  phase: z.string().nullish(),
  sequence: z.number().nullish(),
  status: z.string(),
  observed_at: isoString.nullish(),
  expected: z.object({
    day_offset_min: z.number().nullish(),
    day_offset_max: z.number().nullish(),
    display: z.string().nullish(),
  }),
});

export const journeyPathwayProgressSchema = z.object({
  state: z.string(),
  demo: z.boolean(),
  // Always false in Phase D — the drawer must never present this as guidance.
  clinical_use: z.boolean(),
  notice: z.string().nullish(),
  pathway: z.object({
    key: z.string(),
    label: z.string(),
    version_uuid: z.string().nullish(),
    semantic_version: z.string().nullish(),
    digest: z.string().nullish(),
  }),
  milestones: z.array(journeyPathwayMilestoneSchema),
  summary: z.object({
    completed: z.number(),
    current: z.number(),
    planned: z.number(),
    delayed: z.number(),
    canceled: z.number(),
    total: z.number(),
    current_stable_key: z.string().nullish(),
    elements_met_label: z.string(),
  }),
  source: z.string(),
  as_of: isoString,
});

export const patientJourneySchema = z.object({
  patient: z.object({
    patient_context_ref: z.string(),
    display_label: z.string(),
    detail_authorized: z.boolean(),
  }),
  lens: z.object({
    role_id: z.string(),
    patient_dots: z.string(),
  }),
  window: z.object({ from: isoString, to: isoString }),
  header: z.object({
    current_location: z.string().nullish(),
    current_location_name: z.string().nullish(),
    current_unit_code: z.string().nullish(),
    current_floor: z.number().nullish(),
    service_line: z.string().nullish(),
    patient_class: z.string().nullish(),
    admitted_at: isoString.nullish(),
    los_minutes: z.number().nullish(),
    as_of: isoString.nullish(),
  }),
  events: z.array(journeyEventSchema),
  segments: z.array(journeySegmentSchema),
  phases: z.object({
    ed: z.array(journeyPhaseSchema),
    periop: z.array(journeyPhaseSchema),
  }),
  milestones: z.array(journeyMilestoneSchema),
  logistics: z.array(journeyLogisticsSchema),
  home: z.array(journeyHomeSchema),
  unit_context_barriers: z.array(journeyBarrierSchema),
  next: z.object({
    target_location: z.string().nullish(),
    transport_needed_at: isoString.nullish(),
    expected_discharge_date: z.string().nullish(),
  }),
  epoch: z.object({
    epoch: z.string(),
    refreshed_at: isoString.nullish(),
    status: z.string(),
  }).nullish(),
  pathway_progress: journeyPathwayProgressSchema.nullish(),
  as_of: isoString,
  generated_at: isoString,
});

export type PatientJourney = z.infer<typeof patientJourneySchema>;
export type JourneyPathwayProgress = z.infer<typeof journeyPathwayProgressSchema>;
export type JourneyPathwayMilestone = z.infer<typeof journeyPathwayMilestoneSchema>;
export type JourneyEvent = z.infer<typeof journeyEventSchema>;
export type JourneySegment = z.infer<typeof journeySegmentSchema>;
export type JourneyPhase = z.infer<typeof journeyPhaseSchema>;
