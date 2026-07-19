// First-run guided intro (HFE closure H5.1). The legend answers "what is
// this?"; the intro answers "what can I do?" — five coach-mark stops over the
// live controls. Storage semantics mirror savedViews: guarded access, and
// blocked storage (kiosk privacy mode) means the dismissal could never
// persist, so the intro NEVER auto-starts there — a wall display on the 6h
// demo-refresh cycle must not loop the welcome card forever.

export interface IntroStop {
  id: string;
  title: string;
  body: string;
  /** CSS selector of the control the stop points at (existing stable overlay classes). */
  anchor: string;
}

export const INTRO_SEEN = 'seen';

export function introTourKey(role: string | null | undefined): string {
  return `flow4d.tour.${role ?? 'house'}`;
}

/** Auto-start only when storage is readable AND records no prior visit. */
export function shouldAutoStartIntro(read: () => string | null): boolean {
  try {
    return read() === null;
  } catch {
    return false;
  }
}

/** Any close (Done, Skip, Escape) is the one-time dismissal. */
export function persistIntroSeen(write: () => void): void {
  try {
    write();
  } catch {
    // Blocked storage: the dismissal holds for this mount only.
  }
}

const BASE_STOPS: IntroStop[] = [
  {
    id: 'census',
    title: 'Census scope',
    body: 'All shows every occupied location; Delayed narrows the disks to breached timers only. When the narrow scope is on, a chip above the scene says so and offers the way back.',
    anchor: '.patient-flow-census-toggle',
  },
  {
    id: 'chronobar',
    title: 'Time travel',
    body: 'Drag anywhere on the bar to review the past 24 hours or preview the projected next 24. Now returns to the present, and the small ticks jump straight to shift changes and barrier events.',
    anchor: '.patient-flow-chronobar',
  },
  {
    id: 'legend',
    title: 'Key',
    body: 'Every shape in the scene is listed here: disks are occupancy, triangles mark delays, diamonds are barriers, rings are rounds stops.',
    anchor: '.patient-flow-legend',
  },
  {
    id: 'floors',
    title: 'Floors & shortcuts',
    body: 'Step floors here or with the arrow keys. Press ? any time for the full keyboard list — H frames the house, F flies to your selection, N returns to now.',
    anchor: '.patient-flow-floor-rail',
  },
];

const ROUNDS_STOP: IntroStop = {
  id: 'rounds',
  title: 'Virtual rounds',
  body: 'A rounds run is loaded: walk it stop-to-stop with Prev and Next, or switch on Auto to let the camera advance for you.',
  anchor: '.patient-flow-rounds-hud',
};

/** The rounds stop renders only when a run is actually loaded (plan H5.1). */
export function introStops(roundsActive: boolean): IntroStop[] {
  return roundsActive ? [...BASE_STOPS, ROUNDS_STOP] : BASE_STOPS;
}
