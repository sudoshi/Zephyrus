import { describe, expect, it } from 'vitest';
import {
  buildProjector,
  computeBounds,
  projectorForPoints,
} from '@/features/patientFlowNavigator/minimapProjection';

/** E1 — the minimap projection round-trips world XZ ↔ minimap [0,1]. */

describe('computeBounds', () => {
  it('pads the extent by a fraction of the larger span', () => {
    const bounds = computeBounds([{ x: 0, z: 0 }, { x: 100, z: 40 }], 0.1);
    expect(bounds).not.toBeNull();
    // spanX 100 → padX 10; spanZ 40 → padZ 4.
    expect(bounds!.minX).toBeCloseTo(-10);
    expect(bounds!.maxX).toBeCloseTo(110);
    expect(bounds!.minZ).toBeCloseTo(-4);
    expect(bounds!.maxZ).toBeCloseTo(44);
  });

  it('returns null for no points', () => {
    expect(computeBounds([])).toBeNull();
  });

  it('never collapses a zero span to a divide-by-zero', () => {
    const bounds = computeBounds([{ x: 5, z: 5 }], 0);
    const projector = buildProjector(bounds!);
    expect(Number.isFinite(projector.project(5, 5).u)).toBe(true);
  });
});

describe('buildProjector round-trip', () => {
  it('project ∘ unproject is identity across the plate', () => {
    const projector = buildProjector({ minX: -20, maxX: 80, minZ: 10, maxZ: 60 });
    for (const [x, z] of [[-20, 10], [80, 60], [30, 35], [0, 20]] as Array<[number, number]>) {
      const { u, v } = projector.project(x, z);
      const back = projector.unproject(u, v);
      expect(back.x).toBeCloseTo(x, 6);
      expect(back.z).toBeCloseTo(z, 6);
    }
  });

  it('maps the bounds corners to the unit square', () => {
    const projector = buildProjector({ minX: 0, maxX: 100, minZ: 0, maxZ: 50 });
    expect(projector.project(0, 0)).toEqual({ u: 0, v: 0 });
    expect(projector.project(100, 50)).toEqual({ u: 1, v: 1 });
    expect(projector.project(50, 25)).toEqual({ u: 0.5, v: 0.5 });
  });
});

describe('projectorForPoints', () => {
  it('is null for an empty set, a projector otherwise', () => {
    expect(projectorForPoints([])).toBeNull();
    expect(projectorForPoints([{ x: 1, z: 2 }])).not.toBeNull();
  });
});
