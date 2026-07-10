import TransportLayout from './TransportLayout';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';
import { MetricTile } from './components';
import { useTransportOverview } from '@/features/transport/hooks';
import type { TransportMeasure } from '@/features/transport/types';
import { formatDurationHours, formatDurationMinutes } from '@/lib/duration';

function measureValue(measure: TransportMeasure): string {
  if (measure.value === null) return '—';
  if (measure.key === 'avoidable_bed_hours') {
    return `${measure.value.toLocaleString(undefined, { maximumFractionDigits: 1 })} bed-hr`;
  }
  if (measure.unit === 'min') return formatDurationMinutes(measure.value);
  if (measure.unit === 'hrs') return formatDurationHours(measure.value);

  return `${measure.value} ${measure.unit}`.trim();
}

function measureCaption(measure: TransportMeasure): string {
  return measure.caption;
}

export default function Analytics() {
  const query = useTransportOverview();
  const { data } = query;
  const byType = data?.by_type ?? {};
  const measures = data?.measures ?? [];

  return (
    <TransportLayout
      title="Transport Analytics"
      subtitle="Early operational scorecard for throughput, delay risk, request mix, and vendor dependency"
      current="/transport/analytics"
    >
      {query.isError ? (
        <OperationalDataError title="Transport analytics unavailable" error={query.error} onRetry={() => void query.refetch()} />
      ) : query.isLoading || !data ? (
        <div className="rounded-md border border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">Loading transport analytics...</div>
      ) : (
        <>
      <SourceFreshnessBanner source={data.source} onRetry={() => void query.refetch()} />
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricTile label="Active" value={data.metrics.active} />
        <MetricTile label="At Risk" value={data.metrics.at_risk} tone={data.metrics.at_risk > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Completed Today" value={data.metrics.completed_today} tone="good" />
        <MetricTile label="STAT" value={data.metrics.stat} />
      </div>

      <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Active Request Mix</h2>
        <div className="mt-4 space-y-3">
          {Object.entries(byType).map(([type, count]) => {
            const total = Math.max(data.metrics.active, 1);
            const width = `${Math.round((count / total) * 100)}%`;
            return (
              <div key={type}>
                <div className="mb-1 flex items-center justify-between text-sm/[18px]">
                  <span className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{type.replaceAll('_', ' ')}</span>
                  <span className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{count}</span>
                </div>
                <div className="h-2 rounded bg-healthcare-hover dark:bg-healthcare-hover-dark">
                  <div className="h-2 rounded bg-healthcare-primary" style={{ width }} />
                </div>
              </div>
            );
          })}
          {Object.keys(byType).length === 0 && (
            <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              No active requests yet. Analytics populate from the canonical request and event tables.
            </p>
          )}
        </div>
      </section>

      <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Operational Measures</h2>
        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {measures.map((measure) => (
            <div key={measure.key} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
              <div className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{measure.label.replace(/ minutes$/, '')}</div>
              <div className="mt-1 text-2xl/[28px] font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {measureValue(measure)}
              </div>
              <div className="mt-1 text-xs/[16px] tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{measureCaption(measure)}</div>
            </div>
          ))}
          {measures.length === 0 && (
            <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark md:col-span-2 xl:col-span-3">
              No measures yet. They populate from the transport request and lifecycle event tables.
            </p>
          )}
        </div>
      </section>
        </>
      )}
    </TransportLayout>
  );
}
