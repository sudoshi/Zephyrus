import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CircleDashed, Clock, Gauge, HelpCircle, Info, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { usePharmacyControlled } from '@/features/pharmacy/hooks';
import {
  pharmacyControlledSchema,
  type PharmacyControlled,
  type PharmacyControlledAgingStatus,
  type PharmacyControlledDiscrepancy,
  type PharmacyControlledRateStatus,
  type PharmacyControlledStation,
} from '@/features/pharmacy/controlled-schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

// Server-provided rate status only: no raw-rate comparison happens in the view.
const RATE_META = {
  no_data: { label: 'No data', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  within_target: { label: 'Within target', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  near_target: { label: 'Near target', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  over_target: { label: 'Over target', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
} as const;

// Server-provided aging status only: the view never compares raw minutes.
// Language is operational and non-accusatory — a location is due, at, or past
// the reconciliation policy, never a person.
const AGING_META = {
  due_this_shift: { label: 'Due this shift', Icon: Clock, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
  at_shift_end: { label: 'At shift-end', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  past_policy: { label: 'Past reconciliation policy', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
} as const;

const stamp = (value: string | null) => value === null ? '—' : new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
const pct = (value: number | null) => value === null ? '—' : `${value}%`;
const durationLabel = (minutes: number | null) => minutes === null ? '—' : minutes >= 60 ? `${Math.floor(minutes / 60)} h ${minutes % 60} min` : `${minutes} min`;

function RateBadge({ status, kind }: { status: PharmacyControlledRateStatus; kind: string }) {
  const meta = RATE_META[status];
  return <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{kind}{kind ? ' ' : ''}{meta.label}</span>;
}

function AgingBadge({ status }: { status: PharmacyControlledAgingStatus }) {
  const meta = AGING_META[status];
  return <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{meta.label}</span>;
}

function DiscrepancyRow({ item }: { item: PharmacyControlledDiscrepancy }) {
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{item.medicationLabel}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">
          Opened {stamp(item.openedAt)} · shift-end {stamp(item.applicableShiftEndAt)}
        </p>
      </div>
      <AgingBadge status={item.agingStatus} />
    </div>
    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">
      Open {durationLabel(item.minutesOpen)}
      {item.minutesPastShiftEnd > 0 ? ` · ${durationLabel(item.minutesPastShiftEnd)} past shift-end` : ' · within the shift'}
    </p>
  </article>;
}

function StationCard({ station }: { station: PharmacyControlledStation }) {
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{station.label}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{station.unitName ?? 'Unit unavailable'} · {station.stationType}</p>
      </div>
      {station.openDiscrepancies > 0
        ? <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-healthcare-warning/40 px-2 py-0.5 text-xs font-medium text-healthcare-warning dark:text-healthcare-warning-dark tabular-nums"><AlertTriangle className="size-3.5" aria-hidden="true" />{station.openDiscrepancies} open</span>
        : null}
    </div>
    <dl className="mt-2 grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Ctrl vends</dt><dd className="text-base font-semibold tabular-nums">{station.controlledVends}</dd></div>
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Overrides</dt><dd className="text-base font-semibold tabular-nums">{station.controlledOverrides}</dd></div>
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Discrepancies</dt><dd className="text-base font-semibold tabular-nums">{station.controlledDiscrepancies}</dd></div>
    </dl>
    <div className="mt-2 rounded-md border border-healthcare-border p-2 dark:border-healthcare-border-dark">
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Controlled override rate</p>
      <p className="text-lg font-semibold tabular-nums">{station.hasDenominator ? pct(station.overrideRatePercent) : 'no data'}</p>
      <RateBadge status={station.overrideStatus} kind="Override" />
    </div>
    {station.openDiscrepanciesPastPolicy > 0
      ? <p className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark tabular-nums"><AlertTriangle className="size-3.5" aria-hidden="true" />{station.openDiscrepanciesPastPolicy} past reconciliation policy</p>
      : null}
    {!station.hasDenominator
      ? <p className="mt-2 inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><CircleDashed className="size-3.5" aria-hidden="true" />No controlled vends in the window — no rate denominator.</p>
      : null}
  </article>;
}

export function Controlled({ controlled }: { controlled: PharmacyControlled }) {
  const initial = pharmacyControlledSchema.parse(controlled);
  const query = usePharmacyControlled(initial);
  const board = query.data;
  const { summary } = board.data;
  const cards = [
    { label: 'Open discrepancies', value: summary.openDiscrepancyCount, Icon: AlertTriangle },
    { label: 'Past reconciliation policy', value: summary.openDiscrepanciesPastPolicy, Icon: Clock },
    { label: 'Oldest open', value: durationLabel(summary.oldestOpenMinutes), Icon: Clock },
    { label: 'Stations over override target', value: summary.stationsOverOverrideTarget, Icon: Gauge },
  ];

  return <DashboardLayout><Head title="Controlled Substances" /><PageContentLayout title="Controlled Substances" subtitle="Open controlled-discrepancy reconciliation aged against the shift-end policy, and controlled override/discrepancy patterns by unit and station — aggregates only" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      {/* Out-of-scope statement — first-class, operational, non-accusatory. */}
      <section aria-label="Scope of this view" className="rounded-lg border border-healthcare-info/50 bg-healthcare-info/5 p-4">
        <h2 className="inline-flex items-center gap-2 font-semibold"><ShieldCheck className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Operational reconciliation view</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.scope.statement}</p>
        <p className="mt-2 inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><Info className="size-3.5" aria-hidden="true" />{board.scope.exportStatement}</p>
      </section>

      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Window: last {board.window.hours} h · generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>

      {/* Local policy — deliberately distinct from measured values. */}
      <section className="rounded-lg border border-dashed border-healthcare-info/50 bg-healthcare-info/5 p-4">
        <h2 className="inline-flex items-center gap-2 font-semibold"><Info className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Local policy</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Configured reference lines — not observed events. Aging and rates above are measured; these are policy.</p>
        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.shiftEnd.label}</dt>
            <dd className="mt-1 text-sm tabular-nums">Shift-ends {board.policy.shiftEnd.times.join(', ')} ({board.policy.shiftEnd.timezone})</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.shiftEnd.description}</dd>
          </div>
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.overrideTargetRate.label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{board.policy.overrideTargetRate.ratePercent}%</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.overrideTargetRate.denominatorLabel}</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.overrideTargetRate.description}</dd>
          </div>
        </dl>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><h2 className="font-semibold">Open controlled discrepancies</h2><span className="text-sm tabular-nums">{board.data.openDiscrepancies.count} open</span></div>
        <div className="mt-3 grid gap-2 md:grid-cols-2">
          {board.data.openDiscrepancies.items.map((item) => <DiscrepancyRow key={item.discrepancyKey} item={item} />)}
          {board.data.openDiscrepancies.items.length === 0 ? <p className="text-sm">No controlled discrepancies are open. Resolved discrepancies do not appear here.</p> : null}
        </div>
        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.openDiscrepancies.basis}</p>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><h2 className="font-semibold">Station patterns</h2><span className="text-sm tabular-nums">{board.data.stationPatterns.stations.length} stations</span></div>
        <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
          {board.data.stationPatterns.stations.map((station) => <StationCard key={station.stationId} station={station} />)}
          {board.data.stationPatterns.stations.length === 0 ? <p className="text-sm">No controlled transactions or open discrepancies at any station in the current window.</p> : null}
        </div>
        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.stationPatterns.basis}</p>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="font-semibold">Unit patterns</h2>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <tr><th className="py-1 pr-3 font-medium">Unit</th><th className="py-1 pr-3 font-medium tabular-nums">Ctrl vends</th><th className="py-1 pr-3 font-medium">Override rate</th><th className="py-1 pr-3 font-medium tabular-nums">Open</th></tr>
            </thead>
            <tbody>
              {board.data.unitPatterns.units.map((unit) => <tr key={unit.unitId} className="border-t border-healthcare-border dark:border-healthcare-border-dark">
                <td className="py-1.5 pr-3">{unit.unitName}</td>
                <td className="py-1.5 pr-3 tabular-nums">{unit.controlledVends}</td>
                <td className="py-1.5 pr-3"><span className="tabular-nums">{unit.hasDenominator ? pct(unit.overrideRatePercent) : 'no data'}</span> <RateBadge status={unit.overrideStatus} kind="" /></td>
                <td className="py-1.5 pr-3 tabular-nums">{unit.openDiscrepancies}{unit.openDiscrepanciesPastPolicy > 0 ? ` (${unit.openDiscrepanciesPastPolicy} past policy)` : ''}</td>
              </tr>)}
              {board.data.unitPatterns.units.length === 0 ? <tr><td colSpan={4} className="py-2 text-sm">No controlled unit-level activity in the current window.</td></tr> : null}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.unitPatterns.basis}</p>
      </section>
    </div>
  </PageContentLayout></DashboardLayout>;
}

export default Controlled;
