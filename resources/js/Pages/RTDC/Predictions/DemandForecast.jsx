import React, { useMemo, useState } from 'react';
import { Icon } from '@iconify/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

/**
 * RTDC › Predictions › Demand Forecast
 *
 * Live predicted demand vs. available capacity for the upcoming service date,
 * computed by App\Services\Rtdc\DemandForecastService from prod.rtdc_predictions
 * (+ latest census). Two seeded planning horizons drive the view: "By 2 PM"
 * (intraday) and "By Midnight" (end of day). Status is always paired with an
 * icon + label, never colour alone, per the design non-negotiables.
 */

const STATUS_TEXT = {
    critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
    success: 'text-healthcare-success dark:text-healthcare-success-dark',
    info: 'text-healthcare-info dark:text-healthcare-info-dark',
};

const STATUS_ICON = {
    critical: 'heroicons:exclamation-triangle',
    warning: 'heroicons:exclamation-circle',
    success: 'heroicons:check-circle',
    info: 'heroicons:information-circle',
};

const STATUS_LABEL = {
    critical: 'Short',
    warning: 'Tight',
    success: 'Adequate',
    info: 'Stable',
};

function StatusPill({ status }) {
    const tone = STATUS_TEXT[status] ?? STATUS_TEXT.info;
    return (
        <span className={`inline-flex items-center gap-1 text-xs font-medium ${tone}`}>
            <Icon icon={STATUS_ICON[status] ?? STATUS_ICON.info} className="h-4 w-4" aria-hidden="true" />
            {STATUS_LABEL[status] ?? STATUS_LABEL.info}
        </span>
    );
}

function EmptyState({ icon = 'heroicons:chart-bar', children }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-12 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <Icon icon={icon} className="h-8 w-8 opacity-60" aria-hidden="true" />
            <p className="text-sm">{children}</p>
        </div>
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

    if (!hasData) {
        return (
            <RTDCPageLayout
                title="Demand Forecast"
                subtitle="Predicted demand vs. available capacity"
            >
                <Card className="p-4">
                    <EmptyState icon="heroicons:presentation-chart-line">
                        No demand forecast has been published yet. Predictions appear here once the
                        bed-meeting horizons are seeded for an upcoming service date.
                    </EmptyState>
                </Card>
            </RTDCPageLayout>
        );
    }

    return (
        <RTDCPageLayout
            title="Demand Forecast"
            subtitle={`Predicted demand vs. available capacity — forecast for ${formatDate(serviceDate)}`}
        >
            {/* Horizon toggle */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Planning horizon
                </div>
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
            </div>

            {/* KPI wall — driven by the active horizon roll-up */}
            <MetricsCardGroup cols={4}>
                <MetricsCard
                    title="Predicted census"
                    value={activeSummary?.predictedCensus ?? 0}
                    icon="heroicons:user-group"
                    description={`${activeSummary?.label ?? ''} • occupied + admits − discharges`}
                    comparison={null}
                />
                <MetricsCard
                    title="Predicted demand"
                    value={activeSummary?.demand ?? 0}
                    icon="heroicons:arrow-trending-up"
                    description="Expected admissions into inpatient units"
                    comparison={null}
                />
                <MetricsCard
                    title="Available capacity"
                    value={activeSummary?.capacity ?? 0}
                    icon="heroicons:building-office-2"
                    description="Open beds across non-ED units"
                    comparison={null}
                />
                <MetricsCard
                    title="Net bed need"
                    value={activeSummary?.netBedNeed ?? 0}
                    trend={(activeSummary?.netBedNeed ?? 0) > 0 ? 'up' : 'down'}
                    icon={(activeSummary?.netBedNeed ?? 0) > 0 ? 'heroicons:exclamation-triangle' : 'heroicons:check-circle'}
                    description={`${activeSummary?.unitsShort ?? 0} of ${units.length} units short`}
                    comparison={null}
                />
            </MetricsCardGroup>

            {/* Predicted demand vs. capacity by unit (headline horizon) */}
            <Card>
                <Card.Header>
                    <div className="flex items-center justify-between gap-2">
                        <div>
                            <Card.Title>Predicted demand vs. capacity by unit</Card.Title>
                            <Card.Description>
                                {kpis?.horizonLabel ?? 'By 2 PM'} horizon • {formatDate(serviceDate)}
                            </Card.Description>
                        </div>
                        <Icon
                            icon="heroicons:chart-bar"
                            className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                            aria-hidden="true"
                        />
                    </div>
                </Card.Header>
                <Card.Content>
                    {(capacityChart.labels?.length ?? 0) > 0 ? (
                        <div className="h-[360px]">
                            <BarChart data={chartData} options={chartOptions} />
                        </div>
                    ) : (
                        <EmptyState>No per-unit forecast available for this date.</EmptyState>
                    )}
                </Card.Content>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Per-unit forecast table */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>Unit forecast detail</Card.Title>
                        <Card.Description>
                            Most constrained units first • {kpis?.horizonLabel ?? 'By 2 PM'} horizon
                        </Card.Description>
                    </Card.Header>
                    <Card.Content>
                        {units.length > 0 ? (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        <th className="px-2 py-2">Unit</th>
                                        <th className="px-2 py-2 text-right">Demand</th>
                                        <th className="px-2 py-2 text-right">Capacity</th>
                                        <th className="px-2 py-2 text-right">Exp. D/C</th>
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
                                                {u.expectedDischarges}
                                            </td>
                                            <td className="px-2 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {u.predictedCensus}
                                            </td>
                                            <td
                                                className={`px-2 py-2 text-right font-medium tabular-nums ${
                                                    u.bedNeed > 0
                                                        ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                                        : 'text-healthcare-success dark:text-healthcare-success-dark'
                                                }`}
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
                            <EmptyState icon="heroicons:table-cells">
                                No unit-level forecast rows for this date.
                            </EmptyState>
                        )}
                    </Card.Content>
                </Card>

                {/* Demand mix by source */}
                <Card>
                    <Card.Header>
                        <Card.Title>Demand by source</Card.Title>
                        <Card.Description>{kpis?.horizonLabel ?? 'By 2 PM'} horizon</Card.Description>
                    </Card.Header>
                    <Card.Content>
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
                            <EmptyState icon="heroicons:funnel">
                                No demand-source breakdown for this date.
                            </EmptyState>
                        )}
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
}
