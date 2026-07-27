import { describe, expect, it } from 'vitest';

import { adoptEpoch } from '@/features/patientFlowNavigator/epoch';

/**
 * F-6 pt 2 (FLOW-4D plan §8 Phase A3) — the epoch adoption rules that make
 * the atomic rebootstrap safe: baseline adoption is silent, signal loss is
 * inert, and only a genuine epoch move demands a rebuild.
 */
describe('adoptEpoch', () => {
  it('adopts the first observed epoch as a silent baseline', () => {
    expect(adoptEpoch(null, 'refresh-a')).toEqual({ epoch: 'refresh-a', changed: false });
  });

  it('treats a null signal as inert and keeps the baseline', () => {
    expect(adoptEpoch('refresh-a', null)).toEqual({ epoch: 'refresh-a', changed: false });
    expect(adoptEpoch(null, null)).toEqual({ epoch: null, changed: false });
  });

  it('treats an empty-string signal as inert (no ledger on this deployment)', () => {
    expect(adoptEpoch('refresh-a', '')).toEqual({ epoch: 'refresh-a', changed: false });
    expect(adoptEpoch('', 'refresh-a')).toEqual({ epoch: 'refresh-a', changed: false });
  });

  it('does not demand a rebuild when the epoch is unchanged', () => {
    expect(adoptEpoch('refresh-a', 'refresh-a')).toEqual({ epoch: 'refresh-a', changed: false });
  });

  it('demands exactly one rebuild when the epoch genuinely moves', () => {
    expect(adoptEpoch('refresh-a', 'refresh-b')).toEqual({ epoch: 'refresh-b', changed: true });
    // After adoption the new epoch is the baseline — the next poll is quiet.
    expect(adoptEpoch('refresh-b', 'refresh-b')).toEqual({ epoch: 'refresh-b', changed: false });
  });
});
