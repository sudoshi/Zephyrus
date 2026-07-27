import axios from 'axios';

import { patientJourneySchema } from '@/features/patientFlowNavigator/journeySchemas';
import type { PatientJourney } from '@/features/patientFlowNavigator/journeySchemas';

/**
 * Patient Journey Drawer data + pure helpers (FLOW-4D plan §7.1 / §8 Phase B,
 * findings PJ-1/PJ-2). Fetching rides the SAME contract every lensed surface
 * uses: persona forwarded, patient addressed ONLY by opaque ptok scope; the
 * server 403s personas without patient scope and this module surfaces that as
 * a typed unavailability, never a crash.
 */

export type JourneyFetchResult =
  | { kind: 'ok'; journey: PatientJourney }
  | { kind: 'forbidden' }
  | { kind: 'error'; message: string };

export async function fetchPatientJourney(options: {
  patientContextRef: string;
  persona?: string;
  from?: string;
  to?: string;
}): Promise<JourneyFetchResult> {
  try {
    const response = await axios.get('/api/patient-flow/journey', {
      params: {
        scope: `patient:${options.patientContextRef}`,
        ...(options.persona ? { persona: options.persona } : {}),
        ...(options.from ? { from: options.from } : {}),
        ...(options.to ? { to: options.to } : {}),
      },
    });

    const parsed = patientJourneySchema.safeParse(response.data);
    if (!parsed.success) {
      return { kind: 'error', message: 'Journey payload failed validation' };
    }

    return { kind: 'ok', journey: parsed.data };
  } catch (caught) {
    if (axios.isAxiosError(caught) && caught.response?.status === 403) {
      return { kind: 'forbidden' };
    }

    return {
      kind: 'error',
      message: caught instanceof Error ? caught.message : 'Journey unavailable',
    };
  }
}

/** Sentinel alignment anchors (LifeLines2 align-rank-filter, plan §6.3). */
export type JourneyAlignAnchor = 'clock' | 'arrival' | 'admit';

/** The t0 (ms) for an alignment anchor, or null when the journey lacks it. */
export function alignAnchorMs(journey: PatientJourney, anchor: JourneyAlignAnchor): number | null {
  if (anchor === 'arrival') {
    const first = journey.events.find((event) => event.occurred_at);
    return first?.occurred_at ? Date.parse(first.occurred_at) : null;
  }

  if (anchor === 'admit') {
    return journey.header.admitted_at ? Date.parse(journey.header.admitted_at) : null;
  }

  return null; // 'clock' — absolute times, no re-zeroing
}

/** "+2 hr 10 min" style offset from an anchor; absolute time when unanchored. */
export function alignedTimeLabel(iso: string | null | undefined, anchorMs: number | null): string {
  if (!iso) return '--';
  const ms = Date.parse(iso);
  if (!Number.isFinite(ms)) return '--';

  if (anchorMs === null) {
    return new Date(ms).toLocaleString([], { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  const deltaMinutes = Math.round((ms - anchorMs) / 60_000);
  const sign = deltaMinutes < 0 ? '−' : '+';
  const abs = Math.abs(deltaMinutes);
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  return hours > 0 ? `${sign}${hours} hr ${minutes} min` : `${sign}${minutes} min`;
}

/** Compact dwell label from minutes: "45 min", "3 hr 20 min", "2 d 4 hr". */
export function dwellLabel(minutes: number | null | undefined): string {
  if (typeof minutes !== 'number' || !Number.isFinite(minutes) || minutes < 0) return '--';
  const total = Math.round(minutes);
  if (total < 60) return `${total} min`;
  const hours = Math.floor(total / 60);
  if (hours < 24) return `${hours} hr ${total % 60} min`;
  const days = Math.floor(hours / 24);
  return `${days} d ${hours % 24} hr`;
}

/** The selected patient's event instants (ms) for chronobar ticks (B3/B5). */
export function journeyEventTicks(journey: PatientJourney): number[] {
  return journey.events
    .map((event) => (event.occurred_at ? Date.parse(event.occurred_at) : Number.NaN))
    .filter((ms) => Number.isFinite(ms));
}

/**
 * Scented-widget density (Willett et al., plan §6.2): bucket event instants
 * across a window into normalized 0..1 intensities. Pure and generic — the
 * chronobar feeds it the house event array; the drawer could feed it one
 * journey. Returns an empty array when the window is degenerate.
 */
export function eventDensityBuckets(
  eventTimesMs: number[],
  windowStart: number,
  windowEnd: number,
  bucketCount = 48,
): number[] {
  if (!(windowEnd > windowStart) || bucketCount < 1) return [];

  const counts = new Array<number>(bucketCount).fill(0);
  const span = windowEnd - windowStart;
  for (const ms of eventTimesMs) {
    if (!Number.isFinite(ms) || ms < windowStart || ms > windowEnd) continue;
    const index = Math.min(bucketCount - 1, Math.floor(((ms - windowStart) / span) * bucketCount));
    counts[index] += 1;
  }

  const max = Math.max(...counts);
  if (max <= 0) return counts;
  return counts.map((count) => count / max);
}
