import { describe, expect, it } from 'vitest';
import {
  AGGREGATE_TRAIL_THRESHOLD,
  tallyUnitFlows,
  unitCentroids,
} from '@/features/patientFlowNavigator/flowAggregation';
import type { PatientFlowEvent, PatientFlowLocation, PatientFlowLocations } from '@/features/patientFlowNavigator/types';

/** F2(b) — the aggregate-trails tally that replaces spaghetti above threshold. */

function loc(code: string, unit: string, x: number, z: number): PatientFlowLocation {
  return {
    facility_space_id: 1,
    location_code: code,
    source_location_code: code,
    name: code,
    category: 'bed',
    floor: 3,
    unit_code: unit,
    position_m: { x, y: 0, z },
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = {
  ED1: loc('ED1', 'ED', 0, 0),
  ED2: loc('ED2', 'ED', 10, 0),
  ICU1: loc('ICU1', 'ICU', 100, 0),
  W1: loc('W1', '5W', 200, 0),
};

const T0 = Date.parse('2026-07-27T12:00:00Z');

function ev(patientId: string, to: string, at: number): PatientFlowEvent {
  return {
    event_id: `${patientId}-${at}`,
    event_category: 'movement',
    event_type: 'move',
    patient_id: patientId,
    patient_display_id: patientId,
    encounter_id: `enc-${patientId}`,
    occurred_at: new Date(at).toISOString(),
    to_location: to,
  } as PatientFlowEvent;
}

function tracks(events: PatientFlowEvent[]): Map<string, PatientFlowEvent[]> {
  const map = new Map<string, PatientFlowEvent[]>();
  for (const e of events) {
    const list = map.get(e.patient_id) ?? [];
    list.push(e);
    map.set(e.patient_id, list);
  }
  return map;
}

describe('unitCentroids', () => {
  it('averages each unit\'s placed locations', () => {
    const centroids = unitCentroids(LOCATIONS);
    expect(centroids.get('ED')).toEqual({ x: 5, y: 0, z: 0 }); // mean of ED1(0) + ED2(10)
    expect(centroids.get('ICU')).toEqual({ x: 100, y: 0, z: 0 });
  });
});

describe('tallyUnitFlows', () => {
  it('counts directed unit→unit transitions, ignoring same-unit hops', () => {
    const t = tracks([
      // p1: ED → ED (same unit, ignored) → ICU
      ev('p1', 'ED1', T0 + 1), ev('p1', 'ED2', T0 + 2), ev('p1', 'ICU1', T0 + 3),
      // p2: ED → ICU
      ev('p2', 'ED1', T0 + 1), ev('p2', 'ICU1', T0 + 2),
      // p3: ED → 5W
      ev('p3', 'ED1', T0 + 1), ev('p3', 'W1', T0 + 2),
    ]);
    const active = new Set(['p1', 'p2', 'p3']);
    const { flows, maxCount } = tallyUnitFlows(t, LOCATIONS, active, T0 + 10);

    const edToIcu = flows.find((f) => f.from === 'ED' && f.to === 'ICU');
    expect(edToIcu?.count).toBe(2);
    expect(flows.find((f) => f.from === 'ED' && f.to === '5W')?.count).toBe(1);
    // No same-unit ED→ED edge.
    expect(flows.find((f) => f.from === 'ED' && f.to === 'ED')).toBeUndefined();
    expect(maxCount).toBe(2);
    // Sorted by descending count.
    expect(flows[0].count).toBe(2);
  });

  it('excludes inactive patients and future events', () => {
    const t = tracks([
      ev('p1', 'ED1', T0 + 1), ev('p1', 'ICU1', T0 + 2),
      ev('p2', 'ED1', T0 + 1), ev('p2', 'ICU1', T0 + 5000),
    ]);
    // p2 inactive; p1's ICU move at T0+2 counts, nothing after the window.
    const { flows } = tallyUnitFlows(t, LOCATIONS, new Set(['p1']), T0 + 3);
    expect(flows).toHaveLength(1);
    expect(flows[0]).toMatchObject({ from: 'ED', to: 'ICU', count: 1 });
  });

  it('returns maxCount ≥ 1 even with no flows (safe divisor)', () => {
    const { flows, maxCount } = tallyUnitFlows(new Map(), LOCATIONS, new Set(), T0);
    expect(flows).toEqual([]);
    expect(maxCount).toBe(1);
  });

  it('pins the guard threshold at 50', () => {
    expect(AGGREGATE_TRAIL_THRESHOLD).toBe(50);
  });
});
