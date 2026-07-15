import { z } from 'zod';
import { sourceFreshnessSchema } from '@/Components/Ancillary/schemas';
import { pharmacyStockoutForecastSchema } from './forecast-schemas';

const iso = z.string().datetime({ offset: true });
const nullableIso = iso.nullable();

// A measured rate is either a non-negative number OR null (no denominator).
// It is NEVER a fabricated zero standing in for "unmeasured".
const nullableRate = z.number().nonnegative().nullable();
const rateStatusSchema = z.enum(['no_data', 'within_target', 'near_target', 'over_target']);

// A station rollup: transaction counts and the override/stockout rate computed
// over a DECLARED vend denominator. When hasDenominator is false the rates are
// null — the view shows "no data", never 0%.
const stationSchema = z.object({
  stationId: z.number().int().positive(),
  label: z.string().min(1),
  stationType: z.string().min(1),
  unitName: z.string().nullable(),
  vends: z.number().int().nonnegative(),
  overrides: z.number().int().nonnegative(),
  stockouts: z.number().int().nonnegative(),
  controlledVends: z.number().int().nonnegative(),
  hasDenominator: z.boolean(),
  denominatorCount: z.number().int().nonnegative(),
  overrideRatePercent: nullableRate,
  stockoutRatePercent: nullableRate,
  overrideStatus: rateStatusSchema,
  stockoutStatus: rateStatusSchema,
  hasActiveStockout: z.boolean(),
  transactionCounts: z.record(z.string(), z.number().int().nonnegative()),
}).strict().superRefine((value, context) => {
  // No denominator ⇒ no rate. A rate without a denominator is a fabricated zero.
  if (!value.hasDenominator && value.overrideRatePercent !== null) {
    context.addIssue({ code: 'custom', path: ['overrideRatePercent'], message: 'A station with no vend denominator cannot report an override rate.' });
  }
  if (!value.hasDenominator && value.stockoutRatePercent !== null) {
    context.addIssue({ code: 'custom', path: ['stockoutRatePercent'], message: 'A station with no vend denominator cannot report a stockout rate.' });
  }
  if (!value.hasDenominator && (value.overrideStatus !== 'no_data' || value.stockoutStatus !== 'no_data')) {
    context.addIssue({ code: 'custom', path: ['overrideStatus'], message: 'A station with no denominator must carry no-data status, never a target comparison.' });
  }
});

const unitSchema = z.object({
  unitId: z.number().int().positive(),
  unitName: z.string().min(1),
  vends: z.number().int().nonnegative(),
  overrides: z.number().int().nonnegative(),
  stockouts: z.number().int().nonnegative(),
  hasDenominator: z.boolean(),
  denominatorCount: z.number().int().nonnegative(),
  overrideRatePercent: nullableRate,
  stockoutRatePercent: nullableRate,
  overrideStatus: rateStatusSchema,
  stockoutStatus: rateStatusSchema,
}).strict();

const shortageOrderSchema = z.object({
  orderUuid: z.string().uuid().nullable(),
  medicationLabel: z.string().min(1),
  patientRef: z.string().min(1),
  orderStatus: z.string().min(1),
  locationLabel: z.string().nullable(),
  reasonCode: z.string().nullable(),
  stationKey: z.string().nullable(),
  notedAt: nullableIso,
}).strict();

const vendToRefillStationSchema = z.object({
  stationId: z.number().int().positive(),
  label: z.string().min(1),
  pairCount: z.number().int().positive(),
  medianMinutes: z.number().int().nonnegative().nullable(),
  maxMinutes: z.number().int().nonnegative().nullable(),
}).strict();

const missingDoseChainSchema = z.object({
  orderUuid: z.string().uuid().nullable(),
  medicationLabel: z.string().min(1),
  patientRef: z.string().min(1),
  missingDoseAt: iso,
  reDispenseChannel: z.string().nullable(),
}).strict();

