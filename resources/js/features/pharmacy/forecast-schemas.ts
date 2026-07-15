import { z } from 'zod';

const iso = z.string().datetime({ offset: true });
const metricCoverageSchema = z.object({
  evaluated: z.number().int().nonnegative(),
  total: z.number().int().nonnegative(),
  fraction: z.number().min(0).max(1),
}).strict();

const queueMetricSchema = z.object({
  strategy: z.string().min(1),
  mae: z.number().nonnegative(),
  rmse: z.number().nonnegative(),
  wape: z.number().nonnegative(),
}).strict();

const queueEvaluationSchema = z.object({
  mae: z.number().nonnegative(),
  rmse: z.number().nonnegative(),
  wape: z.number().nonnegative(),
  coverage: metricCoverageSchema,
  baselines: z.object({
    seasonalHourOfWeek: queueMetricSchema,
    lastValue: queueMetricSchema,
  }).strict(),
  winnerRule: z.string().min(1),
  beatsBaselines: z.boolean(),
}).strict();

const stockoutEvaluationSchema = z.object({
  calibrationError: z.number().nonnegative(),
  discriminationAuc: z.number().min(0).max(1),
  brierScore: z.number().min(0).max(1),
  coverage: metricCoverageSchema,
  naiveBaseline: z.object({
    strategy: z.string().min(1),
    baseRate: z.number().min(0).max(1),
    brierScore: z.number().min(0).max(1),
    discriminationAuc: z.number().min(0).max(1),
  }).strict(),
  beatsBaseline: z.boolean(),
}).strict();

const trainingWindowSchema = z.object({
  cohortSize: z.number().int().positive(),
  trainCount: z.number().int().positive(),
  evaluateCount: z.number().int().positive(),
  trainFrom: iso,
  trainTo: iso,
  evaluateFrom: iso,
  evaluateTo: iso,
}).strict();

export const pharmacyForecastProvenanceSchema = z.object({
  modelVersion: z.string().min(1),
  modelFamily: z.string().min(1),
  calibratedAt: iso,
  synthetic: z.literal(true),
  syntheticLabel: z.string().min(1),
  targets: z.object({
    queue: z.object({ definition: z.string().min(1), horizonHours: z.number().int().positive(), cutoffRule: z.string().min(1) }).strict(),
    stockout: z.object({ definition: z.string().min(1), horizonHours: z.number().int().positive(), cutoffRule: z.string().min(1) }).strict(),
  }).strict(),
  queueEvaluation: queueEvaluationSchema,
  stockoutEvaluation: stockoutEvaluationSchema,
  queueTrainingWindow: trainingWindowSchema,
  stockoutTrainingWindow: trainingWindowSchema,
}).strict();

export const pharmacyQueueForecastSchema = z.object({
  kind: z.literal('forecast'),
  target: z.literal('verification_queue_depth'),
  status: z.enum(['available', 'low_confidence', 'unavailable']),
  horizonHours: z.number().int().positive(),
  currentDepth: z.number().int().nonnegative(),
  history: z.object({
    lookbackDays: z.number().int().positive(),
    historyHours: z.number().int().positive(),
    seasonality: z.literal('hour_of_week'),
    recentNetPerHour: z.number(),
  }).strict().nullable(),
  points: z.array(z.object({
    at: iso,
    horizonHour: z.number().int().positive(),
    startingDepth: z.number().nonnegative(),
    forecastDepth: z.number().nonnegative(),
    lowerDepth: z.number().nonnegative(),
    upperDepth: z.number().nonnegative(),
    historicalArrivalRate: z.number().nonnegative(),
    historicalCompletionRate: z.number().nonnegative(),
    scheduledDemand: z.number().int().nonnegative(),
    scheduledDemandContribution: z.number(),
  }).strict()),
  missingSignals: z.array(z.string().min(1)),
  explanation: z.string().min(1),
  provenance: pharmacyForecastProvenanceSchema.nullable(),
}).strict();

export const pharmacyStockoutForecastSchema = z.object({
  kind: z.literal('forecast'),
  target: z.literal('station_medication_stockout_within_six_hours'),
  status: z.enum(['available', 'velocity_only', 'unavailable']),
  horizonHours: z.number().int().positive(),
  lookbackHours: z.number().int().positive(),
  rows: z.array(z.object({
    stationId: z.number().int().positive(),
    stationKey: z.string().min(1),
    stationLabel: z.string().min(1),
    unitLabel: z.string().nullable(),
    localCode: z.string().min(1),
    medicationLabel: z.string().min(1),
    terminologyStatus: z.enum(['mapped', 'unmapped_local']),
    horizonHours: z.number().int().positive(),
    inventory: z.object({
      onHand: z.number().nullable(),
      parLevel: z.number().nullable(),
      capturedAt: iso.nullable(),
    }).strict(),
    velocityPressure: z.object({
      vendUnitsPerHour: z.number().nonnegative(),
      refillUnitsPerHour: z.number().nonnegative(),
      minutesSinceRefill: z.number().nonnegative().nullable(),
      shortageFlag: z.boolean(),
      basis: z.string().min(1),
    }).strict(),
    availability: z.enum(['observed', 'available', 'low_confidence', 'velocity_only', 'unavailable']),
    observedState: z.enum(['stockout_open', 'none']),
    probability: z.number().min(0).max(1).nullable(),
    band: z.enum(['low', 'watch', 'elevated']).nullable(),
    factors: z.array(z.object({
      feature: z.string().min(1),
      effect: z.number(),
      direction: z.enum(['pressure', 'protective']),
    }).strict()),
    missingSignals: z.array(z.string().min(1)),
    explanation: z.string().min(1),
  }).strict()),
  coverage: z.object({
    stationMedicationPairs: z.number().int().nonnegative(),
    probabilityAvailable: z.number().int().nonnegative(),
    velocityOnly: z.number().int().nonnegative(),
    observedStockouts: z.number().int().nonnegative(),
  }).strict(),
  explanation: z.string().min(1),
  provenance: pharmacyForecastProvenanceSchema.nullable(),
  privacy: z.object({
    stationMedicationAggregatesOnly: z.literal(true),
    individualStaffFeaturesIncluded: z.literal(false),
    controlledDiversionScoreIncluded: z.literal(false),
  }).strict(),
}).strict().superRefine((value, context) => {
  value.rows.forEach((row, index) => {
    const hasProbability = row.availability === 'available' || row.availability === 'low_confidence';
    if (hasProbability !== (row.probability !== null && row.band !== null)) {
      context.addIssue({ code: 'custom', path: ['rows', index, 'probability'], message: 'Only valid inventory forecasts may carry a probability and band.' });
    }
    if (row.availability === 'observed' && row.observedState !== 'stockout_open') {
      context.addIssue({ code: 'custom', path: ['rows', index, 'observedState'], message: 'Observed forecast rows must preserve an open-stockout fact.' });
    }
  });
});

export type PharmacyQueueForecast = z.infer<typeof pharmacyQueueForecastSchema>;
export type PharmacyStockoutForecast = z.infer<typeof pharmacyStockoutForecastSchema>;
export type PharmacyStockoutForecastRow = PharmacyStockoutForecast['rows'][number];
