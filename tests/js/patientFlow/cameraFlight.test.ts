import { describe, expect, it } from 'vitest';
import {
  buildFlightPath,
  flightDurationMs,
  FLIGHT_MAX_MS,
  FLIGHT_MIN_MS,
} from '@/features/patientFlowNavigator/cameraFlight';

/** E2 — the van Wijk arc: verified properties, not just "it moves". */

describe('buildFlightPath (van Wijk & Nuij)', () => {
  it('starts and ends exactly at the endpoint widths and pan bounds', () => {
    const path = buildFlightPath(200, 40, 90);
    expect(path.panAt(0)).toBeCloseTo(0, 5);
    expect(path.panAt(1)).toBeCloseTo(1, 5);
    expect(path.widthAt(0)).toBeCloseTo(40, 4);
    expect(path.widthAt(1)).toBeCloseTo(90, 4);
  });

  it('pans monotonically', () => {
    const path = buildFlightPath(300, 60, 60);
    let previous = -1;
    for (let step = 0; step <= 20; step += 1) {
      const pan = path.panAt(step / 20);
      expect(pan).toBeGreaterThanOrEqual(previous);
      previous = pan;
    }
  });

  it('rises above both endpoint widths on a long pan — the context arc', () => {
    const path = buildFlightPath(600, 30, 30);
    let peak = 0;
    for (let step = 0; step <= 40; step += 1) {
      peak = Math.max(peak, path.widthAt(step / 40));
    }
    expect(peak).toBeGreaterThan(30 * 1.5);
    expect(path.widthAt(0)).toBeCloseTo(30, 4);
    expect(path.widthAt(1)).toBeCloseTo(30, 4);
  });

  it('keeps a short hop close to the endpoint widths (no gratuitous zoom-out)', () => {
    const path = buildFlightPath(8, 50, 50);
    let peak = 0;
    for (let step = 0; step <= 20; step += 1) {
      peak = Math.max(peak, path.widthAt(step / 20));
    }
    expect(peak).toBeLessThan(50 * 1.2);
  });

  it('degenerates to a pure exponential zoom when the pan distance is zero', () => {
    const zoomIn = buildFlightPath(0, 100, 25);
    expect(zoomIn.pathLength).toBeGreaterThan(0);
    expect(zoomIn.widthAt(0)).toBeCloseTo(100, 4);
    expect(zoomIn.widthAt(1)).toBeCloseTo(25, 3);
    expect(zoomIn.widthAt(0.5)).toBeCloseTo(50, 3); // geometric midpoint
    expect(zoomIn.panAt(0.5)).toBe(1);
  });

  it('handles a zero-length flight without NaNs', () => {
    const still = buildFlightPath(0, 60, 60);
    expect(still.pathLength).toBe(0);
    expect(still.widthAt(1)).toBeCloseTo(60, 5);
  });
});

describe('flightDurationMs', () => {
  it('clamps into the [min, max] band', () => {
    expect(flightDurationMs(0)).toBe(FLIGHT_MIN_MS);
    expect(flightDurationMs(1_000)).toBe(FLIGHT_MAX_MS);
  });

  it('scales with path length between the clamps', () => {
    expect(flightDurationMs(2)).toBeGreaterThan(flightDurationMs(1));
  });
});
