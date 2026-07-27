// URL view state (plan §Phase E, E5): serialize/parse the operator's exact
// view — camera, floor, layers, time, selection, census scope, window preset —
// so any view is shareable as a link. Pure module, round-trip pinned by
// viewState.test.ts (seeded property sweep).
//
// Identity rule: a patient selection travels ONLY as the existing opaque
// `patient=ptok_…` param and a round stop as `focus_stop=` — this module
// never invents a second way to address a person. `sel=` carries the two
// aggregate kinds (occupancy location, barrier id) exclusively.
import type { PatientLayerState } from './types';
import type { WindowPreset } from './replayTimeline';

export interface ViewCamera {
  position: { x: number; y: number; z: number };
  target: { x: number; y: number; z: number };
}

export type AggregateSelectionKind = 'occupancy' | 'barrier';

export interface AggregateSelection {
  kind: AggregateSelectionKind;
  id: string;
}

/** Everything the copy-view-link path snapshots. Null/default fields are omitted from the URL. */
export interface NavigatorViewSnapshot {
  camera: ViewCamera | null;
  /** Floor filter; 'all' is the default and omitted. */
  floor: string;
  /** Full layer state; null omits the param (recipient keeps lens defaults). */
  layers: PatientLayerState | null;
  censusScope: 'all' | 'delayed' | 'deviations';
  /** Scrubbed instant; null = following now (recipient opens at their now). */
  timeMs: number | null;
  windowPreset: WindowPreset;
  /** Aggregate selection (occupancy/barrier). Patients/round stops use their own params. */
  selection: AggregateSelection | null;
  /** Opaque patient pivot (ptok) — reuses the Phase B `patient=` contract. */
  patient: string | null;
  /** Round-stop pivot — reuses the R-1 `focus_stop=` contract. */
  focusStop: string | null;
}

/** Parsed view-state additions layered onto the legacy handoff params. */
export interface ParsedViewState {
  camera: ViewCamera | null;
  layers: PatientLayerState | null;
  censusScope: 'delayed' | 'deviations' | null;
  windowPreset: WindowPreset | null;
  selection: AggregateSelection | null;
  /** Wall/kiosk chrome suppression (E1): hides desk-only widgets like the minimap. */
  wall: boolean;
}

export const LAYER_KEYS: ReadonlyArray<keyof PatientLayerState> = [
  'base', 'tokens', 'trails', 'heat', 'ghosts', 'barriers', 'rounds', 'pathway',
];

const WINDOW_PRESETS: ReadonlyArray<WindowPreset> = ['48h', '24h', '6h', 'shift'];

const CAMERA_COORD_LIMIT = 100_000;

function roundCoord(value: number): number {
  return Math.round(value * 10) / 10;
}

function validCoord(value: number): boolean {
  return Number.isFinite(value) && Math.abs(value) <= CAMERA_COORD_LIMIT;
}

/** `cam=px,py,pz,tx,ty,tz` — 1-decimal fixed so links stay short and stable. */
export function serializeCamera(camera: ViewCamera): string {
  const parts = [
    camera.position.x, camera.position.y, camera.position.z,
    camera.target.x, camera.target.y, camera.target.z,
  ];
  return parts.map((part) => String(roundCoord(part))).join(',');
}

export function parseCamera(raw: string | null): ViewCamera | null {
  if (!raw) return null;
  const parts = raw.split(',').map(Number);
  if (parts.length !== 6 || !parts.every(validCoord)) return null;
  return {
    position: { x: parts[0], y: parts[1], z: parts[2] },
    target: { x: parts[3], y: parts[4], z: parts[5] },
  };
}

/** `layers=base,tokens,heat` — listed keys on, unlisted off; unknown keys dropped. */
export function serializeLayers(layers: PatientLayerState): string {
  return LAYER_KEYS.filter((key) => layers[key]).join(',');
}

export function parseLayers(raw: string | null): PatientLayerState | null {
  if (raw === null) return null;
  const requested = new Set(raw.split(',').filter(Boolean));
  const known = new Set<string>(LAYER_KEYS);
  for (const key of requested) {
    if (!known.has(key)) requested.delete(key);
  }
  const state = {} as PatientLayerState;
  for (const key of LAYER_KEYS) state[key] = requested.has(key);
  return state;
}

function parseSelection(raw: string | null): AggregateSelection | null {
  if (!raw) return null;
  const splitAt = raw.indexOf(':');
  if (splitAt <= 0) return null;
  const kind = raw.slice(0, splitAt);
  const id = raw.slice(splitAt + 1);
  if (!id) return null;
  if (kind !== 'occupancy' && kind !== 'barrier') return null;
  return { kind, id };
}

/**
 * Build the shareable search string for a snapshot. Defaults are omitted so a
 * home-view link is just the page URL; param names extend the existing
 * mobile→web handoff grammar (`scope`/`t`/`patient`/`focus_stop`) unchanged.
 */
export function buildViewSearch(snapshot: NavigatorViewSnapshot): string {
  const params = new URLSearchParams();
  if (snapshot.floor !== 'all' && /^\d+$/.test(snapshot.floor)) {
    params.set('scope', `floor:${snapshot.floor}`);
  }
  if (snapshot.timeMs !== null && Number.isFinite(snapshot.timeMs)) {
    params.set('t', new Date(snapshot.timeMs).toISOString());
  }
  if (snapshot.camera) params.set('cam', serializeCamera(snapshot.camera));
  if (snapshot.layers) params.set('layers', serializeLayers(snapshot.layers));
  if (snapshot.censusScope !== 'all') params.set('census', snapshot.censusScope);
  if (snapshot.windowPreset !== '48h') params.set('win', snapshot.windowPreset);
  if (snapshot.selection) params.set('sel', `${snapshot.selection.kind}:${snapshot.selection.id}`);
  if (snapshot.patient) params.set('patient', snapshot.patient);
  if (snapshot.focusStop) params.set('focus_stop', snapshot.focusStop);
  const search = params.toString();
  return search ? `?${search}` : '';
}

/** Parse the E5 additions from a search string. Garbage degrades to nulls, never throws. */
export function parseViewState(search: string): ParsedViewState {
  const params = new URLSearchParams(search);

  const rawCensus = params.get('census');
  const censusScope = rawCensus === 'delayed' || rawCensus === 'deviations' ? rawCensus : null;

  const rawPreset = params.get('win');
  const windowPreset = WINDOW_PRESETS.includes(rawPreset as WindowPreset)
    ? (rawPreset as WindowPreset)
    : null;

  return {
    camera: parseCamera(params.get('cam')),
    layers: parseLayers(params.get('layers')),
    censusScope,
    windowPreset,
    selection: parseSelection(params.get('sel')),
    wall: params.get('wall') === '1',
  };
}
