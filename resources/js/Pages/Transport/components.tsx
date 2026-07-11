import { CheckCircle2, Clock3, Play, Route, Send, UserPlus, XCircle } from 'lucide-react';
import type { CompleteTransportHandoffInput, CreateTransportRequestInput, TransportPriority, TransportRequest, TransportRequestType, TransportStatus } from '@/features/transport/types';
import { formatRelativeDurationMinutes } from '@/lib/duration';
import { useState } from 'react';

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
  if (priority === 'stat') return 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark';
  if (priority === 'urgent') return 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark';
  return 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info/20 dark:text-healthcare-info-dark';
}

export function statusClass(status: TransportStatus): string {
  if (['completed', 'handoff_complete'].includes(status)) return 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark';
  if (['canceled', 'failed', 'patient_not_ready'].includes(status)) return 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark';
  if (['dispatched', 'picked_up', 'en_route'].includes(status)) return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200';
  return 'bg-healthcare-border text-healthcare-text-secondary dark:bg-healthcare-border-dark dark:text-healthcare-text-secondary-dark';
}

export function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: 'neutral' | 'risk' | 'good' }) {
  const toneClass = tone === 'risk'
    ? 'border-healthcare-critical/30 bg-healthcare-critical/10 dark:border-healthcare-critical/40 dark:bg-healthcare-critical/20'
    : tone === 'good'
      ? 'border-healthcare-success/30 bg-healthcare-success/10 dark:border-healthcare-success/40 dark:bg-healthcare-success/20'
      : 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark';

  return (
    <div className={`rounded-md border p-4 ${toneClass}`}>
      <div className="text-xs/[16px] font-medium uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
  onHandoff,
}: {
  request: TransportRequest;
  onAssign?: (request: TransportRequest) => void;
  onStatus?: (request: TransportRequest, status: TransportStatus) => void;
  onHandoff?: (request: TransportRequest, input: CompleteTransportHandoffInput) => void;
}) {
  const [showHandoff, setShowHandoff] = useState(false);
  const [handoffTo, setHandoffTo] = useState('');
  const [receiverRole, setReceiverRole] = useState('registered_nurse');
  const [summary, setSummary] = useState('');
  const [risks, setRisks] = useState('');
  const canMove = request.permissions.can_assign
    || request.permissions.can_handoff
    || request.allowed_transitions.length > 0;
  const slaLabel = request.sla.minutes_until_due === null
    ? request.sla.label
    : formatRelativeDurationMinutes(request.sla.minutes_until_due);

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded px-2 py-0.5 text-xs/[16px] font-semibold ${priorityClass(request.priority)}`}>
              {request.priority.toUpperCase()}
            </span>
            <span className={`rounded px-2 py-0.5 text-xs/[16px] font-semibold ${statusClass(request.status)}`}>
              {statusLabels[request.status]}
            </span>
            <span className="text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {typeLabels[request.request_type]} · {request.transport_mode}
            </span>
          </div>
          <div className="mt-2 text-base/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {request.patient_ref}
            {request.encounter_ref ? <span className="font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"> · {request.encounter_ref}</span> : null}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span>{request.origin}</span>
            <Route className="h-4 w-4" />
            <span>{request.destination}</span>
          </div>
          <div className="mt-2 flex flex-wrap gap-2 text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span className={request.sla.at_risk ? 'font-semibold text-healthcare-critical dark:text-healthcare-critical-dark' : ''}>{slaLabel}</span>
            {request.assigned_team ? <span>Team: {request.assigned_team}</span> : null}
            {request.assigned_vendor ? <span>Vendor: {request.assigned_vendor}</span> : null}
            {request.active_assignment?.resource_name ? <span>Reserved: {request.active_assignment.resource_name}</span> : null}
            {request.clinical_service ? <span>Service: {request.clinical_service}</span> : null}
            {request.handoff_required ? <span>Structured handoff required</span> : <span>No handoff required</span>}
          </div>
        </div>
        {canMove && (
          <div className="flex flex-wrap gap-2">
            {onAssign && request.permissions.can_assign && (
              <button
                type="button"
                onClick={() => onAssign(request)}
                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm/[18px] font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
              >
                <UserPlus className="h-4 w-4" /> Assign
              </button>
            )}
            {onStatus && request.allowed_transitions
              .filter((status) => status !== 'assigned' && status !== 'handoff_complete')
              .map((status) => {
                const destructive = ['canceled', 'failed'].includes(status);
                const positive = status === 'completed';
                const Icon = status === 'completed'
                  ? CheckCircle2
                  : status === 'canceled' || status === 'failed'
                    ? XCircle
                    : status === 'dispatched'
                      ? Send
                      : Play;
                return (
                  <button
                    key={status}
                    type="button"
                    onClick={() => onStatus(request, status)}
                    className={positive
                      ? 'inline-flex items-center gap-1 rounded-md bg-healthcare-success px-3 py-1.5 text-sm font-medium text-white hover:bg-healthcare-success/90'
                      : destructive
                        ? 'inline-flex items-center gap-1 rounded-md border border-healthcare-critical/30 px-3 py-1.5 text-sm font-medium text-healthcare-critical hover:bg-healthcare-critical/10 dark:border-healthcare-critical/40 dark:text-healthcare-critical-dark dark:hover:bg-healthcare-critical/20'
                        : 'inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark'}
                  >
                    <Icon className="h-4 w-4" /> {statusLabels[status]}
                  </button>
                );
              })}
            {onHandoff && request.permissions.can_handoff ? (
              <button
                type="button"
                onClick={() => setShowHandoff((visible) => !visible)}
                className="inline-flex items-center gap-1 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
              >
                <CheckCircle2 className="h-4 w-4" /> Structured handoff
              </button>
            ) : null}
          </div>
        )}
      </div>
      {showHandoff && onHandoff ? (
        <form
          className="mt-4 grid gap-3 border-t border-healthcare-border pt-4 md:grid-cols-2 dark:border-healthcare-border-dark"
          onSubmit={(event) => {
            event.preventDefault();
            const outstandingRisks = risks.split('\n').map((risk) => risk.trim()).filter(Boolean);
            onHandoff(request, {
              handoff_to: handoffTo.trim(),
              receiver_role: receiverRole.trim(),
              acceptance_status: outstandingRisks.length > 0 ? 'accepted_with_risks' : 'accepted',
              handoff_summary: summary.trim() || undefined,
              outstanding_risks: outstandingRisks,
            });
            setShowHandoff(false);
          }}
        >
          <label className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Receiver
            <input
              required
              value={handoffTo}
              onChange={(event) => setHandoffTo(event.target.value)}
              className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 font-normal dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"
              placeholder="Receiving clinician or service"
            />
          </label>
          <label className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Receiver role
            <input
              required
              value={receiverRole}
              onChange={(event) => setReceiverRole(event.target.value)}
              className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 font-normal dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"
            />
          </label>
          <label className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Summary
            <textarea
              value={summary}
              onChange={(event) => setSummary(event.target.value)}
              className="mt-1 min-h-20 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 font-normal dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"
            />
          </label>
          <label className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Outstanding risks (one per line)
            <textarea
              value={risks}
              onChange={(event) => setRisks(event.target.value)}
              className="mt-1 min-h-20 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 font-normal dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"
            />
          </label>
          <div className="flex gap-2 md:col-span-2">
            <button type="submit" className="rounded-md bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
              Record accepted handoff
            </button>
            <button type="button" onClick={() => setShowHandoff(false)} className="rounded-md border border-healthcare-border px-4 py-2 text-sm font-semibold dark:border-healthcare-border-dark">
              Close
            </button>
          </div>
        </form>
      ) : null}
    </div>
  );
}

export function EmptyTransportState({ onCreate }: { onCreate: () => void }) {
  return (
    <div className="rounded-md border border-dashed border-healthcare-border p-6 text-center dark:border-healthcare-border-dark">
      <Clock3 className="mx-auto h-8 w-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
      <h3 className="mt-3 text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        No active transport requests
      </h3>
      <p className="mx-auto mt-1 max-w-xl text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Create a request to exercise the command workflow: assign, dispatch, track movement, and complete the transport.
      </p>
      <button
        type="button"
        onClick={onCreate}
        className="mt-4 rounded-md bg-healthcare-primary px-4 py-2 text-sm/[18px] font-semibold text-white hover:opacity-90"
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
