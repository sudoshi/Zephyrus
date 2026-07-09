import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';
import { KpiTile, metric } from '@/Components/system';
import { useAssignStaffingRequest, useStaffingOverview, useStaffingWorkforce, useUpdateStaffingStatus } from '@/features/staffing/hooks';
import type {
  StaffingAssignedSource,
  StaffingRequest,
  StaffingRoleGap,
  StaffingShift,
  StaffingUnitAtRisk,
  StaffingWorkforceSummary,
} from '@/features/staffing/types';
import { Head } from '@inertiajs/react';
import { AlertTriangle, BriefcaseBusiness, CheckCircle2, ChevronLeft, ChevronRight, Search, ShieldAlert, UserCog, Users } from 'lucide-react';
import { useDeferredValue, useEffect, useState } from 'react';

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

const CHIP = 'rounded-md bg-healthcare-background px-2 py-1 text-xs font-medium text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark';

// Delegates to the gold-standard KpiTile (status dot, not a colored fill).
function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: 'neutral' | 'risk' | 'good' }) {
  const status = tone === 'risk' ? 'critical' : tone === 'good' ? 'success' : 'neutral';
  const numeric = typeof value === 'number';
  return (
    <KpiTile
      metric={metric({
        key: `staffing-${label.toLowerCase().replace(/\s+/g, '-')}`,
        label,
        value: numeric ? (value as number) : 0,
        display: numeric ? undefined : String(value),
        status,
      })}
    />
  );
}

