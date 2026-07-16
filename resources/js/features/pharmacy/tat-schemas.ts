import { z } from 'zod';
import { sourceFreshnessSchema, slaDefinitionSchema } from '@/Components/Ancillary/schemas';

const nullableIso = z.string().datetime({ offset: true }).nullable();

const distributionSchema = z.object({
  count: z.number().int().nonnegative(),
  medianMinutes: z.number().nonnegative().nullable(),
  p90Minutes: z.number().nonnegative().nullable(),
  meanMinutes: z.number().nonnegative().nullable(),
}).strict();

const pointSchema = distributionSchema.extend({ key: z.string(), label: z.string() }).strict();

const basisSchema = z.enum(['real_time', 'warehouse_as_of']);

const chartContextSchema = z.object({
  clockDefinition: slaDefinitionSchema.nullable(),
  cohortCount: z.number().int().nonnegative(),
  sourceCutoffAt: nullableIso,
  benchmarkSourceLabel: z.string().min(1),
}).strict();

const assertionSchema = z.object({
  milestoneUuid: z.string().uuid(),
  code: z.string(),
  basis: basisSchema,
  occurredAt: z.string().datetime({ offset: true }),
  receivedAt: z.string().datetime({ offset: true }),
  sourceKey: z.string(),
  sourceRank: z.number().int().nonnegative(),
  assertionCount: z.number().int().positive(),
}).strict();

