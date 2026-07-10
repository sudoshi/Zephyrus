import { Clock3, Truck } from 'lucide-react';
import TransportLayout from './TransportLayout';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';
import { MetricTile, sampleRequest, TransportRequestRow } from './components';
import { useAssignTransportRequest, useCreateTransportRequest, useTransportOverview, useUpdateTransportStatus } from '@/features/transport/hooks';
import type { TransportRequest, TransportStatus } from '@/features/transport/types';
import { formatDurationHours, formatDurationMinutes } from '@/lib/duration';

export default function Dashboard() {
  const { data, error, isError, isLoading, refetch } = useTransportOverview();
  const createRequest = useCreateTransportRequest();
  const assignRequest = useAssignTransportRequest();
  const updateStatus = useUpdateTransportStatus();

  function assign(request: TransportRequest) {
    assignRequest.mutate({
      id: request.transport_request_id,
      assignedTeam: request.request_type === 'inpatient' || request.request_type === 'ems' ? 'Porter Pool' : undefined,
      assignedVendor: request.request_type === 'discharge' ? 'Ride Health' : request.request_type === 'transfer' ? 'Contracted Ambulance' : undefined,
    });
  }

  function status(request: TransportRequest, nextStatus: TransportStatus) {
    updateStatus.mutate({ id: request.transport_request_id, status: nextStatus });
  }

  if (isLoading) {
    return (
      <TransportLayout title="Transport Command Center" subtitle="Enterprise movement control for inpatient transport, transfers, discharge rides, EMS handoffs, and care transitions" current="/dashboard/transport">
        <div className="rounded-md border border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          Loading command queue...
        </div>
      </TransportLayout>
    );
  }

  if (isError || !data) {
    return (
      <TransportLayout title="Transport Command Center" subtitle="Enterprise movement control for inpatient transport, transfers, discharge rides, EMS handoffs, and care transitions" current="/dashboard/transport">
        <OperationalDataError title="Transport command center unavailable" error={error} onRetry={() => void refetch()} />
      </TransportLayout>
    );
  }

  return (
    <TransportLayout
      title="Transport Command Center"
      subtitle="Enterprise movement control for inpatient transport, transfers, discharge rides, EMS handoffs, and care transitions"
      current="/dashboard/transport"
    >
      <SourceFreshnessBanner source={data.source} onRetry={() => void refetch()} />
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-7">
        <MetricTile label="Active" value={data.metrics.active} />
        <MetricTile label="At Risk" value={data.metrics.at_risk} tone={data.metrics.at_risk > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="STAT" value={data.metrics.stat} tone={data.metrics.stat > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Completed Today" value={data.metrics.completed_today} tone="good" />
        <MetricTile label="Transfers" value={data.metrics.transfer_backlog} />
        <MetricTile label="Discharge Rides" value={data.metrics.discharge_rides} />
        <MetricTile label="EMS Inbound" value={data.metrics.ems_inbound} />
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <section className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg/[24px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Priority Queue
              </h2>
              <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Ordered by clinical priority and SLA target.
              </p>
            </div>
            {data.source.synthetic ? <div className="flex flex-wrap gap-2">
              {(['inpatient', 'transfer', 'discharge', 'ems', 'care_transition'] as const).map((type) => (
                <button
                  key={type}
                  type="button"
                  onClick={() => createRequest.mutate(sampleRequest(type))}
                  className="rounded-md border border-healthcare-border px-3 py-1.5 text-xs/[16px] font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
                >
                  Add {type.replace('_', ' ')}
                </button>
              ))}
            </div> : null}
          </div>
          {data.queue.length ? (
            data.queue.map((request) => (
              <TransportRequestRow key={request.transport_request_id} request={request} onAssign={assign} onStatus={status} />
            ))
          ) : (
            <div className="rounded-md border border-dashed border-healthcare-border p-6 dark:border-healthcare-border-dark">
              <div className="flex items-center gap-3">
                <Truck className="h-8 w-8 text-healthcare-primary" />
                <div>
                  <h3 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">No active movement yet</h3>
                  <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Use the quick-add controls above to create a request and run the dispatch workflow.
                  </p>
                </div>
              </div>
            </div>
          )}
        </section>

        <aside className="space-y-4">
          <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Active Status Mix
            </h2>
            <div className="mt-3 space-y-2">
              {Object.entries(data.by_status).map(([status, count]) => (
                <div key={status} className="flex items-center justify-between text-sm/[18px]">
                  <span className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{status.replaceAll('_', ' ')}</span>
                  <span className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{count}</span>
                </div>
              ))}
              {Object.keys(data.by_status).length === 0 && (
                <div className="flex items-center gap-2 text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <Clock3 className="h-4 w-4" /> No active statuses.
                </div>
              )}
            </div>
          </section>

          <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Service Measures
            </h2>
            <div className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {data.measures.slice(0, 4).map((measure) => (
                <div key={measure.key} className="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0">
                  <div>
                    <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{measure.label.replace(/ minutes$/, '')}</div>
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {measure.caption}
                    </div>
                  </div>
                  <div className="shrink-0 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {measure.value === null
                      ? 'Unknown'
                      : measure.key === 'avoidable_bed_hours'
                        ? `${measure.value.toLocaleString(undefined, { maximumFractionDigits: 1 })} bed-hr`
                        : measure.unit === 'min'
                        ? formatDurationMinutes(measure.value)
                        : measure.unit === 'hrs'
                          ? formatDurationHours(measure.value)
                          : `${measure.value} ${measure.unit}`}
                  </div>
                </div>
              ))}
            </div>
          </section>
        </aside>
      </div>
    </TransportLayout>
  );
}
