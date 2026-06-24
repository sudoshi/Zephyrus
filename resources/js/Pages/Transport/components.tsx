import { CheckCircle2, Clock3, Play, Route, Send, UserPlus, XCircle } from 'lucide-react';
import type { CreateTransportRequestInput, TransportPriority, TransportRequest, TransportRequestType, TransportStatus } from '@/features/transport/types';

export const typeLabels: Record<TransportRequestType, string> = {
  inpatient: 'Inpatient',
  transfer: 'Transfer',
  discharge: 'Discharge',
  ems: 'EMS',
  care_transition: 'Care Transition',
};

export const statusLabels: Record<TransportStatus, string> = {
  requested: 'Requested',
  accepted: 'Accepted',
  queued: 'Queued',
  assigned: 'Assigned',
  dispatched: 'Dispatched',
  arrived_pickup: 'At pickup',
  patient_ready: 'Ready',
  patient_not_ready: 'Not ready',
  picked_up: 'Picked up',
  en_route: 'En route',
  arrived_destination: 'At destination',
  handoff_started: 'Handoff started',
  handoff_complete: 'Handoff complete',
  completed: 'Completed',
  canceled: 'Canceled',
  escalated: 'Escalated',
  failed: 'Failed',
};

export function priorityClass(priority: TransportPriority): string {
  if (priority === 'stat') return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-200';
  if (priority === 'urgent') return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200';
  return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200';
}

export function statusClass(status: TransportStatus): string {
  if (['completed', 'handoff_complete'].includes(status)) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200';
  if (['canceled', 'failed', 'patient_not_ready'].includes(status)) return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-200';
  if (['dispatched', 'picked_up', 'en_route'].includes(status)) return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200';
  return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100';
}

export function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: 'neutral' | 'risk' | 'good' }) {
  const toneClass = tone === 'risk'
    ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/20'
    : tone === 'good'
      ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20'
      : 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark';

  return (
    <div className={`rounded-md border p-4 ${toneClass}`}>
      <div className="text-[12px]/[16px] font-medium uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </div>
      <div className="mt-2 text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </div>
    </div>
  );
}

