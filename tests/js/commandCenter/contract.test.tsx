// tests/js/commandCenter/contract.test.tsx
import { describe, it, expect } from 'vitest';
import { parseCommandCenterData, commandCenterDataSchema } from '@/types/commandCenter';

const minimalMetric = {
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%',
  target: 85, targetDisplay: '≤85%', status: 'warning',
  trajectory: { points: [80, 84, 88], direction: 'up', goodWhenDown: true },
  drillHref: '/rtdc/bed-tracking', definition: 'Staffed occupancy.',
};
const band = {
  key: 'capacity', title: 'Capacity', summary: '88% occupied', drillHref: '/rtdc/bed-tracking',
  drillLabel: 'open RTDC', metrics: [minimalMetric],
};
const valid = {
  generatedAtIso: '2026-06-22T12:00:00Z',
  strain: { level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
    drivers: [{ label: 'Occupancy', value: '88%', status: 'warning' }], updatedAtIso: '2026-06-22T12:00:00Z' },
  heroMetrics: [minimalMetric],
  capacity: band, flow: { ...band, key: 'flow', title: 'Flow' },
  outcomes: { ...band, key: 'outcomes', title: 'Outcomes' },
  forecast: { ...band, key: 'forecast', title: 'Forecast' },
  forecastDetail: { predictedDischarges24h: 22, predictedDischarges48h: 40, predictedEdArrivals: 60,
    predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
    occupancyCurve: [{ hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 }],
    netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }] },
  unitCensus: [{ unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' }],
  objectives: [{ key: 'flow', title: 'Improve access & flow',
    keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' }] }],
};

describe('command center contract', () => {
  it('parses a valid payload', () => {
    const parsed = parseCommandCenterData(valid);
    expect(parsed.strain.level).toBe(2);
    expect(parsed.flow.title).toBe('Flow');
  });

  it('rejects an invalid payload (bad status enum)', () => {
    const bad = { ...valid, strain: { ...valid.strain, status: 'purple' } };
    expect(() => parseCommandCenterData(bad)).toThrow();
  });

  it('rejects a missing band', () => {
    const { forecast, ...rest } = valid;
    expect(commandCenterDataSchema.safeParse(rest).success).toBe(false);
  });
});
