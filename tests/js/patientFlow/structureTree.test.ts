import { describe, expect, it } from 'vitest';
import {
  buildStructureTree,
  flattenVisible,
  parentIdOf,
} from '@/features/patientFlowNavigator/structureTree';
import type { PatientFlowLocation, PatientFlowLocations } from '@/features/patientFlowNavigator/types';

/** E3 — the floor→unit→bed structure model, keyboard-walked. */

function loc(overrides: Partial<PatientFlowLocation> & { location_code: string }): PatientFlowLocation {
  return {
    facility_space_id: 1,
    source_location_code: overrides.location_code,
    name: overrides.location_code,
    category: 'bed',
    floor: 3,
    unit_code: '5E',
    position_m: { x: 0, y: 0, z: 0 },
    ...overrides,
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = {
  '3-5E-01': loc({ location_code: '3-5E-01', name: 'Bed 01', floor: 3, unit_code: '5e', position_m: { x: 10, y: 0, z: 10 } }),
  '3-5E-02': loc({ location_code: '3-5E-02', name: 'Bed 02', floor: 3, unit_code: '5e', position_m: { x: 12, y: 0, z: 10 } }),
  '3-ICU-01': loc({ location_code: '3-ICU-01', name: 'ICU A', floor: 3, unit_code: 'icu', position_m: { x: 20, y: 0, z: 20 } }),
  '2-ED-01': loc({ location_code: '2-ED-01', name: 'ED 1', floor: 2, unit_code: 'ed', category: 'ed', position_m: { x: 5, y: 0, z: 5 } }),
  'lift-1': loc({ location_code: 'lift-1', name: 'Elevator', floor: 2, unit_code: null, category: 'elevator' }),
  'nofloor': loc({ location_code: 'nofloor', name: 'Limbo', floor: null, unit_code: 'x' }),
};

describe('buildStructureTree', () => {
  it('groups floor → unit → bed, floors ascending and units alphabetized', () => {
    const tree = buildStructureTree(LOCATIONS);
    expect(tree.map((floor) => floor.label)).toEqual(['Floor 2', 'Floor 3']);

    const floor3 = tree.find((node) => node.id === 'floor:3')!;
    expect(floor3.children.map((unit) => unit.label)).toEqual(['5E', 'ICU']);

    const unit5e = floor3.children.find((unit) => unit.label === '5E')!;
    expect(unit5e.children.map((bed) => bed.label)).toEqual(['Bed 01', 'Bed 02']);
    expect(unit5e.children[0].locationCode).toBe('3-5E-01');
  });

  it('excludes infrastructure (elevators) and floorless locations', () => {
    const tree = buildStructureTree(LOCATIONS);
    const codes = tree.flatMap((f) => f.children.flatMap((u) => u.children.map((b) => b.locationCode)));
    expect(codes).not.toContain('lift-1');
    expect(codes).not.toContain('nofloor');
  });

  it('derives unit and floor anchor positions from descendant beds', () => {
    const tree = buildStructureTree(LOCATIONS);
    const unit5e = tree.find((f) => f.id === 'floor:3')!.children.find((u) => u.label === '5E')!;
    expect(unit5e.position).toEqual({ x: 11, y: 0, z: 10 }); // centroid of beds 01/02
    const floor3 = tree.find((f) => f.id === 'floor:3')!;
    expect(floor3.position).not.toBeNull();
  });

  it('returns an empty tree for empty locations', () => {
    expect(buildStructureTree({})).toEqual([]);
  });
});

describe('flattenVisible', () => {
  it('shows only roots when nothing is expanded', () => {
    const tree = buildStructureTree(LOCATIONS);
    const rows = flattenVisible(tree, new Set());
    expect(rows).toHaveLength(2);
    expect(rows.every((row) => row.depth === 0)).toBe(true);
    expect(rows[0].expanded).toBe(false); // floors are expandable
  });

  it('reveals a floor\'s units when expanded, beds when the unit is too', () => {
    const tree = buildStructureTree(LOCATIONS);
    const rows = flattenVisible(tree, new Set(['floor:3', 'unit:3:5E']));
    const labels = rows.map((row) => row.node.label);
    expect(labels).toContain('5E');
    expect(labels).toContain('Bed 01');
    // Floor 2 stays collapsed — its ED bed is not shown.
    expect(labels).not.toContain('ED 1');
    // Beds are leaves — no expanded flag.
    const bed = rows.find((row) => row.node.label === 'Bed 01')!;
    expect(bed.expanded).toBeUndefined();
    expect(bed.depth).toBe(2);
  });
});

describe('parentIdOf', () => {
  it('maps a unit to its floor and a floor to null', () => {
    expect(parentIdOf('unit:3:5E')).toBe('floor:3');
    expect(parentIdOf('floor:3')).toBeNull();
  });
});
