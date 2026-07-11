import { describe, expect, it } from 'vitest';
import { buildOccupancyInsights } from '@/features/patientFlowNavigator/occupancyInsights';
import { formatDurationMinutes } from '@/lib/duration';
import type { PatientFlowEvent, PatientVisibleState } from '@/features/patientFlowNavigator/types';

const NOW = Date.parse('2026-07-09T12:00:00Z');

function state(id: string, staySeconds: number, nextSeconds?: number): PatientVisibleState {
  const event: PatientFlowEvent = {
    event_id: `event-${id}`,
    event_category: 'movement',
    event_type: 'transfer',
    patient_id: `patient-${id}`,
    patient_display_id: `PT-${id}`,
    encounter_id: `encounter-${id}`,
    occurred_at: new Date(NOW - staySeconds * 1_000).toISOString(),
    to_location: `ROOM-${id}`,
    service_line: 'medicine',
  };

  return {
    patientId: event.patient_id,
    event,
    position: { x: 0, y: 0, z: 0 },
    recent: [event],
    arrivedAt: event.occurred_at,
    nextEvent: nextSeconds === undefined ? null : {
      ...event,
      event_id: `next-${id}`,
      occurred_at: new Date(NOW + nextSeconds * 1_000).toISOString(),
      to_location: `NEXT-${id}`,
    },
  };
}

describe('patient flow occupancy duration precision', () => {
  it('preserves whole-second stays and timer deadlines through aggregation', () => {
    const result = buildOccupancyInsights(
      [state('a', 3_690, 31), state('b', 3_691)],
      {},
      [],
      NOW,
      undefined,
    );

    expect(result.summary.avgStayMinutes).toBeCloseTo(61.5083333333, 8);
    expect(formatDurationMinutes(result.summary.avgStayMinutes)).toBe('1 hr 1 min 31 sec');
    expect(result.insights[0].timers.find((timer) => timer.kind === 'next_transport')?.minutesRemaining)
      .toBeCloseTo(31 / 60, 8);
  });
});
