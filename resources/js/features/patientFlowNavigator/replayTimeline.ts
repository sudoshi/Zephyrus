import type {
  PatientFlowEvent,
  PatientFlowFreshness,
  PatientFlowSummary,
} from './types';
import { parseTime } from './stateProjection';

export const LIVE_WINDOW_HALF_MS = 24 * 60 * 60 * 1000;
const MINIMUM_HISTORICAL_WINDOW_MS = 2 * 60 * 60 * 1000;

/**
 * Chronobar window presets (TN-6): the 48h review/projection window is the
 * default; 24h/6h narrow it symmetrically around now so recent activity is
 * not compressed into a sliver; `shift` anchors to the current 07:00/19:00
 * shift block (local clock, matching the chronobar's shift detents).
 */
export type WindowPreset = '48h' | '24h' | '6h' | 'shift';

export const WINDOW_PRESET_LABELS: Record<WindowPreset, string> = {
  '48h': '48 h',
  '24h': '24 h',
  '6h': '6 h',
  shift: 'Shift',
};

const PRESET_HALF_MS: Record<Exclude<WindowPreset, 'shift'>, number> = {
  '48h': LIVE_WINDOW_HALF_MS,
  '24h': 12 * 60 * 60 * 1000,
  '6h': 3 * 60 * 60 * 1000,
};

/** Start of the shift block containing `nowMs` (07:00–19:00 / 19:00–07:00 local). */
function shiftStart(nowMs: number): number {
  const cursor = new Date(nowMs);
  cursor.setMinutes(0, 0, 0);
  // Walk back at most 12 hours to the latest 07:00/19:00 boundary at or
  // before now — the same boundaries the chronobar renders as detents.
  for (let step = 0; step <= 12; step += 1) {
    if (cursor.getHours() === 7 || cursor.getHours() === 19) return cursor.getTime();
    cursor.setHours(cursor.getHours() - 1);
  }
  return cursor.getTime();
}

/** The live window for a preset. Historical sources keep their data-extent window instead. */
export function windowForPreset(preset: WindowPreset, nowMs: number): { start: number; end: number } {
  if (preset === 'shift') {
    const start = shiftStart(nowMs);
    return { start, end: start + 12 * 60 * 60 * 1000 };
  }
  const half = PRESET_HALF_MS[preset];
  return { start: nowMs - half, end: nowMs + half };
}

export interface ReplayTimeline {
  windowStart: number;
  windowEnd: number;
  currentTime: number;
  dataStart: number | null;
  dataEnd: number | null;
  freshness: PatientFlowFreshness;
  historical: boolean;
}

export interface PreparedReplay {
  events: PatientFlowEvent[];
  timeline: ReplayTimeline;
}

function validTime(value?: string | null): number | null {
  if (!value) return null;
  const time = parseTime(value);
  return Number.isFinite(time) ? time : null;
}

function eventExtent(events: PatientFlowEvent[]): { start: number | null; end: number | null } {
  let start: number | null = null;
  let end: number | null = null;

  for (const event of events) {
    const time = validTime(event.occurred_at);
    if (time === null) continue;
    start = start === null ? time : Math.min(start, time);
    end = end === null ? time : Math.max(end, time);
  }

  return { start, end };
}

function inferredFreshness(summary: PatientFlowSummary, nowMs: number, dataEnd: number | null): PatientFlowFreshness {
  if (summary.source?.freshness) return summary.source.freshness;
  if (dataEnd === null) return 'missing';
  const staleAfterMs = Math.max(1, summary.source?.stale_after_seconds ?? 900) * 1000;
  return nowMs - dataEnd > staleAfterMs ? 'stale' : 'fresh';
}

/**
 * Builds the viewer clock from the data contract. Stale events remain loaded
 * and get their own historical window; only fresh data uses the wall-clock
 * review/projection window.
 */
