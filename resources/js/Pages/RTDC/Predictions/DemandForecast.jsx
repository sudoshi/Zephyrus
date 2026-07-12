import React, { useMemo, useState } from 'react';
import { Icon } from '@iconify/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

/**
 * RTDC › Predictions › Demand Forecast
 *
 * Live predicted demand vs. available capacity for the upcoming service date,
 * computed by App\Services\Rtdc\DemandForecastService from prod.rtdc_predictions
 * (+ latest census). Two seeded planning horizons drive the view: "By 2 PM"
 * (intraday) and "By Midnight" (end of day). Rebuilt on the gold-standard design
 * system: the horizon roll-up is a MetricGrid of KpiTiles, and the chart / unit
 * table / demand-source list live in Panels under Section headers. Status is
 * always paired with an icon + label (never colour alone) per the design
 * non-negotiables.
 */

const STATUS_ICON = {
    critical: 'heroicons:exclamation-triangle',
    warning: 'heroicons:exclamation-circle',
    success: 'heroicons:check-circle',
    info: 'heroicons:information-circle',
    neutral: 'heroicons:minus-circle',
};

const STATUS_LABEL = {
    critical: 'Short',
    warning: 'Tight',
    success: 'Adequate',
    info: 'Stable',
    neutral: 'Stable',
};

function StatusPill({ status }) {
    const color = STATUS_VAR[status] ?? STATUS_VAR.info;
    return (
        <span className="inline-flex items-center gap-1 text-xs font-medium" style={{ color }}>
            <Icon icon={STATUS_ICON[status] ?? STATUS_ICON.info} className="h-4 w-4" aria-hidden="true" />
            {STATUS_LABEL[status] ?? STATUS_LABEL.info}
        </span>
    );
}

const formatDate = (iso) => {
    if (!iso) return '—';
    const parsed = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return iso;
    return parsed.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
};

