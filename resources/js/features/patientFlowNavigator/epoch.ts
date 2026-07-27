/**
 * Dataset-epoch adoption for the 4D Navigator (Codex HFE audit F-6 pt 2,
 * FLOW-4D plan §8 Phase A3 / DI-1). The 6h demo refresh rebases every
 * timestamp in place; the server exposes the refresh ledger's newest terminal
 * run as an opaque epoch id. The client's job is narrow: adopt a baseline on
 * first sight, ignore signal loss, and demand ONE atomic rebootstrap when the
 * epoch genuinely moves.
 */

export interface EpochAdoption {
  epoch: string | null;
  changed: boolean;
}

/**
 * Pure epoch comparison:
 * - first non-null observation becomes the baseline WITHOUT a rebootstrap
 *   (bootstrap already loaded that epoch's data);
 * - a null next (endpoint down, no ledger on this deployment) never triggers
 *   and never clears the baseline — the feature degrades to inert;
 * - a different non-null next demands a rebootstrap and becomes the baseline.
 */
export function adoptEpoch(prev: string | null, next: string | null): EpochAdoption {
  if (next === null || next === '') {
    return { epoch: prev, changed: false };
  }

  if (prev === null || prev === '') {
    return { epoch: next, changed: false };
  }

  if (prev === next) {
    return { epoch: prev, changed: false };
  }

  return { epoch: next, changed: true };
}
