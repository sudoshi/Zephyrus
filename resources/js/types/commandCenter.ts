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

export const sourceTrustSchema = z.object({
  score: z.number(),
  status: z.enum(['critical', 'warning', 'success']),
  freshSourceCount: z.number(),
  staleSourceCount: z.number(),
  missingSourceCount: z.number(),
});
export type SourceTrust = z.infer<typeof sourceTrustSchema>;

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
  lineageHref: z.string().optional(),
  lineageSummary: z.string().optional(),
  sourceTrust: sourceTrustSchema.optional(),
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

export const commandCenterDailyMetricSchema = z.object({
  metricKey: z.string(),
  label: z.string(),
  panelKey: z.string(),
  groupKey: z.string().nullable(),
  value: z.number(),
  display: z.string(),
  target: z.number().nullable(),
  targetDisplay: z.string().nullable(),
  status: z.enum(statusLevels),
  varianceToTarget: z.number().nullable(),
});
export type CommandCenterDailyMetric = z.infer<typeof commandCenterDailyMetricSchema>;

export const commandCenterDrilldownHistoryPointSchema = z.object({
  date: z.string(),
  value: z.number(),
  display: z.string(),
  status: z.enum(statusLevels),
  varianceToTarget: z.number().nullable(),
  detailHref: z.string(),
});
export type CommandCenterDrilldownHistoryPoint = z.infer<typeof commandCenterDrilldownHistoryPointSchema>;

export const commandCenterMetricDrilldownSchema = z.object({
  key: z.string(),
  label: z.string(),
  panelKey: z.string(),
  panelTitle: z.string(),
  groupKey: z.string().nullable(),
  groupLabel: z.string().nullable(),
  definition: z.string(),
  target: z.number().nullable(),
  targetDisplay: z.string().nullable(),
  current: z.object({
    value: z.number(),
    display: z.string(),
    status: z.enum(statusLevels),
  }),
  distribution: z.object({
    min: z.number(),
    p10: z.number(),
    median: z.number(),
    p90: z.number(),
    max: z.number(),
  }),
  history: z.array(commandCenterDrilldownHistoryPointSchema),
  recommendedInteractions: z.array(z.string()),
});
export type CommandCenterMetricDrilldown = z.infer<typeof commandCenterMetricDrilldownSchema>;

export const commandCenterPanelDailySchema = z.object({
  date: z.string(),
  panelKey: z.string(),
  status: z.enum(statusLevels),
  metricCount: z.number(),
  driverCount: z.number(),
  metrics: z.record(z.string(), commandCenterDailyMetricSchema),
  detailHref: z.string(),
});
export type CommandCenterPanelDaily = z.infer<typeof commandCenterPanelDailySchema>;

export const commandCenterPanelDrilldownSchema = z.object({
  key: z.string(),
  title: z.string(),
  summary: z.string(),
  drillHref: z.string(),
  apiDrillHref: z.string(),
  recommendedInteractions: z.array(z.string()),
  daily: z.array(commandCenterPanelDailySchema),
  metrics: z.array(commandCenterMetricDrilldownSchema),
});
export type CommandCenterPanelDrilldown = z.infer<typeof commandCenterPanelDrilldownSchema>;

export const commandCenterTimelineDaySchema = z.object({
  date: z.string(),
  detailHref: z.string(),
  status: z.enum(statusLevels),
  driverCount: z.number(),
  metrics: z.record(z.string(), commandCenterDailyMetricSchema),
  drivers: z.array(z.object({
    metricKey: z.string(),
    panelKey: z.string(),
    label: z.string(),
    display: z.string(),
    status: z.enum(statusLevels),
  })),
  safetyOpportunityCount: z.number(),
});
export type CommandCenterTimelineDay = z.infer<typeof commandCenterTimelineDaySchema>;

export const commandCenterUnitDrilldownSchema = z.object({
  unitId: z.number(),
  name: z.string(),
  type: z.string(),
  current: z.record(z.string(), z.unknown()),
  history: z.array(z.object({
    date: z.string(),
    staffed: z.number(),
    occupied: z.number(),
    available: z.number(),
    blocked: z.number(),
    occupancyPct: z.number(),
    acuityAdjustedPct: z.number(),
    status: z.enum(statusLevels),
    detailHref: z.string(),
  })),
});
export type CommandCenterUnitDrilldown = z.infer<typeof commandCenterUnitDrilldownSchema>;

export const commandCenterEventSchema = z.object({
  eventId: z.string(),
  date: z.string(),
  timestampIso: z.string(),
  panelKey: z.string(),
  metricKey: z.string(),
  unitId: z.number(),
  unitName: z.string(),
  severity: z.enum(statusLevels),
  title: z.string(),
  description: z.string(),
  recommendedAction: z.string(),
  timeAtRiskMinutes: z.number(),
  avoidableBedDays: z.number(),
  patientSafetyDomains: z.array(z.string()),
  synthetic: z.boolean(),
});
export type CommandCenterEvent = z.infer<typeof commandCenterEventSchema>;

export const commandCenterOpportunitySchema = z.object({
  opportunityId: z.string(),
  panelKey: z.string(),
  metricKey: z.string(),
  title: z.string(),
  currentSignal: z.string(),
  patientSafetySignal: z.string(),
  operationalLever: z.string(),
  expectedImpact: z.string(),
  confidencePct: z.number(),
  firstActions: z.array(z.string()),
  evidenceHref: z.string(),
});
export type CommandCenterOpportunity = z.infer<typeof commandCenterOpportunitySchema>;

export const commandCenterDrilldownSchema = z.object({
  generatedAtIso: z.string(),
  window: z.object({
    startDate: z.string(),
    endDate: z.string(),
    days: z.number(),
    grain: z.literal('daily'),
    minimumDrillDays: z.number(),
    synthetic: z.boolean(),
  }),
  focus: z.object({
    type: z.string(),
    key: z.string(),
    label: z.string(),
    matched: z.boolean(),
  }),
  panels: z.array(commandCenterPanelDrilldownSchema),
  timeline: z.array(commandCenterTimelineDaySchema),
  units: z.array(commandCenterUnitDrilldownSchema),
  events: z.array(commandCenterEventSchema),
  opportunities: z.array(commandCenterOpportunitySchema),
  playbooks: z.array(z.object({
    key: z.string(),
    title: z.string(),
    trigger: z.string(),
    cadenceMinutes: z.number(),
    actions: z.array(z.string()),
  })),
  dataQuality: z.object({
    mode: z.string(),
    clinicalUseNotice: z.string(),
    lineage: z.record(z.string(), z.string()),
  }),
});
export type CommandCenterDrilldown = z.infer<typeof commandCenterDrilldownSchema>;

export function parseCommandCenterDrilldown(input: unknown): CommandCenterDrilldown {
  return commandCenterDrilldownSchema.parse(input);
}
