import { describe, expect, it } from 'vitest';
import {
  densityGrid,
  gridToWorldCells,
  heatBounds,
  hourlySlices,
} from '@/features/patientFlowNavigator/trailHeat';
import type { PatientFlowEvent, PatientFlowLocation, PatientFlowLocations } from '@/features/patientFlowNavigator/types';

/** E4 — the flatten/analysis model: density grids + hourly slices. */

function loc(code: string, floor: number, x: number, z: number): PatientFlowLocation {
  return {
    facility_space_id: 1,
    location_code: code,
    source_location_code: code,
    name: code,
    category: 'bed',
    floor,
    unit_code: 'U',
    position_m: { x, y: 0, z },
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = {
  'A': loc('A', 3, 0, 0),
  'B': loc('B', 3, 100, 0),
  'C': loc('C', 3, 100, 100),
  'D': loc('D', 2, 0, 0),
};

const T0 = Date.parse('2026-07-27T12:00:00Z');

function ev(patientId: string, to: string, at: number, floor = 3): PatientFlowEvent {
  return {
    event_id: `${patientId}-${at}`,
    event_category: 'movement',
    event_type: 'move',
    patient_id: patientId,
    patient_display_id: patientId,
    encounter_id: `enc-${patientId}`,
    occurred_at: new Date(at).toISOString(),
    to_location: to,
    location_floor: floor,
  } as PatientFlowEvent;
}

function tracksFrom(events: PatientFlowEvent[]): Map<string, PatientFlowEvent[]> {
  const map = new Map<string, PatientFlowEvent[]>();
  for (const event of events) {
    const list = map.get(event.patient_id) ?? [];
    list.push(event);
    map.set(event.patient_id, list);
  }
  return map;
}

describe('heatBounds', () => {
  it('frames only the requested floor with padding', () => {
    const bounds = heatBounds(LOCATIONS, '3');
    expect(bounds).not.toBeNull();
    expect(bounds!.minX).toBeLessThan(0);
    expect(bounds!.maxX).toBeGreaterThan(100);
  });

  it('is null when no placed location matches the floor', () => {
    expect(heatBounds(LOCATIONS, '9')).toBeNull();
  });
});

describe('densityGrid', () => {
  it('bins in-window events into cells and tracks the max', () => {
    const tracks = tracksFrom([
      ev('p1', 'A', T0 + 1000),
      ev('p2', 'A', T0 + 2000),
      ev('p3', 'B', T0 + 3000),
    ]);
    const bounds = heatBounds(LOCATIONS, '3')!;
    const grid = densityGrid(tracks, LOCATIONS, bounds, T0, T0 + 10_000, 8, 8, '3');
    const total = grid.cells.reduce((sum, count) => sum + count, 0);
    expect(total).toBe(3);
    expect(grid.max).toBe(2); // two events landed on A's cell
  });

  it('excludes events outside the time window', () => {
    const tracks = tracksFrom([ev('p1', 'A', T0 - 10_000), ev('p2', 'A', T0 + 1000)]);
    const bounds = heatBounds(LOCATIONS, '3')!;
    const grid = densityGrid(tracks, LOCATIONS, bounds, T0, T0 + 10_000, 8, 8, '3');
    expect(grid.cells.reduce((sum, count) => sum + count, 0)).toBe(1);
  });

  it('excludes other-floor events when a floor is set', () => {
    const tracks = tracksFrom([ev('p1', 'D', T0 + 1000, 2), ev('p2', 'A', T0 + 1000, 3)]);
    const bounds = heatBounds(LOCATIONS, '3')!;
    const grid = densityGrid(tracks, LOCATIONS, bounds, T0, T0 + 10_000, 8, 8, '3');
    expect(grid.cells.reduce((sum, count) => sum + count, 0)).toBe(1);
  });
});

describe('hourlySlices', () => {
  it('produces `hours` slices, oldest first, each labeled and patient-counted', () => {
    const end = T0;
    const tracks = tracksFrom([
      ev('p1', 'A', end - 30 * 60_000), // 30 min ago → newest slice
      ev('p2', 'B', end - 90 * 60_000), // 90 min ago → second-newest
    ]);
    const bounds = heatBounds(LOCATIONS, '3')!;
    const slices = hourlySlices(tracks, LOCATIONS, bounds, end, 6, '3');
    expect(slices).toHaveLength(6);
    // Oldest first, latest last.
    expect(slices[0].startMs).toBeLessThan(slices[5].startMs);
    // The last slice (0–1h ago) holds p1; the second-to-last (1–2h ago) holds p2.
    expect(slices[5].patients).toBe(1);
    expect(slices[4].patients).toBe(1);
    expect(slices[0].patients).toBe(0);
  });
});

describe('gridToWorldCells', () => {
  it('emits only non-empty cells with normalized intensity and world centers', () => {
    const bounds = { minX: 0, maxX: 100, minZ: 0, maxZ: 100 };
    const grid = { cols: 2, rows: 2, cells: [0, 4, 0, 2], max: 4 };
    const cells = gridToWorldCells(grid, bounds, 0.5);
    expect(cells).toHaveLength(2);
    const hottest = cells.find((cell) => cell.intensity === 1)!;
    expect(hottest.x).toBeCloseTo(75); // col 1 center of a 2-col grid over 0..100
    expect(hottest.z).toBeCloseTo(25); // row 0 center
    expect(cells.every((cell) => cell.y === 0.5)).toBe(true);
  });

  it('is empty when the grid has no density', () => {
    expect(gridToWorldCells({ cols: 2, rows: 2, cells: [0, 0, 0, 0], max: 0 }, { minX: 0, maxX: 1, minZ: 0, maxZ: 1 })).toEqual([]);
  });
});
