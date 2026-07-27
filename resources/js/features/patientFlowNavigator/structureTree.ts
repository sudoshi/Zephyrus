// Structure traversal (E3): a floor → unit → bed hierarchy over the navigator's
// locations, for Data-Navigator-style keyboard walking of the building without
// the pointer. Pure model — buildStructureTree + flattenVisible are unit-tested;
// NavigatorStructureNav renders the flattened rows with roving arrow-key focus
// and selects through the SAME selectEntity seam a canvas click uses.
import type { PatientFlowLocation, PatientFlowLocations } from './types';

export type StructureKind = 'floor' | 'unit' | 'bed';

export interface StructureNode {
  /** Stable id — `floor:{n}` / `unit:{floor}:{code}` / `bed:{locationCode}`. */
  id: string;
  kind: StructureKind;
  label: string;
  /** For a bed: the location code (selects the occupancy entity). */
  locationCode: string | null;
  /** Anchor to frame this node's points; null for beds without geometry. */
  position: { x: number; y: number; z: number } | null;
  /** Floor number this node belongs to, for the camera-frame fallback. */
  floor: number | null;
  children: StructureNode[];
}

export interface FlatRow {
  node: StructureNode;
  depth: number;
  /** Undefined for beds (leaves); true/false for expandable rows. */
  expanded?: boolean;
}

function positionOf(loc: PatientFlowLocation): { x: number; y: number; z: number } | null {
  return loc.position_m ? { x: loc.position_m.x, y: loc.position_m.y ?? 0, z: loc.position_m.z } : null;
}

function averagePosition(
  positions: Array<{ x: number; y: number; z: number }>,
): { x: number; y: number; z: number } | null {
  if (positions.length === 0) return null;
  const sum = positions.reduce(
    (acc, point) => ({ x: acc.x + point.x, y: acc.y + point.y, z: acc.z + point.z }),
    { x: 0, y: 0, z: 0 },
  );
  return { x: sum.x / positions.length, y: sum.y / positions.length, z: sum.z / positions.length };
}

/**
 * Build the floor → unit → bed tree from the navigator's locations. Beds sort
 * by name; units by code; floors ascending. Locations without a floor or a
 * bed-like category (rooms/beds/ED spaces) are grouped under their unit; pure
 * infra (elevators, corridors) is excluded — it is not a wayfinding target.
 */
export function buildStructureTree(locations: PatientFlowLocations): StructureNode[] {
  const EXCLUDED = new Set(['elevator', 'corridor', 'floor']);
  const byFloor = new Map<number, Map<string, PatientFlowLocation[]>>();

  for (const loc of Object.values(locations)) {
    if (loc.floor === null || loc.floor === undefined) continue;
    if (EXCLUDED.has(loc.category)) continue;
    const unitCode = (loc.unit_code ?? 'Unassigned').toUpperCase();
    let units = byFloor.get(loc.floor);
    if (!units) {
      units = new Map();
      byFloor.set(loc.floor, units);
    }
    const beds = units.get(unitCode) ?? [];
    beds.push(loc);
    units.set(unitCode, beds);
  }

  return [...byFloor.entries()]
    .sort(([a], [b]) => a - b)
    .map(([floor, units]) => {
      const unitNodes: StructureNode[] = [...units.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([unitCode, beds]) => {
          const bedNodes: StructureNode[] = beds
            .slice()
            .sort((a, b) => (a.name ?? a.location_code).localeCompare(b.name ?? b.location_code))
            .map((loc) => ({
              id: `bed:${loc.location_code}`,
              kind: 'bed' as const,
              label: loc.name ?? loc.location_code,
              locationCode: loc.location_code,
              position: positionOf(loc),
              floor,
              children: [],
            }));
          const unitPos = averagePosition(
            bedNodes.map((bed) => bed.position).filter((p): p is NonNullable<typeof p> => p !== null),
          );
          return {
            id: `unit:${floor}:${unitCode}`,
            kind: 'unit' as const,
            label: unitCode === 'UNASSIGNED' ? 'Unassigned' : unitCode,
            locationCode: null,
            position: unitPos,
            floor,
            children: bedNodes,
          };
        });
      const floorPos = averagePosition(
        unitNodes.map((unit) => unit.position).filter((p): p is NonNullable<typeof p> => p !== null),
      );
      return {
        id: `floor:${floor}`,
        kind: 'floor' as const,
        label: `Floor ${floor}`,
        locationCode: null,
        position: floorPos,
        floor,
        children: unitNodes,
      };
    });
}

/** Flatten the tree to the currently-visible rows given the expanded set. */
export function flattenVisible(nodes: StructureNode[], expanded: Set<string>): FlatRow[] {
  const rows: FlatRow[] = [];
  const walk = (list: StructureNode[], depth: number): void => {
    for (const node of list) {
      const isLeaf = node.children.length === 0;
      rows.push({ node, depth, expanded: isLeaf ? undefined : expanded.has(node.id) });
      if (!isLeaf && expanded.has(node.id)) walk(node.children, depth + 1);
    }
  };
  walk(nodes, 0);
  return rows;
}

/** The parent id of a node id, or null for a floor (root). */
export function parentIdOf(id: string): string | null {
  if (id.startsWith('bed:')) return null; // resolved by scan (a bed's unit id isn't derivable from its code)
  if (id.startsWith('unit:')) {
    const [, floor] = id.split(':');
    return `floor:${floor}`;
  }
  return null;
}
