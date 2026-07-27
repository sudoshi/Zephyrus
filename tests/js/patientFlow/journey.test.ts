import { describe, expect, it } from 'vitest';

import {
  alignAnchorMs,
  alignedTimeLabel,
  dwellLabel,
  eventDensityBuckets,
  journeyEventTicks,
} from '@/features/patientFlowNavigator/journey';
import { patientJourneySchema } from '@/features/patientFlowNavigator/journeySchemas';
import type { PatientJourney } from '@/features/patientFlowNavigator/journeySchemas';

/** A minimal valid journey fixture (matches PatientJourneyService::build). */
export function journeyFixture(overrides: Partial<PatientJourney> = {}): PatientJourney {
  const base = {
    patient: {
      patient_context_ref: 'ptok_abcdefabcdefabcdefabcdef',
      display_label: 'Patient ABCDEF',
      detail_authorized: true,
    },
    lens: { role_id: 'house_supervisor', patient_dots: 'full' },
    window: { from: '2026-07-25T02:00:00Z', to: '2026-07-27T08:00:00Z' },
    header: {
      current_location: 'MS5B-B001',
      current_location_name: 'Med Surg 5B',
      current_unit_code: 'MS5B',
      current_floor: 5,
      service_line: 'critical_care',
      patient_class: 'inpatient',
      admitted_at: '2026-07-25T08:00:00Z',
      los_minutes: 735,
      as_of: '2026-07-25T20:15:00Z',
    },
    events: [
      { occurred_at: '2026-07-25T08:00:00Z', event_category: 'movement', event_type: 'admit', trigger_event: 'A01', to_location: 'TICU-B001', location_name: 'TICU', unit_code: 'TICU' },
      { occurred_at: '2026-07-25T11:30:00Z', event_category: 'movement', event_type: 'transfer', trigger_event: 'A02', to_location: 'MS5B-B001', location_name: 'Med Surg 5B', unit_code: 'MS5B' },
    ],
    segments: [
      { kind: 'location', location: 'TICU-B001', location_name: 'TICU', unit_code: 'TICU', started_at: '2026-07-25T08:00:00Z', ended_at: '2026-07-25T11:30:00Z', dwell_minutes: 210, open: false },
      { kind: 'location', location: 'MS5B-B001', location_name: 'Med Surg 5B', unit_code: 'MS5B', started_at: '2026-07-25T11:30:00Z', ended_at: null, dwell_minutes: 525, open: true },
    ],
    phases: {
      ed: [{ phase: 'boarding', started_at: '2026-07-25T06:00:00Z', ended_at: '2026-07-25T08:00:00Z', minutes: 120, open: false }],
      periop: [],
    },
    milestones: [
      { source: 'or_case', case_id: 7, milestone_type: 'pre_op_assessment', status: 'completed', required: true, completed_at: '2026-07-25T09:00:00Z' },
    ],
    logistics: [
      { domain: 'bed_request', status: 'pending', required_unit_type: 'Med Surg', requested_at: '2026-07-25T07:00:00Z' },
    ],
    home: [],
    unit_context_barriers: [
      { barrier_id: 3, unit_id: 12, category: 'placement', description: 'EVS delay', opened_at: '2026-07-25T10:00:00Z', attribution: 'unit' },
    ],
    next: { target_location: 'Med Surg', transport_needed_at: null, expected_discharge_date: '2026-07-27' },
    epoch: { epoch: 'refresh-abc12345', refreshed_at: '2026-07-25T18:00:00Z', status: 'passed' },
    as_of: '2026-07-25T20:15:00Z',
    generated_at: '2026-07-25T20:15:00Z',
  };

  return patientJourneySchema.parse({ ...base, ...overrides });
}

describe('patientJourneySchema', () => {
  it('parses the service fixture shape', () => {
    expect(() => journeyFixture()).not.toThrow();
  });

  it('tolerates server additions without failing (safeParse discipline)', () => {
    const parsed = patientJourneySchema.safeParse({
      ...journeyFixture(),
      some_future_field: { anything: true },
    });
    expect(parsed.success).toBe(true);
  });
});

describe('alignAnchorMs / alignedTimeLabel', () => {
  it('re-zeroes on arrival (first event) and admit', () => {
    const journey = journeyFixture();
    expect(alignAnchorMs(journey, 'arrival')).toBe(Date.parse('2026-07-25T08:00:00Z'));
    expect(alignAnchorMs(journey, 'admit')).toBe(Date.parse('2026-07-25T08:00:00Z'));
    expect(alignAnchorMs(journey, 'clock')).toBeNull();
  });

  it('labels offsets from the anchor in hours and minutes', () => {
    const anchor = Date.parse('2026-07-25T08:00:00Z');
    expect(alignedTimeLabel('2026-07-25T11:30:00Z', anchor)).toBe('+3 hr 30 min');
    expect(alignedTimeLabel('2026-07-25T07:15:00Z', anchor)).toBe('−45 min');
  });

  it('falls back to absolute time when unanchored, and -- on garbage', () => {
    expect(alignedTimeLabel('not-a-date', null)).toBe('--');
    expect(alignedTimeLabel(null, null)).toBe('--');
    expect(alignedTimeLabel('2026-07-25T11:30:00Z', null)).not.toBe('--');
  });
});

describe('dwellLabel', () => {
  it('formats minutes, hours, and days compactly', () => {
    expect(dwellLabel(45)).toBe('45 min');
    expect(dwellLabel(210)).toBe('3 hr 30 min');
    expect(dwellLabel(60 * 26 + 15)).toBe('1 d 2 hr');
    expect(dwellLabel(null)).toBe('--');
    expect(dwellLabel(-5)).toBe('--');
  });
});

describe('journeyEventTicks', () => {
  it('returns finite instants only', () => {
    const ticks = journeyEventTicks(journeyFixture());
    expect(ticks).toHaveLength(2);
    expect(ticks.every((ms) => Number.isFinite(ms))).toBe(true);
  });
});

describe('eventDensityBuckets', () => {
  const t0 = Date.parse('2026-07-25T00:00:00Z');
  const t1 = t0 + 48 * 3_600_000;

  it('normalizes bucket intensities to the busiest bucket', () => {
    const times = [t0 + 1_000, t0 + 2_000, t0 + 3_000, t1 - 1_000];
    const buckets = eventDensityBuckets(times, t0, t1, 48);
    expect(buckets).toHaveLength(48);
    expect(buckets[0]).toBe(1);
    expect(buckets[47]).toBeCloseTo(1 / 3);
    expect(buckets[24]).toBe(0);
  });

  it('drops out-of-window instants and survives empty input', () => {
    expect(eventDensityBuckets([t0 - 5_000, t1 + 5_000], t0, t1, 8)).toEqual(new Array(8).fill(0));
    expect(eventDensityBuckets([], t0, t1, 8)).toEqual(new Array(8).fill(0));
  });

  it('returns empty for a degenerate window', () => {
    expect(eventDensityBuckets([t0], t1, t0)).toEqual([]);
  });
});
