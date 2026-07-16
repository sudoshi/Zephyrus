import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Clock3, Database, ShieldCheck } from 'lucide-react';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import {
  PharmacyDischargeReadinessChart, PharmacyMappingCoverageChart, PharmacyMissingDoseParetoChart,
  PharmacyQueueHeatmap, PharmacyShortageImpactChart, PharmacyTatDailyTrendChart,
  PharmacyTatDistributionChart, PharmacyTatWaterfallChart,
} from '@/Components/Pharmacy/PharmacyTatCharts';
import { usePharmacyTat } from '@/features/pharmacy/hooks';
import { pharmacyTatSchema, type PharmacyTat } from '@/features/pharmacy/tat-schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

const duration = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()} min`;
const words = (value: string) => value.replaceAll('_', ' ');
const asOf = (value: string | null) => value === null ? 'cutoff unavailable' : new Date(value).toLocaleString();

function Panel({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
    <h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
    {description ? <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{description}</p> : null}
    <div className="mt-3">{children}</div>
  </section>;
}

export default function PharmacyTatPage({ pharmacyTat }: { pharmacyTat: PharmacyTat }) {
  const initial = pharmacyTatSchema.parse(pharmacyTat);
  const query = usePharmacyTat(initial);
  const view = query.data;
  const summary = [
    { label: 'Order-to-administration median', value: duration(view.summary.medianMinutes), detail: `${view.summary.count} administered as of ${asOf(view.summary.administrationCutoffAt)}`, Icon: Clock3 },
    { label: 'Order-to-administration P90', value: duration(view.summary.p90Minutes), detail: `Mean ${duration(view.summary.meanMinutes)} shown secondarily`, Icon: BarChart3 },
    { label: 'Interval coverage', value: `${view.coverage.percent}%`, detail: `${view.coverage.includedIntervalCount} of ${view.coverage.possibleIntervalCount} applicable intervals`, Icon: Database },
    { label: 'Included orders', value: view.summary.includedOrderCount.toLocaleString(), detail: `${view.summary.candidateOrderCount} bounded candidates`, Icon: ShieldCheck },
  ];
  const hasExclusions = view.coverage.missingAssertionIntervalCount > 0
    || view.coverage.excludedNegativeIntervalCount > 0 || view.coverage.invalidTimestampIntervalCount > 0
    || view.coverage.selectedAssertionConflictCount > 0 || view.coverage.truncated;

  return <DashboardLayout>
    <Head title="Pharmacy TAT Study - Zephyrus" />
    <PageContentLayout title="Pharmacy TAT Study" subtitle="Governed Pharmacy percentiles across the verification, prepare, dispense, deliver, and administer waterfall. Real-time order-to-dispense segments are separated from the warehouse-fed administration tail, which is always cutoff-qualified and never real-time." headerContent={<div className="flex flex-wrap items-center gap-2"><SourceFreshnessBadge value={view.freshness} /><SourceFreshnessBadge value={view.administrationFreshness} /></div>}>
      <div className="space-y-4">
        <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[view.state]}`}><span>{view.stateMessage}</span><span className="tabular-nums">Generated {new Date(view.generatedAt).toLocaleString()} · Administration as of {asOf(view.administrationCutoffAt)}</span></div>

        <form action="/analytics/pharmacy-tat" method="get" aria-label="Pharmacy TAT study filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">From<input type="date" name="dateFrom" defaultValue={view.filters.dateFrom} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Through<input type="date" name="dateTo" defaultValue={view.filters.dateTo} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Priority<select name="priority" defaultValue={view.filters.priority ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All priorities</option>{view.filterOptions.priorities.map((item) => <option key={item} value={item}>{words(item)}</option>)}</select></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Patient class<select name="patientClass" defaultValue={view.filters.patientClass ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All classes</option>{view.filterOptions.patientClasses.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Shift<select name="shift" defaultValue={view.filters.shift ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All shifts</option>{view.filterOptions.shifts.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Branch<select name="branch" defaultValue={view.filters.branch ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All branches</option>{view.filterOptions.branches.map((item) => <option key={item} value={item}>{words(item)}</option>)}</select></label>
          <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Row limit<input type="number" name="limit" min={1} max={view.filterOptions.maxLimit} defaultValue={view.filters.limit} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
          <button type="submit" className="self-end rounded-md bg-healthcare-primary px-4 py-2 font-medium text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark">Apply</button>
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark sm:col-span-2 lg:col-span-4 xl:col-span-7">Inclusive range up to {view.filterOptions.maxRangeDays} days. Order-to-administration and delivery-to-administration percentiles are qualified by the warehouse batch cutoff and are never real-time.</p>
        </form>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{summary.map(({ label, value, detail, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between gap-2"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p><p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</p></section>)}</div>

        {hasExclusions ? <div role="alert" className="flex gap-3 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><span>Visible evidence limitations: {view.coverage.missingAssertionIntervalCount} missing pairs (including administration segments with no in-cohort warehouse record as of cutoff), {view.coverage.excludedNegativeIntervalCount} negative intervals, {view.coverage.invalidTimestampIntervalCount} invalid timestamps, {view.coverage.selectedAssertionConflictCount} selected-assertion conflicts, and {view.coverage.unanalyzedCandidateCount} candidates beyond the row limit.</span></div> : null}

        <Panel title="Governed Pharmacy waterfall" description="Verification, prepare, dispense, deliver, and end-to-end clocks are effective-dated definitions. Median and P90 are primary; mean is secondary. Delivery and administration segments are warehouse-fed and cutoff-qualified."><PharmacyTatWaterfallChart value={view.waterfall} /></Panel>
        <Panel title="Daily order-to-administration trend"><PharmacyTatDailyTrendChart value={view.dailyTrend} /></Panel>
        <div className="grid gap-4 xl:grid-cols-2">{Object.values(view.breakdowns).map((breakdown) => <Panel key={breakdown.dimension} title={breakdown.label}><PharmacyTatDistributionChart value={breakdown} /></Panel>)}</div>

        <div className="grid gap-4 xl:grid-cols-2">
          <Panel title="Verification queue-depth heatmap" description="Verification-queue entries by weekday and hour. A full accessible table accompanies the heatmap."><PharmacyQueueHeatmap value={view.queueDepthHeatmap} /></Panel>
          <Panel title="Missing-dose Pareto by branch"><PharmacyMissingDoseParetoChart value={view.missingDosePareto} /></Panel>
          <Panel title="Discharge medication readiness trend"><PharmacyDischargeReadinessChart value={view.dischargeReadinessTrend} /></Panel>
          <Panel title="Shortage impact contrast"><PharmacyShortageImpactChart value={view.shortageImpact} /></Panel>
        </div>
        <Panel title="Terminology mapping coverage"><PharmacyMappingCoverageChart value={view.mappingCoverage} /></Panel>

        <div className="grid gap-4 xl:grid-cols-2">
          <Panel title="Coverage and invalid-interval ledger" description={view.coverage.definition}><dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">{[
            ['Candidates', view.coverage.candidateOrderCount], ['Analyzed', view.coverage.analyzedOrderCount], ['Included orders', view.coverage.includedOrderCount],
            ['Possible intervals', view.coverage.possibleIntervalCount], ['Included intervals', view.coverage.includedIntervalCount], ['Coverage', `${view.coverage.percent}%`],
            ['Missing pairs', view.coverage.missingAssertionIntervalCount], ['Negative excluded', view.coverage.excludedNegativeIntervalCount], ['Invalid timestamps', view.coverage.invalidTimestampIntervalCount],
            ['Assertion conflicts', view.coverage.selectedAssertionConflictCount], ['Beyond limit', view.coverage.unanalyzedCandidateCount], ['Truncated', view.coverage.truncated ? 'Yes' : 'No'],
          ].map(([label, value]) => <div key={label} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark"><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</dt><dd className="mt-1 font-semibold tabular-nums">{value}</dd></div>)}</dl></Panel>
          <Panel title="Governed benchmark and policy references" description="References come from effective SLA definitions. Study segments are reference-only clocks with no numeric benchmark; a benchmark is never silently promoted to an SLA."><div className="overflow-x-auto"><table aria-label="Pharmacy governed benchmark references" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Clock</th><th className="px-2 py-1">Basis</th><th className="px-2 py-1">Classification</th><th className="px-2 py-1">Numeric lines</th><th className="px-2 py-1">Source</th></tr></thead><tbody>{view.benchmarkReferences.map((reference) => <tr key={reference.definitionUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{reference.label}</span><span className="block text-xs">{reference.metricKey}</span></td><td className="px-2 py-2 text-xs">{reference.basis === 'warehouse_as_of' ? 'Warehouse as-of' : 'Real-time'}</td><td className="px-2 py-2 capitalize">{words(reference.classification)}</td><td className="px-2 py-2 text-xs">{reference.numericLines.length === 0 ? 'No governed numeric line' : reference.numericLines.map((line) => `${line.kind} ${line.value} ${line.unit}`).join(' · ')}</td><td className="px-2 py-2 text-xs">{reference.sourceLabel}<span className="block">{reference.sourceReferenceId ?? 'No reference ID'}</span></td></tr>)}</tbody></table></div></Panel>
        </div>

        <details className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><summary className="cursor-pointer font-medium">Selected assertion and clock audit sample ({view.lineage.items.length} of {view.lineage.count} intervals)</summary><p className="mt-3 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{view.lineage.definition}</p><p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{view.privacy.identifierPolicy}</p><div className="mt-3 overflow-x-auto"><table aria-label="Pharmacy TAT selected assertion lineage sample" className="w-full min-w-[1120px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Order</th><th className="px-2 py-1">Clock / cohort</th><th className="px-2 py-1">Basis</th><th className="px-2 py-1">Selected start</th><th className="px-2 py-1">Selected stop</th><th className="px-2 py-1 text-right">Minutes</th></tr></thead><tbody>{view.lineage.items.map((item) => <tr key={`${item.orderUuid}-${item.definitionUuid}`} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2 text-xs tabular-nums">{item.orderUuid}</td><td className="px-2 py-2">{item.metricKey}<span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.medicationLabel} · {item.priority} · {item.patientClass} · {item.unitLabel} · {item.shift}</span></td><td className="px-2 py-2 text-xs">{item.basis === 'warehouse_as_of' ? 'Warehouse as-of' : 'Real-time'}</td><td className="px-2 py-2 text-xs">{item.startAssertion.code}<span className="block">{item.startAssertion.sourceKey} · rank {item.startAssertion.sourceRank}</span><span className="block tabular-nums">{new Date(item.startAssertion.occurredAt).toLocaleString()}</span></td><td className="px-2 py-2 text-xs">{item.stopAssertion.code}<span className="block">{item.stopAssertion.sourceKey} · rank {item.stopAssertion.sourceRank}</span><span className="block tabular-nums">{new Date(item.stopAssertion.occurredAt).toLocaleString()}</span></td><td className="px-2 py-2 text-right tabular-nums">{item.minutes}</td></tr>)}</tbody></table></div></details>

        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Operational follow-up remains in the Pharmacy workspace. This Study page is read-only, retrospective, and refreshes as one five-minute aggregate.</span><Link href="/pharmacy" className="font-medium text-healthcare-primary hover:underline">Open Medication Flow Board</Link></div>
      </div>
    </PageContentLayout>
  </DashboardLayout>;
}
