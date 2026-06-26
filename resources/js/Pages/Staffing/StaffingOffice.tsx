import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { useAssignStaffingRequest, useStaffingOverview, useUpdateStaffingStatus } from '@/features/staffing/hooks';
import type {
  StaffingAssignedSource,
  StaffingRequest,
  StaffingRoleGap,
  StaffingUnitAtRisk,
} from '@/features/staffing/types';
import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ShieldAlert, UserCog, Users } from 'lucide-react';

const SOURCE_LABELS: Record<StaffingAssignedSource, string> = {
  float_pool: 'Float',
  overtime: 'Overtime',
  agency: 'Agency',
  on_call: 'On-call',
};

const PRIORITY_TONE: Record<string, string> = {
  stat: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark',
  urgent: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark',
  routine: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/20 dark:text-healthcare-info-dark',
};

const CHIP = 'rounded-md bg-slate-100 px-2 py-1 text-xs/[15px] font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300';

function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: 'neutral' | 'risk' | 'good' }) {
  const toneClass =
    tone === 'risk'
      ? 'border-healthcare-critical/30 bg-healthcare-critical/5 dark:border-healthcare-critical-dark/30 dark:bg-healthcare-critical-dark/10'
      : tone === 'good'
        ? 'border-healthcare-success/30 bg-healthcare-success/5 dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10'
        : 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark';

  return (
    <div className={`rounded-md border p-4 ${toneClass}`}>
      <div className="text-xs/[16px] font-medium uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </div>
      <div className="mt-1 text-2xl/[28px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </div>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const critical = status === 'critical_gap';
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs/[15px] font-semibold ${
        critical ? 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark' : 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark'
      }`}
    >
      {critical ? <ShieldAlert className="size-3" /> : <AlertTriangle className="size-3" />}
      {critical ? 'Critical gap' : 'Gap'}
    </span>
  );
}

function UnitCard({ unit }: { unit: StaffingUnitAtRisk }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-2">
        <div>
          <div className="text-base/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {unit.unit_label}
          </div>
          <div className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Short {unit.gap_headcount} · worst {unit.worst_role_label}
          </div>
        </div>
        <StatusBadge status={unit.status} />
      </div>
      {unit.below_minimum_safe && (
        <div className="mt-2 flex items-center gap-1 text-xs/[16px] font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
          <ShieldAlert className="size-3.5" /> Below minimum safe staffing
        </div>
      )}
      <ul className="mt-3 space-y-1">
        {unit.roles.map((role) => (
          <li
            key={role.staffing_plan_id}
            className="flex items-center justify-between text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
          >
            <span>{role.role_label}</span>
            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {Math.max(role.scheduled_count, role.actual_count)}/{role.required_count}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function RoleGapBar({ row }: { row: StaffingRoleGap }) {
  const pct = row.required_count > 0 ? Math.round((row.available_count / row.required_count) * 100) : 100;
  return (
    <div>
      <div className="flex items-center justify-between text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span>{row.role_label}</span>
        <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          short {row.gap_headcount}
        </span>
      </div>
      <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
        <div className="h-full rounded-full bg-healthcare-primary" style={{ width: `${Math.min(100, pct)}%` }} />
      </div>
    </div>
  );
}

function RequestRow({ request }: { request: StaffingRequest }) {
  const assign = useAssignStaffingRequest();
  const updateStatus = useUpdateStaffingStatus();
  const isActive = !['filled', 'completed', 'canceled', 'unfilled'].includes(request.status);
  const sources: StaffingAssignedSource[] = ['float_pool', 'overtime', 'agency', 'on_call'];

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-base/[19px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {request.unit_label} · {request.role_label}
            </span>
            <span className={`rounded-full px-2 py-0.5 text-xs/[15px] font-semibold ${PRIORITY_TONE[request.priority] ?? ''}`}>
              {request.priority.toUpperCase()}
            </span>
          </div>
          <div className="mt-0.5 text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Need {request.headcount_needed} · {request.shift} shift · {request.sla.label}
            {request.assigned_source ? ` · ${SOURCE_LABELS[request.assigned_source]}` : ''}
          </div>
        </div>
        <span className={CHIP}>{request.status}</span>
      </div>

      {isActive && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {request.status !== 'assigned' &&
            sources.map((source) => (
              <button
                key={source}
                type="button"
                disabled={assign.isPending}
                onClick={() => assign.mutate({ id: request.staffing_request_id, input: { assigned_source: source, owner_name: 'Staffing office' } })}
                className="rounded-md border border-healthcare-border px-2.5 py-1 text-xs/[16px] font-medium text-healthcare-text-secondary hover:bg-slate-50 disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-white/5"
              >
                {SOURCE_LABELS[source]}
              </button>
            ))}
          <button
            type="button"
            disabled={updateStatus.isPending}
            onClick={() => updateStatus.mutate({ id: request.staffing_request_id, status: 'filled' })}
            className="ml-auto inline-flex items-center gap-1 rounded-md bg-healthcare-primary px-3 py-1 text-xs/[16px] font-semibold text-white hover:opacity-90 disabled:opacity-60"
          >
            <CheckCircle2 className="size-3.5" /> Mark filled
          </button>
        </div>
      )}
    </div>
  );
}

export default function StaffingOffice() {
  const { data, isLoading } = useStaffingOverview();

  return (
    <DashboardLayout>
      <Head title="Staffing Office" />
      <PageContentLayout
        title="Staffing Office"
        subtitle="Live coverage posture, unit gaps, and governed gap-mitigation across float, overtime, agency, and on-call sources"
        headerContent={null}
      >
        {isLoading || !data ? (
          <div className="rounded-md border border-healthcare-border p-6 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            Loading staffing posture...
          </div>
        ) : (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <MetricTile label="Coverage" value={`${data.metrics.coverage_pct}%`} tone={data.metrics.coverage_pct < 90 ? 'risk' : 'good'} />
              <MetricTile label="Units at risk" value={data.metrics.at_risk_units} tone={data.metrics.at_risk_units > 0 ? 'risk' : 'good'} />
              <MetricTile label="Gap headcount" value={data.metrics.total_gap_headcount} tone={data.metrics.total_gap_headcount > 0 ? 'risk' : 'neutral'} />
              <MetricTile label="Open requests" value={data.metrics.open_requests} />
            </div>

            <section className="space-y-3">
              <div className="flex items-center gap-2">
                <Users className="size-5 text-healthcare-primary" />
                <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Units at risk
                </h2>
              </div>
              {data.units_at_risk.length === 0 ? (
                <div className="rounded-md border border-healthcare-success/30 bg-healthcare-success/5 p-4 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10 dark:text-healthcare-text-secondary-dark">
                  All units are at or above target for the current shift.
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                  {data.units_at_risk.map((unit) => (
                    <UnitCard key={unit.unit_label} unit={unit} />
                  ))}
                </div>
              )}
            </section>

            {data.by_role.length > 0 && (
              <section className="space-y-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <div className="flex items-center gap-2">
                  <UserCog className="size-5 text-healthcare-primary" />
                  <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Gap by role
                  </h2>
                </div>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {data.by_role.map((row) => (
                    <RoleGapBar key={row.role} row={row} />
                  ))}
                </div>
              </section>
            )}

            <section className="space-y-3">
              <div className="flex items-center justify-between">
                <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Gap-mitigation queue
                </h2>
                <div className="flex flex-wrap gap-2">
                  {data.resource_options.map((option) => (
                    <span key={option.key} className={CHIP}>
                      {option.name}: {option.available}
                    </span>
                  ))}
                </div>
              </div>
              {data.queue.length === 0 ? (
                <div className="rounded-md border border-healthcare-border p-4 text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                  No open staffing requests.
                </div>
              ) : (
                <div className="space-y-3">
                  {data.queue.map((request) => (
                    <RequestRow key={request.staffing_request_id} request={request} />
                  ))}
                </div>
              )}
            </section>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
