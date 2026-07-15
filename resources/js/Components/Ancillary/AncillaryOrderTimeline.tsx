import { AlertTriangle, Check, Circle, CircleDashed, RotateCcw, Square } from 'lucide-react';
import { usePageClock } from './PageClock';
import type { AncillaryTimelineContract, MilestoneState, SelectedClockContract } from './contracts';

const STATE: Record<MilestoneState, { label: string; icon: typeof Check; className: string }> = {
  done: { label: 'Completed', icon: Check, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  current: { label: 'Current selected milestone', icon: Circle, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
  pending_required: { label: 'Pending required milestone', icon: CircleDashed, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  missing_optional: { label: 'Optional milestone not asserted', icon: Square, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  terminal: { label: 'Terminal milestone', icon: Check, className: 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' },
  exception: { label: 'Exception or rework milestone', icon: RotateCcw, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
};

function minutesBetween(start: string, end: Date): number {
  return Math.max(0, Math.floor((end.getTime() - new Date(start).getTime()) / 60_000));
}

function clockText(clock: SelectedClockContract, now: Date): string {
  const elapsed = clock.state === 'complete' || clock.startedAt === null
    ? clock.elapsedMinutes
    : minutesBetween(clock.startedAt, now);
  if (elapsed === null) return 'Elapsed unavailable';
  const remaining = clock.breachMinutes === null ? null : Math.max(0, clock.breachMinutes - elapsed);
  return `${elapsed} min elapsed${remaining === null ? '' : ` · ${remaining} min remaining`}`;
}

export interface AncillaryOrderTimelineProps {
  value: AncillaryTimelineContract;
  variant?: 'compact' | 'expanded';
  onOpenDefinition?: (definitionUuid: string) => void;
}

export function AncillaryOrderTimeline({ value, variant = 'expanded', onOpenDefinition }: AncillaryOrderTimelineProps) {
  const now = usePageClock();
  const compact = variant === 'compact';

  return (
    <section aria-label={`${value.label} milestone timeline`} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value.label}</h3>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Source cutoff <span className="tabular-nums">{value.freshness.sourceCutoffAt ? new Date(value.freshness.sourceCutoffAt).toLocaleString() : 'unavailable'}</span> · {value.freshness.status === 'batch' ? 'Warehouse as-of' : value.freshness.status}
          </p>
        </div>
        {value.degradedMode && (
          <span role="status" className="inline-flex items-center gap-1 rounded-md border border-healthcare-warning px-2 py-1 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
            <AlertTriangle className="size-3.5" aria-hidden="true" /> Degraded feed: {value.degradedExplanation ?? 'Intermediate milestones are unavailable'}
          </span>
        )}
      </div>

      <ol className={`mt-3 grid gap-2 ${compact ? 'grid-cols-1' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
        {value.milestones.map((milestone) => {
          const style = STATE[milestone.state];
          const Icon = style.icon;
          return (
            <li key={milestone.code} className="min-w-0 rounded-md border border-healthcare-border p-2 dark:border-healthcare-border-dark">
              <div className="flex items-start gap-2">
                <span role="img" aria-label={style.label} className={style.className}><Icon className="size-4" aria-hidden="true" /></span>
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{milestone.label}</p>
                  <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {style.label}{milestone.required ? ' · Required' : ' · Optional'}
                  </p>
                  {milestone.selectedSource && <p className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Selected source: {milestone.selectedSource}</p>}
                  {milestone.conflict && <p role="status" className="text-xs text-healthcare-warning dark:text-healthcare-warning-dark">Source conflict · {milestone.assertionCount} assertions retained</p>}
                  {milestone.occurredAt && <time className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" dateTime={milestone.occurredAt}>{new Date(milestone.occurredAt).toLocaleString()}</time>}
                </div>
              </div>
            </li>
          );
        })}
      </ol>

      {value.clock && (
        <div role="timer" aria-live="off" aria-label={`${value.clock.label}: ${value.clock.state}`} className="mt-3 flex flex-wrap items-center gap-2 rounded-md bg-healthcare-background px-3 py-2 text-sm dark:bg-healthcare-background-dark">
          <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value.clock.label}</span>
          <span className="min-w-[18ch] tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{clockText(value.clock, now)}</span>
          {onOpenDefinition && <button type="button" className="ml-auto rounded-md border border-healthcare-border px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:border-healthcare-border-dark" onClick={() => onOpenDefinition(value.clock!.definitionUuid)}>View clock definition</button>}
        </div>
      )}
    </section>
  );
}
