// Camera flight path (E2): van Wijk & Nuij's "Smooth and efficient zooming
// and panning" (InfoVis 2003), operationalized for the orbit camera — the
// orbit TARGET is the pan position, camera DISTANCE is the zoom width. Long
// pans rise above both endpoints (zoom out → pan → zoom in) so the operator
// keeps context; short hops stay near-linear. Pure math, pinned by
// cameraFlight.test.ts; NavigatorScene consumes it frame-by-frame.

export interface FlightPath {
  /** Total path length in van Wijk's (u, w) space — duration scales on this. */
  pathLength: number;
  /** Pan progress 0..1 along the straight target line at normalized time t. */
  panAt: (t: number) => number;
  /** Camera distance (zoom width) at normalized time t. */
  widthAt: (t: number) => number;
}

/** van Wijk's ρ — trade-off between panning and zooming; 1.42 is his ρ*. */
export const FLIGHT_RHO = 1.42;

/** ms per unit of path length; clamped so no flight is jarring or glacial. */
export const FLIGHT_MS_PER_UNIT = 420;
export const FLIGHT_MIN_MS = 320;
export const FLIGHT_MAX_MS = 2_200;

export function flightDurationMs(pathLength: number): number {
  return Math.min(FLIGHT_MAX_MS, Math.max(FLIGHT_MIN_MS, pathLength * FLIGHT_MS_PER_UNIT));
}

const EPSILON = 1e-6;

/**
 * Build the optimal pan/zoom path from (pan 0, width w0) to (pan panDistance,
 * width w1). Widths must be positive; a degenerate pan collapses to the pure
 * exponential zoom van Wijk gives for u0 = u1.
 */
export function buildFlightPath(panDistance: number, w0: number, w1: number, rho = FLIGHT_RHO): FlightPath {
  const startWidth = Math.max(EPSILON, w0);
  const endWidth = Math.max(EPSILON, w1);
  const distance = Math.max(0, panDistance);

  if (distance < EPSILON) {
    // Pure zoom: w(s) = w0 · e^(±ρs), S = |ln(w1/w0)| / ρ.
    const pathLength = Math.abs(Math.log(endWidth / startWidth)) / rho;
    const direction = endWidth >= startWidth ? 1 : -1;
    return {
      pathLength,
      panAt: () => 1,
      widthAt: (t) => startWidth * Math.exp(direction * rho * (t * pathLength)),
    };
  }

  // b_i and r_i per van Wijk & Nuij §3 (asinh form keeps precision).
  const rhoSq = rho * rho;
  const b = (i: 0 | 1): number => {
    const wi = i === 0 ? startWidth : endWidth;
    const sign = i === 0 ? 1 : -1;
    return (endWidth * endWidth - startWidth * startWidth + sign * (rhoSq * rhoSq * distance * distance))
      / (2 * wi * rhoSq * distance);
  };
  const r = (bi: number): number => Math.log(-bi + Math.sqrt(bi * bi + 1));
  const r0 = r(b(0));
  const r1 = r(b(1));
  const pathLength = (r1 - r0) / rho;

  const u = (s: number): number => (startWidth / rhoSq) * (Math.cosh(r0) * Math.tanh(rho * s + r0) - Math.sinh(r0));
  const w = (s: number): number => (startWidth * Math.cosh(r0)) / Math.cosh(rho * s + r0);

  return {
    pathLength,
    panAt: (t) => Math.min(1, Math.max(0, u(t * pathLength) / distance)),
    widthAt: (t) => w(t * pathLength),
  };
}
