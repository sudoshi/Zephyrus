import { z } from 'zod';
import { sourceFreshnessSchema } from '@/Components/Ancillary/schemas';

const iso = z.string().datetime({ offset: true });
const nullableIso = iso.nullable();

// A measured rate is either a non-negative number OR null (no denominator).
// It is NEVER a fabricated zero standing in for "unmeasured".
const nullableRate = z.number().nonnegative().nullable();
const rateStatusSchema = z.enum(['no_data', 'within_target', 'near_target', 'over_target']);

// An open discrepancy's age is folded against the shift-end policy server-side.
// The view renders this status; it never compares raw minutes.
const agingStatusSchema = z.enum(['due_this_shift', 'at_shift_end', 'past_policy']);

// One open controlled discrepancy, aged against the applicable shift-end.
// Every dimension here is operational — a pseudonymous discrepancy key, a
// station, a unit, a medication label, and timestamps. There is NO individual,
// user, staff, or actor field, and none may ever be added.
const openDiscrepancySchema = z.object({
  discrepancyKey: z.string().min(1),
  stationId: z.number().int().positive(),
  unitId: z.number().int().positive().nullable(),
  medicationLabel: z.string().min(1),
  openedAt: iso,
  applicableShiftEndAt: iso,
  minutesOpen: z.number().int().nonnegative(),
  minutesPastShiftEnd: z.number().int(),
  agingStatus: agingStatusSchema,
}).strict();

const stationPatternSchema = z.object({
  stationId: z.number().int().positive(),
  label: z.string().min(1),
  stationType: z.string().min(1),
  unitName: z.string().nullable(),
  controlledVends: z.number().int().nonnegative(),
  controlledOverrides: z.number().int().nonnegative(),
  controlledDiscrepancies: z.number().int().nonnegative(),
  controlledWaste: z.number().int().nonnegative(),
  hasDenominator: z.boolean(),
  denominatorCount: z.number().int().nonnegative(),
  overrideRatePercent: nullableRate,
  overrideStatus: rateStatusSchema,
  openDiscrepancies: z.number().int().nonnegative(),
  openDiscrepanciesPastPolicy: z.number().int().nonnegative(),
  oldestOpenDiscrepancyAt: nullableIso,
  transactionCounts: z.record(z.string(), z.number().int().nonnegative()),
}).strict().superRefine((value, context) => {
  // No denominator ⇒ no rate. A rate without a denominator is a fabricated zero.
  if (!value.hasDenominator && value.overrideRatePercent !== null) {
    context.addIssue({ code: 'custom', path: ['overrideRatePercent'], message: 'A station with no controlled-vend denominator cannot report an override rate.' });
  }
  if (!value.hasDenominator && value.overrideStatus !== 'no_data') {
    context.addIssue({ code: 'custom', path: ['overrideStatus'], message: 'A station with no denominator must carry no-data status, never a target comparison.' });
  }
});

const unitPatternSchema = z.object({
  unitId: z.number().int().positive(),
  unitName: z.string().min(1),
  controlledVends: z.number().int().nonnegative(),
  controlledOverrides: z.number().int().nonnegative(),
  controlledDiscrepancies: z.number().int().nonnegative(),
  hasDenominator: z.boolean(),
  denominatorCount: z.number().int().nonnegative(),
  overrideRatePercent: nullableRate,
  overrideStatus: rateStatusSchema,
  openDiscrepancies: z.number().int().nonnegative(),
  openDiscrepanciesPastPolicy: z.number().int().nonnegative(),
}).strict();

const overrideTargetSchema = z.object({
  label: z.string().min(1),
  ratePercent: z.number().nonnegative(),
  denominatorLabel: z.string().min(1),
  description: z.string().min(1),
}).strict();

export const pharmacyControlledSchema = z.object({
  generatedAt: iso,
  sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'batch', 'unknown']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  appliedSlaDefinitions: z.array(z.unknown()),
  policy: z.object({
    kind: z.literal('local_policy'),
    shiftEnd: z.object({
      label: z.string().min(1),
      timezone: z.string().min(1),
      times: z.array(z.string().min(1)).min(1),
      graceMinutes: z.number().int().nonnegative(),
      description: z.string().min(1),
    }).strict(),
    overrideTargetRate: overrideTargetSchema,
  }).strict(),
  window: z.object({
    hours: z.number().int().positive(),
    startAt: iso,
    endAt: iso,
  }).strict(),
  data: z.object({
    summary: z.object({
      openDiscrepancyCount: z.number().int().nonnegative(),
      openDiscrepanciesPastPolicy: z.number().int().nonnegative(),
      oldestOpenMinutes: z.number().int().nonnegative().nullable(),
      stationsWithOpenDiscrepancy: z.number().int().nonnegative(),
      stationsOverOverrideTarget: z.number().int().nonnegative(),
      totalControlledVends: z.number().int().nonnegative(),
      totalControlledOverrides: z.number().int().nonnegative(),
    }).strict(),
    openDiscrepancies: z.object({
      count: z.number().int().nonnegative(),
      items: z.array(openDiscrepancySchema),
      basis: z.string().min(1),
    }).strict(),
    stationPatterns: z.object({
      stations: z.array(stationPatternSchema),
      basis: z.string().min(1),
    }).strict(),
    unitPatterns: z.object({
      units: z.array(unitPatternSchema),
      basis: z.string().min(1),
    }).strict(),
  }).strict(),
  // The out-of-scope statement is a first-class contract field. Both flags are
  // literal false: diversion investigation and individual scoring can never be
  // in scope, and no individual/user dimension is ever included.
  scope: z.object({
    diversionInvestigationInScope: z.literal(false),
    individualScoringInScope: z.literal(false),
    individualPerformanceIncluded: z.literal(false),
    userLevelDimensionIncluded: z.literal(false),
    aggregationLevel: z.literal('unit_and_station'),
    statement: z.string().min(1),
    exportEnabled: z.boolean(),
    exportStatement: z.string().min(1),
    tone: z.literal('operational_non_accusatory'),
  }).strict(),
}).strict();

export type PharmacyControlled = z.infer<typeof pharmacyControlledSchema>;
export type PharmacyControlledStation = PharmacyControlled['data']['stationPatterns']['stations'][number];
export type PharmacyControlledUnit = PharmacyControlled['data']['unitPatterns']['units'][number];
export type PharmacyControlledDiscrepancy = PharmacyControlled['data']['openDiscrepancies']['items'][number];
export type PharmacyControlledRateStatus = PharmacyControlledStation['overrideStatus'];
export type PharmacyControlledAgingStatus = PharmacyControlledDiscrepancy['agingStatus'];
