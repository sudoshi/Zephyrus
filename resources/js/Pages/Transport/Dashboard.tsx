import { Activity, AlertTriangle, Ambulance, ArrowRightLeft, CheckCircle2, Clock3, Send, Truck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import TransportLayout from './TransportLayout';
import { MetricTile, sampleRequest, TransportRequestRow } from './components';
import { useAssignTransportRequest, useCreateTransportRequest, useTransportOverview, useUpdateTransportStatus } from '@/features/transport/hooks';
import type { TransportRequest, TransportStatus } from '@/features/transport/types';

const integrationSignals: { label: string; detail: string; Icon: LucideIcon }[] = [
  { label: 'FHIR ServiceRequest', detail: 'Canonical request/order', Icon: Activity },
  { label: 'FHIR Task', detail: 'Execution state and assignment', Icon: CheckCircle2 },
  { label: 'HL7 ADT', detail: 'Location and encounter movement feed', Icon: ArrowRightLeft },
  { label: 'NEMT APIs', detail: 'Ride quote, book, cancel, status', Icon: Send },
  { label: 'EMS Handoff', detail: 'ETA, ePCR, activation signals', Icon: Ambulance },
];

export default function Dashboard() {
  const { data, isLoading } = useTransportOverview();
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

  return (
    <TransportLayout
      title="Transport Command Center"
      subtitle="Enterprise movement control for inpatient transport, transfers, discharge rides, EMS handoffs, and care transitions"
      current="/dashboard/transport"
    >
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-7">
        <MetricTile label="Active" value={data?.metrics.active ?? 0} />
        <MetricTile label="At Risk" value={data?.metrics.at_risk ?? 0} tone={(data?.metrics.at_risk ?? 0) > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="STAT" value={data?.metrics.stat ?? 0} tone={(data?.metrics.stat ?? 0) > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Completed Today" value={data?.metrics.completed_today ?? 0} tone="good" />
        <MetricTile label="Transfers" value={data?.metrics.transfer_backlog ?? 0} />
        <MetricTile label="Discharge Rides" value={data?.metrics.discharge_rides ?? 0} />
        <MetricTile label="EMS Inbound" value={data?.metrics.ems_inbound ?? 0} />
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
            <div className="flex flex-wrap gap-2">
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
            </div>
          </div>
          {isLoading ? (
            <div className="rounded-md border border-healthcare-border p-4 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              Loading command queue...
            </div>
          ) : data?.queue.length ? (
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
              Integration Posture
            </h2>
            <div className="mt-3 space-y-3 text-sm/[18px]">
              {integrationSignals.map(({ label, detail, Icon }) => (
                <div key={label} className="flex gap-3">
                  <Icon className="mt-0.5 h-4 w-4 flex-shrink-0 text-healthcare-primary" />
                  <div>
                    <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{label}</div>
                    <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</div>
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Active Status Mix
            </h2>
            <div className="mt-3 space-y-2">
              {Object.entries(data?.by_status ?? {}).map(([status, count]) => (
                <div key={status} className="flex items-center justify-between text-sm/[18px]">
                  <span className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{status.replaceAll('_', ' ')}</span>
                  <span className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{count}</span>
                </div>
              ))}
              {Object.keys(data?.by_status ?? {}).length === 0 && (
                <div className="flex items-center gap-2 text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <Clock3 className="h-4 w-4" /> No active statuses.
                </div>
              )}
            </div>
          </section>

          <section className="rounded-md border border-healthcare-warning/30 bg-healthcare-warning/10 p-4 dark:border-healthcare-warning/30 dark:bg-healthcare-warning/20">
            <div className="flex gap-3">
              <AlertTriangle className="mt-0.5 h-5 w-5 flex-shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark" />
              <div>
                <h2 className="text-base/[20px] font-semibold text-healthcare-warning dark:text-healthcare-warning-dark">Phase 1 boundary</h2>
                <p className="mt-1 text-sm/[18px] text-healthcare-warning dark:text-healthcare-warning-dark">
                  This release establishes the command surface, canonical event stream, and connector-ready API. Vendor credentials and production interface engines remain future phases.
                </p>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </TransportLayout>
  );
}
