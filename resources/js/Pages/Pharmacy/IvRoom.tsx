import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Beaker, CheckCircle2, Circle, Clock3, FlaskConical, HelpCircle, Layers, ShieldAlert, Trash2 } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { usePharmacyIvRoom } from '@/features/pharmacy/hooks';
import { pharmacyIvRoomSchema, type PharmacyIvRoom, type PharmacyIvRoomBatch, type PharmacyIvRoomPrep, type PharmacyIvRoomDegradedOrder } from '@/features/pharmacy/iv-room-schemas';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

// Server-provided BUD state only: no raw-minute comparison happens in the view.
const BUD_META = {
  none: { label: 'No BUD recorded', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  within_window: { label: 'Within BUD window', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  expiring: { label: 'BUD expiring', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  expired: { label: 'BUD expired', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
} as const;

function prepTypeHref(board: PharmacyIvRoom, prepType: string | null) {
  const query = new URLSearchParams();
  if (prepType) query.set('prepType', prepType);
  const suffix = query.toString();
  return suffix ? `/pharmacy/iv-room?${suffix}` : '/pharmacy/iv-room';
}

const clock = (value: string | null) => value === null ? '—' : new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const stamp = (value: string | null) => value === null ? '—' : new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

function BudBadge({ state, minutes }: { state: PharmacyIvRoomPrep['budState']; minutes: number | null }) {
  const meta = BUD_META[state];
  const remaining = minutes === null ? '' : minutes <= 0 ? ` · ${Math.abs(minutes)} min past` : ` · ${minutes} min left`;
  return <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{meta.label}<span className="tabular-nums">{remaining}</span></span>;
}

function PrepRow({ prep }: { prep: PharmacyIvRoomPrep }) {
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{prep.label}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{prep.patientRef} · {prep.locationLabel ?? 'Location unavailable'} · {prep.prepTypeLabel} · {prep.prepStateLabel}{prep.batchRef ? ` · batch ${prep.batchRef}` : ''}</p>
        <p className="mt-1"><BudBadge state={prep.budState} minutes={prep.budMinutesRemaining} /></p>
      </div>
      <div className="shrink-0 text-right">
        <p className="font-semibold tabular-nums">{prep.elapsedIsMeasured && prep.elapsedMinutes !== null ? `${prep.elapsedMinutes} min` : 'not measured'}</p>
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">elapsed</p>
      </div>
    </div>
    <ol className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" aria-label={`${prep.label} preparation stages`}>
      {prep.stages.map((stage) => <li key={stage.code} className="inline-flex items-center gap-1">
        {stage.state === 'complete'
          ? <CheckCircle2 className="size-3.5 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
          : <Circle className="size-3.5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />}
        <span>{stage.label}</span>
        <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{clock(stage.at)}</span>
      </li>)}
    </ol>
  </article>;
}

function BatchCard({ batch }: { batch: PharmacyIvRoomBatch }) {
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{batch.batched ? batch.batchRef : `Unbatched ${batch.prepTypeLabel.toLowerCase()}`}</h3>
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{batch.prepTypeLabel} · {batch.prepCount} preps · {batch.activeCount} active</p>
      </div>
      <span className="shrink-0 rounded-md bg-healthcare-background px-2 py-0.5 text-xs font-medium tabular-nums dark:bg-healthcare-background-dark">{batch.prepCount}</span>
    </div>
    <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Earliest start</dt><dd className="tabular-nums">{clock(batch.earliestStartedAt)}</dd></div>
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Latest compound</dt><dd className="tabular-nums">{clock(batch.latestCompletedAt)}</dd></div>
    </dl>
    <p className="mt-2"><BudBadge state={batch.budState} minutes={batch.budMinutesRemaining} /></p>
    {batch.budExpiresAt ? <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">BUD {stamp(batch.budExpiresAt)}{batch.budCrossesDayBoundary ? ' · next day' : ''}</p> : null}
  </article>;
}

function DegradedRow({ order }: { order: PharmacyIvRoomDegradedOrder }) {
  return <article className="rounded-md border border-healthcare-warning/40 p-3">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{order.label}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{order.patientRef} · {order.locationLabel ?? 'Location unavailable'} · {order.orderStatus}</p>
        <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-healthcare-warning dark:text-healthcare-warning-dark"><ShieldAlert className="size-3.5" aria-hidden="true" />Coarse verify → dispense only</p>
      </div>
      <div className="shrink-0 text-right">
        <p className="font-semibold tabular-nums">{order.coarseVerifyToDispenseMinutes === null ? 'unavailable' : `${order.coarseVerifyToDispenseMinutes} min`}</p>
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">verified {clock(order.verifiedAt)} · dispensed {clock(order.dispensedAt)}</p>
      </div>
    </div>
  </article>;
}

export default function IvRoom({ ivRoom }: { ivRoom: PharmacyIvRoom }) {
  const initial = pharmacyIvRoomSchema.parse(ivRoom);
  const query = usePharmacyIvRoom(initial);
  const board = query.data;
  const cards = [
    { label: 'Active preparations', value: board.data.summary.activePreps, Icon: Beaker },
    { label: 'Batches', value: board.data.summary.batches, Icon: Layers },
    { label: 'BUD expiring soon', value: board.data.summary.budExpiringSoon, Icon: Clock3 },
    { label: 'Degraded orders', value: board.data.summary.degradedOrders, Icon: ShieldAlert },
  ];

  return <DashboardLayout><Head title="IV Room and Batches" /><PageContentLayout title="IV Room and Batches" subtitle="Current and next batches, beyond-use windows, chemo and TPN preparation, active work, and waste measures from governed operational facts" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>

      <nav aria-label="Preparation type" className="flex flex-wrap gap-2">
        <Link href={prepTypeHref(board, null)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.prepType === null ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>All types</Link>
        {board.filterOptions.prepType.map((type) => <Link key={type} href={prepTypeHref(board, board.filters.prepType === type ? null : type)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.prepType === type ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>{type.replaceAll('_', ' ')}</Link>)}
      </nav>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>

      {/* Policy / configuration — deliberately distinct from measured timing. */}
      <section className="rounded-lg border border-dashed border-healthcare-info/50 bg-healthcare-info/5 p-4">
        <h2 className="inline-flex items-center gap-2 font-semibold"><ShieldAlert className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Policy and configuration</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Configured deadlines and windows — reference lines, not observed events.</p>
        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.tpnCutoff.label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{String(board.policy.tpnCutoff.localHour).padStart(2, '0')}:00 <span className="text-xs font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.tpnCutoff.timezone}</span></dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">Next enforceable cutoff {stamp(board.policy.tpnCutoff.nextCutoffAt)}</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.tpnCutoff.description}</dd>
          </div>
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.budWarningWindow.label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{board.policy.budWarningWindow.minutes} min</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.budWarningWindow.description}</dd>
          </div>
        </dl>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><h2 className="font-semibold">Current and next batches</h2><span className="text-sm tabular-nums">{board.data.batches.length} shown</span></div>
        <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
          {board.data.batches.map((batch) => <BatchCard key={batch.key} batch={batch} />)}
          {board.data.batches.length === 0 ? <p className="text-sm">No IV-room batches match the selected preparation type.</p> : null}
        </div>
      </section>

      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="inline-flex items-center gap-2 font-semibold"><FlaskConical className="size-4 text-healthcare-primary" aria-hidden="true" />Chemotherapy preparation timeline</h2>
          <div className="mt-3 space-y-2">
            {board.data.chemoTimeline.map((prep) => <PrepRow key={prep.prepUuid} prep={prep} />)}
            {board.data.chemoTimeline.length === 0 ? <p className="text-sm">No chemotherapy preparations are in the current window.</p> : null}
          </div>
        </section>
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="inline-flex items-center gap-2 font-semibold"><Beaker className="size-4 text-healthcare-primary" aria-hidden="true" />Active preparation work</h2>
          <div className="mt-3 space-y-2">
            {board.data.activeWork.map((prep) => <PrepRow key={prep.prepUuid} prep={prep} />)}
            {board.data.activeWork.length === 0 ? <p className="text-sm">No pending or in-progress preparations in the current window.</p> : null}
          </div>
        </section>
      </div>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="inline-flex items-center gap-2 font-semibold"><Trash2 className="size-4 text-healthcare-primary" aria-hidden="true" />Waste measures</h2>
        <div className="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-2">
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Waste events</p><p className="text-2xl font-semibold tabular-nums">{board.data.waste.wasteEvents}</p></div>
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Waste per 100 vends</p><p className="text-2xl font-semibold tabular-nums">{board.data.waste.wastePerHundredVends === null ? '—' : board.data.waste.wastePerHundredVends}</p></div>
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.waste.denominatorLabel}</p><p className="text-2xl font-semibold tabular-nums">{board.data.waste.denominatorCount}</p></div>
        </div>
        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">Window: last {board.data.waste.windowHours} h ({stamp(board.data.waste.windowStartAt)} → {stamp(board.data.waste.windowEndAt)})</p>
        <p className="mt-1 rounded-md border border-healthcare-border p-2 text-xs dark:border-healthcare-border-dark">{board.data.waste.basis}</p>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">IVWMS coverage</h2><span className={`inline-flex items-center gap-1 text-sm font-medium ${board.data.degradedOrders.coverage === 'partial' ? 'text-healthcare-warning dark:text-healthcare-warning-dark' : 'text-healthcare-success dark:text-healthcare-success-dark'}`}>{board.data.degradedOrders.coverage === 'partial' ? <AlertTriangle className="size-4" aria-hidden="true" /> : <CheckCircle2 className="size-4" aria-hidden="true" />}IV workflow {board.data.degradedOrders.coverage}</span></div>
        <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.degradedOrders.coverageStatement}</p>
        <div className="mt-3 space-y-2">
          {board.data.degradedOrders.orders.map((order) => <DegradedRow key={order.orderUuid} order={order} />)}
        </div>
      </section>
    </div>
  </PageContentLayout></DashboardLayout>;
}
