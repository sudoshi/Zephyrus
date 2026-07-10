import type { SourceFreshness } from '@/features/operations/sourceFreshness';
import { errorReference } from '@/features/operations/sourceFreshness';
import { AlertTriangle, Database, RefreshCw } from 'lucide-react';
import { formatDurationMinutes } from '@/lib/duration';

function freshnessMessage(source: SourceFreshness): string {
  if (source.age_minutes === null || !['aging', 'stale'].includes(source.status)) {
    return source.message;
  }

  return `${source.label} is ${source.status}; the last observation was ${formatDurationMinutes(source.age_minutes)} ago.`;
}

export function OperationalDataError({
  title,
  error,
  onRetry,
}: {
  title: string;
  error?: unknown;
  onRetry: () => void;
}) {
  const reference = errorReference(error);

  return (
    <div role="alert" className="rounded-md border border-healthcare-critical/30 bg-healthcare-critical/5 p-5 dark:border-healthcare-critical-dark/30 dark:bg-healthcare-critical-dark/10">
      <div className="flex items-start gap-3">
        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-healthcare-critical dark:text-healthcare-critical-dark" aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
          <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            The operational response could not be validated, so no empty or healthy state is being inferred.
          </p>
          {reference ? (
            <p className="mt-2 break-all font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Reference: {reference}
            </p>
          ) : null}
        </div>
        <button
          type="button"
          onClick={onRetry}
          className="inline-flex shrink-0 items-center gap-2 rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
        >
          <RefreshCw className="size-4" aria-hidden="true" />
          Retry
        </button>
      </div>
    </div>
  );
}

export function SourceFreshnessBanner({ source, onRetry }: { source: SourceFreshness; onRetry?: () => void }) {
  const isProblem = ['stale', 'missing', 'degraded'].includes(source.status);
  const isAging = source.status === 'aging';
  const tone = isProblem
    ? 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:border-healthcare-warning/40 dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark'
    : isAging
      ? 'border-healthcare-info/30 bg-healthcare-info/5 text-healthcare-text-secondary dark:border-healthcare-info/30 dark:bg-healthcare-info/10 dark:text-healthcare-text-secondary-dark'
      : 'border-healthcare-success/25 bg-healthcare-success/5 text-healthcare-text-secondary dark:border-healthcare-success-dark/25 dark:bg-healthcare-success-dark/10 dark:text-healthcare-text-secondary-dark';

  return (
    <div role={isProblem ? 'alert' : 'status'} className={`flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm ${tone}`}>
      {isProblem ? <AlertTriangle className="size-4 shrink-0" aria-hidden="true" /> : <Database className="size-4 shrink-0" aria-hidden="true" />}
      <span className="font-semibold capitalize">{source.status}</span>
      <span className="min-w-0 flex-1">{freshnessMessage(source)}</span>
      {source.synthetic ? <span className="rounded border border-current/25 px-1.5 py-0.5 text-xs font-semibold">Synthetic</span> : null}
      {onRetry && isProblem ? (
        <button type="button" onClick={onRetry} className="inline-flex items-center gap-1 font-semibold underline underline-offset-2 hover:no-underline">
          <RefreshCw className="size-3.5" aria-hidden="true" /> Retry
        </button>
      ) : null}
    </div>
  );
}
