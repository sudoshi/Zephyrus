import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { fetchRecommendations, createBedRequest } from '@/features/rtdc/bedPlacement';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

describe('bed placement api', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchRecommendations validates the envelope', async () => {
    mocked.get.mockResolvedValue({ data: { data: {
      recommendations: [{ bed_id: 1, bed_label: '5E-01', unit_id: 1, unit_name: '5 East', score: 30, breakdown: [{ term: 'acuity_headroom', value: 20 }], chips: [{ label: 'Ratio OK', ok: true }] }],
      runner_up_delta: 12, excluded: [{ bed_id: 2, reason: 'isolation mismatch' }],
    } } });
    const recs = await fetchRecommendations(7);
    expect(recs.recommendations[0].bed_id).toBe(1);
    expect(recs.runner_up_delta).toBe(12);
    expect(mocked.get).toHaveBeenCalledWith('/api/rtdc/bed-requests/7/recommendations');
  });

  it('createBedRequest posts validated payload', async () => {
    mocked.post.mockResolvedValue({ data: { data: { bed_request_id: 5, patient_ref: 'p', source: 'ed', acuity_tier: 2, isolation_required: 'none', required_unit_type: 'med_surg', status: 'pending' } } });
    const r = await createBedRequest({ patient_ref: 'p', source: 'ed', acuity_tier: 2, isolation_required: 'none', required_unit_type: 'med_surg' });
    expect(r.bed_request_id).toBe(5);
  });
});
