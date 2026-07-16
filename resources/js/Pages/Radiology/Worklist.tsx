import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight, Gauge, Search } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { AncillaryOrderTimeline, PageClockProvider, ReadinessVector, SourceFreshnessBadge } from '@/Components/Ancillary';
import { BreachRiskCell } from '@/Pages/Radiology/BreachRiskCell';
import { radiologyWorklistSchema, type RadiologyWorklist } from '@/features/radiology/schemas';
import { useRadiologyWorklist } from '@/features/radiology/hooks';

function filterQuery(worklist: RadiologyWorklist, overrides: Record<string, string | null> = {}) {
  const query = new URLSearchParams();
  Object.entries(worklist.filters).forEach(([key, value]) => {
    if (value === null || value === '' || value === false || key === 'cursor' || (key === 'lens' && value === 'all')) return;
    query.set(key, value === true ? 'on' : String(value));
  });
  Object.entries(overrides).forEach(([key, value]) => {
    if (value === null) query.delete(key);
    else query.set(key, value);
  });
  return query;
}

function pageHref(worklist: RadiologyWorklist, cursor: string | null) {
  const query = filterQuery(worklist);
  if (cursor) query.set('cursor', cursor);
  return `/radiology/worklist?${query.toString()}`;
}

export default function Worklist({ worklist }: { worklist: RadiologyWorklist }) {
  const initial = radiologyWorklistSchema.parse(worklist);
  const query = useRadiologyWorklist(initial);
  const data = query.data;

  return (
    <DashboardLayout>
      <Head title="Radiology Order Worklist" />
      <PageContentLayout title="Radiology Order Worklist" subtitle="Server-filtered operational orders with selected clocks, source assertions, downstream impact, and governed barriers" headerContent={<SourceFreshnessBadge value={data.freshness} />}>
        <PageClockProvider initialNow={new Date(data.generatedAt)}>
          <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex flex-wrap gap-2">{data.filterOptions.lenses.map((lens) => <Link key={lens} href={`/radiology/worklist${lens === 'all' ? '' : `?lens=${lens}`}`} className={`rounded-md border px-3 py-1.5 text-sm capitalize ${data.filters.lens === lens ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark'}`}>{lens}</Link>)}</div>
              <form method="get" action="/radiology/worklist" className="flex items-center gap-2"><label className="sr-only" htmlFor="radiology-search">Search operational identifier</label><div className="relative"><Search className="pointer-events-none absolute left-2 top-2 size-4 text-healthcare-text-secondary" aria-hidden="true" /><input id="radiology-search" name="search" defaultValue={data.filters.search ?? ''} minLength={3} maxLength={64} pattern="[A-Za-z0-9_.:-]+" placeholder="Order or patient reference" className="rounded-md border-healthcare-border bg-healthcare-background py-1.5 pl-8 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-background-dark" /></div><button type="submit" className="rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-medium text-white">Search</button></form>
            </div>

            {data.filters.source ? <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Filtered deep link from <span className="font-medium">{data.filters.source.replaceAll('_', ' ')}</span>.</p> : null}
            {data.filters.sort === 'breach_risk' ? <div className="flex items-center gap-2 rounded-md border border-healthcare-info/40 bg-healthcare-info/10 p-3 text-sm text-healthcare-info dark:text-healthcare-info-dark"><AlertTriangle className="size-4" aria-hidden="true" />{data.predictiveSort.explanation}</div> : null}

            {data.predictiveSort.available ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <div className="flex items-start gap-2">
                  <Gauge className="mt-0.5 size-4 text-healthcare-text-secondary" aria-hidden="true" />
                  <div className="text-sm">
                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Optional planning risk (synthetic)</p>
                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.predictiveSort.explanation}</p>
                    {data.predictiveSort.enabled && data.predictiveSort.model ? (
                      <p className="mt-1 text-xs text-healthcare-text-secondary tabular-nums dark:text-healthcare-text-secondary-dark">
                        Model {data.predictiveSort.model.modelVersion} · calibrated {data.predictiveSort.model.calibratedAt ? new Date(data.predictiveSort.model.calibratedAt).toLocaleDateString() : 'unknown'} · AUC {data.predictiveSort.model.evaluation.discriminationAuc ?? '—'} · {data.predictiveSort.model.syntheticLabel}
                      </p>
                    ) : null}
                  </div>
                </div>
                <Link
                  href={`/radiology/worklist?${filterQuery(data, { risk: data.filters.risk ? null : 'on', sort: data.filters.risk ? null : 'breach_risk', cursor: null }).toString()}`}
                  aria-pressed={data.filters.risk}
                  className={`rounded-md border px-3 py-1.5 text-sm font-medium ${data.filters.risk ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark'}`}
                >
                  {data.filters.risk ? 'Hide planning risk' : 'Show planning risk'}
                </Link>
              </div>
            ) : null}

            <div className="space-y-3">{data.data.map((row) => <article key={row.orderUuid} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.label}</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.patientRef} · {row.patientClass} · {row.priority} · {row.locationLabel ?? 'Location unavailable'}</p><div className="mt-2 flex flex-wrap gap-2 text-xs">{row.downstreamImpact.edDecision ? <span className="rounded-md border border-healthcare-info/40 px-2 py-1 text-healthcare-info dark:text-healthcare-info-dark">ED decision</span> : null}{row.downstreamImpact.dischargeBlocking ? <span className="rounded-md border border-healthcare-warning/40 px-2 py-1 text-healthcare-warning dark:text-healthcare-warning-dark">Discharge blocking</span> : null}{row.downstreamImpact.orCaseId ? <span className="rounded-md border border-healthcare-border px-2 py-1 dark:border-healthcare-border-dark">OR case {row.downstreamImpact.orCaseId}</span> : null}{row.barriers.map((barrier) => <span key={barrier.barrierId} className="rounded-md border border-healthcare-warning/40 px-2 py-1 text-healthcare-warning dark:text-healthcare-warning-dark">{barrier.label}</span>)}</div></div><div className="flex flex-col items-end gap-2 text-right"><div><p className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.ageMinutes} min</p><p className="text-xs capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.status}</p></div>{row.risk ? <BreachRiskCell risk={row.risk} /> : null}</div></div><details className="mt-3"><summary className="cursor-pointer text-sm font-medium text-healthcare-primary focus:outline-none focus:ring-2 focus:ring-healthcare-info">Expand milestone and source detail</summary><div className="mt-3 space-y-3">{row.readiness.length > 0 ? <ReadinessVector axes={row.readiness} variant="compact" /> : null}<AncillaryOrderTimeline value={row.timeline} /><details className="text-sm"><summary className="cursor-pointer text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">View {row.sourceAssertions.length} retained source assertions</summary><div className="mt-2 overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-healthcare-border dark:border-healthcare-border-dark"><th className="p-2 text-left">Milestone</th><th className="p-2 text-left">Source</th><th className="p-2 text-left">Occurred</th><th className="p-2 text-left">Selection</th></tr></thead><tbody>{row.sourceAssertions.map((assertion) => <tr key={assertion.milestoneUuid} className="border-b border-healthcare-border/60 dark:border-healthcare-border-dark/60"><td className="p-2">{assertion.code}</td><td className="p-2">{assertion.sourceKey}</td><td className="p-2 tabular-nums">{new Date(assertion.occurredAt).toLocaleString()}</td><td className="p-2">{assertion.selected ? 'Selected' : 'Retained'}</td></tr>)}</tbody></table></div></details></div></details></article>)}{data.data.length === 0 ? <div className="rounded-lg border border-dashed border-healthcare-border p-8 text-center text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">No Radiology orders match the allowlisted filters.</div> : null}</div>

            <nav aria-label="Worklist pagination" className="flex items-center justify-between"><span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.meta.count} rows on this page · maximum {data.meta.perPage}</span><div className="flex gap-2">{data.meta.previousCursor ? <Link href={pageHref(data, data.meta.previousCursor)} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm dark:border-healthcare-border-dark"><ChevronLeft className="size-4" />Previous</Link> : null}{data.meta.nextCursor ? <Link href={pageHref(data, data.meta.nextCursor)} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-1.5 text-sm dark:border-healthcare-border-dark">Next<ChevronRight className="size-4" /></Link> : null}</div></nav>
          </div>
        </PageClockProvider>
      </PageContentLayout>
    </DashboardLayout>
  );
}
