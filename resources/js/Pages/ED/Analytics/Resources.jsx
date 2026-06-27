import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

// ED Resource Analytics rebuilt on the gold-standard design system: the KPI wall
// is one MetricGrid of KpiTiles, the occupancy / throughput charts and the
// bed-hours-by-acuity table live in Panels under Section headers. All values are
// server-computed from the live `prod` schema (seeded ed_visits); the page
// renders empty states rather than fabricating data.

// ESI badge styling — data-driven (acuity tier), not a status signal. Higher
// acuity (lower number) reads as more urgent via the warm tokens.
const ESI_BADGE = {
    1: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    2: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    3: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    4: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
    5: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
};

// Occupancy band -> status token + label. Earned urgency: only sustained
// crowding (>=90%) reads critical; <50% reads as comfortable headroom.
function occupancyStatus(pct) {
    if (pct >= 90) {
        return { tone: 'critical', label: 'At capacity', icon: 'heroicons:exclamation-triangle' };
    }
    if (pct >= 75) {
        return { tone: 'warning', label: 'Busy', icon: 'heroicons:arrow-trending-up' };
    }
    if (pct >= 50) {
        return { tone: 'info', label: 'Steady', icon: 'heroicons:minus' };
    }
    return { tone: 'success', label: 'Headroom', icon: 'heroicons:check-circle' };
}

