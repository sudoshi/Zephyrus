import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Clock3, GitBranch, ShieldAlert } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { useLabDecisionPending } from '@/features/lab/hooks';
import { labDecisionPendingSchema, type LabDecisionPending } from '@/features/lab/schemas';
import BarrierAnnotationDrawer from './BarrierAnnotationDrawer';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

const URGENCY_STYLE = {
  breach: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  unconfigured: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
} as const;

const words = (value: string) => value.replaceAll('_', ' ');

export default function PendingDecisions({ pendingDecisions }: { pendingDecisions: LabDecisionPending }) {
  const initial = labDecisionPendingSchema.parse(pendingDecisions);
  const query = useLabDecisionPending(initial);
  const data = query.data;
  const cards = [
    { label: 'Ranked decisions', value: data.summary.visible, Icon: GitBranch },
    { label: 'Live OR gates', value: data.summary.orGates, Icon: ShieldAlert },
    { label: 'Discharge gates', value: data.summary.dischargeGates, Icon: ArrowRight },
    { label: 'ED dispositions', value: data.summary.edDispositions, Icon: Clock3 },
  ];

  return <DashboardLayout><Head title="Decision-Pending Results - Laboratory" /><PageContentLayout title="Decision-Pending Results" subtitle="Catalog-governed Laboratory gates ranked by validated downstream impact without clinical result content" headerContent={<SourceFreshnessBadge value={data.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[data.state]}`}><span>{data.stateMessage}</span><span className="tabular-nums">Generated {new Date(data.generatedAt).toLocaleTimeString()}</span></div>
      {data.summary.unresolvedDestinations > 0 ? <div role="alert" className="flex items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><p>{data.summary.unresolvedDestinations} candidate(s) are withheld because their downstream object could not be validated. No gate is inferred from a test name.</p></div> : null}
      <section aria-label="Ranking rule" className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><strong>Deterministic ranking:</strong> {data.rankingRule}</section>
      <form method="get" action="/lab/pending-decisions" aria-label="Decision-pending filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        {data.filters.orderUuid ? <input type="hidden" name="orderUuid" value={data.filters.orderUuid} /> : null}
        {data.filters.source ? <input type="hidden" name="source" value={data.filters.source} /> : null}
        <label className="text-sm">Decision class<select name="decisionClass" defaultValue={data.filters.decisionClass} className="mt-1 block w-full rounded-md"><option value="all">All decisions</option>{data.filterOptions.decisionClasses.filter((value) => value !== 'all').map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Priority<select name="priority" defaultValue={data.filters.priority ?? ''} className="mt-1 block w-full rounded-md"><option value="">All priorities</option>{data.filterOptions.priorities.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <label className="text-sm">Unit<select name="unitId" defaultValue={data.filters.unitId ?? ''} className="mt-1 block w-full rounded-md"><option value="">All units</option>{data.filterOptions.units.map((unit) => <option key={unit.unitId} value={unit.unitId}>{unit.label}</option>)}</select></label>
        <label className="text-sm">SLA state<select name="urgency" defaultValue={data.filters.urgency} className="mt-1 block w-full rounded-md">{data.filterOptions.urgencies.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>
      <div className="space-y-3">{data.data.map((item) => <article key={item.pendingKey} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><span className="rounded-md bg-healthcare-primary px-2 py-0.5 text-xs font-semibold text-white">#{item.ranking.position}</span><h2 className="font-semibold">{item.label}</h2><span className="rounded-md border border-healthcare-border px-2 py-0.5 text-xs dark:border-healthcare-border-dark">{words(item.decisionClass)}</span><span className={`rounded-md border px-2 py-0.5 text-xs ${URGENCY_STYLE[item.sla.urgency]}`}>{words(item.sla.urgency)}</span></div><p className="mt-1 break-all text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.patientRef} · {item.locationLabel ?? 'Unit unavailable'} · {item.priority} · {item.testFamily}</p></div><div className="text-right"><p className="text-lg font-semibold tabular-nums">{item.ageMinutes} min</p><p className="text-xs">{words(item.currentStage)}</p></div></div>
        <div className="mt-3 grid gap-3 lg:grid-cols-3">
          <section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">What is pending</h3><p className="mt-1">{words(item.resultState.stage)} · {words(item.resultState.status)}{item.resultState.critical ? ' · critical flag' : ''} · {words(item.resultState.abnormalFlag)}</p><p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Operational state only; result values and narratives are excluded.</p></section>
          <section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">SLA clock</h3>{item.sla.definition ? <><p className="mt-1">{item.sla.definition.label}</p><p className="text-xs">{item.sla.elapsedMinutes ?? 'Unavailable'} min elapsed · warn {item.sla.definition.warningMinutes ?? '—'} · breach {item.sla.definition.breachMinutes ?? '—'}</p></> : <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No matching governed item clock; threshold unconfigured.</p>}</section>
          <section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Downstream destination</h3><p className="mt-1">{item.destination.label}</p><p className="text-xs">{item.decisionContext.explanation}</p>{item.destination.expectedDischargeDate ? <p className="mt-1 text-xs">Expected discharge {item.destination.expectedDischargeDate} · bed impact {item.destination.bedImpact}</p> : null}{item.destination.scheduledAt ? <p className="mt-1 text-xs">Scheduled {new Date(item.destination.scheduledAt).toLocaleString()}</p> : null}</section>
        </div>
        <details className="mt-3 rounded-md border border-healthcare-border p-3 text-sm dark:border-healthcare-border-dark"><summary className="cursor-pointer font-medium">Why this rank and gate?</summary><ul className="mt-2 list-disc space-y-1 pl-5">{item.ranking.reasons.map((reason) => <li key={reason}>{reason}</li>)}</ul><p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.gateEvidence.explanation}</p></details>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2"><div className="flex flex-wrap gap-2"><Link href={item.drill.specimenHref} className="rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium dark:border-healthcare-border-dark">Open specimen chain</Link><Link href={item.drill.destinationHref} className="rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium dark:border-healthcare-border-dark">Open destination</Link></div>{data.canAnnotateBarriers && item.encounterLinked ? <BarrierAnnotationDrawer item={item} reasons={data.barrierReasons} onSaved={() => query.refetch()} /> : null}</div>
      </article>)}{data.data.length === 0 ? <div className="rounded-lg border border-dashed border-healthcare-border p-8 text-center text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">No validated decision-pending Laboratory results match the selected filters.</div> : null}</div>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Excluded from this queue: {data.exclusions.noGateCatalog} current non-gating catalog order(s), {data.exclusions.completedOrCancelled} completed/cancelled gated order(s), and {data.summary.unresolvedDestinations} unvalidated destination(s). {data.exclusions.explanation}</p>
    </div>
  </PageContentLayout></DashboardLayout>;
}
