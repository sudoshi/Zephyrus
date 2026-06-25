// resources/js/types/commandCenter.ts
import { z } from 'zod';

export const statusLevels = ['critical', 'warning', 'success', 'info', 'neutral'] as const;
export type StatusLevel = (typeof statusLevels)[number];

export const trajectorySchema = z.object({
  points: z.array(z.number()),
  direction: z.enum(['up', 'down', 'flat']),
  goodWhenDown: z.boolean(),
});
export type Trajectory = z.infer<typeof trajectorySchema>;

export const kpiMetricDetailSchema = z.object({
  caption: z.string(),
  segments: z.array(z.object({
    label: z.string(),
    value: z.number(),
    display: z.string(),
    status: z.enum(statusLevels),
  })),
  rows: z.array(z.object({
    label: z.string(),
    value: z.string(),
    status: z.enum(statusLevels),
  })),
});
export type KpiMetricDetail = z.infer<typeof kpiMetricDetailSchema>;

export const kpiMetricSchema = z.object({
  key: z.string(),
  label: z.string(),
  value: z.number(),
  unit: z.string(),
  display: z.string(),
  target: z.number().nullable(),
  targetDisplay: z.string().nullable(),
  status: z.enum(statusLevels),
  trajectory: trajectorySchema.nullable(),
  drillHref: z.string().nullable(),
  definition: z.string(),
  detail: kpiMetricDetailSchema.nullable().optional(),
});
export type KpiMetric = z.infer<typeof kpiMetricSchema>;

export const strainStateSchema = z.object({
  level: z.number(),
  label: z.string(),
  status: z.enum(statusLevels),
  previousLevel: z.number(),
  drivers: z.array(z.object({ label: z.string(), value: z.string(), status: z.enum(statusLevels) })),
  updatedAtIso: z.string(),
});
export type StrainState = z.infer<typeof strainStateSchema>;

export const unitCensusSchema = z.object({
  unitId: z.number(),
  name: z.string(),
  type: z.string(),
  staffed: z.number(),
  occupied: z.number(),
  blocked: z.number(),
  available: z.number(),
  occupancyPct: z.number(),
  acuityAdjustedPct: z.number(),
  status: z.enum(statusLevels),
});
export type UnitCensus = z.infer<typeof unitCensusSchema>;

export const forecastStateSchema = z.object({
  predictedDischarges24h: z.number(),
  predictedDischarges48h: z.number(),
  predictedEdArrivals: z.number(),
  predictedAdmissions: z.number(),
  netBedPosition: z.number(),
  surgeProbabilityPct: z.number(),
  occupancyCurve: z.array(z.object({
    hourOffset: z.number(), occupancyPct: z.number(), lowerPct: z.number(), upperPct: z.number(),
  })),
  netBedByUnit: z.array(z.object({ unitId: z.number(), name: z.string(), net: z.number() })),
});
export type ForecastState = z.infer<typeof forecastStateSchema>;

export const objectiveSchema = z.object({
  key: z.string(),
  title: z.string(),
  keyResults: z.array(z.object({
    label: z.string(), current: z.number(), target: z.number(), baseline: z.number(),
    progressPct: z.number(), status: z.enum(statusLevels), display: z.string(),
  })),
});
export type Objective = z.infer<typeof objectiveSchema>;

export const bandKeys = ['capacity', 'flow', 'outcomes', 'forecast'] as const;
export const bandDataSchema = z.object({
  key: z.enum(bandKeys),
  title: z.string(),
  summary: z.string(),
  drillHref: z.string(),
  drillLabel: z.string(),
  metrics: z.array(kpiMetricSchema),
  subgroups: z.array(z.object({
    key: z.string(), label: z.string(), metrics: z.array(kpiMetricSchema),
  })).optional(),
});
export type BandData = z.infer<typeof bandDataSchema>;

export const commandCenterDataSchema = z.object({
  generatedAtIso: z.string(),
  strain: strainStateSchema,
  heroMetrics: z.array(kpiMetricSchema),
  capacity: bandDataSchema,
  flow: bandDataSchema,
  outcomes: bandDataSchema,
  forecast: bandDataSchema,
  forecastDetail: forecastStateSchema,
  unitCensus: z.array(unitCensusSchema),
  objectives: z.array(objectiveSchema),
});
export type CommandCenterData = z.infer<typeof commandCenterDataSchema>;

export function parseCommandCenterData(input: unknown): CommandCenterData {
  return commandCenterDataSchema.parse(input);
}
