import { describe, expect, it } from 'vitest';
import {
  buildViewSearch,
  LAYER_KEYS,
  parseCamera,
  parseLayers,
  parseViewState,
  serializeCamera,
} from '@/features/patientFlowNavigator/viewState';
import type { NavigatorViewSnapshot, ViewCamera } from '@/features/patientFlowNavigator/viewState';
import { windowForPreset, WINDOW_PRESET_LABELS } from '@/features/patientFlowNavigator/replayTimeline';
import type { WindowPreset } from '@/features/patientFlowNavigator/replayTimeline';
import type { PatientLayerState } from '@/features/patientFlowNavigator/types';

/** E5 — any exact view is shareable as a URL; parse(serialize(x)) === x. */

const BASE_SNAPSHOT: NavigatorViewSnapshot = {
  camera: null,
  floor: 'all',
  layers: null,
  censusScope: 'all',
  timeMs: null,
  windowPreset: '48h',
  selection: null,
  patient: null,
  focusStop: null,
};

function layersFrom(enabled: Array<keyof PatientLayerState>): PatientLayerState {
  const state = {} as PatientLayerState;
  for (const key of LAYER_KEYS) state[key] = enabled.includes(key);
  return state;
}

/** Deterministic LCG so the property sweep is reproducible in CI. */
function seededRandom(seed: number): () => number {
  let state = seed >>> 0;
  return () => {
    state = (state * 1664525 + 1013904223) >>> 0;
    return state / 2 ** 32;
  };
}

describe('viewState camera round-trip', () => {
  it('serializes to 1-decimal fixed and parses back', () => {
    const camera: ViewCamera = {
      position: { x: 88.04, y: 104.16, z: -161.95 },
      target: { x: 0, y: 48, z: 0.25 },
    };
    const raw = serializeCamera(camera);
    expect(raw).toBe('88,104.2,-161.9,0,48,0.3');
    expect(parseCamera(raw)).toEqual({
      position: { x: 88, y: 104.2, z: -161.9 },
      target: { x: 0, y: 48, z: 0.3 },
    });
  });

  it('rejects malformed, short, and out-of-range payloads', () => {
    expect(parseCamera(null)).toBeNull();
    expect(parseCamera('')).toBeNull();
    expect(parseCamera('1,2,3')).toBeNull();
    expect(parseCamera('1,2,3,4,5,abc')).toBeNull();
    expect(parseCamera('1,2,3,4,5,9999999')).toBeNull();
  });
});

describe('viewState layers round-trip', () => {
  it('lists enabled keys and treats unlisted as off', () => {
    const layers = layersFrom(['base', 'heat', 'pathway']);
    const parsed = parseLayers('base,heat,pathway');
    expect(parsed).toEqual(layers);
  });

  it('drops unknown keys instead of failing', () => {
    const parsed = parseLayers('base,glitter,heat');
    expect(parsed).toEqual(layersFrom(['base', 'heat']));
  });

  it('null means the param was absent (lens defaults win)', () => {
    expect(parseLayers(null)).toBeNull();
  });
});

describe('buildViewSearch defaults', () => {
  it('emits an empty string for the default view', () => {
    expect(buildViewSearch(BASE_SNAPSHOT)).toBe('');
  });

  it('reuses the legacy param grammar for floor, time, patient, and stop', () => {
    const search = buildViewSearch({
      ...BASE_SNAPSHOT,
      floor: '3',
      timeMs: Date.parse('2026-07-27T12:00:00Z'),
      patient: 'ptok_AAAAAAAAAAAAAAAAAAAAAAAA',
      focusStop: 'stop-uuid-1',
    });
    const params = new URLSearchParams(search);
    expect(params.get('scope')).toBe('floor:3');
    expect(params.get('t')).toBe('2026-07-27T12:00:00.000Z');
    expect(params.get('patient')).toBe('ptok_AAAAAAAAAAAAAAAAAAAAAAAA');
    expect(params.get('focus_stop')).toBe('stop-uuid-1');
  });

  it('never emits a raw identifier param kind for patients', () => {
    // The only person-addressing params are the opaque ptok and stop uuid.
    const search = buildViewSearch({
      ...BASE_SNAPSHOT,
      selection: { kind: 'occupancy', id: 'ED-BED-04' },
      patient: 'ptok_AAAAAAAAAAAAAAAAAAAAAAAA',
    });
    expect(search).not.toContain('mrn');
    expect(search).not.toContain('encounter');
    expect(new URLSearchParams(search).get('sel')).toBe('occupancy:ED-BED-04');
  });
});

