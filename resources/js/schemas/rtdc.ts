import { z } from 'zod';

export const horizonSchema = z.enum(['by_2pm', 'by_midnight']);

export const unitCensusSchema = z.object({
  unit_id: z.number(),
  name: z.string(),
  type: z.enum(['ed', 'med_surg', 'icu', 'step_down']),
  staffed_bed_count: z.number(),
  census: z.object({
    occupied: z.number(),
    available: z.number(),
    blocked: z.number(),
    acuity_adjusted_capacity: z.number(),
  }),
});
export type UnitCensus = z.infer<typeof unitCensusSchema>;

export const predictionSchema = z.object({
  rtdc_prediction_id: z.number(),
  unit_id: z.number(),
  service_date: z.string(),
  horizon: horizonSchema,
  discharges_definite: z.number().optional(),
  discharges_probable: z.number().optional(),
  discharges_possible: z.number().optional(),
  discharges_weighted: z.coerce.number(),
  demand_ed: z.number().optional(),
  demand_or: z.number().optional(),
  demand_transfer: z.number().optional(),
  demand_direct: z.number().optional(),
  demand_expected: z.number(),
  capacity_now: z.number(),
  bed_need: z.number(),
  status: z.enum(['open', 'closed']),
});
export type Prediction = z.infer<typeof predictionSchema>;

export const bedMeetingUnitSchema = z.object({
  unit_id: z.number(),
  unit_name: z.string().nullable(),
  bed_need: z.number(),
  capacity_now: z.number(),
  demand_expected: z.number(),
});

export const bedMeetingSchema = z.object({
  net_bed_need: z.number(),
  total_positive_bed_need: z.number(),
  units: z.array(bedMeetingUnitSchema),
});
export type BedMeeting = z.infer<typeof bedMeetingSchema>;

export const barrierSchema = z.object({
  barrier_id: z.number(),
  unit_id: z.number().nullable().optional(),
  encounter_id: z.number().nullable().optional(),
  category: z.enum(['medical', 'logistical', 'placement', 'social']),
  reason_code: z.string().nullable().optional(),
  description: z.string().nullable().optional(),
  owner: z.string().nullable().optional(),
  status: z.enum(['open', 'resolved']),
});
export type Barrier = z.infer<typeof barrierSchema>;

export const censusUpdatedEventSchema = z.object({
  unit_id: z.number(),
  captured_at: z.string().nullable(),
  staffed_beds: z.number(),
  occupied: z.number(),
  available: z.number(),
  blocked: z.number(),
  acuity_adjusted_capacity: z.number(),
});
export type CensusUpdatedEvent = z.infer<typeof censusUpdatedEventSchema>;
