import {
  pharmacyQueueForecastSchema,
  pharmacyStockoutForecastSchema,
  type PharmacyQueueForecast,
  type PharmacyStockoutForecast,
} from '@/features/pharmacy/forecast-schemas';

const provenance = {
  modelVersion: 'rx-forecast-2026.07.15-synthetic-v1',
  modelFamily: 'seasonal_queue_and_calibrated_logistic_stockout',
  calibratedAt: '2026-07-15T12:00:00+00:00',
  synthetic: true,
  syntheticLabel: 'Synthetic demo calibration — operational planning aid only.',
  targets: {
    queue: { definition: 'Open verification queue depth.', horizonHours: 8, cutoffRule: 'Signals must be known at cutoff.' },
    stockout: { definition: 'Station-medication inventory reaches zero.', horizonHours: 6, cutoffRule: 'Inventory and transactions must be known at cutoff.' },
  },
  queueEvaluation: {
    mae: 0.2373, rmse: 0.2916, wape: 0.001,
    coverage: { evaluated: 303, total: 303, fraction: 1 },
    baselines: {
      seasonalHourOfWeek: { strategy: 'current_depth_plus_hour_of_week_net', mae: 1.13, rmse: 1.34, wape: 0.005 },
      lastValue: { strategy: 'persistence_current_depth', mae: 1.29, rmse: 1.57, wape: 0.006 },
    },
    winnerRule: 'MAE and RMSE must beat both baselines.', beatsBaselines: true,
  },
  stockoutEvaluation: {
    calibrationError: 0.037, discriminationAuc: 0.8978, brierScore: 0.0762,
    coverage: { evaluated: 270, total: 270, fraction: 1 },
    naiveBaseline: { strategy: 'training_base_rate', baseRate: 0.2, brierScore: 0.14, discriminationAuc: 0.5 },
    beatsBaseline: true,
  },
  queueTrainingWindow: { cohortSize: 1008, trainCount: 705, evaluateCount: 303, trainFrom: '2026-06-03T12:00:00+00:00', trainTo: '2026-07-02T20:00:00+00:00', evaluateFrom: '2026-07-02T21:00:00+00:00', evaluateTo: '2026-07-15T11:00:00+00:00' },
  stockoutTrainingWindow: { cohortSize: 900, trainCount: 630, evaluateCount: 270, trainFrom: '2026-06-08T00:00:00+00:00', trainTo: '2026-07-04T05:00:00+00:00', evaluateFrom: '2026-07-04T06:00:00+00:00', evaluateTo: '2026-07-15T11:00:00+00:00' },
} as const;

export function queueForecastFixture(): PharmacyQueueForecast {
  return pharmacyQueueForecastSchema.parse({
    kind: 'forecast', target: 'verification_queue_depth', status: 'low_confidence', horizonHours: 8, currentDepth: 3,
    history: { lookbackDays: 28, historyHours: 24, seasonality: 'hour_of_week', recentNetPerHour: 0.33 },
    points: Array.from({ length: 8 }, (_, index) => ({
      at: `2026-07-11T${String(15 + index).padStart(2, '0')}:00:00+00:00`,
      horizonHour: index + 1, startingDepth: 3 + index, forecastDepth: 4 + index,
      lowerDepth: 3 + index, upperDepth: 5 + index, historicalArrivalRate: 1.5,
      historicalCompletionRate: 1.0, scheduledDemand: index === 1 ? 1 : 0,
      scheduledDemandContribution: index === 1 ? -0.8 : -1.6,
    })),
    missingSignals: ['seven_days_of_observed_queue_history'],
    explanation: 'Less than seven days of observed queue history is available; the series is low confidence.',
    provenance,
  });
}