export const pharmacyTatSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  sourceCutoffAt: nullableIso,
  administrationCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'unknown', 'batch']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  administrationFreshness: sourceFreshnessSchema,
  filters: z.object({
    dateFrom: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    dateTo: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    priority: z.string().nullable(),
    patientClass: z.string().nullable(),
    shift: z.enum(['day', 'evening', 'night', 'weekend']).nullable(),
    branch: z.enum(['adc', 'iv_room', 'central', 'unknown']).nullable(),
    limit: z.number().int().positive().max(2000),
  }).strict(),
  filterOptions: z.object({
    priorities: z.array(z.string()),
    patientClasses: z.array(z.string()),
    shifts: z.array(z.enum(['day', 'evening', 'night', 'weekend'])),
    branches: z.array(z.enum(['adc', 'iv_room', 'central', 'unknown'])),
    maxRangeDays: z.number().int().positive(),
    maxLimit: z.number().int().positive(),
  }).strict(),
  appliedSlaDefinitions: z.array(slaDefinitionSchema),
  summary: distributionSchema.extend({
    candidateOrderCount: z.number().int().nonnegative(),
    includedOrderCount: z.number().int().nonnegative(),
    clockDefinition: slaDefinitionSchema.nullable(),
    basis: z.literal('warehouse_as_of'),
    administrationCutoffAt: nullableIso,
  }).strict(),
  waterfall: z.array(distributionSchema.extend({
    phase: z.enum(['verification', 'preparation', 'dispense', 'delivery', 'end_to_end']),
    basis: basisSchema,
    definition: slaDefinitionSchema,
    cohortCount: z.number().int().nonnegative(),
    missingIntervalCount: z.number().int().nonnegative(),
    excludedNegativeCount: z.number().int().nonnegative(),
    invalidTimestampCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso,
    benchmarkSourceLabel: z.string().min(1),
  }).strict()),
  dailyTrend: chartContextSchema.extend({ label: z.string(), basis: z.literal('warehouse_as_of'), points: z.array(pointSchema) }).strict(),
  breakdowns: z.object({
    priority: chartContextSchema.extend({ label: z.string(), dimension: z.literal('priority'), basis: z.literal('warehouse_as_of'), points: z.array(pointSchema) }).strict(),
    shift: chartContextSchema.extend({ label: z.string(), dimension: z.literal('shift'), basis: z.literal('warehouse_as_of'), points: z.array(pointSchema) }).strict(),
    unit: chartContextSchema.extend({ label: z.string(), dimension: z.literal('unitLabel'), basis: z.literal('warehouse_as_of'), points: z.array(pointSchema) }).strict(),
    branch: chartContextSchema.extend({ label: z.string(), dimension: z.literal('branch'), basis: z.literal('warehouse_as_of'), points: z.array(pointSchema) }).strict(),
  }).strict(),
  queueDepthHeatmap: z.object({
    clockDefinition: z.string().min(1),
    basis: z.literal('real_time'),
    days: z.array(z.string()),
    hours: z.array(z.number().int().min(0).max(23)),
    cells: z.array(z.object({
      day: z.string(),
      dayIndex: z.number().int().min(1).max(7),
      hour: z.number().int().min(0).max(23),
      count: z.number().int().nonnegative(),
    }).strict()),
    totalQueued: z.number().int().nonnegative(),
    peakCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso,
  }).strict(),
  missingDosePareto: z.object({
    clockDefinition: z.string().min(1),
    basis: z.literal('real_time'),
    chainCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso,
    points: z.array(z.object({
      key: z.string(), label: z.string(), count: z.number().int().nonnegative(),
      percent: z.number().min(0).max(100), cumulativePercent: z.number().min(0).max(100),
    }).strict()),
  }).strict(),
  dischargeReadinessTrend: z.object({
    clockDefinition: z.string().min(1),
    basis: z.literal('real_time'),
    cohortCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso,
    points: z.array(z.object({
      date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), label: z.string(),
      cohortCount: z.number().int().nonnegative(), readyOnTimeCount: z.number().int().nonnegative(),
      readyOnTimePercent: z.number().min(0).max(100).nullable(),
    }).strict()),
  }).strict(),
  shortageImpact: z.object({
    clockDefinition: z.string().min(1),
    basis: z.literal('warehouse_as_of'),
    shortageOrderCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso,
    points: z.array(distributionSchema.extend({ key: z.string(), label: z.string() }).strict()),
  }).strict(),
  mappingCoverage: z.object({
    clockDefinition: z.string().min(1),
    totalOrderCount: z.number().int().nonnegative(),
    mappedCount: z.number().int().nonnegative(),
    unmappedLocalCount: z.number().int().nonnegative(),
    mappedPercent: z.number().min(0).max(100).nullable(),
    unmappedLocalPercent: z.number().min(0).max(100).nullable(),
    points: z.array(z.object({ key: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  }).strict(),
  benchmarkReferences: z.array(z.object({
    definitionUuid: z.string().uuid(), metricKey: z.string(), label: z.string(), basis: basisSchema,
    sourceReferenceId: z.string().nullable(), sourceLabel: z.string(),
    classification: z.enum(['local_policy', 'established_reference', 'site_policy_required', 'no_numeric_benchmark', 'governed_reference']),
    numericLines: z.array(z.object({ kind: z.enum(['warning', 'breach', 'target']), value: z.number().nonnegative(), unit: z.string() }).strict()),
  }).strict()),
  coverage: z.object({
    candidateOrderCount: z.number().int().nonnegative(), analyzedOrderCount: z.number().int().nonnegative(), includedOrderCount: z.number().int().nonnegative(),
    possibleIntervalCount: z.number().int().nonnegative(), includedIntervalCount: z.number().int().nonnegative(), percent: z.number().min(0).max(100),
    missingAssertionIntervalCount: z.number().int().nonnegative(), excludedNegativeIntervalCount: z.number().int().nonnegative(),
    invalidTimestampIntervalCount: z.number().int().nonnegative(), selectedAssertionConflictCount: z.number().int().nonnegative(),
    truncated: z.boolean(), unanalyzedCandidateCount: z.number().int().nonnegative(), definition: z.string().min(1),
  }).strict(),
  lineage: z.object({
    count: z.number().int().nonnegative(), truncated: z.boolean(), definition: z.string().min(1),
    items: z.array(z.object({
      orderUuid: z.string().uuid(), definitionUuid: z.string().uuid(), metricKey: z.string(), basis: basisSchema,
      minutes: z.number().nonnegative(), date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), priority: z.string(),
      clockClass: z.string(), branch: z.string(), medicationLabel: z.string(), patientClass: z.string(),
      unitLabel: z.string(), shift: z.enum(['day', 'evening', 'night', 'weekend']), sourceCutoffAt: z.string().datetime({ offset: true }),
      startAssertion: assertionSchema, stopAssertion: assertionSchema,
    }).strict()),
  }).strict(),
  privacy: z.object({
    patientIdentifiersIncluded: z.literal(false), doseInstructionsIncluded: z.literal(false),
    individualPerformanceIncluded: z.literal(false), identifierPolicy: z.string().min(1),
  }).strict(),
}).strict();

export type PharmacyTat = z.infer<typeof pharmacyTatSchema>;
