import { describe, expect, it } from 'vitest';
import {
  SAVED_VIEW_SLOTS,
  mergeLayers,
  parseSavedViews,
  savedViewsKey,
  serializeSavedViews,
} from '@/features/patientFlowNavigator/savedViews';
import type { SavedView } from '@/features/patientFlowNavigator/savedViews';

const view: SavedView = {
  camera: {
    position: { x: 88, y: 104, z: 162 },
    target: { x: 0, y: 48, z: 0 },
  },
  floor: '3',
  layers: { base: true, tokens: true, trails: false, heat: true, ghosts: false, barriers: true, rounds: true },
};

describe('saved views (N-7)', () => {
  it('round-trips through serialize/parse', () => {
    const stored = serializeSavedViews([null, view, null]);
    expect(parseSavedViews(stored)).toEqual([null, view, null]);
  });

  it('always yields exactly the slot count', () => {
    expect(parseSavedViews(null)).toHaveLength(SAVED_VIEW_SLOTS);
    expect(parseSavedViews(serializeSavedViews([view, view, view]))).toHaveLength(SAVED_VIEW_SLOTS);
  });

  it('degrades garbage storage to empty slots, never a crash', () => {
    expect(parseSavedViews('not json')).toEqual([null, null, null]);
    expect(parseSavedViews('{"a":1}')).toEqual([null, null, null]);
    expect(parseSavedViews('[{"camera":"bogus"}]')).toEqual([null, null, null]);
    // A valid slot next to a corrupt one survives.
    const mixed = JSON.stringify([{ nope: true }, view, 42]);
    expect(parseSavedViews(mixed)).toEqual([null, view, null]);
  });

  it('keys storage by persona role with a house default', () => {
    expect(savedViewsKey('charge_nurse')).toBe('flow4d.views.charge_nurse');
    expect(savedViewsKey(null)).toBe('flow4d.views.house');
    expect(savedViewsKey(undefined)).toBe('flow4d.views.house');
  });

  it('merges saved layers over current state without dropping keys', () => {
    const current = { base: true, tokens: false, trails: true, heat: false, ghosts: true, barriers: false, rounds: false };
    expect(mergeLayers(current, view.layers)).toEqual(view.layers);
  });
});
