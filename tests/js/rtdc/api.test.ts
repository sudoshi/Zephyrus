import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { fetchUnits, upsertCapacity } from '@/features/rtdc/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

describe('rtdc api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchUnits returns Zod-validated units', async () => {
    mocked.get.mockResolvedValue({
      data: { data: [{ unit_id: 1, name: '5 East', type: 'med_surg', staffed_bed_count: 32, census: { occupied: 1, available: 2, blocked: 0, acuity_adjusted_capacity: 30 } }] },
    });

    const units = await fetchUnits();
    expect(units[0].name).toBe('5 East');
    expect(mocked.get).toHaveBeenCalledWith('/api/rtdc/units');
  });

  it('fetchUnits throws on schema violation', async () => {
    mocked.get.mockResolvedValue({ data: { data: [{ unit_id: 'oops' }] } });
    await expect(fetchUnits()).rejects.toThrow();
  });

  it('upsertCapacity posts to the unit capacity endpoint', async () => {
    mocked.post.mockResolvedValue({
      data: { data: { rtdc_prediction_id: 1, unit_id: 1, service_date: '2026-06-20', horizon: 'by_2pm', discharges_weighted: 2, demand_expected: 0, capacity_now: 0, bed_need: 0, status: 'open' } },
    });
    const pred = await upsertCapacity(1, { service_date: '2026-06-20', horizon: 'by_2pm', definite: 2, probable: 0, possible: 0 });
    expect(pred.bed_need).toBe(0);
    expect(mocked.post).toHaveBeenCalledWith('/api/rtdc/units/1/capacity', expect.objectContaining({ definite: 2 }));
  });
});
