import type { ReadinessCheck, ReadinessReport, ReadinessStatus } from '@/features/deployment/types';
import { CheckCircle2, ChevronRight, XCircle } from 'lucide-react';
import { ReadinessPill } from './status';

// Order checks so the reader sees what blocks deployment first, then advisories.
const STATUS_ORDER: Record<ReadinessStatus, number> = {
  fail: 0,
  warn: 1,
  info: 2,
  pass: 3,
  not_applicable: 4,
};

function summarizeFailure(failure: Record<string, unknown>): string {
  return Object.entries(failure)
    .map(([k, v]) => (k === undefined ? String(v) : `${k}: ${String(v)}`))
    .join(' · ');
}

function CheckRow({ check }: { check: ReadinessCheck }) {
  const hasDetail = check.count > 0 && check.failures.length > 0;

  const header = (
    <div className="flex items-center gap-3">
      <span className="w-6 shrink-0 text-xs font-medium tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {check.criterion}
      </span>
      <span className="min-w-0 flex-1 truncate text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {check.title}
      </span>
      {check.count > 0 && (
        <span className="shrink-0 text-xs font-medium tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {check.count}
        </span>
      )}
      <ReadinessPill status={check.status} />
    </div>
  );

  if (!hasDetail) {
    return <div className="px-3 py-2">{header}</div>;
  }

  return (
    <details className="group px-3 py-2">
      <summary className="flex cursor-pointer list-none items-center gap-2 rounded-md">
        <ChevronRight className="size-3.5 shrink-0 text-healthcare-text-secondary transition-transform duration-200 group-open:rotate-90 dark:text-healthcare-text-secondary-dark" />
        <div className="min-w-0 flex-1">{header}</div>
      </summary>
      <ul className="mt-2 space-y-1 pl-9">
        {check.failures.slice(0, 25).map((failure, i) => (
          <li
            key={i}
            className="truncate rounded bg-healthcare-background px-2 py-1 text-xs tabular-nums text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark"
          >
            {summarizeFailure(failure)}
          </li>
        ))}
        {check.count > 25 && (
          <li className="px-2 py-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            + {check.count - 25} more
          </li>
        )}
      </ul>
    </details>
  );
}

export function ReadinessScorecard({ report }: { report: ReadinessReport }) {
  const ready = report.deployment_ready;
  const checks = [...report.checks].sort((a, b) => STATUS_ORDER[a.status] - STATUS_ORDER[b.status] || a.criterion - b.criterion);

  const summaryOrder: ReadinessStatus[] = ['fail', 'warn', 'info', 'pass', 'not_applicable'];
  const summaryLabel: Record<ReadinessStatus, string> = {
    fail: 'Blocking',
    warn: 'Review',
    info: 'Info',
    pass: 'Passing',
    not_applicable: 'N/A',
  };

  return (
    <div className="space-y-3">
      {/* Verdict — coral only for a genuine block, teal for a genuine pass (earned). */}
      <div
        className={`flex items-center gap-3 rounded-lg border p-4 ${
          ready
            ? 'border-healthcare-success/30 bg-healthcare-success/5 dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10'
            : 'border-healthcare-critical/30 bg-healthcare-critical/5 dark:border-healthcare-critical-dark/30 dark:bg-healthcare-critical-dark/10'
        }`}
      >
        {ready ? (
          <CheckCircle2 className="size-6 shrink-0 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
        ) : (
          <XCircle className="size-6 shrink-0 text-healthcare-critical dark:text-healthcare-critical-dark" aria-hidden="true" />
        )}
        <div className="min-w-0">
          <div className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {ready ? 'Deployment ready' : 'Not deployment ready'}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {report.facility_name ?? report.facility_key} ·{' '}
            <span className="tabular-nums">{report.summary.fail ?? 0}</span> blocking of{' '}
            <span className="tabular-nums">{report.checks.length}</span> checks
          </div>
        </div>
        <div className="ml-auto flex flex-wrap items-center gap-1.5">
          {summaryOrder.map((s) =>
            (report.summary[s] ?? 0) > 0 ? (
              <span
                key={s}
                className="inline-flex items-center gap-1 rounded-full bg-healthcare-background px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark"
              >
                <span className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {report.summary[s]}
                </span>
                {summaryLabel[s]}
              </span>
            ) : null,
          )}
        </div>
      </div>

      {/* Per-criterion rows */}
      <div className="divide-y divide-healthcare-border rounded-lg border border-healthcare-border bg-healthcare-surface dark:divide-healthcare-border-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        {checks.map((check) => (
          <CheckRow key={check.key} check={check} />
        ))}
      </div>
    </div>
  );
}