const targetRateSchema = z.object({
  label: z.string().min(1),
  ratePercent: z.number().nonnegative(),
  denominatorLabel: z.string().min(1),
  description: z.string().min(1),
}).strict();

export const pharmacyDispenseSchema = z.object({
  generatedAt: iso,
  sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'batch', 'unknown']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({ stationType: z.string().nullable(), forecast: z.boolean() }).strict(),
  filterOptions: z.object({ stationType: z.array(z.string()) }).strict(),
  appliedSlaDefinitions: z.array(z.unknown()),
  policy: z.object({
    kind: z.literal('local_policy'),
    overrideTargetRate: targetRateSchema,
    stockoutTargetRate: targetRateSchema,
  }).strict(),
  window: z.object({
    hours: z.number().int().positive(),
    startAt: iso,
    endAt: iso,
  }).strict(),
  planningForecast: z.object({
    requested: z.boolean(),
    enabled: z.boolean(),
    stockout: pharmacyStockoutForecastSchema.nullable(),
    explanation: z.string().min(1),
  }).strict(),
  data: z.object({
    summary: z.object({
      stationsReporting: z.number().int().nonnegative(),
      stationsWithDenominator: z.number().int().nonnegative(),
      stationsWithoutDenominator: z.number().int().nonnegative(),
      totalVends: z.number().int().nonnegative(),
      totalOverrides: z.number().int().nonnegative(),
      totalStockouts: z.number().int().nonnegative(),
      overrideRatePercent: nullableRate,
      stockoutRatePercent: nullableRate,
      stationsOverOverrideTarget: z.number().int().nonnegative(),
      stationsWithActiveStockout: z.number().int().nonnegative(),
    }).strict(),
    stations: z.array(stationSchema),
    units: z.array(unitSchema),
    shortages: z.object({
      count: z.number().int().nonnegative(),
      orders: z.array(shortageOrderSchema),
      basis: z.string().min(1),
    }).strict(),
    vendToRefill: z.object({
      measurableStations: z.number().int().nonnegative(),
      stations: z.array(vendToRefillStationSchema),
      basis: z.string().min(1),
    }).strict(),
    missingDose: z.object({
      chainCount: z.number().int().nonnegative(),
      chains: z.array(missingDoseChainSchema),
      basis: z.string().min(1),
    }).strict(),
    delivery: z.object({
      coverage: z.enum(['available', 'absent']),
      dispenses: z.number().int().nonnegative(),
      delivered: z.number().int().nonnegative(),
      returned: z.number().int().nonnegative(),
      medianMinutes: z.number().int().nonnegative().nullable(),
      p90Minutes: z.number().int().nonnegative().nullable(),
      coverageStatement: z.string().min(1),
    }).strict().superRefine((value, context) => {
      // Delivery tracking absent ⇒ no interval. Never a fabricated zero.
      if (value.coverage === 'absent' && (value.medianMinutes !== null || value.p90Minutes !== null)) {
        context.addIssue({ code: 'custom', path: ['medianMinutes'], message: 'Absent delivery tracking cannot report a delivery interval.' });
      }
    }),
  }).strict(),
  privacy: z.object({
    directPatientIdentifiersIncluded: z.literal(false),
    individualPerformanceIncluded: z.literal(false),
    diversionScoringIncluded: z.literal(false),
    userLevelDimensionIncluded: z.literal(false),
    identifierPolicy: z.string().min(1),
  }).strict(),
  canViewPatientDetail: z.boolean(),
}).strict();

export type PharmacyDispense = z.infer<typeof pharmacyDispenseSchema>;
export type PharmacyDispenseStation = PharmacyDispense['data']['stations'][number];
export type PharmacyDispenseUnit = PharmacyDispense['data']['units'][number];
export type PharmacyDispenseShortage = PharmacyDispense['data']['shortages']['orders'][number];
export type PharmacyDispenseRateStatus = PharmacyDispenseStation['overrideStatus'];
