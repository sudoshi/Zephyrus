import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, Microscope } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import FrozenSectionTimer from '@/Components/Lab/FrozenSectionTimer';
import { useAnatomicPathology } from '@/features/lab/hooks';
import { anatomicPathologySchema, type AnatomicPathology as Contract } from '@/features/lab/schemas';

const words = (value: string) => value.replaceAll('_', ' ');
const duration = (minutes: number | null) => minutes === null ? 'Complete' : minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
const time = (value: string | null) => value ? new Date(value).toLocaleString() : 'Not asserted';
const STATE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

export default function AnatomicPathology({ pathology }: { pathology: Contract }) {
  const initial = anatomicPathologySchema.parse(pathology);
  const data = useAnatomicPathology(initial).data;
  const coverageMissing = data.coverage.apLis.status === 'missing' || data.coverage.backfill.status === 'missing';
  const cards = [
    { label: 'Cases shown', value: data.summary.visible, Icon: Microscope },
    { label: 'Open cases', value: data.summary.open, Icon: Clock3 },
    { label: 'Completed', value: data.summary.completed, Icon: CheckCircle2 },
    { label: 'Active frozen', value: data.summary.activeFrozen, Icon: AlertTriangle },
  ];

  return <DashboardLayout><Head title="AP Case Aging - Laboratory" /><PageContentLayout title="Anatomic Pathology Case Aging" subtitle="Stage aging, structural histology batches, consult/send-out work, and active frozen-section timers" headerContent={<SourceFreshnessBadge value={data.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`rounded-md border p-3 text-sm ${STATE[data.state]}`}>{data.stateMessage}</div>
      <form method="get" action="/lab/anatomic-path" aria-label="Anatomic Pathology filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <label className="text-sm">Stage<select name="stage" defaultValue={data.filters.stage} className="mt-1 block w-full rounded-md">{data.filterOptions.stages.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Cohort<select name="cohort" defaultValue={data.filters.cohort} className="mt-1 block w-full rounded-md">{data.filterOptions.cohorts.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Status<select name="status" defaultValue={data.filters.status} className="mt-1 block w-full rounded-md">{data.filterOptions.statuses.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Age band<select name="ageBand" defaultValue={data.filters.ageBand} className="mt-1 block w-full rounded-md">{data.filterOptions.ageBands.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>
      <section aria-labelledby="ap-benchmarks" className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><h2 id="ap-benchmarks" className="font-semibold">Established benchmark reference lines</h2><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Evidence references are context only and do not establish local policy or score cases.</p><div className="mt-3 grid gap-3 lg:grid-cols-3">{data.benchmarkLines.map((line) => <article key={line.key} className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><div className="flex justify-between gap-2"><strong>{line.label}</strong><span className="tabular-nums">P{line.percentile} · {line.thresholdValue} {line.thresholdUnit}</span></div><p className="mt-2 text-xs">{line.evidenceLabel}</p><p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{line.applicability}</p></article>)}</div></section>
      {(coverageMissing || data.coverage.backfill.status === 'not_configured') ? <section aria-label="AP feed coverage" className={`rounded-md border p-3 text-sm ${coverageMissing ? 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark' : 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark'}`}><p>{data.coverage.apLis.explanation}</p><p className="mt-1">{data.coverage.backfill.explanation}</p></section> : null}
      <div className="space-y-3">{data.data.map((item) => <article key={item.apCaseUuid} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold">{item.procedureLabel}</h2><span className="rounded-md border border-healthcare-border px-2 py-0.5 text-xs dark:border-healthcare-border-dark">{item.cohortLabel}</span><span className="rounded-md border border-healthcare-primary/40 px-2 py-0.5 text-xs text-healthcare-primary">{item.stageLabel}</span></div><p className="mt-1 break-all text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.sourceAccessionKey ?? item.sourceCaseKey} · {item.caseLabel ?? 'No active OR link'}</p></div><div className="text-right"><p className="font-semibold tabular-nums">{duration(item.stageAgeMinutes)} in stage</p><p className="text-xs tabular-nums">{duration(item.totalAgeMinutes)} total</p></div></div>
        {item.frozen.timer ? <div className="mt-3"><FrozenSectionTimer timer={item.frozen.timer} /><p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Operational timer only; established single-block reference is 90% within 20 minutes and is not a clinical command.</p></div> : null}
        {item.structuralStage.kind !== 'none' ? <div className="mt-3 rounded-md border border-healthcare-info/40 bg-healthcare-info/10 p-3 text-sm text-healthcare-info dark:text-healthcare-info-dark"><strong>{item.structuralStage.label}</strong><p className="mt-1">{item.structuralStage.explanation}</p>{item.structuralStage.enteredAt ? <p className="mt-1 text-xs">Entered {time(item.structuralStage.enteredAt)}</p> : null}</div> : null}
        <ol aria-label={`${item.procedureLabel} stages`} className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{item.timeline.map((stage) => <li key={stage.stage} className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><div className="flex justify-between gap-2"><strong>{stage.label}</strong><span>{words(stage.state)}</span></div><p className="mt-1 text-xs tabular-nums">{time(stage.at)}</p></li>)}</ol>
      </article>)}{data.data.length === 0 ? <div className="rounded-lg border border-dashed border-healthcare-border p-8 text-center text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">No AP cases match the selected filters.</div> : null}</div>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.privacy.explanation}</p>
    </div>
  </PageContentLayout></DashboardLayout>;
}
