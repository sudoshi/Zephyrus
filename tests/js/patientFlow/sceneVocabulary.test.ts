import { describe, expect, it } from 'vitest';
import {
  BASE_CATEGORY_STYLES,
  CATEGORY_LABELS,
  ELEMENT_LABELS,
  LEGEND_SECTIONS,
  PATIENT_HUE_MIN,
  PATIENT_HUE_SPAN,
  elementLabelFor,
  patientHue,
} from '@/features/patientFlowNavigator/sceneVocabulary';
import type { PatientLayerState } from '@/features/patientFlowNavigator/types';

/**
 * §5.1 SSOT guarantees: the legend covers every scene layer, the identity hue
 * can never impersonate status colors, and every GLB category is accounted
 * for. If a new layer or category ships without a legend entry, these fail.
 */

const ALL_LAYERS: Array<keyof PatientLayerState> = [
  'base',
  'tokens',
  'trails',
  'heat',
  'ghosts',
  'barriers',
  'rounds',
];

describe('legend completeness (E-1)', () => {
  it('covers every scene layer with at least one legend entry', () => {
    const coveredLayers = new Set(
      LEGEND_SECTIONS.flatMap((section) => section.entries.map((entry) => entry.layer)),
    );
    for (const layer of ALL_LAYERS) {
      expect(coveredLayers.has(layer), `layer "${layer}" has no legend entry`).toBe(true);
    }
  });

  it('gives every entry a distinct key, a label, and a worded description', () => {
    const entries = LEGEND_SECTIONS.flatMap((section) => section.entries);
    const keys = entries.map((entry) => entry.key);
    expect(new Set(keys).size).toBe(keys.length);
    for (const entry of entries) {
      expect(entry.label.length).toBeGreaterThan(0);
      expect(entry.description.length).toBeGreaterThan(0);
    }
  });

  it('words the status meanings — status is never color alone', () => {
    const descriptions = LEGEND_SECTIONS.flatMap((section) => section.entries)
      .map((entry) => `${entry.label} ${entry.description}`)
      .join(' ');
    for (const word of ['Amber', 'Coral', 'Green']) {
      expect(descriptions).toContain(word);
    }
  });
});

describe('patient identity hue clamp (E-3)', () => {
  it('stays inside 160°–280° for arbitrary ids', () => {
    const samples = [
      '',
      'a',
      'PT-000001',
      'PT-999999',
      'encounter:5f2c-88ab',
      '5-212',
      'zzzzzzzzzzzzzzzzzzzzzzzz',
      '☃ unicode ☃',
      ...Array.from({ length: 500 }, (_, index) => `patient-${index * 7919}`),
    ];
    for (const sample of samples) {
      const hue = patientHue(sample);
      expect(hue).toBeGreaterThanOrEqual(PATIENT_HUE_MIN);
      expect(hue).toBeLessThan(PATIENT_HUE_MIN + PATIENT_HUE_SPAN);
    }
  });

  it('is deterministic per id', () => {
    expect(patientHue('PT-42')).toBe(patientHue('PT-42'));
  });
});

describe('base category coverage (E-2)', () => {
  it('styles or deliberately excludes every labeled category', () => {
    // `floor` keeps the model material as the datum plane — the only allowed gap.
    for (const category of Object.keys(CATEGORY_LABELS)) {
      if (category === 'floor') continue;
      expect(BASE_CATEGORY_STYLES[category], `category "${category}" has no style`).toBeDefined();
    }
  });

  it('keeps base opacities at or below 0.85 so status layers keep priority', () => {
    for (const [category, style] of Object.entries(BASE_CATEGORY_STYLES)) {
      expect(style.opacity, `category "${category}" opacity`).toBeLessThanOrEqual(0.85);
    }
  });
});

describe('elementLabelFor (E-4/E-5)', () => {
  it('names every scene element kind', () => {
    for (const kind of Object.keys(ELEMENT_LABELS)) {
      expect(elementLabelFor({ kind })).toBe(ELEMENT_LABELS[kind]);
    }
  });

  it('falls back to the building category, then null', () => {
    expect(elementLabelFor({ category: 'bed' })).toBe('Bed');
    expect(elementLabelFor({ category: 'emergency_department' })).toBe('Emergency department');
    expect(elementLabelFor({})).toBeNull();
    expect(elementLabelFor({ kind: 'unknown-kind' })).toBeNull();
  });
});
