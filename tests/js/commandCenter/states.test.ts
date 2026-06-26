// tests/js/commandCenter/states.test.ts
import { describe, it, expect } from 'vitest';
import { safeParseCommandCenterData } from '@/types/commandCenter';
import { relativeTimeFrom } from '@/Components/CommandCenter/states';
import { commandCenterFixture } from './fixture';

describe('safeParseCommandCenterData', () => {
  it('returns ok with parsed data for a valid payload', () => {
    const result = safeParseCommandCenterData(commandCenterFixture);
    expect(result.ok).toBe(true);
    if (result.ok) expect(result.data.generatedAtIso).toBe('2026-06-22T12:00:00Z');
  });

  it('returns ok:false with a path-tagged message instead of throwing on a malformed payload', () => {
    const broken = { ...commandCenterFixture, unitCensus: 'not-an-array' };
    const result = safeParseCommandCenterData(broken);
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.error).toMatch(/unitCensus/);
  });

  it('does not throw on completely invalid input', () => {
    expect(() => safeParseCommandCenterData(null)).not.toThrow();
    expect(safeParseCommandCenterData(null).ok).toBe(false);
  });
});

describe('relativeTimeFrom', () => {
  const base = Date.parse('2026-06-22T12:00:00Z');

  it('reads as "just now" within 30s', () => {
    expect(relativeTimeFrom('2026-06-22T12:00:00Z', base + 10_000)).toBe('just now');
  });

  it('ages into minutes', () => {
    expect(relativeTimeFrom('2026-06-22T12:00:00Z', base + 3 * 60_000)).toBe('3 min ago');
  });

  it('ages into hours', () => {
    expect(relativeTimeFrom('2026-06-22T12:00:00Z', base + 2 * 3_600_000)).toBe('2 hr ago');
  });

  it('degrades gracefully on an unparseable timestamp', () => {
    expect(relativeTimeFrom('not-a-date', base)).toBe('an unknown time');
  });
});
