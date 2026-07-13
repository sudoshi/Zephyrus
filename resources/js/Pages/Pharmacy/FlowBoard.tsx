import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Circle, Clock3, Database, GitBranch, HelpCircle, ListChecks, Pill, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { usePharmacyFlowBoard } from '@/features/pharmacy/hooks';
import { pharmacyFlowBoardSchema, type PharmacyFlowBoard, type PharmacySepsisTimer } from '@/features/pharmacy/schemas';
import BarrierAnnotationDrawer from './BarrierAnnotationDrawer';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

// Server-provided status classes only: no raw-minute comparison happens here.
const SLA_STATE_META = {
  normal: { label: 'Within thresholds', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  warning: { label: 'Warning', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  breach: { label: 'Breached', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  unknown: { label: 'Unknown · as-of', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
} as const;

const TIMER_STATE_META = {
  complete: { label: 'Administered · as-of', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  breached: { label: 'Breached', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  warning: { label: 'Warning', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  running: { label: 'Running', Icon: Clock3, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
  unknown: { label: 'Unknown · as-of', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
} as const;

const ADMIN_SEGMENT_META = {
  administered_as_of: { label: 'Administered as of cutoff', Icon: Database },
  no_evidence_as_of_cutoff: { label: 'No evidence as of cutoff', Icon: Database },
  unknown: { label: 'Administration unknown', Icon: HelpCircle },
} as const;

function lensHref(board: PharmacyFlowBoard, lens: string) {
  const query = new URLSearchParams();
  if (lens !== 'all') query.set('lens', lens);
  if (board.filters.clockClass) query.set('clockClass', board.filters.clockClass);
  if (board.filters.branch) query.set('branch', board.filters.branch);
  if (board.filters.status) query.set('status', board.filters.status);
  if (board.filters.unitId) query.set('unitId', String(board.filters.unitId));
  if (board.filters.source) query.set('source', board.filters.source);
  const suffix = query.toString();
  return suffix ? `/pharmacy?${suffix}` : '/pharmacy';
}

const format = (value: number | null, suffix = '') => value === null ? '—' : `${value}${suffix}`;

function SepsisTimerRow({ timer }: { timer: PharmacySepsisTimer }) {
  const meta = TIMER_STATE_META[timer.state];
  const adminMeta = ADMIN_SEGMENT_META[timer.adminSegment.state];
  const cutoff = timer.adminSegment.sourceCutoffAt ? new Date(timer.adminSegment.sourceCutoffAt).toLocaleTimeString() : 'cutoff unavailable';

  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h3 className="font-medium">{timer.label}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{timer.patientRef} · {timer.locationLabel ?? 'Location unavailable'}</p>
      </div>
      <div className="text-right">
        <p className={`inline-flex items-center gap-1 text-sm font-medium ${meta.className}`}><meta.Icon className="size-4" aria-hidden="true" />{meta.label}</p>
        <p className="font-semibold tabular-nums">{timer.elapsedMinutes} min</p>
      </div>
    </div>
    <ol className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" aria-label={`${timer.label} milestone segments`}>
      {timer.segments.map((segment) => <li key={segment.code} className="inline-flex items-center gap-1">
        {segment.state === 'complete'
          ? <CheckCircle2 className="size-3.5 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
          : <Circle className="size-3.5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />}
        <span>{segment.label}</span>
        <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{segment.at ? new Date(segment.at).toLocaleTimeString() : 'pending'}</span>
      </li>)}
      <li className="inline-flex items-center gap-1 rounded-md border border-healthcare-info/40 px-1.5 py-0.5 text-healthcare-info dark:text-healthcare-info-dark">
        <adminMeta.Icon className="size-3.5" aria-hidden="true" />
        <span>{adminMeta.label}</span>
        <span className="tabular-nums">· {cutoff}</span>
      </li>
    </ol>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{timer.adminSegment.explanation}</p>
  </article>;
}

export default function FlowBoard({ flowBoard }: { flowBoard: PharmacyFlowBoard }) {
  const initial = pharmacyFlowBoardSchema.parse(flowBoard);
  const query = usePharmacyFlowBoard(initial);
  const board = query.data;
  const cards = [
    { label: 'Open orders', value: board.data.summary.openOrders, Icon: Pill },
    { label: 'Verification queue', value: board.data.summary.verificationQueueDepth, Icon: ListChecks },
    { label: 'STAT compliance', value: format(board.data.summary.statCompliancePercent, '%'), Icon: ShieldCheck },
    { label: 'Open breaches', value: board.data.summary.openBreaches, Icon: AlertTriangle },
  ];

  return <DashboardLayout><Head title="Medication Flow Board" /><PageContentLayout title="Medication Flow Board" subtitle="Verification queue, governed medication clocks, preparation branches, and the cutoff-qualified administration tail from governed operational facts" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>
      <nav aria-label="Pharmacy lenses" className="flex flex-wrap gap-2">{board.filterOptions.lenses.map((lens) => <Link key={lens} href={lensHref(board, lens)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium ${board.filters.lens === lens ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>{lens.replaceAll('_', ' ')}</Link>)}</nav>
      <form method="get" action="/pharmacy" aria-label="Pharmacy filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <input type="hidden" name="lens" value={board.filters.lens} />
        {board.filters.source ? <input type="hidden" name="source" value={board.filters.source} /> : null}
        <label className="text-sm">Priority clock<select name="clockClass" defaultValue={board.filters.clockClass ?? ''} className="mt-1 block w-full rounded-md"><option value="">All clock classes</option>{board.filterOptions.clockClasses.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Preparation branch<select name="branch" defaultValue={board.filters.branch ?? ''} className="mt-1 block w-full rounded-md"><option value="">All branches</option>{board.filterOptions.branches.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Status<select name="status" defaultValue={board.filters.status ?? ''} className="mt-1 block w-full rounded-md"><option value="">All statuses</option>{board.filterOptions.statuses.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Unit<select name="unitId" defaultValue={board.filters.unitId ?? ''} className="mt-1 block w-full rounded-md"><option value="">All units</option>{board.filterOptions.units.map((unit) => <option key={unit.unitId} value={unit.unitId}>{unit.label}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>

      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="font-semibold">Verification queue</h2>
          <div className="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-2">
            <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Depth</p><p className="text-2xl font-semibold tabular-nums">{board.data.verificationQueue.depth}</p></div>
            <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Oldest</p><p className="text-2xl font-semibold tabular-nums">{format(board.data.verificationQueue.oldestAgeMinutes, ' min')}</p></div>
            <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Median</p><p className="text-2xl font-semibold tabular-nums">{format(board.data.verificationQueue.medianAgeMinutes, ' min')}</p></div>
          </div>
          <table className="mt-3 w-full text-sm"><caption className="sr-only">Verification queue age distribution</caption><thead><tr><th scope="col" className="p-1 text-left font-medium">Queue age</th><th scope="col" className="p-1 text-right font-medium">Orders</th></tr></thead><tbody>{board.data.verificationQueue.ageDistribution.map((bucket) => <tr key={bucket.key}><th scope="row" className="p-1 text-left font-normal">{bucket.label}</th><td className="p-1 text-right tabular-nums">{bucket.count}</td></tr>)}</tbody></table>
        </section>
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="font-semibold">Operational clocks</h2>
          <div className="mt-3 grid grid-cols-2 gap-3">
            <div>
              <p className="text-sm font-medium">Order → dispense <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">(real-time)</span></p>
              <p className="text-2xl font-semibold tabular-nums">{format(board.data.segments.orderToDispense.medianMinutes, ' min')}</p>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">p90 {format(board.data.segments.orderToDispense.p90Minutes, ' min')} · n={board.data.segments.orderToDispense.count}</p>
            </div>
            <div>
              <p className="text-sm font-medium">Dispense → administration <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">(warehouse as-of)</span></p>
              <p className="text-2xl font-semibold tabular-nums">{format(board.data.segments.dispenseToAdmin.medianMinutes, ' min')}</p>
              <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">p90 {format(board.data.segments.dispenseToAdmin.p90Minutes, ' min')} · n={board.data.segments.dispenseToAdmin.count}</p>
              <div className="mt-1"><SourceFreshnessBadge value={board.data.segments.dispenseToAdmin.freshness} /></div>
            </div>
          </div>
          <p className="mt-3 rounded-md border border-healthcare-border p-2 text-xs dark:border-healthcare-border-dark">{board.data.segments.dispenseToAdmin.definition}</p>
        </section>
      </div>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="font-semibold">Medication clocks and breach summary</h2>
        <div className="mt-3 grid gap-3 md:grid-cols-3">{board.data.clockClasses.map((row) => {
          const meta = SLA_STATE_META[row.state];
          return <article key={row.metricKey} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <div className="flex items-center justify-between gap-2"><h3 className="text-sm font-medium">{row.label}</h3><span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-4" aria-hidden="true" />{meta.label}</span></div>
            <dl className="mt-2 grid grid-cols-3 gap-2 text-xs">
              <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Open breaches</dt><dd className="text-lg font-semibold tabular-nums">{row.openBreaches}</dd></div>
              <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Open warnings</dt><dd className="text-lg font-semibold tabular-nums">{row.openWarnings === null ? '—' : row.openWarnings}</dd></div>
              <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cleared</dt><dd className="text-lg font-semibold tabular-nums">{row.clearedBreaches}</dd></div>
            </dl>
            <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.definition.definitionText}</p>
            <p className="mt-1 text-xs">{row.explanation}</p>
          </article>;
        })}</div>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">Sepsis antibiotic timers</h2><SourceFreshnessBadge value={board.administrationFreshness} /></div>
        <div className="mt-3 space-y-2">{board.data.sepsisTimers.map((timer) => <SepsisTimerRow key={timer.orderUuid} timer={timer} />)}{board.data.sepsisTimers.length === 0 ? <p className="text-sm">No sepsis antibiotic orders match the selected filters.</p> : null}</div>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="font-semibold">Preparation branches</h2>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">{board.data.preparationBranches.branches.map((branch) => <div key={branch.branch} className="rounded-md bg-healthcare-background p-3 dark:bg-healthcare-background-dark"><p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{branch.label}</p><p className="text-xl font-semibold tabular-nums">{branch.orders}</p><p className="text-xs tabular-nums">{branch.openOrders} open{branch.degradedOrders > 0 ? ` · ${branch.degradedOrders} degraded` : ''}</p></div>)}</div>
        <p className={`mt-3 flex items-start gap-2 rounded-md border p-2 text-xs ${board.data.preparationBranches.ivwms.status === 'partial' ? 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}><GitBranch className="size-4 shrink-0" aria-hidden="true" /><span><strong>IV workflow {board.data.preparationBranches.ivwms.status}</strong> — {board.data.preparationBranches.ivwms.explanation}</span></p>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><div><h2 className="font-semibold">Oldest active items</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Server-ranked operational drill with governed clock state; medication label and operational fields only.</p></div><span className="text-sm tabular-nums">{board.data.oldestItems.length} shown</span></div>
        <div className="mt-3 space-y-2">{board.data.oldestItems.map((item) => {
          const meta = SLA_STATE_META[item.slaState];
          return <article key={item.orderUuid} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 className="font-medium">{item.label}</h3>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.patientRef} · {item.locationLabel ?? 'Location unavailable'} · {item.clockClass.replaceAll('_', ' ')} · {item.preparationBranch.replaceAll('_', ' ')}{item.onShortage ? ' · shortage' : ''}{item.isControlled ? ' · controlled' : ''}</p>
                <p className={`mt-1 inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{meta.label} · {item.slaExplanation}</p>
              </div>
              <div className="text-right">
                <p className="font-semibold tabular-nums">{item.ageMinutes} min</p>
                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.orderStatus}</p>
                {board.canAnnotateBarriers && item.encounterLinked ? <BarrierAnnotationDrawer item={item} reasons={board.barrierReasons} onSaved={() => query.refetch()} /> : null}
              </div>
            </div>
          </article>;
        })}{board.data.oldestItems.length === 0 ? <p className="text-sm">No active items match the selected filters.</p> : null}</div>
      </section>
    </div>
  </PageContentLayout></DashboardLayout>;
}
