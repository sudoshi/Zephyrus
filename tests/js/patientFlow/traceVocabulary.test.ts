import { describe, expect, it } from 'vitest';

import {
  LEGEND_SECTIONS,
  TRACE_DWELL_MIN_MINUTES,
  dwellNodeScale,
  hslToRgb,
  patientHue,
  traceGradientRgb,
} from '@/features/patientFlowNavigator/sceneVocabulary';

/**
 * Trace-mode vocabulary (plan B3, PJ-2). Pins the doctrine: the gradient is
 * IDENTITY territory (dim slate → the patient's own clamped hue — never a
 * status color), dwell markers earn size from time-in-place only, and the
 * legend words the new element (E-1: nothing in-scene is unexplained).
 */
describe('traceGradientRgb', () => {
  it('brightens monotonically from oldest to newest', () => {
    const sum = (rgb: [number, number, number]): number => rgb[0] + rgb[1] + rgb[2];
    const oldest = traceGradientRgb(0, 'ptok_x');
    const middle = traceGradientRgb(0.5, 'ptok_x');
    const newest = traceGradientRgb(1, 'ptok_x');
    expect(sum(middle)).toBeGreaterThan(sum(oldest));
    expect(sum(newest)).toBeGreaterThan(sum(middle));
  });

  it('clamps t outside 0..1', () => {
    expect(traceGradientRgb(-4, 'p')).toEqual(traceGradientRgb(0, 'p'));
    expect(traceGradientRgb(9, 'p')).toEqual(traceGradientRgb(1, 'p'));
  });

  it('stays inside the identity hue clamp (160°–280°) — never amber/coral', () => {
    // The hue driving the gradient is patientHue's, which is clamped; the
    // red channel must therefore never dominate (amber/coral are red-led).
    for (const id of ['a', 'zz', 'ptok_123', 'patient-9']) {
      const hue = patientHue(id);
      expect(hue).toBeGreaterThanOrEqual(160);
      expect(hue).toBeLessThanOrEqual(280);
      const [r, g, b] = traceGradientRgb(1, id);
      expect(Math.max(g, b)).toBeGreaterThanOrEqual(r);
    }
  });
});

describe('dwellNodeScale', () => {
  it('is zero below the threshold — short stops earn no marker', () => {
    expect(dwellNodeScale(0)).toBe(0);
    expect(dwellNodeScale(TRACE_DWELL_MIN_MINUTES - 1)).toBe(0);
    expect(dwellNodeScale(Number.NaN)).toBe(0);
  });

  it('grows monotonically with dwell and hard-caps', () => {
    const short = dwellNodeScale(TRACE_DWELL_MIN_MINUTES);
    const hours = dwellNodeScale(240);
    const days = dwellNodeScale(60 * 48);
    expect(short).toBeGreaterThan(0);
    expect(hours).toBeGreaterThan(short);
    expect(days).toBeGreaterThanOrEqual(hours);
    expect(days).toBeLessThanOrEqual(2.2);
  });
});

describe('hslToRgb', () => {
  it('produces expected anchor colors', () => {
    expect(hslToRgb(0, 0, 0.5)).toEqual([0.5, 0.5, 0.5]);
    const [r, g, b] = hslToRgb(120, 1, 0.5);
    expect(r).toBeCloseTo(0);
    expect(g).toBeCloseTo(1);
    expect(b).toBeCloseTo(0);
  });
});

describe('legend coverage', () => {
  it('words the dwell marker so the trace is never unexplained geometry', () => {
    const entries = LEGEND_SECTIONS.flatMap((section) => section.entries);
    const dwell = entries.find((entry) => entry.key === 'trace-dwell');
    expect(dwell).toBeDefined();
    expect(dwell!.layer).toBe('trails');
    expect(dwell!.description.toLowerCase()).toContain('longer');
    expect(dwell!.description.toLowerCase()).toContain('never status');
  });
});
