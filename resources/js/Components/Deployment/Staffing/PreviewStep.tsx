import type { ImportResult } from '@/features/deployment/staffing/types';
import { BUCKET_META, BUCKET_ORDER, BucketPill } from './bucketMeta';
import { BTN_GHOST, BTN_PRIMARY } from './controls';

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
      <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
    </div>
  );
}

interface PreviewStepProps {
  result: ImportResult;
  onNext: () => void;
  onBack: () => void;
}

export function PreviewStep({ result, onNext, onBack }: PreviewStepProps) {
  const counts = result.run.counts;
  const total = counts.total ?? 0;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-3 gap-3">
        <Stat label="Staged" value={total} />
        <Stat label="New people" value={counts.new ?? 0} />
        <Stat label="Updated" value={counts.updated ?? 0} />
      </div>

      <div className="space-y-2">
        <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Resolution buckets</div>
        <div className="space-y-2 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          {BUCKET_ORDER.map((bucket) => {
            const n = counts[bucket] ?? 0;
            const pct = total > 0 ? Math.round((n / total) * 100) : 0;
            return (
              <div key={bucket} className="flex items-center gap-3">
                <div className="w-36 shrink-0">
                  <BucketPill bucket={bucket} />
                </div>
                <div className="h-2 flex-1 overflow-hidden rounded-full bg-healthcare-background dark:bg-white/5">
                  <div className="h-full rounded-full bg-healthcare-primary/40" style={{ width: `${pct}%` }} />
                </div>
                <div className="w-14 shrink-0 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {n}
                </div>
              </div>
            );
          })}
        </div>
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Dry run — nothing is written yet. People are staged and resolved; the review step is where you approve, edit, or reject each.
        </p>
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        {BUCKET_ORDER.map((bucket) => (
          <div key={bucket} className="flex items-start gap-2 rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark">
            <BucketPill bucket={bucket} />
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{BUCKET_META[bucket].blurb}</span>
          </div>
        ))}
      </div>

      <div className="flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_GHOST} onClick={onBack}>← Back</button>
        <button type="button" className={BTN_PRIMARY} onClick={onNext}>Review &amp; reconcile →</button>
      </div>
    </div>
  );
}
