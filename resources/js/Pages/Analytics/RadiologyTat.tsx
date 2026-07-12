import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Clock3, Database, ShieldCheck } from 'lucide-react';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import {
  TatDailyTrendChart,
  TatDistributionChart,
  TatParetoChart,
  TatWaterfallChart,
} from '@/Components/Radiology/RadiologyTatCharts';
import { useRadiologyTat } from '@/features/radiology/hooks';
import { radiologyTatSchema, type RadiologyTat } from '@/features/radiology/schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

function duration(value: number | null) {
  if (value === null) return 'Unavailable';
  return `${value.toLocaleString()} min`;
}

function Panel({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      {description ? <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{description}</p> : null}
      <div className="mt-3">{children}</div>
    </section>
  );
}

export default function RadiologyTatPage({ radiologyTat }: { radiologyTat: RadiologyTat }) {
  const initial = radiologyTatSchema.parse(radiologyTat);
  const query = useRadiologyTat(initial);
  const view = query.data;
  const summary = [
    { label: 'Order-to-final median', value: duration(view.summary.median), detail: `${view.summary.count} comparable exams`, Icon: Clock3 },
    { label: 'Order-to-final P90', value: duration(view.summary.p90), detail: `Mean ${duration(view.summary.meanMinutes)} shown secondarily`, Icon: BarChart3 },
    { label: 'Interval coverage', value: `${view.coverage.percent}%`, detail: `${view.coverage.includedIntervalCount} of ${view.coverage.possibleIntervalCount} applicable intervals`, Icon: Database },
    { label: 'Included exams', value: view.summary.includedExamCount.toLocaleString(), detail: `${view.summary.candidateExamCount} bounded candidates`, Icon: ShieldCheck },
  ];

  return (
    <DashboardLayout>
      <Head title="Radiology TAT Study - Zephyrus" />
      <PageContentLayout
        title="Radiology TAT Study"
        subtitle="Governed segment turnaround, percentile distributions, cohort comparisons, breach Pareto, and selected-assertion audit"
        headerContent={<SourceFreshnessBadge value={view.freshness} />}
      >
        <div className="space-y-4">
          <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[view.state]}`}>
            <span>{view.stateMessage}</span>
            <span className="tabular-nums">Generated {new Date(view.generatedAt).toLocaleString()}</span>
          </div>

          <form action="/analytics/radiology-tat" method="get" aria-label="Radiology TAT study filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">From<input type="date" name="dateFrom" defaultValue={view.filters.dateFrom} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Through<input type="date" name="dateTo" defaultValue={view.filters.dateTo} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Priority<select name="priority" defaultValue={view.filters.priority ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All priorities</option>{view.filterOptions.priorities.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Modality<select name="modality" defaultValue={view.filters.modality ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All modalities</option>{view.filterOptions.modalities.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}</select></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Patient class<select name="patientClass" defaultValue={view.filters.patientClass ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All classes</option>{view.filterOptions.patientClasses.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Shift<select name="shift" defaultValue={view.filters.shift ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 capitalize text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All shifts</option>{view.filterOptions.shifts.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Row limit<input type="number" name="limit" min={1} max={view.filterOptions.maxLimit} defaultValue={view.filters.limit} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <button type="submit" className="self-end rounded-md bg-healthcare-primary px-4 py-2 font-medium text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark">Apply</button>
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark sm:col-span-2 lg:col-span-4 xl:col-span-8">Inclusive date range up to {view.filterOptions.maxRangeDays} days. Query candidates are index-bounded before assertion hydration; truncation is reported as degraded coverage.</p>
          </form>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{summary.map(({ label, value, detail, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between gap-2"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p><p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</p></section>)}</div>

          {(view.coverage.missingAssertionIntervalCount > 0 || view.coverage.excludedNegativeIntervalCount > 0 || view.coverage.excludedCorrectedExamCount > 0 || view.coverage.truncated) ? <div role="alert" className="flex gap-3 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><span>Visible exclusions: {view.coverage.missingAssertionIntervalCount} missing assertion pairs, {view.coverage.excludedNegativeIntervalCount} negative intervals, {view.coverage.invalidTimestampIntervalCount} invalid timestamps, {view.coverage.excludedCorrectedExamCount} corrected exams, {view.coverage.selectedAssertionConflictCount} selected-assertion conflicts, and {view.coverage.unanalyzedCandidateCount} candidates beyond the row limit.</span></div> : null}

          <Panel title="Governed segment waterfall" description="Median and P90 are primary. Mean is retained only as a secondary descriptive statistic."><TatWaterfallChart value={view.waterfall} /></Panel>
          <Panel title="Daily order-to-final trend"><TatDailyTrendChart value={view.dailyTrend} /></Panel>

          <div className="grid gap-4 xl:grid-cols-2">
            {Object.values(view.breakdowns).map((breakdown) => <Panel key={breakdown.dimension} title={breakdown.label}><TatDistributionChart value={breakdown} /></Panel>)}
          </div>

          <Panel title="Night and weekend comparison" description={view.nightWeekendComparison.definition}><TatDistributionChart value={view.nightWeekendComparison} /></Panel>
          <Panel title="Breach Pareto"><TatParetoChart value={view.breachPareto} /></Panel>

          <div className="grid gap-4 xl:grid-cols-2">
            <Panel title="Coverage and exclusion ledger" description={view.coverage.definition}>
              <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                {[
                  ['Candidates', view.coverage.candidateExamCount], ['Analyzed', view.coverage.analyzedExamCount], ['Included exams', view.coverage.includedExamCount],
                  ['Possible intervals', view.coverage.possibleIntervalCount], ['Included intervals', view.coverage.includedIntervalCount], ['Coverage', `${view.coverage.percent}%`],
                  ['Missing pairs', view.coverage.missingAssertionIntervalCount], ['Negative excluded', view.coverage.excludedNegativeIntervalCount], ['Corrected excluded', view.coverage.excludedCorrectedExamCount],
                  ['Invalid timestamps', view.coverage.invalidTimestampIntervalCount], ['Assertion conflicts', view.coverage.selectedAssertionConflictCount], ['Beyond limit', view.coverage.unanalyzedCandidateCount],
                ].map(([label, value]) => <div key={label} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark"><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</dt><dd className="mt-1 font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</dd></div>)}
              </dl>
            </Panel>
            <Panel title="Governed benchmark and policy lines" description="Numeric lines appear only when the persisted definition supplies a target, warning, or breach threshold. Local policy is not relabeled as an external benchmark.">
              <div className="overflow-x-auto"><table aria-label="Radiology governed benchmark lines" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Clock / scope</th><th className="px-2 py-1">Line</th><th className="px-2 py-1 text-right">Value</th><th className="px-2 py-1">Source</th></tr></thead><tbody>{view.benchmarkLines.map((line) => <tr key={`${line.definitionUuid}-${line.lineKind}`} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{line.label}</span><span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{line.scopeLabel}</span></td><td className="px-2 py-2 capitalize">{line.lineKind}</td><td className="px-2 py-2 text-right tabular-nums">{duration(line.valueMinutes)}</td><td className="px-2 py-2 text-xs">{line.sourceLabel}<span className="block">{line.sourceReferenceId ?? 'No reference ID'}</span></td></tr>)}{view.benchmarkLines.length === 0 ? <tr><td colSpan={4} className="px-2 py-6 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No governed numeric lines match this definition registry.</td></tr> : null}</tbody></table></div>
            </Panel>
          </div>

          <details className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <summary className="cursor-pointer font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Selected assertion and clock audit sample ({view.lineage.items.length} of {view.lineage.count} intervals)</summary>
            <p className="mt-3 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{view.lineage.definition}</p>
            <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{view.privacy.identifierPolicy}</p>
            <div className="mt-3 overflow-x-auto"><table aria-label="Radiology TAT selected assertion lineage sample" className="w-full min-w-[980px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Exam</th><th className="px-2 py-1">Clock</th><th className="px-2 py-1">Selected start</th><th className="px-2 py-1">Selected stop</th><th className="px-2 py-1 text-right">Minutes</th></tr></thead><tbody>{view.lineage.items.map((item) => <tr key={`${item.examUuid}-${item.definitionUuid}`} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2 font-mono text-xs">{item.examUuid}</td><td className="px-2 py-2">{item.metricKey}<span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.priority} · {item.modality} · {item.patientClass} · {item.shift}</span></td><td className="px-2 py-2 text-xs">{item.startAssertion.code}<span className="block">{item.startAssertion.sourceKey} · rank {item.startAssertion.sourceRank}</span><span className="block tabular-nums">{new Date(item.startAssertion.occurredAt).toLocaleString()}</span></td><td className="px-2 py-2 text-xs">{item.stopAssertion.code}<span className="block">{item.stopAssertion.sourceKey} · rank {item.stopAssertion.sourceRank}</span><span className="block tabular-nums">{new Date(item.stopAssertion.occurredAt).toLocaleString()}</span></td><td className="px-2 py-2 text-right tabular-nums">{item.minutes}</td></tr>)}</tbody></table></div>
          </details>

          <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Operational follow-up stays in the Radiology workspace; this Study page is read-only and retrospective.</span><Link href="/radiology" className="font-medium text-healthcare-primary hover:underline">Open Imaging Flow Board</Link></div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
