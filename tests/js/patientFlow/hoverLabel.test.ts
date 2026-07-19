import { describe, expect, it } from 'vitest';
import {
  CATEGORY_LABELS,
  ELEMENT_LABELS,
  hoverLabelFor,
} from '@/features/patientFlowNavigator/sceneVocabulary';

/**
 * H5.2 — the missing identity guard, pinned as a test.
 *
 * The hover chip is stricter than the inspector: the inspector redacts by
 * lens policy, the hover chip NEVER carries identity for anyone. That was
 * previously enforced by construction only; this test asserts that
 * hoverLabelFor never emits patient_display_id / patient_id / encounter_id
 * for any element kind or GLB category, no matter what the userData carries.
 */

const IDENTITY_FIELDS = {
  patient_display_id: 'IDENT-DISPLAY-SENTINEL',
  patient_id: 'IDENT-UUID-SENTINEL',
  encounter_id: 'IDENT-ENCOUNTER-SENTINEL',
};

const SENTINELS = Object.values(IDENTITY_FIELDS);

function expectNoIdentity(label: string | null): void {
  for (const sentinel of SENTINELS) {
    expect(label ?? '').not.toContain(sentinel);
  }
}

describe('hoverLabelFor identity exclusion (H5.2)', () => {
  it('never emits identity for any element kind, even with no other name source', () => {
    for (const kind of Object.keys(ELEMENT_LABELS)) {
      const label = hoverLabelFor({ kind, ...IDENTITY_FIELDS });
      expect(label).toBe(ELEMENT_LABELS[kind]);
      expectNoIdentity(label);
    }
  });

  it('never emits identity for any GLB category', () => {
    for (const category of Object.keys(CATEGORY_LABELS)) {
      const label = hoverLabelFor({ category, ...IDENTITY_FIELDS });
      expect(label).toBe(CATEGORY_LABELS[category]);
      expectNoIdentity(label);
    }
  });

  it('prefers the non-identity name and still excludes identity alongside it', () => {
    const label = hoverLabelFor({
      kind: 'occupancy-marker',
      location_name: 'MedSurg 3 East',
      ...IDENTITY_FIELDS,
    });
    expect(label).toBe('Census disk · MedSurg 3 East');
    expectNoIdentity(label);
  });

  it('labels a bed by its code, never by its occupant', () => {
    const label = hoverLabelFor({
      category: 'bed',
      bed: 'B-312',
      ...IDENTITY_FIELDS,
    });
    expect(label).toBe('Bed · B-312');
    expectNoIdentity(label);
  });

  it('returns null for unknown userData instead of falling back to arbitrary fields', () => {
    expect(hoverLabelFor(IDENTITY_FIELDS)).toBeNull();
  });

  it('collapses a name identical to the element label', () => {
    expect(hoverLabelFor({ kind: 'patient-token', label: 'Patient' })).toBe('Patient');
  });
});
