import { describe, expect, it } from 'vitest';
import {
  LEGEND_SECTIONS,
  OCCUPANCY_STATUS_COLORS,
} from '@/features/patientFlowNavigator/sceneVocabulary';

/**
 * H1.1 — color-vision-deficiency rationale, pinned as a test.
 *
 * The census disks encode ok/delayed as green vs coral — a red-green axis.
 * Under deuteranopia (the most common CVD) those two hues collapse, which is
 * exactly why the scene gives `delayed` a SHAPE (the triangle cue) and the
 * legend words it. This test simulates deuteranopia (Machado et al. 2009,
 * severity 1.0) and asserts (a) the collapse is real — the fix must stay —
 * and (b) the vocabulary carries the shape-based compensation.
 */

// Machado et al. (2009) deuteranopia (severity 1.0) matrix on linear RGB.
const DEUTERANOPIA = [
  [0.367322, 0.860646, -0.227968],
  [0.280085, 0.672501, 0.047413],
  [-0.01182, 0.04294, 0.968881],
];

function hexToRgb(hex: number): [number, number, number] {
  return [(hex >> 16) & 0xff, (hex >> 8) & 0xff, hex & 0xff];
}

function srgbToLinear(channel: number): number {
  const c = channel / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function simulateDeuteranopia(hex: number): [number, number, number] {
  const [r, g, b] = hexToRgb(hex).map(srgbToLinear);
  return [
    DEUTERANOPIA[0][0] * r + DEUTERANOPIA[0][1] * g + DEUTERANOPIA[0][2] * b,
    DEUTERANOPIA[1][0] * r + DEUTERANOPIA[1][1] * g + DEUTERANOPIA[1][2] * b,
    DEUTERANOPIA[2][0] * r + DEUTERANOPIA[2][1] * g + DEUTERANOPIA[2][2] * b,
  ];
}

function linearDistance(a: [number, number, number], b: [number, number, number]): number {
  return Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);
}

describe('census status palette under deuteranopia (H1.1)', () => {
  it('documents the green/coral collapse that motivates the shape cue', () => {
    const ok = OCCUPANCY_STATUS_COLORS.ok.color;
    const delayed = OCCUPANCY_STATUS_COLORS.delayed.color;

    const normalDistance = linearDistance(
      hexToRgb(ok).map(srgbToLinear) as [number, number, number],
      hexToRgb(delayed).map(srgbToLinear) as [number, number, number],
    );
    const simulatedDistance = linearDistance(
      simulateDeuteranopia(ok),
      simulateDeuteranopia(delayed),
    );

    // The discriminability a trichromat gets from this pair largely vanishes
    // for a deuteranope. If a palette change ever makes this assertion fail
    // (i.e. the pair becomes CVD-safe), the shape cue may be revisited —
    // until then it is load-bearing.
    expect(simulatedDistance).toBeLessThan(normalDistance * 0.5);
  });

  it('carries the shape-based compensation in the vocabulary', () => {
    const entry = LEGEND_SECTIONS.flatMap((section) => section.entries)
      .find((candidate) => candidate.key === 'occupancy-delayed-cue');
    expect(entry).toBeDefined();
    expect(entry?.shape).toBe('triangle');
    expect(entry?.layer).toBe('heat');
    // Worded, never color alone.
    expect(entry?.description).toContain('shape');
  });
});
