import { describe, expect, it } from 'vitest';
import { RISK_TO_STATUS, statusForRisk } from '@/Components/cockpit/riskStatus';

// P6 WS-6 — the one tier↔state mapping, mirrored server-side by
// EddyApprovalNotifier::statusForRisk. If this table changes, both sides
// change together.
describe('riskStatus', () => {
  it('maps the four catalog risks onto the plan §P6.6 states', () => {
    expect(RISK_TO_STATUS).toEqual({
      critical: 'crit',
      high: 'warn',
      medium: 'watch',
      low: 'ok',
    });
  });

  it('never escalates on uncertainty', () => {
    expect(statusForRisk('unknown')).toBe('watch');
    expect(statusForRisk(null)).toBe('watch');
    expect(statusForRisk(undefined)).toBe('watch');
  });
});
