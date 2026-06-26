import TransportLayout from './TransportLayout';
import { EmptyTransportState, MetricTile, sampleRequest, TransportRequestRow, typeLabels } from './components';
import { useAssignTransportRequest, useCreateTransportRequest, useTransportRequests, useUpdateTransportStatus } from '@/features/transport/hooks';
import type { TransportRequest, TransportRequestType, TransportStatus } from '@/features/transport/types';
import type { ReactNode } from 'react';

interface WorklistPageProps {
  title: string;
  subtitle: string;
  current: string;
  requestType?: TransportRequestType;
  children?: ReactNode;
}

export default function WorklistPage({ title, subtitle, current, requestType, children }: WorklistPageProps) {
  const { data: requests, isLoading } = useTransportRequests(requestType);
  const createRequest = useCreateTransportRequest();
  const assignRequest = useAssignTransportRequest();
  const updateStatus = useUpdateTransportStatus();
  const rows = requests ?? [];
  const active = rows.filter((row) => !['completed', 'canceled', 'failed'].includes(row.status));
  const atRisk = rows.filter((row) => row.sla.at_risk).length;

  function handleCreate() {
    createRequest.mutate(sampleRequest(requestType ?? 'inpatient'));
  }

  function handleAssign(request: TransportRequest) {
    const assignedVendor = request.request_type === 'discharge' || request.request_type === 'care_transition'
      ? 'Ride Health'
      : request.request_type === 'ems'
        ? 'EMS Liaison'
        : undefined;
    const assignedTeam = assignedVendor ? undefined : 'Porter Pool';
    assignRequest.mutate({ id: request.transport_request_id, assignedTeam, assignedVendor });
  }

  function handleStatus(request: TransportRequest, status: TransportStatus) {
    updateStatus.mutate({ id: request.transport_request_id, status });
  }

  return (
    <TransportLayout title={title} subtitle={subtitle} current={current}>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricTile label="Visible Requests" value={rows.length} />
        <MetricTile label="Active" value={active.length} />
        <MetricTile label="At Risk" value={atRisk} tone={atRisk > 0 ? 'risk' : 'neutral'} />
        <MetricTile label="Completed" value={rows.filter((row) => row.status === 'completed').length} tone="good" />
      </div>

      {children}

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div>
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {requestType ? `${typeLabels[requestType]} worklist` : 'Enterprise transport worklist'}
          </h2>
          <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Operational controls update the canonical transport event stream.
          </p>
        </div>
        <button
          type="button"
          onClick={handleCreate}
          disabled={createRequest.isPending}
          className="rounded-md bg-healthcare-primary px-4 py-2 text-sm/[18px] font-semibold text-white hover:opacity-90 disabled:opacity-60"
        >
          {createRequest.isPending ? 'Creating...' : 'Create sample request'}
        </button>
      </div>

      <div className="space-y-3">
        {isLoading ? (
          <div className="rounded-md border border-healthcare-border p-4 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            Loading transport requests...
          </div>
        ) : rows.length === 0 ? (
          <EmptyTransportState onCreate={handleCreate} />
        ) : (
          rows.map((request) => (
            <TransportRequestRow
              key={request.transport_request_id}
              request={request}
              onAssign={handleAssign}
              onStatus={handleStatus}
            />
          ))
        )}
      </div>
    </TransportLayout>
  );
}
