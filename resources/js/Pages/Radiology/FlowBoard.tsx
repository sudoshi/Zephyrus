import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CircleSlash2, Clock3, ScanLine } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { AgingHeatmap, SourceFreshnessBadge } from '@/Components/Ancillary';
import { radiologyFlowBoardSchema, type RadiologyFlowBoard } from '@/features/radiology/schemas';
import { useRadiologyFlowBoard } from '@/features/radiology/hooks';
import BarrierAnnotationDrawer from './BarrierAnnotationDrawer';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

function hrefFor(board: RadiologyFlowBoard, next: Partial<RadiologyFlowBoard['filters']>) {
  const values = { ...board.filters, ...next };
  const query = new URLSearchParams();
  if (values.lens !== 'all') query.set('lens', values.lens);
  if (values.priority) query.set('priority', values.priority);
  if (values.modality) query.set('modality', values.modality);
  if (values.unitId) query.set('unitId', String(values.unitId));
  const suffix = query.toString();
  return suffix ? `/radiology?${suffix}` : '/radiology';
}

export default function FlowBoard({ flowBoard }: { flowBoard: RadiologyFlowBoard }) {
  const initial = radiologyFlowBoardSchema.parse(flowBoard);
  const query = useRadiologyFlowBoard(initial);
  const board = query.data;
  const summaryCards = [
    { label: 'Open orders', value: board.summary.openOrders, Icon: Clock3 },
    { label: 'Open breaches', value: board.summary.openBreaches, Icon: AlertTriangle },
    { label: 'Discharge blocking', value: board.summary.dischargeBlocking, Icon: CircleSlash2 },
    { label: 'Degraded', value: board.summary.degradedOrders, Icon: ScanLine },
  ];

  return (
    <DashboardLayout>
      <Head title="Imaging Flow Board - Radiology" />
      <PageContentLayout title="Imaging Flow Board" subtitle="Operational aging, downstream impact, scanner state, and governed barriers from the Radiology milestone spine" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
        <div className="space-y-4">
          <div role="status" className={`flex items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>

          <nav aria-label="Radiology lenses" className="flex flex-wrap gap-2">{board.filterOptions.lenses.map((lens) => <Link key={lens} href={hrefFor(board, { lens })} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium capitalize ${board.filters.lens === lens ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border text-healthcare-text-secondary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'}`}>{lens}</Link>)}</nav>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {summaryCards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p></section>)}
          </div>

          <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <AgingHeatmap title="Open imaging orders by modality and age" cells={board.heatmap} />
            <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Current scanner state</h2><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.scanners.operational} operational · {board.scanners.downtime} in downtime · {board.scanners.total} total</p><ul className="mt-3 space-y-2">{board.scanners.items.map((scanner) => <li key={scanner.scannerUuid} className="flex items-center justify-between gap-2 text-sm"><span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{scanner.label} <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">({scanner.modality})</span></span><span className="capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{scanner.state}</span></li>)}</ul>{board.scanners.items.length === 0 ? <p className="mt-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No scanner inventory is available.</p> : null}</section>
          </div>

          <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Oldest matching orders</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Bounded preview; use the worklist for pagination and detail.</p></div><Link href={board.worklistHref} className="text-sm font-medium text-healthcare-primary hover:underline">Open worklist</Link></div><ul className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">{board.oldestItems.map((item) => <li key={item.orderUuid} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.label}</p><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.patientRef} · {item.patientClass} · {item.priority} · {item.locationLabel ?? 'Location unavailable'}</p></div><div className="flex items-center gap-2"><span className="text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.ageMinutes} min</span>{item.barrierCount > 0 ? <span className="rounded-md border border-healthcare-warning/40 px-2 py-1 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">{item.barrierCount} barrier{item.barrierCount === 1 ? '' : 's'}</span> : null}{board.canAnnotateBarriers && item.encounterLinked ? <BarrierAnnotationDrawer item={item} reasons={board.barrierReasons} onSaved={() => void query.refetch()} /> : null}</div></li>)}{board.oldestItems.length === 0 ? <li className="py-6 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No open Radiology orders match this lens.</li> : null}</ul></section>
            <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Barrier Pareto preview</h2><ol className="mt-3 space-y-2">{board.barrierPareto.map((barrier) => <li key={barrier.reasonCode} className="flex items-center justify-between gap-2 text-sm"><span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.label}</span><span className="font-semibold tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{barrier.count}</span></li>)}</ol>{board.barrierPareto.length === 0 ? <div className="mt-3 flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><CheckCircle2 className="size-4" aria-hidden="true" /> No linked open barriers.</div> : null}<details className="mt-4 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><summary className="cursor-pointer">Clock definitions</summary><ul className="mt-2 space-y-1">{board.thresholds.definitions.map((definition) => <li key={definition.definitionUuid}>{definition.label}: {definition.startMilestoneCode} → {definition.stopMilestoneCode}</li>)}</ul></details></section>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