export function buildReplayTimeline(
  summary: PatientFlowSummary,
  events: PatientFlowEvent[],
  nowMs: number,
  requestedTime: number | null = null,
): ReplayTimeline {
  const loaded = eventExtent(events);
  const contractStart = validTime(summary.data_extent?.first_event_at ?? summary.min_occurred_at);
  const contractEnd = validTime(summary.data_extent?.last_event_at ?? summary.max_occurred_at);
  const dataStart = loaded.start ?? contractStart;
  const dataEnd = loaded.end ?? contractEnd;
  const freshness = inferredFreshness(summary, nowMs, dataEnd);
  const historical = freshness === 'stale' && dataEnd !== null;

  if (!historical) {
    const windowStart = nowMs - LIVE_WINDOW_HALF_MS;
    const windowEnd = nowMs + LIVE_WINDOW_HALF_MS;
    const requested = requestedTime ?? nowMs;

    return {
      windowStart,
      windowEnd,
      currentTime: Math.min(windowEnd, Math.max(windowStart, requested)),
      dataStart,
      dataEnd,
      freshness,
      historical: false,
    };
  }

  const suggested = validTime(summary.suggested_initial_time) ?? dataEnd;
  const extentStart = dataStart ?? dataEnd;
  const span = Math.max(0, dataEnd - extentStart);
  const padding = Math.max(0, (MINIMUM_HISTORICAL_WINDOW_MS - span) / 2);
  const windowStart = extentStart - padding;
  const windowEnd = dataEnd + padding;
  const requested = requestedTime ?? suggested;

  return {
    windowStart,
    windowEnd,
    currentTime: Math.min(windowEnd, Math.max(windowStart, requested)),
    dataStart,
    dataEnd,
    freshness,
    historical: true,
  };
}

export function replayStatus(timeline: ReplayTimeline): string {
  if (timeline.freshness === 'missing' || timeline.dataEnd === null) return 'No flow events available';
  if (timeline.freshness === 'degraded') return 'Degraded source - replay may be incomplete';
  if (timeline.historical) {
    return `Historical - last event ${new Date(timeline.dataEnd).toLocaleString()}`;
  }

  return 'Current event data loaded';
}

export function prepareReplay(
  summary: PatientFlowSummary,
  events: PatientFlowEvent[],
  nowMs: number,
  requestedTime: number | null = null,
): PreparedReplay {
  const orderedEvents = [...events].sort((left, right) => {
    const timeOrder = parseTime(left.occurred_at) - parseTime(right.occurred_at);
    return timeOrder !== 0 ? timeOrder : left.event_id.localeCompare(right.event_id);
  });

  return {
    events: orderedEvents,
    timeline: buildReplayTimeline(summary, orderedEvents, nowMs, requestedTime),
  };
}

export function recentReplayEvents(events: PatientFlowEvent[], limit = 8): PatientFlowEvent[] {
  if (limit <= 0) return [];
  const latest = [...events].sort((left, right) => {
    const timeOrder = parseTime(right.occurred_at) - parseTime(left.occurred_at);
    return timeOrder !== 0 ? timeOrder : right.event_id.localeCompare(left.event_id);
  });
  const selected = latest.slice(0, Math.ceil(limit / 2));
  const selectedIds = new Set(selected.map((event) => event.event_id));
  const movementEvents = latest.filter((event) => event.event_category === 'movement');
  const locatedMovements = movementEvents.filter((event) => event.from_location || event.to_location);
  const movementCandidates = locatedMovements.length > 0 ? locatedMovements : movementEvents;
  const movementTarget = Math.min(3, movementCandidates.length);

  for (const movement of movementCandidates) {
    if (selected.length >= limit || selected.filter((event) => event.event_category === 'movement').length >= movementTarget) break;
    if (!selectedIds.has(movement.event_id)) {
      selected.push(movement);
      selectedIds.add(movement.event_id);
    }
  }

  for (const event of latest) {
    if (selected.length >= limit) break;
    if (!selectedIds.has(event.event_id)) {
      selected.push(event);
      selectedIds.add(event.event_id);
    }
  }

  return selected.sort((left, right) => parseTime(right.occurred_at) - parseTime(left.occurred_at));
}