export default function DemandForecast({
    serviceDate = null,
    horizons = [],
    kpis = {},
    units = [],
    capacityChart = { labels: [], demand: [], capacity: [], deficit: [] },
    demandBySource = [],
}) {
    const hasData = (horizons?.length ?? 0) > 0 && (units?.length ?? 0) > 0;

    // The default (server-headlined) horizon is "by_2pm"; the toggle lets ops
    // leaders flip to the end-of-day projection. Per-unit rows + chart are
    // derived client-side from the full unit set the server already shipped for
    // the headline horizon; the horizon roll-ups always come from the server.
    const [activeHorizon, setActiveHorizon] = useState(
        () => horizons.find((h) => h.horizon === 'by_2pm')?.horizon ?? horizons[0]?.horizon ?? 'by_2pm'
    );

    const activeSummary = useMemo(
        () => horizons.find((h) => h.horizon === activeHorizon) ?? horizons[0] ?? null,
        [horizons, activeHorizon]
    );

    // Chart.js grouped-bar series: predicted demand vs. available capacity per unit.
    const chartData = useMemo(
        () => ({
            labels: capacityChart.labels ?? [],
            datasets: [
                {
                    label: 'Predicted demand',
                    data: capacityChart.demand ?? [],
                    backgroundColor: 'rgb(var(--color-healthcare-warning))',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.6,
                },
                {
                    label: 'Available capacity',
                    data: capacityChart.capacity ?? [],
                    backgroundColor: 'rgb(var(--color-healthcare-info))',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.6,
                },
            ],
        }),
        [capacityChart]
    );

    const chartOptions = useMemo(
        () => ({
            plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } },
            scales: { x: { stacked: false }, y: { stacked: false } },
        }),
        []
    );

    const demandTotal = useMemo(
        () => demandBySource.reduce((acc, d) => acc + (d.value ?? 0), 0),
        [demandBySource]
    );

    // Gold-standard KPI wall, driven by the active horizon roll-up. Net bed need
    // drives status; no sparklines (no honest per-metric series is shipped).
    const netBedNeed = activeSummary?.netBedNeed ?? 0;
    const kpiMetrics = useMemo(
        () => [
            metric({
                key: 'predicted-census',
                label: 'Predicted census',
                value: activeSummary?.predictedCensus ?? 0,
                status: 'info',
                caption: `${activeSummary?.label ?? ''} · occupied + admits − discharges`,
                definition: 'Projected occupied beds at the end of the selected horizon (current census + expected admits − expected discharges).',
            }),
            metric({
                key: 'predicted-demand',
                label: 'Predicted demand',
                value: activeSummary?.demand ?? 0,
                status: 'warning',
                caption: 'Expected admissions into inpatient units',
                definition: 'Expected inpatient admissions arriving within the selected horizon.',
            }),
            metric({
                key: 'available-capacity',
                label: 'Available capacity',
                value: activeSummary?.capacity ?? 0,
                status: 'info',
                caption: 'Open beds across non-ED units',
                definition: 'Open, staffable beds across all non-ED inpatient units right now.',
            }),
            metric({
                key: 'net-bed-need',
                label: 'Net bed need',
                value: netBedNeed,
                display: netBedNeed > 0 ? `+${netBedNeed.toLocaleString('en-US')}` : netBedNeed.toLocaleString('en-US'),
                status: netBedNeed > 0 ? 'critical' : 'success',
                goodWhenDown: true,
                caption: `${activeSummary?.unitsShort ?? 0} of ${units.length} units short · ${activeSummary?.deficit ?? 0} short / ${activeSummary?.surplus ?? 0} spare`,
                definition: 'Predicted demand minus available capacity. Positive means more admissions than open beds. The short/spare split shows where the net hides offsetting units.',
            }),
        ],
        [activeSummary, netBedNeed, units.length]
    );

    if (!hasData) {
        return (
            <RTDCPageLayout
                title="Demand Forecast"
                subtitle="Predicted demand vs. available capacity"
            >
                <Panel className="p-4">
                    <EmptyState
                        icon="heroicons:presentation-chart-line"
                        message="No demand forecast has been published yet. Predictions appear here once the bed-meeting horizons are seeded for an upcoming service date."
                    />
                </Panel>
            </RTDCPageLayout>
        );
    }

    const horizonToggle = (
        <div
            className="inline-flex rounded-lg border border-healthcare-border dark:border-healthcare-border-dark p-0.5"
            role="tablist"
            aria-label="Forecast horizon"
        >
            {horizons.map((h) => {
                const active = h.horizon === activeHorizon;
                return (
                    <button
                        key={h.horizon}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => setActiveHorizon(h.horizon)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-200 ${
                            active
                                ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                                : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
                        }`}
                    >
                        {h.label}
                    </button>
                );
            })}
        </div>
    );

    return (
        <RTDCPageLayout
            title="Demand Forecast"
            subtitle={`Predicted demand vs. available capacity — forecast for ${formatDate(serviceDate)}`}
        >
            <div className="flex flex-col gap-5">
                {/* KPI wall — driven by the active horizon roll-up */}
                <Section
                    title="Horizon roll-up"
                    icon="heroicons:chart-bar-square"
                    summary={`${activeSummary?.label ?? 'Planning horizon'} · ${formatDate(serviceDate)}`}
                    actions={horizonToggle}
                >
                    <MetricGrid metrics={kpiMetrics} />
                </Section>

                {/* Predicted demand vs. capacity by unit (headline horizon) */}
                <Section
                    title="Predicted demand vs. capacity by unit"
                    icon="heroicons:chart-bar"
                    summary={`${kpis?.horizonLabel ?? 'By 2 PM'} horizon · ${formatDate(serviceDate)}`}
                >
                    <Panel className="p-4">
                        {(capacityChart.labels?.length ?? 0) > 0 ? (
                            <div className="h-[360px]">
                                <BarChart data={chartData} options={chartOptions} />
                            </div>
                        ) : (
                            <EmptyState icon="heroicons:chart-bar" message="No per-unit forecast available for this date." />
                        )}
                    </Panel>
                </Section>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Per-unit forecast table */}
                    <Section
                        className="lg:col-span-2"
                        title="Unit forecast detail"
                        icon="heroicons:table-cells"
                        summary={`Most constrained first · ${kpis?.horizonLabel ?? 'By 2 PM'} horizon`}
                    >
                        <Panel className="p-4">
                            {units.length > 0 ? (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            <th className="px-2 py-2">Unit</th>
                                            <th className="px-2 py-2 text-right">Demand</th>
                                            <th className="px-2 py-2 text-right">Capacity</th>
                                            <th className="px-2 py-2 text-right">Exp. D/C</th>
                                            <th className="px-2 py-2 text-right">Occ. now</th>
                                            <th className="px-2 py-2 text-right">Pred. census</th>
                                            <th className="px-2 py-2 text-right">Bed need</th>
                                            <th className="px-2 py-2 text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {units.map((u) => (
                                            <tr
                                                key={u.unitId}
                                                className="border-t border-healthcare-border dark:border-healthcare-border-dark"
                                            >
                                                <td className="px-2 py-2">
                                                    <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {u.name}
                                                    </div>
                                                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {u.type}
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {u.demand}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {u.capacity}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    <div>{u.expectedDischarges}</div>
                                                    {(u.dischargesDefinite ?? 0) + (u.dischargesProbable ?? 0) + (u.dischargesPossible ?? 0) > 0 && (
                                                        <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                            {u.dischargesDefinite ?? 0} def · {u.dischargesProbable ?? 0} prob · {u.dischargesPossible ?? 0} poss
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {u.occupiedNow}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {u.predictedCensus}
                                                </td>
                                                <td
                                                    className="px-2 py-2 text-right font-medium tabular-nums"
                                                    style={{ color: STATUS_VAR[u.bedNeed > 0 ? 'critical' : 'success'] }}
                                                >
                                                    {u.bedNeed > 0 ? `+${u.bedNeed}` : u.bedNeed}
                                                </td>
                                                <td className="px-2 py-2 text-right">
                                                    <StatusPill status={u.status} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <EmptyState icon="heroicons:table-cells" message="No unit-level forecast rows for this date." />
                            )}
                        </Panel>
                    </Section>

                    {/* Demand mix by source */}
                    <Section
                        title="Demand by source"
                        icon="heroicons:funnel"
                        summary={`${kpis?.horizonLabel ?? 'By 2 PM'} horizon`}
                    >
                        <Panel className="p-4">
                            {demandTotal > 0 ? (
                                <ul className="space-y-3">
                                    {demandBySource.map((d) => {
                                        const pct = demandTotal > 0 ? Math.round((d.value / demandTotal) * 100) : 0;
                                        return (
                                            <li key={d.source}>
                                                <div className="flex items-center justify-between text-sm">
                                                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {d.source}
                                                    </span>
                                                    <span className="font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {d.value}
                                                        <span className="ml-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            ({pct}%)
                                                        </span>
                                                    </span>
                                                </div>
                                                <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                                                    <div
                                                        className="h-full rounded-full bg-healthcare-info dark:bg-healthcare-info-dark"
                                                        style={{ width: `${pct}%` }}
                                                    />
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <EmptyState icon="heroicons:funnel" message="No demand-source breakdown for this date." />
                            )}
                        </Panel>
                    </Section>
                </div>
            </div>
        </RTDCPageLayout>
    );
}
