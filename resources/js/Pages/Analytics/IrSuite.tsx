import { Head, Link } from '@inertiajs/react';
import { Activity, Clock3, Database, Gauge, Workflow } from 'lucide-react';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { IrGateChart, IrRoomRunningChart, IrRoomUtilizationChart } from '@/Components/Radiology/IrSuiteCharts';
import { useIrSuite } from '@/features/radiology/hooks';
import { irSuiteSchema, type IrSuite } from '@/features/radiology/schemas';

const metric = (value: number | null, unit = '') => value === null ? 'Unavailable' : `${value.toLocaleString()}${unit}`;

function Panel({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>{description ? <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{description}</p> : null}<div className="mt-3">{children}</div></section>;
}

export default function IrSuitePage({ irSuite }: { irSuite: IrSuite }) {
  const initial = irSuiteSchema.parse(irSuite);
  const query = useIrSuite(initial);
  const view = query.data;
  const cards = [
    { label: 'IR suite utilization', value: metric(view.summary.utilizationPercent, '%'), detail: `${metric(view.summary.occupiedMinutes, ' min')} occupied / ${metric(view.summary.availableMinutes, ' min')} declared`, Icon: Gauge },
    { label: 'First-case on-time starts', value: metric(view.summary.fcots.percent, '%'), detail: `${view.summary.fcots.onTimeCount} of ${view.summary.fcots.eligibleCount} eligible · ${view.summary.fcots.graceMinutes}-minute grace`, Icon: Clock3 },
    { label: 'Median room turnover', value: metric(view.summary.turnover.median, ' min'), detail: `P90 ${metric(view.summary.turnover.p90, ' min')} · mean ${metric(view.summary.turnover.meanMinutes, ' min')}`, Icon: Workflow },
    { label: 'Interval coverage', value: metric(view.coverage.percent, '%'), detail: `${view.coverage.coveredIntervalCount} of ${view.coverage.candidateIntervalCount} completed cases`, Icon: Database },
  ];

  return (
    <DashboardLayout>
      <Head title="IR Suite Study - Zephyrus" />
      <PageContentLayout title="IR Suite Study" subtitle="Shared Perioperative suite definitions with explicit IR denominators and imaging preparation, transport, and read gates" headerContent={<SourceFreshnessBadge value={view.freshness} />}>
        <div className="space-y-4">
          <div role="status" className={`rounded-md border p-3 text-sm ${view.state === 'normal' ? 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark' : view.state === 'degraded' ? 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark' : 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark'}`}>{view.stateMessage}<span className="ml-3 tabular-nums">Generated {new Date(view.generatedAt).toLocaleString()}</span></div>

          <form action="/analytics/ir-utilization" method="get" aria-label="IR Suite Study filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <label className="text-sm">From<input aria-label="From" name="dateFrom" type="date" defaultValue={view.filters.dateFrom} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark" /></label>
            <label className="text-sm">Through<input aria-label="Through" name="dateTo" type="date" defaultValue={view.filters.dateTo} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark" /></label>
            <label className="text-sm">Declared room<select aria-label="Declared room" name="roomUuid" defaultValue={view.filters.roomUuid ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"><option value="">All declared IR rooms</option>{view.filterOptions.rooms.map((room) => <option key={room.roomUuid} value={room.roomUuid}>{room.label}</option>)}</select></label>
            <label className="text-sm">Patient class<select aria-label="Patient class" name="patientClass" defaultValue={view.filters.patientClass ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark"><option value="">All classes</option>{view.filterOptions.patientClasses.map((item) => <option key={item} value={item}>{item.replaceAll('_', ' ')}</option>)}</select></label>
            <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-info px-4 py-2 font-medium text-white">Apply bounded filters</button></div>
          </form>

          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(({ label, value, detail, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><Icon className="h-4 w-4" />{label}</div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p><p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</p></section>)}</div>

          {(view.coverage.missingIntervalCount + view.coverage.invalidIntervalCount + view.coverage.uncoveredRoomCount + view.coverage.missingGatePairCount + view.coverage.invalidGateIntervalCount) > 0 ? <div role="alert" className="rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">Excluded or unavailable evidence: {view.coverage.missingIntervalCount} missing MPPS interval(s), {view.coverage.invalidIntervalCount} invalid MPPS interval(s), {view.coverage.uncoveredRoomCount} room(s) without complete interval coverage ({view.coverage.missingOperatingWindowRoomCount} missing an operating window), {view.coverage.missingGatePairCount} missing imaging-gate pair(s), and {view.coverage.invalidGateIntervalCount} invalid imaging-gate interval(s).</div> : null}

          <Panel title="Declared IR room utilization" description="A room is in scope only when deployment metadata explicitly declares it as an IR suite."><IrRoomUtilizationChart rooms={view.rooms} denominator={view.definitions.denominator} /></Panel>
          <div className="grid gap-4 xl:grid-cols-2"><Panel title="Rooms running"><IrRoomRunningChart value={view.roomRunning} cutoffAt={view.sourceCutoffAt} cohort={view.summary.analyzedCaseCount} /></Panel><Panel title="Imaging-specific gates"><IrGateChart gates={view.gates} cohort={view.summary.analyzedCaseCount} /></Panel></div>

          <Panel title="Ownership and shared definitions" description={view.ownership.statement}>
            <div className="flex flex-wrap gap-3 text-sm"><Link href={view.ownership.radiologyHref} className="rounded-md border border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">Open live IR worklist</Link><Link href={view.ownership.perioperativeHref} className="rounded-md border border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">Open OR room status</Link><Link href={view.ownership.perioperativeStudyHref} className="rounded-md border border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">Open OR utilization Study</Link></div>
            <table aria-label="IR shared suite metric definitions" className="mt-3 w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Metric</th><th className="px-2 py-1">Definition</th><th className="px-2 py-1">Authority</th></tr></thead><tbody>{view.definitions.shared.map((definition) => <tr key={definition.authority} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2 font-medium">{definition.label}</td><td className="px-2 py-2">{definition.definition}</td><td className="px-2 py-2 font-mono text-xs">{definition.authority}</td></tr>)}</tbody></table>
          </Panel>

          <details className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><summary className="cursor-pointer font-semibold"><Activity className="mr-2 inline h-4 w-4" />Selected assertion and shared-clock audit ({view.lineage.count})</summary><p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{view.lineage.definition}</p><div className="mt-3 overflow-x-auto"><table aria-label="IR Suite selected assertion lineage" className="w-full min-w-[980px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Room / exam</th><th className="px-2 py-1">Scheduled / actual</th><th className="px-2 py-1">FCOTS</th><th className="px-2 py-1">Turnover</th><th className="px-2 py-1">Selected sources</th></tr></thead><tbody>{view.lineage.items.map((item) => <tr key={item.examUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{item.roomLabel}</span><span className="block font-mono text-xs">{item.examUuid}</span></td><td className="px-2 py-2 text-xs">Scheduled {new Date(item.scheduledStartAt).toLocaleString()}<span className="block">Actual {item.actualStartAt ? new Date(item.actualStartAt).toLocaleString() : 'missing'} → {item.actualEndAt ? new Date(item.actualEndAt).toLocaleString() : 'missing'}</span></td><td className="px-2 py-2">{item.isFirstCase ? item.fcotsOnTime === null ? 'Missing start' : item.fcotsOnTime ? 'On time' : 'Late' : 'Not first case'}</td><td className="px-2 py-2 tabular-nums">{metric(item.turnoverFromPriorMinutes, ' min')}</td><td className="px-2 py-2 text-xs">{item.startAssertion?.sourceKey ?? 'missing'} → {item.endAssertion?.sourceKey ?? 'missing'}</td></tr>)}</tbody></table></div></details>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