function StatusBadge({ status }: { status: string }) {
  const critical = status === 'critical_gap';
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${
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
          <div className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {unit.unit_label}
          </div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Short {unit.gap_headcount} · worst {unit.worst_role_label}
          </div>
        </div>
        <StatusBadge status={unit.status} />
      </div>
      {unit.below_minimum_safe && (
        <div className="mt-2 flex items-center gap-1 text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
          <ShieldAlert className="size-3.5" /> Below minimum safe staffing
        </div>
      )}
      <ul className="mt-3 space-y-1">
        {unit.roles.map((role) => (
          <li
            key={role.staffing_plan_id}
            className="flex items-center justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
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
      <div className="flex items-center justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
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
  const isActive = request.freshness_status !== 'expired' && !['filled', 'completed', 'canceled', 'unfilled'].includes(request.status);
  const sources: StaffingAssignedSource[] = ['float_pool', 'overtime', 'agency', 'on_call'];

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {request.unit_label} · {request.role_label}
            </span>
            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${PRIORITY_TONE[request.priority] ?? ''}`}>
              {request.priority.toUpperCase()}
            </span>
          </div>
          <div className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Need {request.headcount_needed} · {request.shift} shift · {request.sla.label}
            {request.assigned_source ? ` · ${SOURCE_LABELS[request.assigned_source]}` : ''}
          </div>
        </div>
        <span className={CHIP}>{request.freshness_status === 'expired' ? 'expired demo' : request.status}</span>
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
                className="rounded-md border border-healthcare-border px-2.5 py-1 text-xs font-medium text-healthcare-text-secondary hover:bg-healthcare-background disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-white/5"
              >
                {SOURCE_LABELS[source]}
              </button>
            ))}
          <button
            type="button"
            disabled={updateStatus.isPending}
            onClick={() => updateStatus.mutate({ id: request.staffing_request_id, status: 'filled' })}
            className="ml-auto inline-flex items-center gap-1 rounded-md bg-healthcare-primary px-3 py-1 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-60"
          >
            <CheckCircle2 className="size-3.5" /> Mark filled
          </button>
        </div>
      )}
    </div>
  );
}

function WorkforceSection({ workforce }: { workforce: StaffingWorkforceSummary }) {
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [shift, setShift] = useState<StaffingShift | ''>('');
  const [status, setStatus] = useState<'active' | 'inactive' | ''>('active');
  const [page, setPage] = useState(1);
  const deferredSearch = useDeferredValue(search.trim());
  const directory = useStaffingWorkforce({
    q: deferredSearch || undefined,
    role: role || undefined,
    shift: shift || undefined,
    status: status || undefined,
    page,
    per_page: 25,
  });

  useEffect(() => {
    setPage(1);
  }, [deferredSearch, role, shift, status]);

  if (!workforce.available) {
    return (
      <section className="border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <div className="flex items-center gap-2">
          <BriefcaseBusiness className="size-5 text-healthcare-primary" />
          <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Workforce roster</h2>
        </div>
        <div className="mt-3 rounded-md border border-healthcare-warning/30 bg-healthcare-warning/5 p-4 text-sm text-healthcare-text-secondary dark:bg-healthcare-warning/10 dark:text-healthcare-text-secondary-dark">
          Workforce alignment data is not available.
        </div>
      </section>
    );
  }

  return (
    <section className="space-y-4 border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <BriefcaseBusiness className="size-5 text-healthcare-primary" />
            <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Workforce roster</h2>
          </div>
          {workforce.assumptions && (
            <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {workforce.assumptions.productive_hours_per_fte.toLocaleString()} productive h/FTE · {workforce.assumptions.relief_factor.toFixed(2)} relief · through {workforce.assumptions.roster_window?.end ?? 'current window'}
            </div>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {workforce.by_shift.map((row) => (
            <span key={row.shift} className={CHIP}>{row.label}: {row.count.toLocaleString()}</span>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricTile label="Active people" value={workforce.metrics.active_members} />
        <MetricTile label="Active FTE" value={workforce.metrics.active_fte.toLocaleString()} />
        <MetricTile label="Roster roles" value={workforce.metrics.role_count} />
        <MetricTile label="Credential attention" value={workforce.metrics.credential_attention} tone={workforce.metrics.credential_attention > 0 ? 'risk' : 'good'} />
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.45fr)]">
        <div className="overflow-hidden rounded-md border border-healthcare-border dark:border-healthcare-border-dark">
          <div className="grid grid-cols-2 border-b border-healthcare-border bg-healthcare-background px-3 py-2 text-xs font-semibold uppercase text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-white/5 dark:text-healthcare-text-secondary-dark sm:grid-cols-4">
            <span>Role</span><span>Category</span><span className="hidden sm:block">People</span><span className="hidden text-right sm:block">FTE</span>
          </div>
          {workforce.by_role.slice(0, 10).map((row) => (
            <div key={row.role_code} className="grid grid-cols-2 border-b border-healthcare-border px-3 py-2 text-sm last:border-b-0 dark:border-healthcare-border-dark sm:grid-cols-4">
              <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.role_label}</span>
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.role_category.replaceAll('_', ' ')}</span>
              <span className="hidden text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark sm:block">{row.active_count.toLocaleString()}</span>
              <span className="hidden text-right font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark sm:block">{row.fte.toLocaleString()}</span>
            </div>
          ))}
        </div>
        <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
          <div className="text-xs font-semibold uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Employment mix</div>
          <div className="mt-2 space-y-2">
            {workforce.by_employment.map((row) => (
              <div key={row.key} className="flex items-center justify-between text-sm">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.label}</span>
                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.count.toLocaleString()}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <label className="relative min-w-56 flex-1">
            <span className="sr-only">Search workforce</span>
            <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search staff, role, unit, or service"
              className="h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface pl-9 pr-3 text-sm text-healthcare-text-primary outline-none focus:border-healthcare-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
            />
          </label>
          <select aria-label="Filter by role" value={role} onChange={(event) => setRole(event.target.value)} className="h-9 rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">
            <option value="">All roles</option>
            {workforce.by_role.map((row) => <option key={row.role_code} value={row.role_code}>{row.role_label}</option>)}
          </select>
          <select aria-label="Filter by shift" value={shift} onChange={(event) => setShift(event.target.value as StaffingShift | '')} className="h-9 rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">
            <option value="">All shifts</option><option value="day">Day</option><option value="evening">Evening</option><option value="night">Night</option>
          </select>
          <select aria-label="Filter by status" value={status} onChange={(event) => setStatus(event.target.value as 'active' | 'inactive' | '')} className="h-9 rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">
            <option value="">All status</option><option value="active">Active</option><option value="inactive">Inactive</option>
          </select>
        </div>

        {directory.isError ? (
          <OperationalDataError title="Workforce directory unavailable" error={directory.error} onRetry={() => void directory.refetch()} />
        ) : (
          <div className="overflow-x-auto rounded-md border border-healthcare-border dark:border-healthcare-border-dark">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="bg-healthcare-background text-xs uppercase text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                <tr><th className="px-3 py-2">Staff member</th><th className="px-3 py-2">Role</th><th className="px-3 py-2">Home</th><th className="px-3 py-2">Shift</th><th className="px-3 py-2">Employment</th><th className="px-3 py-2 text-right">FTE</th></tr>
              </thead>
              <tbody>
                {directory.isLoading ? (
                  <tr><td colSpan={6} className="px-3 py-6 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Loading workforce directory...</td></tr>
                ) : directory.data?.data.length ? directory.data.data.map((member) => (
                  <tr key={`${member.staff_member_id}-${member.role_code}-${member.unit_id ?? 'hospital'}`} className="border-t border-healthcare-border dark:border-healthcare-border-dark">
                    <td className="px-3 py-2"><div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.display_name}</div><div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.availability}{member.credential_status !== 'valid' ? ` · credential ${member.credential_status}` : ''}</div></td>
                    <td className="px-3 py-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.role_label}</td>
                    <td className="px-3 py-2"><div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.unit_label}</div>{member.eligible_float_units.length > 1 && <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.eligible_float_units.length} eligible units</div>}</td>
                    <td className="px-3 py-2 capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.preferred_shift ?? 'Variable'}</td>
                    <td className="px-3 py-2 capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.employment_class.replaceAll('_', ' ')}</td>
                    <td className="px-3 py-2 text-right font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.fte.toFixed(2)}</td>
                  </tr>
                )) : (
                  <tr><td colSpan={6} className="px-3 py-6 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No workforce records match the filters.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        )}

        {directory.data && directory.data.meta.total > 0 && (
          <div className="flex items-center justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span>{directory.data.meta.total.toLocaleString()} assignments · page {directory.data.meta.current_page} of {directory.data.meta.last_page}</span>
            <div className="flex gap-1">
              <button type="button" title="Previous page" aria-label="Previous page" disabled={page <= 1 || directory.isFetching} onClick={() => setPage((value) => Math.max(1, value - 1))} className="grid size-8 place-items-center rounded-md border border-healthcare-border disabled:opacity-40 dark:border-healthcare-border-dark"><ChevronLeft className="size-4" /></button>
              <button type="button" title="Next page" aria-label="Next page" disabled={page >= directory.data.meta.last_page || directory.isFetching} onClick={() => setPage((value) => value + 1)} className="grid size-8 place-items-center rounded-md border border-healthcare-border disabled:opacity-40 dark:border-healthcare-border-dark"><ChevronRight className="size-4" /></button>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}

export default function StaffingOffice() {
  const { data, error, isError, isLoading, refetch } = useStaffingOverview();

  return (
    <DashboardLayout>
      <Head title="Staffing Office" />
      <PageContentLayout
        title="Staffing Office"
        subtitle="Live coverage posture, unit gaps, and governed gap-mitigation across float, overtime, agency, and on-call sources"
        headerContent={null}
      >
        {isLoading ? (
          <div className="rounded-md border border-healthcare-border p-6 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            Loading staffing posture...
          </div>
        ) : isError || !data ? (
          <OperationalDataError title="Staffing posture unavailable" error={error} onRetry={() => void refetch()} />
        ) : (
          <div className="space-y-4">
            <SourceFreshnessBanner source={data.source} onRetry={() => void refetch()} />
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <MetricTile
                label="Coverage"
                value={data.metrics.coverage_pct === null ? 'Unknown' : `${data.metrics.coverage_pct}%`}
                tone={data.metrics.coverage_pct === null ? 'neutral' : data.metrics.coverage_pct < 90 ? 'risk' : 'good'}
              />
              <MetricTile label="Units at risk" value={data.metrics.at_risk_units} tone={data.metrics.at_risk_units > 0 ? 'risk' : 'good'} />
              <MetricTile label="Gap headcount" value={data.metrics.total_gap_headcount} tone={data.metrics.total_gap_headcount > 0 ? 'risk' : 'neutral'} />
              <MetricTile label="Open requests" value={data.metrics.open_requests} />
            </div>

            <WorkforceSection workforce={data.workforce} />

            <section className="space-y-3">
              <div className="flex items-center gap-2">
                <Users className="size-5 text-healthcare-primary" />
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Units at risk
                </h2>
              </div>
              {data.units_at_risk.length === 0 ? (
                <div className={`rounded-md border p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${
                  data.coverage.coverage_pct === null
                    ? 'border-healthcare-warning/30 bg-healthcare-warning/5 dark:border-healthcare-warning/30 dark:bg-healthcare-warning/10'
                    : 'border-healthcare-success/30 bg-healthcare-success/5 dark:border-healthcare-success-dark/30 dark:bg-healthcare-success-dark/10'
                }`}>
                  {data.coverage.coverage_pct === null
                    ? 'No current-shift requirements are available, so coverage and unit risk are unknown.'
                    : 'All units are at or above target for the current shift.'}
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
                  <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
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
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Gap-mitigation queue
                </h2>
                <div className="flex flex-wrap gap-2">
                  {data.resource_options.map((option) => (
                    <span key={option.key} className={CHIP}>
                      {option.name}: {option.available === null ? 'Unknown' : option.available}
                    </span>
                  ))}
                </div>
              </div>
              {data.queue.length === 0 ? (
                <div className="rounded-md border border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
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