export function stockoutForecastFixture(): PharmacyStockoutForecast {
  return pharmacyStockoutForecastSchema.parse({
    kind: 'forecast', target: 'station_medication_stockout_within_six_hours', status: 'available', horizonHours: 6, lookbackHours: 24,
    rows: [
      {
        stationId: 1, stationKey: 'demo:rx:station:ED-01', stationLabel: 'Demo ADC — ED Bay', unitLabel: 'Emergency Dept',
        localCode: 'CEFTRIAXONE_1G_IV', medicationLabel: 'Ceftriaxone 1 g intravenous', terminologyStatus: 'mapped', horizonHours: 6,
        inventory: { onHand: 0, parLevel: 8, capturedAt: '2026-07-11T13:40:00+00:00' },
        velocityPressure: { vendUnitsPerHour: 0.1, refillUnitsPerHour: 0, minutesSinceRefill: 180, shortageFlag: true, basis: 'Station-level ADC transactions only.' },
        availability: 'observed', observedState: 'stockout_open', probability: null, band: null, factors: [], missingSignals: [],
        explanation: 'An open stockout is an observed operational fact, not a forecast.',
      },
      {
        stationId: 1, stationKey: 'demo:rx:station:ED-01', stationLabel: 'Demo ADC — ED Bay', unitLabel: 'Emergency Dept',
        localCode: 'ONDANSETRON_INJ', medicationLabel: 'Ondansetron injection', terminologyStatus: 'mapped', horizonHours: 6,
        inventory: { onHand: 2, parLevel: 12, capturedAt: '2026-07-11T13:40:00+00:00' },
        velocityPressure: { vendUnitsPerHour: 0.2, refillUnitsPerHour: 0, minutesSinceRefill: 240, shortageFlag: false, basis: 'Station-level ADC transactions only.' },
        availability: 'available', observedState: 'none', probability: 0.67, band: 'elevated',
        factors: [{ feature: 'inventory_pressure', effect: 1.2, direction: 'pressure' }], missingSignals: [],
        explanation: 'Valid station-level inventory and velocity evidence are available.',
      },
      {
        stationId: 2, stationKey: 'demo:rx:station:MS-01', stationLabel: 'Demo ADC — Med/Surg', unitLabel: 'Med/Surg',
        localCode: 'MORPHINE_INJ', medicationLabel: 'Morphine injection', terminologyStatus: 'mapped', horizonHours: 6,
        inventory: { onHand: 3, parLevel: 10, capturedAt: '2026-07-11T11:00:00+00:00' },
        velocityPressure: { vendUnitsPerHour: 0, refillUnitsPerHour: 0, minutesSinceRefill: 480, shortageFlag: false, basis: 'Station-level ADC transactions only.' },
        availability: 'low_confidence', observedState: 'none', probability: 0.42, band: 'watch', factors: [], missingSignals: [],
        explanation: 'The inventory snapshot is older than the planning tolerance.',
      },
      {
        stationId: 3, stationKey: 'demo:rx:station:ICU-01', stationLabel: 'Demo ADC — ICU', unitLabel: 'ICU',
        localCode: 'HEPARIN_INFUSION', medicationLabel: 'Heparin continuous infusion', terminologyStatus: 'unmapped_local', horizonHours: 6,
        inventory: { onHand: null, parLevel: null, capturedAt: null },
        velocityPressure: { vendUnitsPerHour: 0, refillUnitsPerHour: 0.42, minutesSinceRefill: 400, shortageFlag: false, basis: 'Station-level ADC transactions only.' },
        availability: 'velocity_only', observedState: 'none', probability: null, band: null, factors: [], missingSignals: ['on_hand', 'par_level', 'inventory_captured_at'],
        explanation: 'No inventory snapshot exists; no probability is claimed.',
      },
    ],
    coverage: { stationMedicationPairs: 4, probabilityAvailable: 2, velocityOnly: 1, observedStockouts: 1 },
    explanation: 'Probability is computed only when valid inventory exists.', provenance,
    privacy: { stationMedicationAggregatesOnly: true, individualStaffFeaturesIncluded: false, controlledDiversionScoreIncluded: false },
  });
}
