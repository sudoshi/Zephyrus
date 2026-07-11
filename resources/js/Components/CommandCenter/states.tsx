// resources/js/Components/CommandCenter/states.tsx
// Resilience surfaces for the command center: a defensible "data unavailable"
// fallback and a reusable empty state. The guiding rule (DESIGN.md): when in
// doubt, show nothing rather than risk showing the wrong numbers.
import { useEffect } from 'react';
import { Icon } from '@iconify/react';
import { formatDurationSeconds } from '@/lib/duration';

/**
 * Relative-time label derived from an ISO timestamp and a caller-supplied
 * "now" (so the caller controls the tick cadence). Truthful by construction:
 * if no fresh payload arrives, the label keeps aging instead of lying.
 */
export function relativeTimeFrom(iso: string, nowMs: number): string {
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return 'an unknown time';
  const s = Math.max(0, Math.round((nowMs - t) / 1000));
  if (s < 30) return 'just now';
  return `${formatDurationSeconds(s)} ago`;
}

export function CommandCenterError({ detail, onRetry }: { detail?: string; onRetry?: () => void }) {
  // Keep the technical reason for developers/support in the console; never put
  // raw schema/JS error text in front of a clinician mid-shift.
  useEffect(() => {
    if (detail) console.error('[CommandCenter] data unavailable:', detail);
  }, [detail]);

  return (
    <div
      role="alert"
      className="flex flex-col items-center justify-center gap-3 rounded-lg border border-healthcare-critical/30 bg-healthcare-critical/5 px-6 py-12 text-center dark:border-healthcare-critical-dark/30"
    >
      <Icon icon="heroicons:exclamation-triangle" aria-hidden="true"
            className="h-8 w-8 text-healthcare-critical dark:text-healthcare-critical-dark" />
      <div className="flex max-w-md flex-col gap-1">
        <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Command center data unavailable
        </h2>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          We couldn&rsquo;t assemble a complete operations picture, so nothing is shown rather than
          risk showing the wrong numbers. Retry in a moment; if it keeps happening, contact the
          operations desk.
        </p>
      </div>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="rounded-md bg-healthcare-primary px-4 py-2 text-sm font-medium text-white transition-colors duration-300 hover:bg-healthcare-primary/90 dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-dark/90"
        >
          Retry
        </button>
      ) : null}
    </div>
  );
}

export function EmptyState({ message, icon = 'heroicons:inbox' }: { message: string; icon?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 rounded-lg border border-dashed border-healthcare-border px-4 py-6 text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
      <Icon icon={icon} aria-hidden="true" className="h-4 w-4 shrink-0" />
      <span>{message}</span>
    </div>
  );
}
