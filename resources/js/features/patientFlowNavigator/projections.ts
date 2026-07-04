import type {
  FlowUnitSummary,
  PatientFlowLocations,
  ProjectionConfidence,
  ProjectionItem,
} from './types';

/**
 * Placement + selection helpers for the projection ghost layer
 * (FLOW-WINDOW-PLAN §5 ghost grammar, §7.3). Pure data — no three.js — so it
 * stays in the main bundle while the scene chunk is lazy-loaded.
 */

export interface ProjectionAnchor {
  x: number;
  y: number;
  z: number;
}

/** Kinds rendered as per-entity ghost tokens at a spatial anchor. */
export const ENTITY_PROJECTION_KINDS: ReadonlyArray<ProjectionItem['kind']> = [
  'expected_discharge',
  'transport_due',
  'evs_due',
  'scheduled_or_case',
];

/** Kinds rendered as an aggregate forecast layer / HUD strip, never tokens. */
export const AGGREGATE_PROJECTION_KINDS: ReadonlyArray<ProjectionItem['kind']> = [
  'predicted_census',
  'predicted_arrivals',
  'surge_probability',
  'staffing_shift_gap',
];

/** Confidence → ghost opacity (plan §5: definite 0.8 / probable 0.5 / possible 0.3). */
export function confidenceOpacity(confidence: ProjectionConfidence | string): number {
  switch (confidence) {
    case 'definite':
      return 0.8;
    case 'probable':
      return 0.5;
    default:
      return 0.3;
  }
}

export interface ProjectionPlacementIndex {
  unitAnchors: Map<number, ProjectionAnchor>;
  unitFloors: Map<number, number>;
  unitCodeById: Map<number, string>;
  roomAnchors: Map<string, ProjectionAnchor>;
  roomFloors: Map<string, number>;
}

/**
 * Join the /locations payload (unit_code, room names, positions) with the
 * unit_id ↔ unit_code bridge from the Inertia prop so projections — which
 * carry unit_id / bed_id / room — resolve to scene positions.
 */
export function buildProjectionPlacementIndex(
  locations: PatientFlowLocations,
  units: FlowUnitSummary[],
): ProjectionPlacementIndex {
  const byUnitCode = new Map<string, { sum: ProjectionAnchor; count: number; floor: number | null }>();
  const roomAnchors = new Map<string, ProjectionAnchor>();
  const roomFloors = new Map<string, number>();

  for (const location of Object.values(locations)) {
    const position = location.position_m;
    if (!position) continue;
    const anchor: ProjectionAnchor = { x: position.x, y: (position.y ?? 0) + 1.7, z: position.z };

    const unitCode = location.unit_code?.toLowerCase();
    if (unitCode) {
      const entry = byUnitCode.get(unitCode) ?? { sum: { x: 0, y: 0, z: 0 }, count: 0, floor: null };
      entry.sum.x += anchor.x;
      entry.sum.y += anchor.y;
      entry.sum.z += anchor.z;
      entry.count += 1;
      if (entry.floor === null && location.floor !== null && location.floor !== undefined) {
        entry.floor = location.floor;
      }
      byUnitCode.set(unitCode, entry);
    }

    for (const key of [location.name, location.location_code]) {
      if (!key) continue;
      const normalized = key.trim().toLowerCase();
      if (!roomAnchors.has(normalized)) {
        roomAnchors.set(normalized, anchor);
        if (location.floor !== null && location.floor !== undefined) {
          roomFloors.set(normalized, location.floor);
        }
      }
    }
  }

  const unitAnchors = new Map<number, ProjectionAnchor>();
  const unitFloors = new Map<number, number>();
  const unitCodeById = new Map<number, string>();

  for (const unit of units) {
    const code = unit.unit_code?.toLowerCase();
    if (!code) continue;
    unitCodeById.set(unit.unit_id, code);
    const entry = byUnitCode.get(code);
    if (entry && entry.count > 0) {
      unitAnchors.set(unit.unit_id, {
        x: entry.sum.x / entry.count,
        y: entry.sum.y / entry.count,
        z: entry.sum.z / entry.count,
      });
    }
    const floor = unit.floor ?? entry?.floor ?? null;
    if (floor !== null) unitFloors.set(unit.unit_id, floor);
  }

  return { unitAnchors, unitFloors, unitCodeById, roomAnchors, roomFloors };
}

