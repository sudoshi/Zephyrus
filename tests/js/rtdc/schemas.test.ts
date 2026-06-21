import { describe, it, expect } from 'vitest';
import { unitCensusSchema, predictionSchema, bedMeetingSchema, barrierSchema } from '@/schemas/rtdc';

describe('rtdc schemas', () => {
  it('parses a valid unit census', () => {
    const parsed = unitCensusSchema.parse({
      unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32,
      census: { occupied: 20, available: 10, blocked: 2, acuity_adjusted_capacity: 8 },
    });
    expect(parsed.census.occupied).toBe(20);
  });

  it('rejects an invalid horizon on a prediction', () => {
    expect(() =>
      predictionSchema.parse({
        rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'tomorrow',
        discharges_weighted: 0, demand_expected: 0, capacity_now: 0, bed_need: 0, status: 'open',
      }),
    ).toThrow();
  });

  it('parses a bed-meeting rollup', () => {
    const parsed = bedMeetingSchema.parse({
      net_bed_need: 3, total_positive_bed_need: 5,
      units: [{ unit_id: 1, unit_name: '5 East', bed_need: 3, capacity_now: 2, demand_expected: 5 }],
    });
    expect(parsed.units).toHaveLength(1);
  });

  it('rejects an invalid barrier category', () => {
    expect(() => barrierSchema.parse({ barrier_id: 1, category: 'financial', status: 'open' })).toThrow();
  });
});
