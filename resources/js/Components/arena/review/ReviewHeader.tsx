// resources/js/Components/arena/review/ReviewHeader.tsx
//
// The Review's window / freshness / lens / Run-review row. Freshness reuses the
// Study's honesty (fresh · cached · serving-last-good) and Run triggers the one
// FlowReviewService::run() shared by the scheduled and on-demand paths.
export type Freshness = 'fresh' | 'cached' | 'stale';

const FRESH_TONE: Record<Freshness, string> = {
  fresh: 'text-healthcare-success dark:text-healthcare-success-dark',
  cached: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
  stale: 'text-healthcare-warning dark:text-healthcare-warning-dark',
};

function freshnessLabel(freshness: Freshness, generatedAt: string): string {
  const time = new Date(generatedAt).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  if (freshness === 'stale') return `Serving last review (mined ${time})`;
  if (freshness === 'cached') return `Cached · ${time}`;
  return `Mined ${time}`;
}

interface Props {
  windowLabel: string;
  priorLabel: string | null;
  freshness: Freshness;
  generatedAt: string;
  lensLabel?: string;
  onRun: () => void;
  running: boolean;
}

export function ReviewHeader({ windowLabel, priorLabel, freshness, generatedAt, lensLabel, onRun, running }: Props) {
  return (
    <div className="flex flex-wrap items-center gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="mr-2">
        <h2 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{windowLabel}</h2>
        {priorLabel && (
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Compared with prior review · {priorLabel}
          </p>
        )}
      </div>

      {lensLabel && (
        <span className="rounded-full border border-healthcare-border px-2.5 py-1 text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          lens · {lensLabel}
        </span>
      )}

      <span className={`text-xs font-medium ${FRESH_TONE[freshness]}`}>{freshnessLabel(freshness, generatedAt)}</span>

      <button
        type="button"
        onClick={onRun}
        disabled={running}
        className="ml-auto rounded-md bg-healthcare-primary px-3 py-1.5 text-xs font-medium text-white transition-colors hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold disabled:opacity-60 dark:bg-healthcare-primary-dark"
      >
        {running ? 'Running review…' : '↻ Run review'}
      </button>
    </div>
  );
}