/** Scene anchor for an entity-bearing projection, or null when unplaceable. */
export function anchorForProjection(
  item: ProjectionItem,
  index: ProjectionPlacementIndex,
): ProjectionAnchor | null {
  if (item.kind === 'scheduled_or_case') {
    return item.room ? index.roomAnchors.get(item.room.trim().toLowerCase()) ?? null : null;
  }
  if (item.unit_id !== null) {
    return index.unitAnchors.get(item.unit_id) ?? null;
  }
  return null;
}

/** Floor a projection belongs to (for the floor filter), or null when unknown. */
export function floorForProjection(
  item: ProjectionItem,
  index: ProjectionPlacementIndex,
): number | null {
  if (item.kind === 'scheduled_or_case' && item.room) {
    return index.roomFloors.get(item.room.trim().toLowerCase()) ?? null;
  }
  if (item.unit_id !== null) {
    return index.unitFloors.get(item.unit_id) ?? null;
  }
  return null;
}

/**
 * Ghosts accumulated by scrubbing into the future half: every projection with
 * now < t ≤ scrub time — the symmetric grammar to past event accumulation.
 */
export function ghostsAt(items: ProjectionItem[], nowMs: number, timeMs: number): ProjectionItem[] {
  if (timeMs <= nowMs) return [];
  return items.filter((item) => {
    const t = Date.parse(item.t);
    return Number.isFinite(t) && t > nowMs && t <= timeMs;
  });
}

export interface ForecastAggregates {
  /** Per-unit predicted census at the bucket closest to (≤) scrub time. */
  censusByUnit: Map<number, ProjectionItem>;
  censusTotal: number | null;
  arrivals: ProjectionItem | null;
  surge: ProjectionItem | null;
  staffingGaps: number;
}

/** House-level aggregates for the forecast HUD strip + heat layer at scrub t. */
export function aggregatesAt(
  items: ProjectionItem[],
  nowMs: number,
  timeMs: number,
): ForecastAggregates {
  const empty: ForecastAggregates = {
    censusByUnit: new Map(),
    censusTotal: null,
    arrivals: null,
    surge: null,
    staffingGaps: 0,
  };
  if (timeMs <= nowMs) return empty;

  const censusByUnit = new Map<number, ProjectionItem>();
  let arrivals: ProjectionItem | null = null;
  let surge: ProjectionItem | null = null;
  let staffingGaps = 0;

  for (const item of items) {
    const t = Date.parse(item.t);
    if (!Number.isFinite(t) || t > timeMs) continue;

    if (item.kind === 'predicted_census' && item.unit_id !== null) {
      const existing = censusByUnit.get(item.unit_id);
      if (!existing || Date.parse(existing.t) < t) censusByUnit.set(item.unit_id, item);
    } else if (item.kind === 'predicted_arrivals') {
      if (!arrivals || Date.parse(arrivals.t) < t) arrivals = item;
    } else if (item.kind === 'surge_probability') {
      if (!surge || Date.parse(surge.t) < t) surge = item;
    } else if (item.kind === 'staffing_shift_gap') {
      const ends = item.ends_at ? Date.parse(item.ends_at) : t;
      if (timeMs <= ends) staffingGaps += 1;
    }
  }

  let censusTotal: number | null = null;
  for (const item of censusByUnit.values()) {
    if (item.value !== null) censusTotal = (censusTotal ?? 0) + item.value;
  }

  return { censusByUnit, censusTotal, arrivals, surge, staffingGaps };
}
