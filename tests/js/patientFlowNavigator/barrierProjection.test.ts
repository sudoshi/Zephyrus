import { describe, expect, it } from 'vitest';
import {
  barrierSeverity,
  buildBarrierCells,
  type ProjectionPlacementIndex,
} from '@/features/patientFlowNavigator/projections';
import type { NavigatorBarrier } from '@/features/patientFlowNavigator/types';

/**
 * Seam 4b — the pure barrier→scene projection. The three.js `rebuildBarriers`
 * is a faithful mirror of the proven `rebuildForecastHeat`/`rebuildGhosts`
 * shape; the interesting logic (severity band, unit anchoring, floor filter,
 * house-level drop) lives here and is pinned without a GPU.
 */

const NOW = Date.parse('2026-07-11T12:00:00Z');
const HOUR = 3_600_000;

function index(): ProjectionPlacementIndex {
  return {
    unitAnchors: new Map([
      [5, { x: 10, y: 2, z: 20 }],
      [7, { x: -5, y: 2, z: 8 }],
    ]),
    unitFloors: new Map([
      [5, 3],
      [7, 4],
    ]),
    unitCodeById: new Map(),
    roomAnchors: new Map(),
    roomFloors: new Map(),
  };
}

function barrier(overrides: Partial<NavigatorBarrier>): NavigatorBarrier {
  return {
    barrier_id: 1,
    unit_id: 5,
    unit_label: '5 East',
    category: 'placement',
    reason_code: 'no_bed',
    description: 'Isolation bed shortage',
    owner: 'C. Ramos',
    status: 'open',
    opened_at: new Date(NOW - 3 * HOUR).toISOString(),
    encounter_ref: null,
    ...overrides,
  };
}

describe('barrierSeverity', () => {
  it('bands by open-age, mirroring the 48h Review (≥48h crit, ≥24h warn, else watch)', () => {
    expect(barrierSeverity(NOW - 60 * HOUR, NOW)).toBe('critical');
    expect(barrierSeverity(NOW - 48 * HOUR, NOW)).toBe('critical');
    expect(barrierSeverity(NOW - 30 * HOUR, NOW)).toBe('warning');
    expect(barrierSeverity(NOW - 24 * HOUR, NOW)).toBe('warning');
    expect(barrierSeverity(NOW - 5 * HOUR, NOW)).toBe('watch');
  });

  it('treats a missing opened_at as freshly open (watch)', () => {
    expect(barrierSeverity(null, NOW)).toBe('watch');
  });
});

describe('buildBarrierCells', () => {
  it('places a unit-anchored open barrier with its severity', () => {
    const cells = buildBarrierCells([barrier({ opened_at: new Date(NOW - 30 * HOUR).toISOString() })], index(), 'all', NOW);
    expect(cells).toHaveLength(1);
    expect(cells[0].anchor).toEqual({ x: 10, y: 2, z: 20 });
    expect(cells[0].severity).toBe('warning');
    expect(cells[0].barrier.barrier_id).toBe(1);
  });

  it('drops house-level (null unit_id) and un-anchored units — they cannot be placed', () => {
    const cells = buildBarrierCells(
      [
        barrier({ barrier_id: 2, unit_id: null }), // house-level
        barrier({ barrier_id: 3, unit_id: 99 }), // no anchor for unit 99
      ],
      index(),
      'all',
      NOW,
    );
    expect(cells).toHaveLength(0);
  });

  it('drops a resolved barrier defensively even if one slips through', () => {
    const cells = buildBarrierCells([barrier({ status: 'resolved' })], index(), 'all', NOW);
    expect(cells).toHaveLength(0);
  });

  it('honors the floor filter via the unit→floor map', () => {
    const barriers = [barrier({ barrier_id: 1, unit_id: 5 }), barrier({ barrier_id: 2, unit_id: 7 })];
    const onFloor3 = buildBarrierCells(barriers, index(), '3', NOW);
    expect(onFloor3.map((cell) => cell.barrier.barrier_id)).toEqual([1]); // unit 5 is floor 3
  });
});
