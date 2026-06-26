import TransportLayout from './TransportLayout';
import { MetricTile } from './components';
import { useTransportOverview } from '@/features/transport/hooks';

export default function Analytics() {
  const { data } = useTransportOverview();
  const byType = data?.by_type ?? {};

  return (
    <TransportLayout
      title="Transport Analytics"
      subtitle="Early operational scorecard for throughput, delay risk, request mix, and vendor dependency"
      current="/transport/analytics"
    >
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricTile label="Active" value={data?.metrics.active ?? 0} />
        <MetricTile label="At Risk" value={data?.metrics.at_risk ?? 0} tone={(data?.metrics.at_risk ?? 0) > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Completed Today" value={data?.metrics.completed_today ?? 0} tone="good" />
        <MetricTile label="STAT" value={data?.metrics.stat ?? 0} />
      </div>

      <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Active Request Mix</h2>
        <div className="mt-4 space-y-3">
          {Object.entries(byType).map(([type, count]) => {
            const total = Math.max(data?.metrics.active ?? 0, 1);
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

      <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Planned Measures</h2>
        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {[
            'Request-to-assign minutes',
            'Dispatch-to-pickup minutes',
            'Pickup-to-destination minutes',
            'Patient-not-ready delay rate',
            'Avoidable bed-hours attributed to transport',
            'Vendor acceptance and cancellation rate',
          ].map((measure) => (
            <div key={measure} className="rounded-md border border-healthcare-border p-3 text-sm/[18px] dark:border-healthcare-border-dark">
              {measure}
            </div>
          ))}
        </div>
      </section>
    </TransportLayout>
  );
}
