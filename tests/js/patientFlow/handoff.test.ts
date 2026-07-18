import { afterEach, describe, expect, it } from 'vitest';
import { parseHandoff } from '@/Components/PatientFlowNavigator/PatientFlowNavigator';

/** R-1 deep-link parsing: board → 4D handoff params. */

function setSearch(search: string): void {
  window.history.replaceState(null, '', `/rtdc/patient-flow-navigator${search}`);
}

describe('parseHandoff', () => {
  afterEach(() => setSearch(''));

  it('reads focus_stop alongside the existing scope/t params', () => {
    setSearch('?focus_stop=abc-123&scope=floor:3&t=2026-07-18T12:00:00Z');
    const handoff = parseHandoff();
    expect(handoff.focusStop).toBe('abc-123');
    expect(handoff.floor).toBe('3');
    expect(handoff.t).toBe(Date.parse('2026-07-18T12:00:00Z'));
  });

  it('yields null focus_stop when absent', () => {
    setSearch('?scope=unit:5e');
    const handoff = parseHandoff();
    expect(handoff.focusStop).toBeNull();
    expect(handoff.unitRef).toBe('5e');
  });
});
