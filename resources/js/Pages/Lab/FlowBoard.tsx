import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Clock3, FlaskConical, GitBranch, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { useLabFlowBoard } from '@/features/lab/hooks';
import { labFlowBoardSchema, type LabFlowBoard } from '@/features/lab/schemas';
import BarrierAnnotationDrawer from './BarrierAnnotationDrawer';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

function lensHref(board: LabFlowBoard, lens: string) {
  const query = new URLSearchParams();
  if (lens !== 'all') query.set('lens', lens);
  if (board.filters.priority) query.set('priority', board.filters.priority);
  if (board.filters.testFamily) query.set('testFamily', board.filters.testFamily);
  if (board.filters.unitId) query.set('unitId', String(board.filters.unitId));
  if (board.filters.shift) query.set('shift', board.filters.shift);
  if (board.filters.source) query.set('source', board.filters.source);
  const suffix = query.toString();
  return suffix ? `/lab?${suffix}` : '/lab';
}

const format = (value: number | null, suffix = '') => value === null ? '—' : `${value}${suffix}`;

export default function FlowBoard({ flowBoard }: { flowBoard: LabFlowBoard }) {
  const initial = labFlowBoardSchema.parse(flowBoard);
  const query = useLabFlowBoard(initial);
  const board = query.data;
  const cards = [
    { label: 'Open orders', value: board.summary.openOrders, Icon: Clock3 },
    { label: 'STAT compliance', value: format(board.summary.statCompliancePercent, '%'), Icon: ShieldCheck },
    { label: 'Decision pending', value: board.summary.pendingDecisions, Icon: GitBranch },
    { label: 'Open callbacks', value: board.summary.openCriticalCallbacks, Icon: AlertTriangle },
  ];

  return <DashboardLayout><Head title="Laboratory Flow Board" /><PageContentLayout title="Laboratory Flow Board" subtitle="Current specimen flow, decision impact, callback safety, and pre-analytic quality from governed operational facts" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>
      <nav aria-label="Laboratory lenses" className="flex flex-wrap gap-2">{board.filterOptions.lenses.map((lens) => <Link key={lens} href={lensHref(board, lens)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium ${board.filters.lens === lens ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>{lens.replace('_', ' ')}</Link>)}</nav>
      <form method="get" action="/lab" aria-label="Laboratory filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <input type="hidden" name="lens" value={board.filters.lens} />
        {board.filters.source ? <input type="hidden" name="source" value={board.filters.source} /> : null}
        <label className="text-sm">Priority<select name="priority" defaultValue={board.filters.priority ?? ''} className="mt-1 block w-full rounded-md"><option value="">All priorities</option>{board.filterOptions.priorities.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <label className="text-sm">Test family<select name="testFamily" defaultValue={board.filters.testFamily ?? ''} className="mt-1 block w-full rounded-md"><option value="">All test families</option>{board.filterOptions.testFamilies.map((value) => <option key={value} value={value}>{value.replace('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Shift<select name="shift" defaultValue={board.filters.shift ?? ''} className="mt-1 block w-full rounded-md"><option value="">All shifts</option>{board.filterOptions.shifts.map((value) => <option key={value} value={value}>{value.replace('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Unit<select name="unitId" defaultValue={board.filters.unitId ?? ''} className="mt-1 block w-full rounded-md"><option value="">All units</option>{board.filterOptions.units.map((unit) => <option key={unit.unitId} value={unit.unitId}>{unit.label}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>
      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold">Current stage distribution</h2><div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">{board.stageDistribution.map((row) => <div key={row.stage} className="rounded-md bg-healthcare-background p-3 dark:bg-healthcare-background-dark"><p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.label}</p><p className="text-xl font-semibold tabular-nums">{row.count}</p></div>)}</div>{board.stageDistribution.length === 0 ? <p className="mt-3 text-sm">No stage cohorts match.</p> : null}</section>
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold">Operational clocks</h2><div className="mt-3 grid grid-cols-2 gap-3">{Object.entries(board.tat).map(([key, value]) => <div key={key}><p className="text-sm font-medium">{key === 'collectToReceive' ? 'Collect → receive' : 'Receive → result'}</p><p className="text-2xl font-semibold tabular-nums">{format(value.medianMinutes, ' min')}</p><p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">p90 {format(value.p90Minutes, ' min')} · {value.granularity}</p></div>)}</div><div className="mt-4 space-y-2">{Object.entries(board.coverage).map(([key, value]) => <p key={key} className="rounded-md border border-healthcare-border p-2 text-xs dark:border-healthcare-border-dark"><strong className="capitalize">{key} {value.status}</strong> — {value.explanation}</p>)}</div></section>
      </div>
      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold">Pre-analytic quality</h2><div className="mt-3 grid gap-3 md:grid-cols-3">{board.qualityStrip.map((metric) => <article key={metric.key} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark"><div className="flex items-center justify-between"><h3 className="text-sm font-medium">{metric.label}</h3><FlaskConical className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-xl font-semibold tabular-nums">{format(metric.ratePercent, '%')}</p><p className="text-xs">{metric.count} of {metric.denominator} collected specimens</p><p className="mt-2 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{metric.reference.kind.replace('_', ' ')} · {metric.reference.label}</p><p className="sr-only">{metric.reference.source}</p></article>)}</div></section>
      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><div><h2 className="font-semibold">Oldest active items</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Filtered operational drill with exact downstream decision context.</p></div><span className="text-sm tabular-nums">{board.oldestItems.length} shown</span></div><div className="mt-3 space-y-2">{board.oldestItems.map((item) => <article key={item.orderUuid} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark"><div className="flex flex-wrap items-start justify-between gap-2"><div><h3 className="font-medium">{item.label}</h3><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.patientRef} · {item.locationLabel ?? 'Location unavailable'} · {item.priority}</p>{item.decisionContext ? <p className="mt-1 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">{item.decisionContext.explanation}</p> : null}</div><div className="text-right"><p className="font-semibold tabular-nums">{item.ageMinutes} min</p>{board.canAnnotateBarriers && item.encounterLinked ? <BarrierAnnotationDrawer item={item} reasons={board.barrierReasons} onSaved={() => query.refetch()} /> : null}</div></div></article>)}{board.oldestItems.length === 0 ? <p className="text-sm">No active items match the selected filters.</p> : null}</div></section>
    </div>
  </PageContentLayout></DashboardLayout>;
}
