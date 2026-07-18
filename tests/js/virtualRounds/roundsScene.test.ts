import { describe, expect, it } from 'vitest';
import type { ProjectionPlacementIndex } from '@/features/patientFlowNavigator/projections';
import {
  buildRoundRoute,
  buildRoundStopCells,
  ROUND_STOP_COLORS,
  type RoundStop,
} from '@/features/virtualRounds/roundsScene';

/**
 * The pure rounds→scene projection (plan §8.1). The three.js `rebuildRounds`
 * mirrors the proven barrier layer shape; the interesting logic — bed-first
 * anchoring with unit-centroid fallback, floor filtering, unplaceable drops —
 * is pinned here without a GPU.
 */

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
    roomAnchors: new Map([['5e-01', { x: 12, y: 2, z: 22 }]]),
    roomFloors: new Map([['5e-01', 3]]),
  };
}

function stop(overrides: Partial<RoundStop>): RoundStop {
  return {
    round_patient_uuid: 'uuid-1',
    status: 'queued',
    priority_band: 6,
    pinned: false,
    discharge_ready: false,
    missing_input: false,
    queue_position: 1,
    unit_id: 5,
    facility_space_id: null,
    bed: null,
    ...overrides,
  };
}

describe('buildRoundStopCells', () => {
  it('anchors at the bed location when the bed label resolves', () => {
    const cells = buildRoundStopCells([stop({ bed: '5E-01' })], index(), 'all');
    expect(cells).toHaveLength(1);
    expect(cells[0].anchor).toEqual({ x: 12, y: 2, z: 22 });
  });

  it('falls back to the unit centroid when the bed is unknown', () => {
    const cells = buildRoundStopCells([stop({ bed: 'ZZ-99' })], index(), 'all');
    expect(cells).toHaveLength(1);
    expect(cells[0].anchor).toEqual({ x: 10, y: 2, z: 20 });
  });

  it('drops stops with no resolvable anchor', () => {
    const cells = buildRoundStopCells([stop({ unit_id: 99, bed: null })], index(), 'all');
    expect(cells).toHaveLength(0);
  });

  it('applies the floor filter from the resolved anchor floor', () => {
    const stops = [
      stop({ round_patient_uuid: 'floor3', unit_id: 5 }),
      stop({ round_patient_uuid: 'floor4', unit_id: 7 }),
    ];
    const cells = buildRoundStopCells(stops, index(), '3');
    expect(cells.map((c) => c.stop.round_patient_uuid)).toEqual(['floor3']);
  });

  it('keeps every stop when the floor filter is all', () => {
    const stops = [
      stop({ round_patient_uuid: 'a', unit_id: 5 }),
      stop({ round_patient_uuid: 'b', unit_id: 7 }),
    ];
    expect(buildRoundStopCells(stops, index(), 'all')).toHaveLength(2);
  });

  it('defines a cool-tone color for every round state (coral never appears)', () => {
    const CORAL = 0xf06755;
    for (const status of [
      'queued',
      'in_progress',
      'awaiting_input',
      'ready_for_review',
      'rounded',
      'deferred',
      'skipped',
    ] as const) {
      expect(ROUND_STOP_COLORS[status]).toBeDefined();
      expect(ROUND_STOP_COLORS[status]).not.toBe(CORAL);
    }
  });

  it('carries the resolved floor on each cell for route grouping (R-4)', () => {
    const cells = buildRoundStopCells([stop({ bed: '5E-01' }), stop({ round_patient_uuid: 'u7', unit_id: 7, bed: null })], index(), 'all');
    expect(cells.map((cell) => cell.floor)).toEqual([3, 4]);
  });
});

describe('buildRoundRoute (R-4)', () => {
  it('connects same-floor stops in queue order as one solid run', () => {
    const cells = buildRoundStopCells(
      [
        stop({ round_patient_uuid: 'second', unit_id: 5, queue_position: 2, bed: '5E-01' }),
        stop({ round_patient_uuid: 'first', unit_id: 5, queue_position: 1 }),
      ],
      index(),
      'all',
    );
    const route = buildRoundRoute(cells);
    expect(route).toHaveLength(1);
    expect(route[0].dashed).toBe(false);
    // Queue order, not input order: unit centroid (pos 1) then bed (pos 2).
    expect(route[0].points).toEqual([
      { x: 10, y: 2, z: 20 },
      { x: 12, y: 2, z: 22 },
    ]);
  });

  it('emits a dashed leg for a floor change — stable ordering, not path-finding', () => {
    const cells = buildRoundStopCells(
      [
        stop({ round_patient_uuid: 'f3a', unit_id: 5, queue_position: 1 }),
        stop({ round_patient_uuid: 'f3b', unit_id: 5, queue_position: 2, bed: '5E-01' }),
        stop({ round_patient_uuid: 'f4', unit_id: 7, queue_position: 3 }),
      ],
      index(),
      'all',
    );
    const route = buildRoundRoute(cells);
    expect(route.map((segment) => segment.dashed)).toEqual([false, true]);
    expect(route[1].points).toEqual([
      { x: 12, y: 2, z: 22 },
      { x: -5, y: 2, z: 8 },
    ]);
  });

  it('excludes skipped and deferred stops from the walk (R-6a skip rule)', () => {
    const cells = buildRoundStopCells(
      [
        stop({ round_patient_uuid: 'a', unit_id: 5, queue_position: 1 }),
        stop({ round_patient_uuid: 'skip', unit_id: 5, queue_position: 2, bed: '5E-01', status: 'skipped' }),
        stop({ round_patient_uuid: 'defer', unit_id: 7, queue_position: 3, status: 'deferred' }),
        stop({ round_patient_uuid: 'b', unit_id: 5, queue_position: 4, bed: '5E-01' }),
      ],
      index(),
      'all',
    );
    const route = buildRoundRoute(cells);
    expect(route).toHaveLength(1);
    expect(route[0].points).toHaveLength(2);
    expect(route[0].dashed).toBe(false);
  });

  it('yields no segments for a single placeable stop', () => {
    const cells = buildRoundStopCells([stop({})], index(), 'all');
    expect(buildRoundRoute(cells)).toEqual([]);
  });
});
