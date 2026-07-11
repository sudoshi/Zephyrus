// Canonical status/priority token maps for Virtual Rounds surfaces.
// Design canon: status is never conveyed by color alone — every chip pairs a
// label (and usually an icon); coral (healthcare-critical) is reserved for
// real breaches, so round states use teal/amber/sky and neutral text only.
import type { PatientStatus, RunStatus } from '@/features/virtualRounds/types';

export const PATIENT_STATUS_LABEL: Record<PatientStatus, string> = {
  queued: 'Queued',
  in_progress: 'In progress',
  awaiting_input: 'Awaiting input',
  ready_for_review: 'Ready for review',
  rounded: 'Rounded',
  deferred: 'Deferred',
  skipped: 'Skipped',
};

export const PATIENT_STATUS_CLASS: Record<PatientStatus, string> = {
  queued:
    'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-healthcare-border dark:border-healthcare-border-dark',
  in_progress:
    'text-healthcare-info dark:text-healthcare-info-dark border-healthcare-info/40 dark:border-healthcare-info-dark/40',
  awaiting_input:
    'text-healthcare-warning dark:text-healthcare-warning-dark border-healthcare-warning/40 dark:border-healthcare-warning-dark/40',
  ready_for_review:
    'text-healthcare-info dark:text-healthcare-info-dark border-healthcare-info/40 dark:border-healthcare-info-dark/40',
  rounded:
    'text-healthcare-success dark:text-healthcare-success-dark border-healthcare-success/40 dark:border-healthcare-success-dark/40',
  deferred:
    'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-healthcare-border dark:border-healthcare-border-dark',
  skipped:
    'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-healthcare-border dark:border-healthcare-border-dark',
};

export const RUN_STATUS_LABEL: Record<RunStatus, string> = {
  draft: 'Draft',
  scheduled: 'Scheduled',
  active: 'Active',
  paused: 'Paused',
  closing: 'Closing',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

export const PRIORITY_BAND_LABEL: Record<number, string> = {
  1: 'Pinned urgent',
  2: 'Time-critical',
  3: 'Discharge-ready',
  4: 'Coordination',
  5: 'Missing input',
  6: 'Routine',
};

// Bands 1–2 earn amber; 3 earns sky; the rest stay neutral. Coral is not
// used here — no round state is a breach.
export function priorityBandClass(band: number): string {
  if (band <= 2) {
    return 'text-healthcare-warning dark:text-healthcare-warning-dark';
  }
  if (band === 3) {
    return 'text-healthcare-info dark:text-healthcare-info-dark';
  }
  return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
}

export function formatWindow(startIso: string | null, endIso: string | null): string {
  if (!startIso || !endIso) {
    return '—';
  }

  const fmt = (iso: string) =>
    new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

  return `${fmt(startIso)}–${fmt(endIso)}`;
}

export function formatClock(iso: string | null): string {
  if (!iso) {
    return '—';
  }
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
