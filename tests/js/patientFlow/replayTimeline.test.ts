import { describe, expect, it } from 'vitest';
import {
  LIVE_WINDOW_HALF_MS,
  prepareReplay,
  recentReplayEvents,
  replayStatus,
} from '@/features/patientFlowNavigator/replayTimeline';
import type {
  PatientFlowEvent,
  PatientFlowFreshness,
  PatientFlowSummary,
} from '@/features/patientFlowNavigator/types';

const NOW = Date.parse('2026-07-09T12:00:00Z');

function event(id: string, occurredAt: string): PatientFlowEvent {
  return {
    event_id: id,
    event_category: 'movement',
    event_type: 'transfer',
    patient_id: `patient-${id}`,
    patient_display_id: `PT-${id}`,
    encounter_id: `encounter-${id}`,
    occurred_at: occurredAt,
    from_location: 'ED-01',
    to_location: 'ICU-01',
  };
}

function summary(
  freshness: PatientFlowFreshness,
  firstEventAt: string | null,
  lastEventAt: string | null,
): PatientFlowSummary {
  return {
    messages: 0,
    normalized_events: firstEventAt ? 2 : 0,
    patients: firstEventAt ? 2 : 0,
    locations: 2,
    movement_events: firstEventAt ? 2 : 0,
    clinical_context_events: 0,
    min_occurred_at: firstEventAt,
    max_occurred_at: lastEventAt,
    live_events: 0,
    facility_code: 'ZEPHYRUS-500',
    model_url: '/model.glb',
    source: {
      mode: 'synthetic',
      system: 'synthetic-flow-ehr',
      scenario_id: null,
      generated_at: new Date(NOW).toISOString(),
      last_event_at: lastEventAt,
      expected_cadence_seconds: 300,
      freshness,
      stale_after_seconds: 900,
      lineage: ['flow_core.flow_events'],
    },
    data_extent: {
      first_event_at: firstEventAt,
      last_event_at: lastEventAt,
      event_count: firstEventAt ? 2 : 0,
    },
    suggested_initial_time: lastEventAt,
    generated_at: new Date(NOW).toISOString(),
  };
}

describe('patient flow replay timeline', () => {
  it('preserves stale events and opens at the actual historical extent', () => {
    const first = '2026-07-02T00:00:00Z';
    const last = '2026-07-05T00:00:00Z';
    const prepared = prepareReplay(
      summary('stale', first, last),
      [event('latest', last), event('earliest', first)],
      NOW,
    );

    expect(prepared.events.map((item) => item.event_id)).toEqual(['earliest', 'latest']);
    expect(prepared.timeline).toMatchObject({
      historical: true,
      freshness: 'stale',
      dataStart: Date.parse(first),
      dataEnd: Date.parse(last),
      windowStart: Date.parse(first),
      windowEnd: Date.parse(last),
      currentTime: Date.parse(last),
    });
    expect(replayStatus(prepared.timeline)).toContain('Historical - last event');
  });

  it('uses the wall-clock review and projection window for a fresh source', () => {
    const last = '2026-07-09T11:58:00Z';
    const prepared = prepareReplay(
      summary('fresh', '2026-07-09T11:00:00Z', last),
      [event('current', last)],
      NOW,
    );

    expect(prepared.timeline).toMatchObject({
      historical: false,
      freshness: 'fresh',
      windowStart: NOW - LIVE_WINDOW_HALF_MS,
      windowEnd: NOW + LIVE_WINDOW_HALF_MS,
      currentTime: NOW,
    });
    expect(replayStatus(prepared.timeline)).toBe('Current event data loaded');
  });

  it('reports an explicit missing state when no events exist', () => {
    const prepared = prepareReplay(summary('missing', null, null), [], NOW);

    expect(prepared.events).toEqual([]);
    expect(prepared.timeline.freshness).toBe('missing');
    expect(replayStatus(prepared.timeline)).toBe('No flow events available');
  });

  it('keeps recent movements visible when newer clinical context is denser', () => {
    const events = [
      event('move-old', '2026-07-09T10:00:00Z'),
      event('move-mid', '2026-07-09T10:30:00Z'),
      event('move-new', '2026-07-09T11:00:00Z'),
      ...Array.from({ length: 8 }, (_, index) => ({
        ...event(`clinical-${index}`, `2026-07-09T11:${String(index + 10).padStart(2, '0')}:00Z`),
        event_category: 'clinical_context',
        event_type: 'observation',
      })),
    ];

    const feed = recentReplayEvents(events, 8);
    expect(feed).toHaveLength(8);
    expect(feed.filter((item) => item.event_category === 'movement')).toHaveLength(3);
  });
});
