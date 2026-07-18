// Placement logic for the 4D Rounds overlay (plan §8.1). Pure data — no
// three.js — mirroring the barrier-cell seam so it stays unit-testable and in
// the main bundle while the scene chunk lazy-loads.
import type {
  ProjectionAnchor,
  ProjectionPlacementIndex,
} from '@/features/patientFlowNavigator/projections';
import { z } from 'zod';
import { roundStopSchema } from './schemas';

export type RoundStop = z.infer<typeof roundStopSchema>;

export interface RoundStopCell {
  anchor: ProjectionAnchor;
  stop: RoundStop;
  /** Resolved floor for the anchor (route grouping); null when unknown. */
  floor: number | null;
}

/**
 * Ring color by round state — cool operational tones; coral never appears
 * here (a round state is work, not a breach). Shape (torus ring) already
 * distinguishes the layer; color adds state, and the inspector carries the
 * label so state is never color-alone.
 */
export const ROUND_STOP_COLORS: Record<RoundStop['status'], number> = {
  queued: 0x94a3b8, // slate
  in_progress: 0x60a5fa, // blue
  awaiting_input: 0xeaa640, // amber — waiting on a required input
  ready_for_review: 0x38bdf8, // sky
  rounded: 0x2dd4bf, // teal
  deferred: 0x64748b, // dim slate
  skipped: 0x64748b, // dim slate
};

/**
 * Place round stops on the model: the bed's own anchor when the bed label
 * resolves to a scene location, else the unit centroid. Stops with neither
 * anchor are dropped here but remain in the board/HUD counts.
 */
export function buildRoundStopCells(
  stops: RoundStop[],
  index: ProjectionPlacementIndex,
  floorFilter: string,
): RoundStopCell[] {
  const cells: RoundStopCell[] = [];

  for (const stop of stops) {
    let anchor: ProjectionAnchor | null = null;
    let floor: number | null = null;

    if (stop.bed) {
      const key = stop.bed.trim().toLowerCase();
      anchor = index.roomAnchors.get(key) ?? null;
      floor = index.roomFloors.get(key) ?? null;
    }

    if (!anchor && stop.unit_id !== null) {
      anchor = index.unitAnchors.get(stop.unit_id) ?? null;
      floor = index.unitFloors.get(stop.unit_id) ?? null;
    }

    if (!anchor) continue;

    if (floorFilter !== 'all' && (floor === null || String(floor) !== floorFilter)) {
      continue;
    }

    cells.push({ anchor, stop, floor });
  }

  return cells;
}

// ---------------------------------------------------------------------------
// Route polyline (R-4): stable-ordering visualization of the itinerary —
// NOT path-finding (routing graphs are explicitly deferred). Solid runs
// connect consecutive same-floor stops; a floor change emits a dashed leg.
// Skipped/deferred stops are not part of the walk.
// ---------------------------------------------------------------------------

export interface RoundRouteSegment {
  points: ProjectionAnchor[];
  dashed: boolean;
}

export function buildRoundRoute(cells: RoundStopCell[]): RoundRouteSegment[] {
  const ordered = cells
    .filter((cell) => !['skipped', 'deferred'].includes(cell.stop.status))
    .sort((a, b) => a.stop.queue_position - b.stop.queue_position);

  const segments: RoundRouteSegment[] = [];
  let run: ProjectionAnchor[] = [];
  let runFloor: number | null = null;

  for (const cell of ordered) {
    if (run.length === 0) {
      run = [cell.anchor];
      runFloor = cell.floor;
      continue;
    }
    const floorChanged = cell.floor !== null && runFloor !== null && cell.floor !== runFloor;
    if (floorChanged) {
      if (run.length > 1) segments.push({ points: run, dashed: false });
      segments.push({ points: [run[run.length - 1], cell.anchor], dashed: true });
      run = [cell.anchor];
      runFloor = cell.floor;
    } else {
      run.push(cell.anchor);
    }
  }
  if (run.length > 1) segments.push({ points: run, dashed: false });

  return segments;
}
