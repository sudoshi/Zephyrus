import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowDownCircle, CheckCircle2, CircleDashed, Gauge, HelpCircle, PackageX, RefreshCw, ShieldAlert, Truck } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { SourceFreshnessBadge } from '@/Components/Ancillary';
import { usePharmacyDispense } from '@/features/pharmacy/hooks';
import { pharmacyDispenseSchema, type PharmacyDispense, type PharmacyDispenseRateStatus, type PharmacyDispenseStation } from '@/features/pharmacy/dispense-schemas';
import { StockoutForecastPanel } from './ForecastPanels';

const STATE_STYLE = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  stale: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
} as const;

// Server-provided rate status only: no raw-rate comparison happens in the view.
const RATE_META = {
  no_data: { label: 'No data', Icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  within_target: { label: 'Within target', Icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  near_target: { label: 'Near target', Icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  over_target: { label: 'Over target', Icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
} as const;

function stationTypeHref(stationType: string | null, forecast: boolean) {
  const query = new URLSearchParams();
  if (stationType) query.set('stationType', stationType);
  if (forecast) query.set('forecast', '1');
  const suffix = query.toString();
  return suffix ? `/pharmacy/dispense?${suffix}` : '/pharmacy/dispense';
}

function forecastHref(board: PharmacyDispense) {
  return stationTypeHref(board.filters.stationType, !board.filters.forecast);
}

const stamp = (value: string | null) => value === null ? '—' : new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
const pct = (value: number | null) => value === null ? '—' : `${value}%`;

function RateBadge({ status, kind }: { status: PharmacyDispenseRateStatus; kind: string }) {
  const meta = RATE_META[status];
  return <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.className}`}><meta.Icon className="size-3.5" aria-hidden="true" />{kind} {meta.label}</span>;
}

function StationCard({ station }: { station: PharmacyDispenseStation }) {
  return <article className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
    <div className="flex flex-wrap items-start justify-between gap-2">
      <div className="min-w-0">
        <h3 className="font-medium">{station.label}</h3>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{station.unitName ?? 'Unit unavailable'} · {station.stationType}</p>
      </div>
      {station.hasActiveStockout
        ? <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-healthcare-critical/40 px-2 py-0.5 text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark"><PackageX className="size-3.5" aria-hidden="true" />Active stockout</span>
        : null}
    </div>
    <dl className="mt-2 grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Vends</dt><dd className="text-base font-semibold tabular-nums">{station.vends}</dd></div>
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Overrides</dt><dd className="text-base font-semibold tabular-nums">{station.overrides}</dd></div>
      <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Stockouts</dt><dd className="text-base font-semibold tabular-nums">{station.stockouts}</dd></div>
    </dl>
    <div className="mt-2 grid grid-cols-2 gap-2">
      <div className="rounded-md border border-healthcare-border p-2 dark:border-healthcare-border-dark">
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Override rate</p>
        {/* A station with no denominator shows no data, never a fabricated 0%. */}
        <p className="text-lg font-semibold tabular-nums">{station.hasDenominator ? pct(station.overrideRatePercent) : 'no data'}</p>
        <RateBadge status={station.overrideStatus} kind="Override" />
      </div>
      <div className="rounded-md border border-healthcare-border p-2 dark:border-healthcare-border-dark">
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Stockout rate</p>
        <p className="text-lg font-semibold tabular-nums">{station.hasDenominator ? pct(station.stockoutRatePercent) : 'no data'}</p>
        <RateBadge status={station.stockoutStatus} kind="Stockout" />
      </div>
    </div>
    {!station.hasDenominator
      ? <p className="mt-2 inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><CircleDashed className="size-3.5" aria-hidden="true" />No vends in the window — no rate denominator.</p>
      : null}
  </article>;
}

export default function Dispense({ dispense }: { dispense: PharmacyDispense }) {
  const initial = pharmacyDispenseSchema.parse(dispense);
  const query = usePharmacyDispense(initial);
  const board = query.data;
  const { summary } = board.data;
  const cards = [
    { label: 'Vends (window)', value: summary.totalVends, Icon: ArrowDownCircle },
    { label: 'Override rate', value: pct(summary.overrideRatePercent), Icon: Gauge },
    { label: 'Stations over target', value: summary.stationsOverOverrideTarget, Icon: AlertTriangle },
    { label: 'Active stockouts', value: summary.stationsWithActiveStockout, Icon: PackageX },
  ];

  return <DashboardLayout><Head title="Dispense and Delivery" /><PageContentLayout title="Dispense and Delivery" subtitle="Station and unit override and stockout rates over a declared vend denominator, shortage context, vend-to-refill intervals, missing-dose chains, and delivery segments — station and unit aggregates only" headerContent={<SourceFreshnessBadge value={board.freshness} />}>
    <div className="space-y-4">
      <div role="status" className={`flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm ${STATE_STYLE[board.state]}`}><span>{board.stateMessage}</span><span className="tabular-nums">Window: last {board.window.hours} h · generated {new Date(board.generatedAt).toLocaleTimeString()}</span></div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{cards.map(({ label, value, Icon }) => <section key={label} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"><div className="flex items-center justify-between"><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</p><Icon className="size-4 text-healthcare-primary" aria-hidden="true" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p></section>)}</div>

      {/* Local policy — deliberately distinct from the measured observed rates. */}
      <section className="rounded-lg border border-dashed border-healthcare-info/50 bg-healthcare-info/5 p-4">
        <h2 className="inline-flex items-center gap-2 font-semibold"><ShieldAlert className="size-4 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />Local policy targets</h2>
        <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Configured reference lines — not observed events. The observed rates above are measured; these are policy.</p>
        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.overrideTargetRate.label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{board.policy.overrideTargetRate.ratePercent}%</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.overrideTargetRate.denominatorLabel}</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.overrideTargetRate.description}</dd>
          </div>
          <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <dt className="text-sm font-medium">{board.policy.stockoutTargetRate.label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{board.policy.stockoutTargetRate.ratePercent}%</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.stockoutTargetRate.denominatorLabel}</dd>
            <dd className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.policy.stockoutTargetRate.description}</dd>
          </div>
        </dl>
      </section>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <nav aria-label="Station type" className="flex flex-wrap gap-2">
          <Link href={stationTypeHref(null, board.filters.forecast)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.stationType === null ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>All stations</Link>
          {board.filterOptions.stationType.map((type) => <Link key={type} href={stationTypeHref(board.filters.stationType === type ? null : type, board.filters.forecast)} preserveState className={`rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.stationType === type ? 'border-healthcare-primary bg-healthcare-primary text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>{type.replaceAll('_', ' ')}</Link>)}
        </nav>
        <Link href={forecastHref(board)} preserveState aria-pressed={board.filters.forecast} className={`rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-healthcare-info ${board.filters.forecast ? 'border-healthcare-info bg-healthcare-info text-white' : 'border-healthcare-border dark:border-healthcare-border-dark'}`}>{board.filters.forecast ? 'Hide planning forecast' : 'Show planning forecast'}</Link>
      </div>

      {board.planningForecast.requested && board.planningForecast.stockout ? <StockoutForecastPanel forecast={board.planningForecast.stockout} /> : null}

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-center justify-between"><h2 className="font-semibold">Station rollup</h2><span className="text-sm tabular-nums">{board.data.stations.length} stations</span></div>
        <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
          {board.data.stations.map((station) => <StationCard key={station.stationId} station={station} />)}
          {board.data.stations.length === 0 ? <p className="text-sm">No stations reported transactions in the current window.</p> : null}
        </div>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="font-semibold">Unit rollup</h2>
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <tr><th className="py-1 pr-3 font-medium">Unit</th><th className="py-1 pr-3 font-medium tabular-nums">Vends</th><th className="py-1 pr-3 font-medium">Override rate</th><th className="py-1 pr-3 font-medium">Stockout rate</th></tr>
            </thead>
            <tbody>
              {board.data.units.map((unit) => <tr key={unit.unitId} className="border-t border-healthcare-border dark:border-healthcare-border-dark">
                <td className="py-1.5 pr-3">{unit.unitName}</td>
                <td className="py-1.5 pr-3 tabular-nums">{unit.vends}</td>
                <td className="py-1.5 pr-3"><span className="tabular-nums">{unit.hasDenominator ? pct(unit.overrideRatePercent) : 'no data'}</span> <RateBadge status={unit.overrideStatus} kind="" /></td>
                <td className="py-1.5 pr-3"><span className="tabular-nums">{unit.hasDenominator ? pct(unit.stockoutRatePercent) : 'no data'}</span> <RateBadge status={unit.stockoutStatus} kind="" /></td>
              </tr>)}
              {board.data.units.length === 0 ? <tr><td colSpan={4} className="py-2 text-sm">No unit-level transactions in the current window.</td></tr> : null}
            </tbody>
          </table>
        </div>
      </section>

      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="inline-flex items-center gap-2 font-semibold"><PackageX className="size-4 text-healthcare-primary" aria-hidden="true" />Shortage context</h2>
          <p className="mt-1 text-sm tabular-nums">{board.data.shortages.count} order{board.data.shortages.count === 1 ? '' : 's'} on shortage</p>
          <div className="mt-3 space-y-2">
            {board.data.shortages.orders.map((order, index) => <article key={order.orderUuid ?? `${order.medicationLabel}-${index}`} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
              <h3 className="font-medium">{order.medicationLabel}</h3>
              <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{order.patientRef} · {order.locationLabel ?? 'Location unavailable'} · {order.orderStatus}</p>
              <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">{order.reasonCode ?? 'Reason unrecorded'}{order.stationKey ? ` · ${order.stationKey}` : ''}{order.notedAt ? ` · noted ${stamp(order.notedAt)}` : ''}</p>
            </article>)}
            {board.data.shortages.orders.length === 0 ? <p className="text-sm">No orders are flagged on shortage in the current window.</p> : null}
          </div>
        </section>

        <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="inline-flex items-center gap-2 font-semibold"><RefreshCw className="size-4 text-healthcare-primary" aria-hidden="true" />Vend-to-refill</h2>
          <p className="mt-1 text-sm tabular-nums">{board.data.vendToRefill.measurableStations} measurable station{board.data.vendToRefill.measurableStations === 1 ? '' : 's'}</p>
          <div className="mt-3 space-y-2">
            {board.data.vendToRefill.stations.map((station) => <div key={station.stationId} className="flex items-center justify-between rounded-md border border-healthcare-border p-2 text-sm dark:border-healthcare-border-dark">
              <span>{station.label}</span>
              <span className="tabular-nums">{station.medianMinutes === null ? '—' : `${station.medianMinutes} min median`} · {station.pairCount} pair{station.pairCount === 1 ? '' : 's'}</span>
            </div>)}
            {board.data.vendToRefill.stations.length === 0 ? <p className="text-sm">No refill followed a vend in the window; no interval is measurable.</p> : null}
          </div>
          <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.vendToRefill.basis}</p>
        </section>
      </div>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h2 className="inline-flex items-center gap-2 font-semibold"><AlertTriangle className="size-4 text-healthcare-primary" aria-hidden="true" />Missing-dose chains</h2>
        <p className="mt-1 text-sm tabular-nums">{board.data.missingDose.chainCount} chain{board.data.missingDose.chainCount === 1 ? '' : 's'}</p>
        <div className="mt-3 space-y-2">
          {board.data.missingDose.chains.map((chain, index) => <article key={chain.orderUuid ?? `${chain.medicationLabel}-${index}`} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
            <h3 className="font-medium">{chain.medicationLabel}</h3>
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">{chain.patientRef} · missing dose {stamp(chain.missingDoseAt)}{chain.reDispenseChannel ? ` · re-dispensed via ${chain.reDispenseChannel}` : ''}</p>
          </article>)}
          {board.data.missingDose.chains.length === 0 ? <p className="text-sm">No missing-dose / re-request chains in the current window.</p> : null}
        </div>
        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{board.data.missingDose.basis}</p>
      </section>

      <section className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="inline-flex items-center gap-2 font-semibold"><Truck className="size-4 text-healthcare-primary" aria-hidden="true" />Delivery segments</h2>
          <span className={`inline-flex items-center gap-1 text-sm font-medium ${board.data.delivery.coverage === 'absent' ? 'text-healthcare-warning dark:text-healthcare-warning-dark' : 'text-healthcare-success dark:text-healthcare-success-dark'}`}>{board.data.delivery.coverage === 'absent' ? <AlertTriangle className="size-4" aria-hidden="true" /> : <CheckCircle2 className="size-4" aria-hidden="true" />}Delivery tracking {board.data.delivery.coverage}</span>
        </div>
        <div className="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-2">
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Dispenses</p><p className="text-2xl font-semibold tabular-nums">{board.data.delivery.dispenses}</p></div>
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Delivered</p><p className="text-2xl font-semibold tabular-nums">{board.data.delivery.delivered}</p></div>
          <div><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Median dispense→delivery</p><p className="text-2xl font-semibold tabular-nums">{board.data.delivery.medianMinutes === null ? 'not tracked' : `${board.data.delivery.medianMinutes} min`}</p></div>
        </div>
        <p className="mt-2 rounded-md border border-healthcare-border p-2 text-xs dark:border-healthcare-border-dark">{board.data.delivery.coverageStatement}</p>
      </section>
    </div>
  </PageContentLayout></DashboardLayout>;
}
