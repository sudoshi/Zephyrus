import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Droplets, ShieldAlert, Siren } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { useBloodBankReadiness } from '@/features/lab/hooks';
import { bloodBankReadinessSchema, type BloodBankReadiness } from '@/features/lab/schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
  source_error: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
} as const;

const GATE_STYLE = {
  blocked: 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark',
  mtp_active: 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark',
  ready: 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark',
  not_applicable: 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark',
  unknown: 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark',
} as const;

const words = (value: string) => value.replaceAll('_', ' ');
const startLabel = (minutes: number) => minutes >= 0 ? `${minutes} min to start` : `${Math.abs(minutes)} min past planned start`;
const time = (value: string | null) => value ? new Date(value).toLocaleString() : 'Not asserted';

export default function BloodBank({ bloodBank }: { bloodBank: BloodBankReadiness }) {
  const initial = bloodBankReadinessSchema.parse(bloodBank);
  const query = useBloodBankReadiness(initial);
  const data = query.data;
  const cards = [
    { label: 'Cases shown', value: data.summary.cases, Icon: Droplets },
    { label: 'Required', value: data.summary.required, Icon: ShieldAlert },
    { label: 'Ready', value: data.summary.ready, Icon: CheckCircle2 },
    { label: 'Active MTP', value: data.summary.mtpActive, Icon: Siren },
  ];

  return <DashboardLayout><Head title="Blood Bank Readiness - Laboratory" /><PageContentLayout title="Blood Bank Readiness" subtitle="Perioperative product requirements, compatibility readiness, issue state, and operational MTP activity without allocation controls" headerContent={<SourceFreshnessBadge value={data.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[data.state]}`}><span>{data.stateMessage}</span><span>{data.operatingDate ? `Operating day ${data.operatingDate}` : 'Operating day unavailable'}</span></div>
      <form method="get" action="/lab/blood-bank" aria-label="Blood Bank filters" className="grid gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 sm:grid-cols-2 xl:grid-cols-5 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <label className="text-sm">Gate state<select name="state" defaultValue={data.filters.state} className="mt-1 block w-full rounded-md">{data.filterOptions.states.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Product class<select name="productClass" defaultValue={data.filters.productClass} className="mt-1 block w-full rounded-md">{data.filterOptions.productClasses.map((value) => <option key={value} value={value}>{words(value)}</option>)}</select></label>
        <label className="text-sm">Service<select name="service" defaultValue={data.filters.service ?? ''} className="mt-1 block w-full rounded-md"><option value="">All services</option>{data.filterOptions.services.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <label className="text-sm">Room<select name="room" defaultValue={data.filters.room ?? ''} className="mt-1 block w-full rounded-md"><option value="">All rooms</option>{data.filterOptions.rooms.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <div className="flex items-end"><button type="submit" className="w-full rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white">Apply filters</button></div>
      </form>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>
      <div className="space-y-3">{data.data.map((gate) => <article key={gate.caseId} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold">{gate.caseLabel}</h2><span className={`rounded-md border px-2 py-0.5 text-xs ${GATE_STYLE[gate.state]}`}>{words(gate.state)}</span>{gate.mtpActive ? <span className="rounded-md bg-healthcare-critical px-2 py-0.5 text-xs font-semibold text-white">MTP operational state</span> : null}</div><p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{gate.roomLabel} · {gate.serviceLabel} · {gate.locationLabel}</p></div><div className="text-right"><p className="font-semibold tabular-nums">{startLabel(gate.minutesToStart)}</p><p className="text-xs">{new Date(gate.scheduledStartAt).toLocaleString()}</p></div></div>
        {gate.mtpActive ? <div role="alert" className="mt-3 flex items-start gap-2 rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /><p>{gate.explanation} This is a read-only operational signal, not an activation, allocation, or closure command.</p></div> : <p className="mt-3 text-sm">{gate.explanation}</p>}
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Requested products</h3><p className="mt-1">{gate.required ? `${gate.units.requested} unit(s) · ${gate.productClasses.map(words).join(', ')}` : 'No active requirement'}</p></section><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Type & screen</h3><p className="mt-1">{words(gate.typeScreenState)}</p></section><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Crossmatch</h3><p className="mt-1">{words(gate.crossmatchState)}</p></section><section className="rounded-md bg-healthcare-background p-3 text-sm dark:bg-healthcare-background-dark"><h3 className="font-medium">Allocation / issue</h3><p className="mt-1">{gate.units.allocated}/{gate.units.requested} allocated · {gate.units.issued} issued · {words(gate.issueState)}</p></section></div>
        {gate.requests.length > 0 ? <details className="mt-3 rounded-md border border-healthcare-border p-3 text-sm dark:border-healthcare-border-dark"><summary className="cursor-pointer font-medium">Request evidence ({gate.requests.length})</summary><div className="mt-3 space-y-2">{gate.requests.map((request) => <section key={request.readinessUuid} className="rounded-md bg-healthcare-background p-3 dark:bg-healthcare-background-dark"><div className="flex flex-wrap justify-between gap-2"><strong>{words(request.productClass)} · {words(request.readinessState)}</strong><span>{request.unitsAllocated}/{request.unitsRequested} allocated · {request.unitsIssued} issued</span></div><p className="mt-1 text-xs">Ordered {time(request.orderedAt)} · needed by {time(request.neededByAt)} · T&amp;S {words(request.typeScreenState)} · crossmatch {words(request.crossmatchState)}</p></section>)}</div></details> : null}
        {gate.coverage.status === 'degraded' ? <p className="mt-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">{gate.coverage.explanation}</p> : null}
      </article>)}{data.data.length === 0 ? <div className="rounded-lg border border-dashed border-healthcare-border p-8 text-center text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">No Blood Bank case gates match the selected filters.</div> : null}</div>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.privacy.explanation}</p>
    </div>
  </PageContentLayout></DashboardLayout>;
}
