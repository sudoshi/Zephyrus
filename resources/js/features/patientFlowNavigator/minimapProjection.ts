// Minimap projection (E1): a top-down 2D schematic of the current floor. Maps
// world XZ (plan view) to a unit square and back, so the minimap can place
// dots/pips/the camera footprint and turn a click back into a world point to
// fly to. Pure + padded bounds; pinned by minimapProjection.test.ts.

export interface Bounds2D {
  minX: number;
  maxX: number;
  minZ: number;
  maxZ: number;
}

export interface MinimapProjector {
  bounds: Bounds2D;
  /** World (x,z) → normalized minimap coords in [0,1] (v grows with +z / south). */
  project: (x: number, z: number) => { u: number; v: number };
  /** Normalized [0,1] minimap coords → world (x, z). */
  unproject: (u: number, v: number) => { x: number; z: number };
}

const MIN_SPAN = 1;

/** Padded XZ bounds over a point set; `pad` is a fraction of the larger span. */
export function computeBounds(
  points: Array<{ x: number; z: number }>,
  pad = 0.08,
): Bounds2D | null {
  if (points.length === 0) return null;
  let minX = Infinity;
  let maxX = -Infinity;
  let minZ = Infinity;
  let maxZ = -Infinity;
  for (const point of points) {
    minX = Math.min(minX, point.x);
    maxX = Math.max(maxX, point.x);
    minZ = Math.min(minZ, point.z);
    maxZ = Math.max(maxZ, point.z);
  }
  const spanX = Math.max(MIN_SPAN, maxX - minX);
  const spanZ = Math.max(MIN_SPAN, maxZ - minZ);
  const padX = spanX * pad;
  const padZ = spanZ * pad;
  return { minX: minX - padX, maxX: maxX + padX, minZ: minZ - padZ, maxZ: maxZ + padZ };
}

/** Build a projector for the given bounds (or a point set via computeBounds). */
export function buildProjector(bounds: Bounds2D): MinimapProjector {
  const spanX = Math.max(MIN_SPAN, bounds.maxX - bounds.minX);
  const spanZ = Math.max(MIN_SPAN, bounds.maxZ - bounds.minZ);
  return {
    bounds,
    project: (x, z) => ({
      u: (x - bounds.minX) / spanX,
      v: (z - bounds.minZ) / spanZ,
    }),
    unproject: (u, v) => ({
      x: bounds.minX + u * spanX,
      z: bounds.minZ + v * spanZ,
    }),
  };
}

/** Convenience: projector straight from a point set, or null if empty. */
export function projectorForPoints(
  points: Array<{ x: number; z: number }>,
  pad?: number,
): MinimapProjector | null {
  const bounds = computeBounds(points, pad);
  return bounds ? buildProjector(bounds) : null;
}
