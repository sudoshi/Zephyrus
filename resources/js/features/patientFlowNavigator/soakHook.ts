/**
 * Read-only field-diagnostics hook consumed by scripts/soak-flow4d.mjs
 * (HFE closure H4.1 — docs/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md).
 *
 * Exposes renderer memory/draw counters, now-marker wall-clock delta, and the
 * active rounds run (opaque uuid + status only — identity never crosses this
 * boundary). Getters are pull-based so installing costs nothing per frame.
 */

export interface SoakRendererInfo {
  geometries: number;
  textures: number;
  calls: number;
  triangles: number;
}

export interface SoakRoundsRun {
  uuid: string;
  status: string;
}

export interface Flow4dSoakHook {
  rendererInfo: () => SoakRendererInfo | null;
  nowDeltaMs: () => number | null;
  roundsRun: () => SoakRoundsRun | null;
}

declare global {
  interface Window {
    __FLOW4D_SOAK__?: Flow4dSoakHook;
  }
}

/** Installs the hook on window; returns an uninstaller that only removes its
 * own installation (a later install wins and is left untouched). */
export function installSoakHook(hook: Flow4dSoakHook): () => void {
  window.__FLOW4D_SOAK__ = hook;
  return () => {
    if (window.__FLOW4D_SOAK__ === hook) {
      delete window.__FLOW4D_SOAK__;
    }
  };
}
