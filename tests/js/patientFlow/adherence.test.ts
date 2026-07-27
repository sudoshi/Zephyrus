import { describe, expect, it } from 'vitest';

import {
  PATHWAY_SPECS,
  deviationEvidence,
  elementsMet,
  expectedObservedLanes,
  pathwayLabel,
  sceneChipLabel,
  translateDeviation,
} from '@/features/patientFlowNavigator/adherence';

/**
 * C2 (finding CF-3): the pattern-translation layer is pinned against EVERY
 * current deviation code of the three sidecar pathways (arena/app/pathways.py
 * is the SSOT — if a code is added there, this test must fail until the
 * mirror learns it).
 */

const REGISTRY_CODES: Record<string, string[]> = {
  sepsis: ['no_lactate', 'no_antibiotic', 'antibiotic_late', 'culture_after_antibiotic', 'no_repeat_lactate'],
  surgical_safety: ['safety_step_missing', 'safety_check_flagged'],
  home_hospital: ['activation_beyond_sla', 'visit_cadence_below_floor', 'escalation_unresolved', 'escalation_response_late'],
};

describe('adherence translation layer (C2)', () => {
  it('translates every registry deviation code with a non-other pattern and clinician copy', () => {
    for (const [pathway, codes] of Object.entries(REGISTRY_CODES)) {
      for (const code of codes) {
        const translated = translateDeviation(pathway, code);
        expect(translated.pattern, `${pathway}:${code}`).not.toBe('other');
        expect(translated.label.length, `${pathway}:${code}`).toBeGreaterThan(10);
        expect(translated.label, `${pathway}:${code} label must not be the raw code`).not.toBe(code);
      }
    }
  });

  it('mirrors the registry exactly — no extra or missing codes', () => {
    for (const [pathway, codes] of Object.entries(REGISTRY_CODES)) {
      expect(Object.keys(PATHWAY_SPECS[pathway].deviations).sort()).toEqual([...codes].sort());
    }
  });

  it('every element code belongs to the pathway registry', () => {
    for (const spec of Object.values(PATHWAY_SPECS)) {
      const known = new Set(Object.keys(spec.deviations));
      for (const element of spec.elements) {
        for (const code of element.codes) {
          expect(known.has(code), `${spec.key} element ${element.key} code ${code}`).toBe(true);
        }
      }
    }
  });

  it('degrades an unknown code to a humanized label, never a crash', () => {
    const translated = translateDeviation('sepsis', 'future_new_rule');
    expect(translated.pattern).toBe('other');
    expect(translated.label).toBe('Future new rule');

    const unknownPathway = translateDeviation('not_a_pathway', 'whatever_code');
    expect(unknownPathway.pattern).toBe('other');
  });

  it('computes the ERAS elements-met headline', () => {
    expect(elementsMet('sepsis', [])).toEqual({ met: 4, total: 4 });
    // antibiotic_late and no_antibiotic judge the SAME element — one unmet.
    expect(elementsMet('sepsis', ['antibiotic_late'])).toEqual({ met: 3, total: 4 });
    expect(elementsMet('sepsis', ['no_antibiotic', 'antibiotic_late'])).toEqual({ met: 3, total: 4 });
    expect(elementsMet('sepsis', ['no_lactate', 'no_repeat_lactate'])).toEqual({ met: 2, total: 4 });
    expect(elementsMet('surgical_safety', ['safety_step_missing'])).toEqual({ met: 1, total: 2 });
    expect(elementsMet('home_hospital', ['escalation_response_late'])).toEqual({ met: 3, total: 4 });
  });

  it('never overstates compliance when the sidecar versions ahead', () => {
    // A code the element map does not know counts as its own unmet element.
    expect(elementsMet('sepsis', ['brand_new_check'])).toEqual({ met: 4, total: 5 });
  });

  it('builds the two-lane expected/observed strip in spec order', () => {
    const lanes = expectedObservedLanes('sepsis', {
      sepsis_recognition: '2026-07-26T08:00:00+00:00',
      antibiotic_administration: '2026-07-26T11:42:00+00:00',
    });
    expect(lanes.map((lane) => lane.key)).toEqual(PATHWAY_SPECS.sepsis.activities.map((a) => a.key));
    expect(lanes[0].observedAt).toBe('2026-07-26T08:00:00+00:00');
    expect(lanes.find((lane) => lane.key === 'antibiotic_administration')?.observedAt).toBe('2026-07-26T11:42:00+00:00');
    expect(lanes.find((lane) => lane.key === 'lactate_order')?.observedAt).toBeNull();
  });

  it('computes timing evidence from the observed timeline', () => {
    expect(deviationEvidence('antibiotic_late', {
      sepsis_recognition: '2026-07-26T08:00:00+00:00',
      antibiotic_administration: '2026-07-26T11:42:00+00:00',
    })).toBe('Antibiotics 42 min past the 3 h target');

    expect(deviationEvidence('activation_beyond_sla', {
      'home-refer': '2026-07-24T08:00:00+00:00',
      'home-activate': '2026-07-25T10:30:00+00:00',
    })).toBe('Activation 2 h 30 min past the 24 h target');

    // Timeline cannot support the arithmetic → null, the label stands alone.
    expect(deviationEvidence('antibiotic_late', {})).toBeNull();
    expect(deviationEvidence('no_lactate', {})).toBeNull();
  });

  it('words the scene hover chip as state, never identity', () => {
    expect(sceneChipLabel([{ pathway: 'sepsis', deviations: ['antibiotic_late'] }]))
      .toBe('sepsis · late step');
    expect(sceneChipLabel([{ pathway: 'sepsis', deviations: ['no_lactate'] }]))
      .toBe('sepsis · missing step');
    expect(sceneChipLabel([{ pathway: 'surgical_safety', deviations: ['safety_check_flagged'] }]))
      .toBe('surgical safety · flagged step');
    expect(sceneChipLabel([
      { pathway: 'sepsis', deviations: ['antibiotic_late', 'no_repeat_lactate'] },
      { pathway: 'home_hospital', deviations: ['escalation_unresolved'] },
    ])).toBe('sepsis +1 · 3 deviations');
  });

  it('labels pathways from the registry mirror', () => {
    expect(pathwayLabel('sepsis')).toBe('Sepsis bundle (SEP-3)');
    expect(pathwayLabel('unknown_thing')).toBe('Unknown thing');
  });
});
