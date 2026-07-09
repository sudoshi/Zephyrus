import TransportLayout from './TransportLayout';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';
import { EmptyTransportState, MetricTile, sampleRequest, TransportRequestRow, typeLabels } from './components';
import { useAssignTransportRequest, useCreateTransportRequest, useTransportOverview, useTransportRequests, useUpdateTransportStatus } from '@/features/transport/hooks';
import type { TransportRequest, TransportRequestType, TransportStatus } from '@/features/transport/types';
import type { ReactNode } from 'react';
import { CheckCircle2 } from 'lucide-react';

interface WorklistPageProps {
  title: string;
  subtitle: string;
  current: string;
  requestType?: TransportRequestType;
  /**
   * `dispatch` narrows the worklist to the actionable dispatch queue: pre-movement,
   * non-terminal requests awaiting assignment or launch. `default` (the implicit value
   * for Requests/Inpatient/etc.) shows the full worklist unchanged.
   */
  mode?: 'dispatch' | 'default';
  children?: ReactNode;
}

// Statuses that mean the transport has already left pickup or otherwise settled —
// these are NOT dispatchable and are excluded from the dispatch queue.
const POST_DISPATCH_STATUSES: TransportStatus[] = [
  'picked_up',
  'en_route',
  'arrived_destination',
  'handoff_started',
  'handoff_complete',
  'completed',
  'canceled',
  'failed',
];

function isDispatchable(row: TransportRequest): boolean {
  return !POST_DISPATCH_STATUSES.includes(row.status);
}

export default function WorklistPage({ title, subtitle, current, requestType, mode = 'default', children }: WorklistPageProps) {
  const requestsQuery = useTransportRequests(requestType, mode === 'dispatch' ? 'dispatch' : 'active');
  const overviewQuery = useTransportOverview();
  const createRequest = useCreateTransportRequest();
  const assignRequest = useAssignTransportRequest();
  const updateStatus = useUpdateTransportStatus();
  const allRows = requestsQuery.data ?? [];
  const isDispatch = mode === 'dispatch';
  const rows = isDispatch ? allRows.filter(isDispatchable) : allRows;
  const unassigned = rows.filter((row) => !row.assigned_team && !row.assigned_vendor).length;
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
      {requestsQuery.isError ? (
        <OperationalDataError
          title="Transport worklist unavailable"
          error={requestsQuery.error}
          onRetry={() => void requestsQuery.refetch()}
        />
      ) : requestsQuery.isLoading ? (
        <div className="rounded-md border border-healthcare-border p-4 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
          Loading transport requests...
        </div>
      ) : (
        <>
      {overviewQuery.data ? <SourceFreshnessBanner source={overviewQuery.data.source} onRetry={() => void overviewQuery.refetch()} /> : null}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricTile label={isDispatch ? 'In Queue' : 'Visible Requests'} value={rows.length} />
        {isDispatch ? (
          <MetricTile label="Unassigned" value={unassigned} tone={unassigned > 0 ? 'risk' : 'good'} />
        ) : (
          <MetricTile label="Active" value={active.length} />
        )}
        <MetricTile label="At Risk" value={atRisk} tone={atRisk > 0 ? 'risk' : 'neutral'} />
        {isDispatch ? (
          <MetricTile label="Assigned" value={rows.length - unassigned} tone="good" />
        ) : (
          <MetricTile label="Completed" value={rows.filter((row) => row.status === 'completed').length} tone="good" />
        )}
      </div>

      {children}

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div>
          <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {isDispatch
              ? 'Dispatch queue'
              : requestType
                ? `${typeLabels[requestType]} worklist`
                : 'Enterprise transport worklist'}
          </h2>
          <p className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {isDispatch
              ? 'Assign a team or vendor, then launch movement. Requests drop off once en route.'
              : 'Operational controls update the canonical transport event stream.'}
          </p>
        </div>
        {overviewQuery.data?.source.synthetic ? (
          <button
            type="button"
            onClick={handleCreate}
            disabled={createRequest.isPending}
            className="rounded-md bg-healthcare-primary px-4 py-2 text-sm/[18px] font-semibold text-white hover:opacity-90 disabled:opacity-60"
          >
            {createRequest.isPending ? 'Creating...' : 'Create sample request'}
          </button>
        ) : null}
      </div>

      <div className="space-y-3">
        {rows.length === 0 ? (
          isDispatch ? (
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-center dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <CheckCircle2 className="mx-auto h-8 w-8 text-healthcare-success dark:text-healthcare-success-dark" />
              <h3 className="mt-3 text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Dispatch queue clear
              </h3>
              <p className="mx-auto mt-1 max-w-xl text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                No requests are awaiting assignment or launch. New requests appear here until they go en route.
              </p>
            </div>
          ) : (
            <EmptyTransportState onCreate={handleCreate} />
          )
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
        </>
      )}
    </TransportLayout>
  );
}
