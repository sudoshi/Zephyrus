// tests/js/cockpit/statusStyle.test.ts
//
// Table-driven contract test for the client mirror of the server StatusEngine.
// The SAME cases are asserted in tests/Unit/Cockpit/StatusEngineTest.php so the
// two implementations can never diverge (plan §3.3).
import { describe, it, expect } from 'vitest';
import {
  statusStyle,
  cockpitStatusStyle,
  COCKPIT_STATE_TO_LEVEL,
  LEVEL_TO_COCKPIT_STATE,
} from '@/Components/cockpit/statusStyle';
import { statusLevels } from '@/types/commandCenter';
import { cockpitStates } from '@/types/cockpit';

describe('statusStyle', () => {
  it('maps every StatusLevel to the canon CSS-var, a distinct glyph, and the ISA-101 value rule', () => {
    expect(statusStyle('neutral')).toEqual({ color: 'var(--text-muted)', glyph: '–', label: 'Normal', valuePrimary: true });
    expect(statusStyle('success')).toEqual({ color: 'var(--success)', glyph: '●', label: 'On target', valuePrimary: false });
    expect(statusStyle('info')).toEqual({ color: 'var(--info)', glyph: '●', label: 'Watch', valuePrimary: true });
    expect(statusStyle('warning')).toEqual({ color: 'var(--warning)', glyph: '▲', label: 'Warning', valuePrimary: false });
    expect(statusStyle('critical')).toEqual({ color: 'var(--critical)', glyph: '◆', label: 'Critical', valuePrimary: false });
  });

  it('never encodes ok vs watch by color alone — they share the dot but differ by value-color discipline', () => {
    const ok = statusStyle('success');
    const watch = statusStyle('info');
    expect(ok.glyph).toBe(watch.glyph);
    expect(ok.color).not.toBe(watch.color);
    expect(ok.valuePrimary).toBe(false); // ok values render teal (rationed confirmation)
    expect(watch.valuePrimary).toBe(true); // watch values stay near-white
  });

  it('D7 alias maps are complete, mutually inverse bijections', () => {
    for (const state of cockpitStates) {
      expect(LEVEL_TO_COCKPIT_STATE[COCKPIT_STATE_TO_LEVEL[state]]).toBe(state);
    }
    for (const level of statusLevels) {
      expect(COCKPIT_STATE_TO_LEVEL[LEVEL_TO_COCKPIT_STATE[level]]).toBe(level);
    }
  });

  it('cockpitStatusStyle resolves logical ISA-101 names through the alias', () => {
    expect(cockpitStatusStyle('crit')).toEqual(statusStyle('critical'));
    expect(cockpitStatusStyle('normal')).toEqual(statusStyle('neutral'));
    expect(cockpitStatusStyle('watch')).toEqual(statusStyle('info'));
  });
});
