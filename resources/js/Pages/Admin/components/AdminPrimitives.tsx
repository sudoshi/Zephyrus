import type { ReactNode } from 'react';

export interface AdminMetric {
  label: string;
  value: number | string;
  detail?: string;
  tone?: 'default' | 'warning' | 'critical';
}

const metricTone = {
  default: 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

export function AdminMetricStrip({ metrics }: { metrics: readonly AdminMetric[] }) {
  return (
    <dl className="grid overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
      {metrics.map((metric) => (
        <div
          key={metric.label}
          className="min-w-0 border-b border-healthcare-border px-3 py-2.5 last:border-b-0 dark:border-healthcare-border-dark sm:border-r sm:[&:nth-child(even)]:border-r-0 lg:[&:nth-child(even)]:border-r lg:[&:nth-child(4n)]:border-r-0 xl:border-b-0 xl:[&:nth-child(4n)]:border-r xl:last:border-r-0"
        >
          <dt className="truncate text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {metric.label}
          </dt>
          <dd className={`mt-0.5 text-xl font-semibold tabular-nums ${metricTone[metric.tone ?? 'default']}`}>
            {typeof metric.value === 'number' ? metric.value.toLocaleString() : metric.value}
          </dd>
          {metric.detail ? (
            <p className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {metric.detail}
            </p>
          ) : null}
        </div>
      ))}
    </dl>
  );
}

function outcomeTone(outcome: string): string {
  const normalized = outcome.toLowerCase();
  if (['success', 'succeeded', 'allowed', 'completed'].includes(normalized)) {
    return 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/10 dark:text-healthcare-success-dark';
  }
  if (['failure', 'failed', 'denied', 'error', 'blocked'].includes(normalized)) {
    return 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/10 dark:text-healthcare-critical-dark';
  }
  return 'bg-healthcare-surface-secondary text-healthcare-text-secondary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark';
}

export function OutcomeBadge({ outcome }: { outcome: string | null | undefined }) {
  const label = outcome || 'Unknown';
  return (
    <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium capitalize ${outcomeTone(label)}`}>
      {label.replaceAll('_', ' ')}
    </span>
  );
}

export function AdminSectionHeading({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <header className="mb-2 flex flex-wrap items-end justify-between gap-2">
      <div>
        <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {title}
        </h2>
        {description ? (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {description}
          </p>
        ) : null}
      </div>
      {action}
    </header>
  );
}
