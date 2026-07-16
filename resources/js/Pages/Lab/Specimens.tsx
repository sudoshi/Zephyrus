import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight, FlaskConical, GitBranch } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { useLabSpecimens } from '@/features/lab/hooks';
import { labSpecimensSchema, type LabSpecimens } from '@/features/lab/schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

function pageHref(data: LabSpecimens, cursor: string) {
  const query = new URLSearchParams();
  Object.entries(data.filters).forEach(([key, value]) => {
    if (key !== 'cursor' && value !== null && value !== 'all') query.set(key, String(value));
  });
  query.set('cursor', cursor);
  return `/lab/specimens?${query}`;
}

const display = (value: string | null) => value ? new Date(value).toLocaleString() : 'Pending';

export default function Specimens({ specimens }: { specimens: LabSpecimens }) {
  const initial = labSpecimensSchema.parse(specimens);
  const query = useLabSpecimens(initial);
  const data = query.data;

  return <DashboardLayout><Head title="Specimen Tracker - Laboratory" /><PageContentLayout title="Specimen Tracker" subtitle="Collection, transport, receipt, result state, recollect lineage, and downstream impact without clinical result content" headerContent={<SourceFreshnessBadge value={data.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[data.state]}`}><span>{data.stateMessage}</span><span>{data.meta.count} on this page</span></div>
      {data.coverage.transport.status === 'missing' ? <div className="flex items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><p>{data.coverage.transport.explanation}</p></div> : null}
      <form method="get" action="/lab/specimens" aria-label="Specimen filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <label className="text-sm">Status<select name="status" defaultValue={data.filters.status ?? ''} className="mt-1 block w-full rounded-md"><option value="">All statuses</option>{data.filterOptions.statuses.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Test family<select name="testFamily" defaultValue={data.filters.testFamily ?? ''} className="mt-1 block w-full rounded-md"><option value="">All families</option>{data.filterOptions.testFamilies.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Unit<select name="unitId" defaultValue={data.filters.unitId ?? ''} className="mt-1 block w-full rounded-md"><option value="">All units</option>{data.filterOptions.units.map((unit) => <option key={unit.unitId} value={unit.unitId}>{unit.label}</option>)}</select></label>
        <label className="text-sm">Priority<select name="priority" defaultValue={data.filters.priority ?? ''} className="mt-1 block w-full rounded-md"><option value="">All priorities</option>{data.filterOptions.priorities.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <label className="text-sm">Rejection<select name="rejection" defaultValue={data.filters.rejection} className="mt-1 block w-full rounded-md">{data.filterOptions.rejections.map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label>
        <label className="text-sm">Age<select name="age" defaultValue={data.filters.age} className="mt-1 block w-full rounded-md">{data.filterOptions.ageBands.map((value) => <option key={value} value={value}>{value.replaceAll('_', '–').replace('plus', '+')}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="space-y-3">{data.data.map((row) => <article key={row.specimenUuid} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><FlaskConical className="size-4 text-healthcare-primary" aria-hidden="true" /><h2 className="font-semibold">{row.testFamily?.replaceAll('_', ' ') ?? row.specimenType}</h2><span className="rounded-md border border-healthcare-border px-2 py-0.5 text-xs capitalize dark:border-healthcare-border-dark">{row.status.replaceAll('_', ' ')}</span>{row.rejectionReasonCode ? <span className="rounded-md border border-healthcare-critical/40 px-2 py-0.5 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">{row.rejectionReasonCode}</span> : null}</div><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.patientRef} · {row.unitLabel ?? 'Unit unavailable'} · {row.priority} · {row.specimenType}</p><p className="mt-1 break-all font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Accession {row.accessionIdentity.sourceAccessionKey ?? 'unavailable'} · specimen {row.accessionIdentity.sourceSpecimenKey}</p></div><div className="text-right"><p className="text-lg font-semibold tabular-nums">{row.ageMinutes} min</p><p className="text-xs">Chain {row.chain.position} of {row.chain.length}</p></div></div>
        <ol aria-label={`Timeline for specimen ${row.accessionIdentity.sourceSpecimenKey}`} className={`mt-4 grid gap-2 ${data.coverage.transport.columnVisible ? 'sm:grid-cols-4 lg:grid-cols-6' : 'sm:grid-cols-3 lg:grid-cols-5'}`}>{row.timeline.map((stage) => <li key={stage.code} className={`rounded-md border p-2 text-xs ${stage.state === 'exception' ? 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}><span className="block font-medium">{stage.label}</span><span className="mt-1 block tabular-nums">{display(stage.at)}</span></li>)}</ol>
        <div className="mt-3 grid gap-3 md:grid-cols-2"><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Result state</h3>{row.result ? <p className="mt-1">{row.result.testLabel} · {row.result.stage.replaceAll('_', ' ')} · {row.result.abnormalFlag}{row.result.autoVerified ? ' · auto-verified' : ''}{row.result.critical ? ' · critical' : ''} · {row.result.versionCount} version{row.result.versionCount === 1 ? '' : 's'}</p> : <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No operational result assertion yet.</p>}</section><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Recollect lineage</h3><p className="mt-1">Depth {row.chain.depth} · root {row.chain.rootSpecimenUuid.slice(0, 8)} · representative {row.chain.representativeSpecimenUuid.slice(0, 8)}</p>{row.chain.parentSpecimenUuid ? <p>Parent {row.chain.parentSpecimenUuid.slice(0, 8)}</p> : null}{row.chain.childSpecimenUuids.length ? <p>Children {row.chain.childSpecimenUuids.map((uuid) => uuid.slice(0, 8)).join(', ')}</p> : null}</section></div>
        {row.downstreamImpact ? <div className="mt-3 flex items-start gap-2 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark"><GitBranch className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><p><strong>{row.downstreamImpact.blocked_object_type.replaceAll('_', ' ')}:</strong> {row.downstreamImpact.explanation}</p></div> : row.decisionRepresentedBySpecimenUuid ? <p className="mt-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Downstream decision is represented once on specimen {row.decisionRepresentedBySpecimenUuid.slice(0, 8)}.</p> : null}
      </article>)}{data.data.length === 0 ? <div className="rounded-lg border border-dashed border-healthcare-border p-8 text-center text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">No Laboratory specimens match the selected filters.</div> : null}</div>
      <nav aria-label="Specimen pagination" className="flex items-center justify-between"><span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.meta.count} rows · maximum {data.meta.perPage}</span><div className="flex gap-2">{data.meta.previousCursor ? <Link href={pageHref(data, data.meta.previousCursor)} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm dark:border-healthcare-border-dark"><ChevronLeft className="size-4" />Previous</Link> : null}{data.meta.nextCursor ? <Link href={pageHref(data, data.meta.nextCursor)} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm dark:border-healthcare-border-dark">Next<ChevronRight className="size-4" /></Link> : null}</div></nav>
    </div>
  </PageContentLayout></DashboardLayout>;
}