export default function Resources({
    kpis = null,
    utilizationSeries = [],
    throughputSeries = [],
    bedHoursByEsi = [],
}) {
    const hasUtilization = utilizationSeries.length > 0;
    const hasThroughput = throughputSeries.length > 0;
    const hasBedHours = bedHoursByEsi.some((row) => row.visits > 0 || row.bedHours > 0);

    const occStatus = useMemo(
        () => occupancyStatus(kpis?.avgOccupancy ?? 0),
        [kpis?.avgOccupancy]
    );

    // Occupancy % per hour against the 100% capacity line.
    const utilizationChart = useMemo(
        () => ({
            labels: utilizationSeries.map((b) => b.hour),
            datasets: [
                {
                    label: 'Occupancy %',
                    data: utilizationSeries.map((b) => b.occupancy),
                    backgroundColor: 'var(--info)',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.7,
                },
            ],
        }),
        [utilizationSeries]
    );

    const utilizationOptions = useMemo(
        () => ({
            plugins: { legend: { display: false } },
            scales: { y: { suggestedMax: 100, ticks: { callback: (v) => `${v}%` } } },
        }),
        []
    );

    // Arrivals vs completed departures per hour — the flow balance.
    const throughputChart = useMemo(
        () => ({
            labels: throughputSeries.map((b) => b.hour),
            datasets: [
                {
                    label: 'Arrivals',
                    data: throughputSeries.map((b) => b.arrivals),
                    backgroundColor: 'var(--info)',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.7,
                },
                {
                    label: 'Departures',
                    data: throughputSeries.map((b) => b.discharges),
                    backgroundColor: 'var(--success)',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.7,
                },
            ],
        }),
        [throughputSeries]
    );

    const throughputOptions = useMemo(
        () => ({
            plugins: { legend: { display: true, position: 'top' } },
        }),
        []
    );

    // Gold-standard KPI wall. Occupancy KPIs carry earned-urgency status; the
    // flow counts are neutral context tiles. Occupancy hours sparkline comes
    // from the live per-hour utilization series when available.
    const occupancyTrajectory = hasUtilization ? utilizationSeries.map((b) => b.occupancy) : null;

    const kpiMetrics = useMemo(() => {
        if (!kpis) return [];
        return [
            metric({
                key: 'current-census',
                label: 'Current Census',
                value: Number(kpis.currentCensus ?? 0),
                display: `${kpis.currentCensus} / ${kpis.capacity}`,
                status: occStatus.tone,
                caption: `${kpis.capacity} staffed beds`,
                definition: 'ED patients currently occupying a bed against staffed-bed capacity.',
            }),
            metric({
                key: 'avg-occupancy',
                label: 'Avg Occupancy',
                value: Number(kpis.avgOccupancy ?? 0),
                unit: '%',
                status: occStatus.tone,
                trajectory: occupancyTrajectory,
                caption: occStatus.label,
                definition: 'Mean hourly census as a share of staffed-bed capacity over the window.',
            }),
            metric({
                key: 'peak-occupancy',
                label: 'Peak Occupancy',
                value: Number(kpis.peakOccupancy ?? 0),
                unit: '%',
                status: kpis.peakOccupancy >= 90 ? 'critical' : kpis.peakOccupancy >= 75 ? 'warning' : 'info',
                caption: `at ${kpis.peakHour}`,
                definition: 'Highest single-hour occupancy reached in the window.',
            }),
            metric({
                key: 'bed-hours',
                label: 'Bed-Hours Used',
                value: Number(kpis.bedHours ?? 0),
                status: 'info',
                caption: `last ${kpis.windowHours}h`,
                definition: 'Total occupied bed-hours consumed across the window.',
            }),
            metric({
                key: 'arrivals',
                label: 'Arrivals',
                value: Number(kpis.totalArrivals ?? 0),
                status: 'info',
                caption: `last ${kpis.windowHours}h`,
                definition: 'ED arrivals registered in the window.',
            }),
            metric({
                key: 'departures',
                label: 'Departures',
                value: Number(kpis.totalDischarges ?? 0),
                status: 'info',
                caption: `last ${kpis.windowHours}h`,
                definition: 'Completed ED departures (discharge or admit) in the window.',
            }),
            metric({
                key: 'bed-turnover',
                label: 'Bed Turnover',
                value: Number(kpis.turnoverRate ?? 0),
                display: `${kpis.turnoverRate}×`,
                status: 'info',
                caption: 'departures per bed',
                definition: 'Completed departures divided by staffed beds — bed cycling rate.',
            }),
            metric({
                key: 'median-los',
                label: 'Median LOS',
                value: Number(kpis.avgLos ?? 0),
                display: `${kpis.avgLos} min`,
                status: kpis.avgLos > 240 ? 'warning' : 'success',
                goodWhenDown: true,
                caption: 'completed visits',
                definition: 'Median ED length of stay for completed visits.',
            }),
        ];
    }, [kpis, occStatus, occupancyTrajectory]);

    return (
        <DashboardLayout>
            <Head title="Resource Analytics - Emergency" />
            <PageContentLayout
                title="ED Resource Analytics"
                subtitle="Utilization, occupancy, and throughput over the last 12 hours"
            >
                {!kpis ? (
                    <Panel className="p-4">
                        <EmptyState message="No ED resource data available for the current window." icon="heroicons:chart-bar-square" />
                    </Panel>
                ) : (
                    <div className="flex flex-col gap-5">
                        {/* KPI wall */}
                        <Section
                            title="Capacity & throughput"
                            icon="heroicons:squares-2x2"
                            summary={`Occupancy, flow, and turnover · last ${kpis.windowHours}h`}
                        >
                            <MetricGrid metrics={kpiMetrics} />
                        </Section>

                        {/* Utilization + throughput charts */}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <Section
                                title="Occupancy Over Time"
                                icon="heroicons:chart-bar"
                                summary="Hourly census as a share of staffed-bed capacity"
                                actions={
                                    <span
                                        className="inline-flex items-center gap-1 text-xs font-medium"
                                        style={{ color: STATUS_VAR[occStatus.tone] }}
                                    >
                                        <Icon icon={occStatus.icon} className="h-3.5 w-3.5" aria-hidden="true" />
                                        {occStatus.label}
                                    </span>
                                }
                            >
                                <Panel className="p-4">
                                    {hasUtilization ? (
                                        <div className="h-64">
                                            <BarChart data={utilizationChart} options={utilizationOptions} />
                                        </div>
                                    ) : (
                                        <EmptyState message="No occupancy data for this window." icon="heroicons:chart-bar" />
                                    )}
                                </Panel>
                            </Section>

                            <Section
                                title="Throughput"
                                icon="heroicons:arrows-right-left"
                                summary="Arrivals vs completed departures by hour"
                            >
                                <Panel className="p-4">
                                    {hasThroughput ? (
                                        <div className="h-64">
                                            <BarChart data={throughputChart} options={throughputOptions} />
                                        </div>
                                    ) : (
                                        <EmptyState message="No throughput data for this window." icon="heroicons:arrows-right-left" />
                                    )}
                                </Panel>
                            </Section>
                        </div>

                        {/* Bed-hours consumption by acuity */}
                        <Section
                            title="Bed-Hours by Acuity"
                            icon="heroicons:rectangle-stack"
                            summary={`Occupied bed-hours consumed per ESI tier over the last ${kpis.windowHours} hours`}
                        >
                            <Panel className="p-4">
                                {hasBedHours ? (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            <thead>
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Acuity
                                                    </th>
                                                    <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Visits
                                                    </th>
                                                    <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Bed-Hours
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Share
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                                {bedHoursByEsi.map((row) => (
                                                    <tr key={row.esi}>
                                                        <td className="px-4 py-3">
                                                            <span
                                                                className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${ESI_BADGE[row.esi] || ESI_BADGE[3]}`}
                                                            >
                                                                {row.label}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {row.visits}
                                                        </td>
                                                        <td className="px-4 py-3 text-right text-sm font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {row.bedHours.toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center space-x-3">
                                                                <div className="h-2 w-28 overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                                                                    <div
                                                                        className="h-full rounded-full bg-healthcare-info dark:bg-healthcare-info-dark"
                                                                        style={{ width: `${row.sharePct}%` }}
                                                                    />
                                                                </div>
                                                                <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                    {row.sharePct}%
                                                                </span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <EmptyState message="No bed-hours recorded for this window." icon="heroicons:rectangle-stack" />
                                )}
                            </Panel>
                        </Section>
                    </div>
                )}
            </PageContentLayout>
        </DashboardLayout>
    );
}