describe('viewState property round-trip (seeded sweep)', () => {
  it('parse(serialize(snapshot)) preserves every field across 200 random views', () => {
    const random = seededRandom(0x5eed);
    const scopes = ['all', 'delayed', 'deviations'] as const;
    const presets: WindowPreset[] = ['48h', '24h', '6h', 'shift'];

    for (let round = 0; round < 200; round += 1) {
      const coord = (): number => Math.round((random() * 800 - 400) * 10) / 10;
      const camera: ViewCamera | null = random() < 0.8
        ? {
            position: { x: coord(), y: coord(), z: coord() },
            target: { x: coord(), y: coord(), z: coord() },
          }
        : null;
      const layers = random() < 0.7
        ? layersFrom(LAYER_KEYS.filter(() => random() < 0.5))
        : null;
      const snapshot: NavigatorViewSnapshot = {
        camera,
        floor: random() < 0.5 ? String(1 + Math.floor(random() * 9)) : 'all',
        layers,
        censusScope: scopes[Math.floor(random() * scopes.length)],
        timeMs: random() < 0.5 ? Date.parse('2026-07-27T00:00:00Z') + Math.floor(random() * 86_400_000) : null,
        windowPreset: presets[Math.floor(random() * presets.length)],
        selection: random() < 0.4
          ? { kind: random() < 0.5 ? 'occupancy' : 'barrier', id: `id-${Math.floor(random() * 999)}` }
          : null,
        patient: null,
        focusStop: null,
      };

      const search = buildViewSearch(snapshot);
      const parsed = parseViewState(search);
      const params = new URLSearchParams(search);

      expect(parsed.camera).toEqual(snapshot.camera);
      expect(parsed.layers).toEqual(snapshot.layers);
      expect(parsed.censusScope).toEqual(snapshot.censusScope === 'all' ? null : snapshot.censusScope);
      expect(parsed.windowPreset).toEqual(snapshot.windowPreset === '48h' ? null : snapshot.windowPreset);
      expect(parsed.selection).toEqual(snapshot.selection);
      expect(params.get('scope')).toEqual(snapshot.floor === 'all' ? null : `floor:${snapshot.floor}`);
      expect(params.get('t')).toEqual(snapshot.timeMs === null ? null : new Date(snapshot.timeMs).toISOString());
    }
  });

  it('parses garbage to nulls, never throwing', () => {
    const parsed = parseViewState('?cam=zzz&layers=&census=everything&win=90h&sel=:&wall=2');
    expect(parsed.camera).toBeNull();
    expect(parsed.layers).toEqual(parseLayers(''));
    expect(parsed.censusScope).toBeNull();
    expect(parsed.windowPreset).toBeNull();
    expect(parsed.selection).toBeNull();
    expect(parsed.wall).toBe(false);
  });

  it('reads wall=1 as the kiosk chrome-suppression flag', () => {
    expect(parseViewState('?wall=1').wall).toBe(true);
  });
});

describe('windowForPreset (TN-6)', () => {
  const noon = Date.parse('2026-07-27T16:00:00Z'); // 12:00 EDT — inside the day shift

  it('offers symmetric halves for 48h/24h/6h', () => {
    for (const [preset, half] of [['48h', 24], ['24h', 12], ['6h', 3]] as Array<[WindowPreset, number]>) {
      const { start, end } = windowForPreset(preset, noon);
      expect(noon - start).toBe(half * 3_600_000);
      expect(end - noon).toBe(half * 3_600_000);
    }
  });

  it('anchors the shift preset to the enclosing 07:00/19:00 block', () => {
    const { start, end } = windowForPreset('shift', noon);
    expect(end - start).toBe(12 * 3_600_000);
    expect(start).toBeLessThanOrEqual(noon);
    expect(end).toBeGreaterThanOrEqual(noon);
    const startHour = new Date(start).getHours();
    expect([7, 19]).toContain(startHour);
  });

  it('labels every preset for the chronobar control', () => {
    for (const preset of ['48h', '24h', '6h', 'shift'] as WindowPreset[]) {
      expect(WINDOW_PRESET_LABELS[preset]).toBeTruthy();
    }
  });
});
