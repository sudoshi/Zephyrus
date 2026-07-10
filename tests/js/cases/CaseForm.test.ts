import { combineDurationParts, splitDurationMinutes } from '@/Components/Cases/CaseForm';

describe('CaseForm duration conversion', () => {
  it('splits canonical minutes without rounding away partial hours', () => {
    expect(splitDurationMinutes(90)).toEqual({ hours: 1, minutes: 30 });
    expect(splitDurationMinutes(135)).toEqual({ hours: 2, minutes: 15 });
  });

  it('recombines hour and minute controls into canonical integer minutes', () => {
    expect(combineDurationParts(1, 30)).toBe(90);
    expect(combineDurationParts('2', '15')).toBe(135);
  });

  it('keeps minutes within the control range and normalizes invalid values', () => {
    expect(combineDurationParts(1, 75)).toBe(119);
    expect(combineDurationParts('', '')).toBe(0);
    expect(splitDurationMinutes('')).toEqual({ hours: '', minutes: '' });
  });
});
