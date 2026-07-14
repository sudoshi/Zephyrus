import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, HelpCircle, PackageCheck, ShieldAlert } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { usePharmacyDischargeReadiness } from '@/features/pharmacy/hooks';
import { pharmacyDischargeSchema, type PharmacyDischarge, type PharmacyDischargeItem } from '@/features/pharmacy/discharge-schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

// Server-provided target state only: no raw-minute comparison happens here.
const TARGET_META = {
  on_track: { label: 'On track', Icon: Clock3, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
  overdue: { label: 'Overdue vs target', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  met: { label: 'Ready by target', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  late: { label: 'Ready after target', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  unknown: { label: 'Unknown', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
} as const;

function pipelineHref(discharge: PharmacyDischarge, status: string | null) {
  const query = new URLSearchParams();
  if (status) query.set('pipeline', status);
  if (discharge.filters.source) query.set('source', discharge.filters.source);
  const suffix = query.toString();
  return suffix ? `/pharmacy/discharge-meds?${suffix}` : '/pharmacy/discharge-meds';
}

const relative = (minutes: number) => minutes === 0 ? 'at target' : minutes > 0 ? `${minutes} min over` : `${Math.abs(minutes)} min ahead`;

function DischargeRow({ item }: { item: PharmacyDischargeItem }) {
  const meta = TARGET_META[item.targetState];
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{item.medicationLabel}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.patientRef} · {item.unitLabel} · {item.pipelineLabel}{item.priorAuthPending ? ' · prior-auth pending' : ''}</p>
        <p className={`mt-1 inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{meta.label} · <span className="tabular-nums">{relative(item.targetRelativeMinutes)}</span></p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1 text-right">
        <p className="font-semibold tabular-nums">{item.ageMinutes} min</p>
        {item.rtdcHref ? <Link href={item.rtdcHref} className="text-xs text-healthcare-primary underline focus:outline-none focus:ring-2 focus:ring-healthcare-info">Discharge board</Link> : null}
      </div>
    </div>
  </article>;
}

export default function DischargeMeds({ discharge }: { discharge: PharmacyDischarge }) {
  const initial = pharmacyDischargeSchema.parse(discharge);
  const query = usePharmacyDischargeReadiness(initial);
  const board = query.data;
  const cards = [
    { label: 'Planned discharges', value: board.data.summary.candidates, Icon: PackageCheck },
    { label: 'Blocking steps', value: board.data.summary.blocking, Icon: AlertTriangle },
    { label: 'Prior-auth pending', value: board.data.summary.priorAuthPending, Icon: ShieldAlert },
    { label: 'Ready by target', value: board.data.summary.readyByTargetPercent === null ? '—' : `${board.data.summary.readyByTargetPercent}%`, Icon: CheckCircle2 },
  ];

  return <DashboardLayout><Head title="Discharge Medication Readiness" /><PageContentLayout title="Discharge Medication Readiness" subtitle="Today's planned discharges by governed pharmacy pipeline state, target-relative aging, and ready-by-target compliance from operational facts" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="font-semibold">Discharge medication pipeline</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.cohortDefinition}</p>
        <nav aria-label="Pipeline stages" className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
          {board.data.pipeline.map((stage) => <Link key={stage.status} href={pipelineHref(board, board.filters.pipeline === stage.status ? null : stage.status)} preserveState className={`rounded-md border p-3 text-left focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.pipeline === stage.status ? 'border-healthcare-primary bg-healthcare-primary/10' : stage.blocking ? 'border-healthcare-warning/40' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{stage.label}</p>
            <p className="text-xl font-semibold tabular-nums">{stage.count}</p>
            <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{stage.oldestAgeMinutes === null ? 'no aging' : `${stage.oldestAgeMinutes} min oldest`}</p>
          </Link>)}
        </nav>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><h2 className="font-semibold">Discharge medication queue</h2><span className="text-sm tabular-nums">{board.data.items.length} shown</span></div>
        <div className="mt-3 space-y-2">
          {board.data.items.map((item) => <DischargeRow key={item.queueUuid} item={item} />)}
          {board.data.items.length === 0 ? <p className="text-sm">No discharge medication work matches the selected pipeline filter.</p> : null}
        </div>
      </section>
    </div>
  </PageContentLayout></DashboardLayout>;
}
