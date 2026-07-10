import {
  formatDurationHours,
  formatDurationForUnit,
  formatDurationMinutes,
  formatDurationSeconds,
  formatRelativeDurationMinutes,
} from '@/lib/duration';

describe('duration formatting', () => {
  it('rounds once and renders hours, minutes, and seconds without decimals', () => {
    expect(formatDurationMinutes(61.50833333333333)).toBe('1 hr 1 min 31 sec');
    expect(formatDurationSeconds(3_661.4)).toBe('1 hr 1 min 1 sec');
    expect(formatDurationHours(1.5)).toBe('1 hr 30 min 0 sec');
  });

  it('keeps seconds visible for short and exact durations', () => {
    expect(formatDurationMinutes(0)).toBe('0 sec');
    expect(formatDurationMinutes(0.5)).toBe('30 sec');
    expect(formatDurationMinutes(2)).toBe('2 min 0 sec');
  });

  it('formats elapsed-time unit aliases without reinterpreting other units', () => {
    expect(formatDurationForUnit(1.525, 'hours')).toBe('1 hr 31 min 30 sec');
    expect(formatDurationForUnit(90.5, 'seconds')).toBe('1 min 31 sec');
    expect(formatDurationForUnit(3, 'beds')).toBeNull();
    expect(formatDurationForUnit(3, 'days')).toBeNull();
  });

  it('formats signed SLA durations with operational context', () => {
    expect(formatRelativeDurationMinutes(-90.25)).toBe('1 hr 30 min 15 sec overdue');
    expect(formatRelativeDurationMinutes(12.5)).toBe('12 min 30 sec remaining');
    expect(formatRelativeDurationMinutes(null)).toBe('No target');
  });

  it('handles invalid values and preserves a sign for generic durations', () => {
    expect(formatDurationMinutes(Number.NaN)).toBe('N/A');
    expect(formatDurationSeconds(-90)).toBe('-1 min 30 sec');
    expect(formatDurationSeconds(-0.4)).toBe('0 sec');
  });
});