export function TransportRequestRow({
  request,
  onAssign,
  onStatus,
}: {
  request: TransportRequest;
  onAssign?: (request: TransportRequest) => void;
  onStatus?: (request: TransportRequest, status: TransportStatus) => void;
}) {
  const canMove = !['completed', 'canceled', 'failed'].includes(request.status);

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded px-2 py-0.5 text-[12px]/[16px] font-semibold ${priorityClass(request.priority)}`}>
              {request.priority.toUpperCase()}
            </span>
            <span className={`rounded px-2 py-0.5 text-[12px]/[16px] font-semibold ${statusClass(request.status)}`}>
              {statusLabels[request.status]}
            </span>
            <span className="text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {typeLabels[request.request_type]} · {request.transport_mode}
            </span>
          </div>
          <div className="mt-2 text-[15px]/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {request.patient_ref}
            {request.encounter_ref ? <span className="font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"> · {request.encounter_ref}</span> : null}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span>{request.origin}</span>
            <Route className="h-4 w-4" />
            <span>{request.destination}</span>
          </div>
          <div className="mt-2 flex flex-wrap gap-2 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span className={request.sla.at_risk ? 'font-semibold text-red-700 dark:text-red-300' : ''}>{request.sla.label}</span>
            {request.assigned_team ? <span>Team: {request.assigned_team}</span> : null}
            {request.assigned_vendor ? <span>Vendor: {request.assigned_vendor}</span> : null}
            {request.clinical_service ? <span>Service: {request.clinical_service}</span> : null}
          </div>
        </div>
        {canMove && (
          <div className="flex flex-wrap gap-2">
            {onAssign && (
              <button
                type="button"
                onClick={() => onAssign(request)}
                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-[13px]/[18px] font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
              >
                <UserPlus className="h-4 w-4" /> Assign
              </button>
            )}
            {onStatus && (
              <>
                <button
                  type="button"
                  onClick={() => onStatus(request, 'dispatched')}
                  className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-[13px]/[18px] font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
                >
                  <Send className="h-4 w-4" /> Dispatch
                </button>
                <button
                  type="button"
                  onClick={() => onStatus(request, 'en_route')}
                  className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-[13px]/[18px] font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
                >
                  <Play className="h-4 w-4" /> En Route
                </button>
                <button
                  type="button"
                  onClick={() => onStatus(request, 'completed')}
                  className="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-[13px]/[18px] font-medium text-white hover:bg-emerald-700"
                >
                  <CheckCircle2 className="h-4 w-4" /> Complete
                </button>
                <button
                  type="button"
                  onClick={() => onStatus(request, 'canceled')}
                  className="inline-flex items-center gap-1 rounded-md border border-red-200 px-3 py-1.5 text-[13px]/[18px] font-medium text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/20"
                >
                  <XCircle className="h-4 w-4" /> Cancel
                </button>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export function EmptyTransportState({ onCreate }: { onCreate: () => void }) {
  return (
    <div className="rounded-md border border-dashed border-healthcare-border p-6 text-center dark:border-healthcare-border-dark">
      <Clock3 className="mx-auto h-8 w-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
      <h3 className="mt-3 text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        No active transport requests
      </h3>
      <p className="mx-auto mt-1 max-w-xl text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Create a request to exercise the command workflow: assign, dispatch, track movement, and complete the transport.
      </p>
      <button
        type="button"
        onClick={onCreate}
        className="mt-4 rounded-md bg-healthcare-primary px-4 py-2 text-[13px]/[18px] font-semibold text-white hover:opacity-90"
      >
        Create sample request
      </button>
    </div>
  );
}

export function sampleRequest(type: TransportRequestType = 'inpatient'): CreateTransportRequestInput {
  const now = new Date();
  now.setMinutes(now.getMinutes() + (type === 'ems' ? 12 : type === 'transfer' ? 45 : 25));

  const base = {
    request_type: type,
    priority: type === 'ems' || type === 'transfer' ? 'urgent' : 'routine',
    patient_ref: `patient-${Math.floor(1000 + Math.random() * 8999)}`,
    encounter_ref: `enc-${Math.floor(10000 + Math.random() * 89999)}`,
    needed_at: now.toISOString(),
    requested_by: 'Zephyrus Transport',
  } satisfies Partial<CreateTransportRequestInput>;

  if (type === 'transfer') {
    return {
      ...base,
      request_type: 'transfer',
      origin: 'Community Hospital ED',
      destination: 'Zephyrus ICU 4E',
      transport_mode: 'critical_care',
      clinical_service: 'Critical Care',
      priority: 'urgent',
      risk_flags: ['oxygen', 'monitor', 'receiving-bed-pending'],
    } as CreateTransportRequestInput;
  }

  if (type === 'discharge') {
    return {
      ...base,
      request_type: 'discharge',
      origin: '6 West',
      destination: 'Home via discharge lounge',
      transport_mode: 'wheelchair',
      clinical_service: 'Hospital Medicine',
      priority: 'routine',
      risk_flags: ['wheelchair', 'family-notified'],
    } as CreateTransportRequestInput;
  }

  if (type === 'ems') {
    return {
      ...base,
      request_type: 'ems',
      origin: 'EMS inbound',
      destination: 'ED Resus 2',
      transport_mode: 'ems',
      clinical_service: 'Emergency',
      priority: 'stat',
      risk_flags: ['eta-12m', 'prehospital-alert'],
    } as CreateTransportRequestInput;
  }

  if (type === 'care_transition') {
    return {
      ...base,
      request_type: 'care_transition',
      origin: '5 East',
      destination: 'Skilled nursing facility pending acceptance',
      transport_mode: 'nemt',
      clinical_service: 'Case Management',
      priority: 'urgent',
      risk_flags: ['authorization-needed', 'packet-pending'],
    } as CreateTransportRequestInput;
  }

  return {
    ...base,
    request_type: 'inpatient',
    origin: 'ED Bay 18',
    destination: 'CT Scanner 2',
    transport_mode: 'stretcher',
    clinical_service: 'Emergency',
    risk_flags: ['fall-risk', 'oxygen'],
  } as CreateTransportRequestInput;
}
