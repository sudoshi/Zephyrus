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

  it('reads the E5 view-state params alongside the legacy grammar', () => {
    setSearch('?scope=floor:2&cam=10,20,30,0,5,0&layers=base,heat&census=delayed&win=6h&sel=occupancy:ED-04&wall=1');
    const handoff = parseHandoff();
    expect(handoff.floor).toBe('2');
    expect(handoff.camera).toEqual({ position: { x: 10, y: 20, z: 30 }, target: { x: 0, y: 5, z: 0 } });
    expect(handoff.layers?.base).toBe(true);
    expect(handoff.layers?.heat).toBe(true);
    expect(handoff.layers?.tokens).toBe(false);
    expect(handoff.censusScope).toBe('delayed');
    expect(handoff.windowPreset).toBe('6h');
    expect(handoff.selection).toEqual({ kind: 'occupancy', id: 'ED-04' });
    expect(handoff.wall).toBe(true);
  });

  it('degrades garbage view-state params to nulls', () => {
    setSearch('?cam=bogus&census=nope&win=90h&sel=patient:raw-mrn-123');
    const handoff = parseHandoff();
    expect(handoff.camera).toBeNull();
    expect(handoff.censusScope).toBeNull();
    expect(handoff.windowPreset).toBeNull();
    // `sel=` refuses person kinds — a patient travels only as an opaque ptok.
    expect(handoff.selection).toBeNull();
  });
});
