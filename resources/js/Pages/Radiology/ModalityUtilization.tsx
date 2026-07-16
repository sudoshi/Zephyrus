import { Head } from '@inertiajs/react';
import { AlertTriangle, Clock3, Gauge, Info, ScanLine, TimerOff } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import ModalityUtilizationChart from '@/Components/Radiology/ModalityUtilizationChart';
import { useModalityUtilization } from '@/features/radiology/hooks';
import { modalityUtilizationSchema, type ModalityUtilization } from '@/features/radiology/schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

const SEGMENT_STYLE = {
  exam: 'bg-healthcare-success',
  planned_downtime: 'bg-healthcare-warning',
  unplanned_downtime: 'bg-healthcare-critical',
  idle: 'bg-healthcare-info',
  unknown: 'bg-healthcare-border-dark',
} as const;

function minutes(value: number | null) {
  if (value === null) return 'Unavailable';
  if (value < 60) return `${value.toLocaleString()} min`;
  return `${(value / 60).toFixed(1)} hr`;
}

function percent(value: number | null) {
  return value === null ? 'Unavailable' : `${value.toFixed(1)}%`;
}

function CoverageBadge({ status }: { status: ModalityUtilization['coverage']['status'] }) {
  const normal = status === 'complete';
  return <span className={`rounded-md border px-2.5 py-1 text-sm font-medium capitalize ${normal ? 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark' : 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark'}`}>MPPS coverage: {status.replace('_', ' ')}</span>;
}

export default function ModalityUtilizationPage({ modalityUtilization }: { modalityUtilization: ModalityUtilization }) {
  const initial = modalityUtilizationSchema.parse(modalityUtilization);
  const query = useModalityUtilization(initial);
  const view = query.data;
  const cards = [
    { label: 'Staffed window', value: minutes(view.summary.availableMinutes), definition: view.definitions.available, Icon: Clock3 },
    { label: 'Machine utilization', value: percent(view.summary.utilizationPercent), definition: view.definitions.utilization, Icon: Gauge },
    { label: 'Exam activity', value: minutes(view.summary.examMinutes), definition: view.definitions.exam, Icon: ScanLine },
    { label: 'Unplanned downtime', value: minutes(view.summary.unplannedDowntimeMinutes), definition: view.definitions.downtime, Icon: TimerOff },
  ];

  return (
    <DashboardLayout>
      <Head title="Modality Utilization - Radiology" />
      <PageContentLayout title="Modality Utilization" subtitle="Coverage-aware scanner activity within declared staffed operating windows" headerContent={<CoverageBadge status={view.coverage.status} />}>
        <div className="space-y-4">
          <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[view.state]}`}>
            <span>{view.stateMessage}</span>
            <span className="tabular-nums">Generated {new Date(view.generatedAt).toLocaleTimeString()}</span>
          </div>

          <form action="/radiology/modality" method="get" aria-label="Modality utilization filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark md:grid-cols-4 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]">
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Date<input name="date" type="date" defaultValue={view.filters.date} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Start time<input name="startTime" type="time" defaultValue={view.filters.startTime} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">End time<input name="endTime" type="time" defaultValue={view.filters.endTime} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark" /></label>
            <label className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Modality<select name="modality" defaultValue={view.filters.modality ?? ''} className="mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-background px-3 py-2 text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark"><option value="">All modalities</option>{view.filterOptions.modalities.map((modality) => <option key={modality.code} value={modality.code}>{modality.label}</option>)}</select></label>
            <button type="submit" className="self-end rounded-md bg-healthcare-primary px-4 py-2 font-medium text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark">Apply</button>
          </form>

          {view.coverage.warning ? <div role="alert" className="flex gap-3 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><div><p className="font-medium">Coverage warning</p><p>{view.coverage.warning}</p><p className="mt-1">{view.coverage.coveredExamCount} of {view.coverage.candidateExamCount} performed intervals covered · {view.coverage.percent.toFixed(1)}% data coverage</p></div></div> : null}

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {cards.map(({ label, value, definition, Icon }) => <section key={label} title={definition} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between gap-2"><p className="flex items-center gap-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}<Info className="size-3.5" aria-label={`Definition: ${definition}`} /></p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p></section>)}
          </div>

          <section className="rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="border-b border-healthcare-border p-4 dark:border-healthcare-border-dark"><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Scanner operating-window partition</h2><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Downtime overlays take precedence over overlapping exams. Unknown coverage is never relabeled as idle.</p></div>
            <ModalityUtilizationChart scanners={view.scanners} referenceLines={view.referenceLines} />
          </section>

          <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Scanner interval detail</h2>
            <div className="mt-3 space-y-4">{view.scanners.map((scanner) => <article key={scanner.scannerUuid} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark"><div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{scanner.label} <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">({scanner.modality})</span></h3><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{minutes(scanner.availableMinutes)} staffed · {percent(scanner.utilizationPercent)} utilized · {scanner.timezone}</p></div><span className={`rounded-md border px-2 py-1 text-xs font-medium ${scanner.coverage.status === 'complete' ? 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark' : 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark'}`}>{scanner.coverage.status.replace('_', ' ')}</span></div>
              {scanner.segments.length > 0 ? <div className="mt-3 flex h-5 overflow-hidden rounded-sm" aria-label={`${scanner.label} interval timeline`}>{scanner.segments.map((segment, index) => <div key={`${segment.startAt}-${index}`} className={SEGMENT_STYLE[segment.type]} style={{ flexGrow: segment.minutes }} title={`${segment.label}: ${segment.minutes} minutes, ${new Date(segment.startAt).toLocaleTimeString()} to ${new Date(segment.endAt).toLocaleTimeString()}`} aria-label={`${segment.label}, ${segment.minutes} minutes`} />)}</div> : <p className="mt-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">No staffed operating interval is declared for this filter.</p>}
              <dl className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4"><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Planned down</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{minutes(scanner.plannedDowntimeMinutes)}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Unplanned down</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{minutes(scanner.unplannedDowntimeMinutes)}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Patient mix</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">ED {scanner.patientMix.ed} · IP {scanner.patientMix.inpatient} · OP {scanner.patientMix.outpatient}</dd></div><div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Reconciliation</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{scanner.reconciliationDeltaMinutes === null ? 'Unavailable' : `${scanner.reconciliationDeltaMinutes.toFixed(2)} min delta`}</dd></div></dl>
              {scanner.coverage.warning ? <p className="mt-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">{scanner.coverage.warning}</p> : null}
            </article>)}{view.scanners.length === 0 ? <p className="py-6 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No active scanners match this filter.</p> : null}</div>
          </section>

          <details className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><summary className="cursor-pointer font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Measure definitions and coverage rules</summary><dl className="mt-3 space-y-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{Object.entries(view.definitions).map(([key, definition]) => <div key={key}><dt className="font-medium capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{key.replace(/([A-Z])/g, ' $1')}</dt><dd>{definition}</dd></div>)}</dl>{view.sourceCutoffAt ? <p className="mt-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Latest MPPS evidence received {new Date(view.sourceCutoffAt).toLocaleString()}.</p> : null}</details>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
