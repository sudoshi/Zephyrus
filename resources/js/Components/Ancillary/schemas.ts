import { z } from 'zod';

const nullableIsoTimestamp = z.string().datetime({ offset: true }).nullable();
const nonnegativeNullable = z.number().nonnegative().nullable();

export const freshnessStatusSchema = z.enum(['fresh', 'stale', 'batch', 'unknown']);
export const operationalStateSchema = z.enum(['normal', 'warning', 'breach', 'stale', 'no_data', 'degraded', 'loading']);

export const sourceFreshnessSchema = z.object({
  status: freshnessStatusSchema,
  asOf: z.string().datetime({ offset: true }),
  sourceCutoffAt: nullableIsoTimestamp,
  lagMinutes: nonnegativeNullable,
  sourceLabel: z.string().min(1),
  explanation: z.string().nullable(),
}).strict().superRefine((value, context) => {
  if (value.status === 'unknown' && value.sourceCutoffAt !== null) {
    context.addIssue({ code: 'custom', path: ['sourceCutoffAt'], message: 'Unknown freshness cannot claim a source cutoff.' });
  }
});

export const readinessAxisSchema = z.object({
  key: z.string().min(1),
  label: z.string().min(1),
  status: z.enum(['ready', 'pending', 'blocked', 'unknown', 'not_applicable']),
  state: z.enum(['ready', 'pending', 'blocked', 'unknown', 'not_applicable']),
  pendingCount: z.number().int().nonnegative(),
  oldestAgeMinutes: z.number().int().nonnegative().nullable(),
  blocking: z.boolean(),
  freshness: sourceFreshnessSchema,
  drillTarget: z.string().min(1).nullable(),
  topOrderUuid: z.string().uuid().nullable(),
  drillHref: z.string().min(1).nullable(),
  explanation: z.string().nullable().optional(),
}).strict().superRefine((value, context) => {
  if (value.blocking && value.status === 'ready') {
    context.addIssue({ code: 'custom', path: ['blocking'], message: 'A ready axis cannot be blocking.' });
  }
  if (value.state !== value.status) {
    context.addIssue({ code: 'custom', path: ['state'], message: 'Readiness state must match status.' });
  }
});

export const slaDefinitionSchema = z.object({
  definitionUuid: z.string().uuid(),
  department: z.enum(['rad', 'lab', 'pathology', 'blood_bank', 'rx']),
  metricKey: z.string().min(1),
  label: z.string().min(1),
  startMilestoneCode: z.string().min(1),
  stopMilestoneCode: z.string().min(1),
  priority: z.string().nullable(),
  patientClass: z.string().nullable(),
  scope: z.record(z.string(), z.unknown()),
  statistic: z.enum(['item_clock', 'compliance_rate', 'median', 'p90', 'count', 'oldest_age']),
  warningMinutes: z.number().int().nonnegative().nullable(),
  breachMinutes: z.number().int().nonnegative().nullable(),
  targetValue: z.number().nullable(),
  direction: z.enum(['lower_is_better', 'higher_is_better', 'target_range']),
  unit: z.string().min(1),
  effectiveFrom: z.string().datetime({ offset: true }),
  effectiveTo: nullableIsoTimestamp,
  version: z.number().int().positive(),
  active: z.boolean(),
  definitionText: z.string().min(1),
  sourceReferenceId: z.string().nullable(),
}).strict().superRefine((value, context) => {
  if (value.warningMinutes !== null && value.breachMinutes !== null && value.warningMinutes > value.breachMinutes) {
    context.addIssue({ code: 'custom', path: ['warningMinutes'], message: 'Warning threshold cannot exceed breach threshold.' });
  }
});

export const metricTileSchema = z.object({
  key: z.string().min(1),
  label: z.string().min(1),
  status: operationalStateSchema,
  value: z.number().nullable(),
  displayValue: z.string().min(1),
  unit: z.string().nullable(),
  cohortCount: z.number().int().nonnegative(),
  median: nonnegativeNullable,
  p90: nonnegativeNullable,
  freshness: sourceFreshnessSchema,
  definition: slaDefinitionSchema.nullable(),
  explanation: z.string().nullable(),
}).strict();

export const worklistRowSchema = z.object({
  orderUuid: z.string().uuid(),
  department: z.enum(['rad', 'lab', 'pathology', 'blood_bank', 'rx']),
  label: z.string().min(1),
  priority: z.string().min(1),
  patientRef: z.string().min(1),
  locationLabel: z.string().nullable(),
  status: operationalStateSchema,
  ageMinutes: nonnegativeNullable,
  barrierCount: z.number().int().nonnegative(),
  readiness: z.array(readinessAxisSchema),
  freshness: sourceFreshnessSchema,
}).strict();

export const readinessVectorSchema = z.array(readinessAxisSchema);
export const metricTilesSchema = z.array(metricTileSchema);
export const ancillaryWorklistSchema = z.array(worklistRowSchema);

export type SourceFreshnessContract = z.infer<typeof sourceFreshnessSchema>;
export type ReadinessAxisContract = z.infer<typeof readinessAxisSchema>;
export type SlaDefinitionContract = z.infer<typeof slaDefinitionSchema>;
export type MetricTileContract = z.infer<typeof metricTileSchema>;
export type WorklistRowContract = z.infer<typeof worklistRowSchema>;
export type OperationalState = z.infer<typeof operationalStateSchema>;
