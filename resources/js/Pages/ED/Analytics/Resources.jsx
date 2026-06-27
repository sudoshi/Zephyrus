import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

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
        return { tone: 'critical', label: 'At capacity', trend: 'down', icon: 'heroicons:exclamation-triangle' };
    }
    if (pct >= 75) {
        return { tone: 'warning', label: 'Busy', trend: 'down', icon: 'heroicons:arrow-trending-up' };
    }
    if (pct >= 50) {
        return { tone: 'info', label: 'Steady', trend: 'neutral', icon: 'heroicons:minus' };
    }
    return { tone: 'success', label: 'Headroom', trend: 'up', icon: 'heroicons:check-circle' };
}

const TONE_TEXT = {
    critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
    info: 'text-healthcare-info dark:text-healthcare-info-dark',
    success: 'text-healthcare-success dark:text-healthcare-success-dark',
};

const TONE_BADGE = {
    critical: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    info: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
    success: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
};

function EmptyState({ message }) {
    return (
        <div className="flex flex-col items-center justify-center py-12 text-center">
            <Icon
                icon="heroicons:chart-bar-square"
                className="mb-3 h-8 w-8 text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark"
            />
            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {message}
            </p>
        </div>
    );
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

    return (
        <DashboardLayout>
            <Head title="Resource Analytics - Emergency" />
            <PageContentLayout
                title="ED Resource Analytics"
                subtitle="Utilization, occupancy, and throughput over the last 12 hours"
            >
                {!kpis ? (
                    <Card>
                        <Card.Content>
                            <EmptyState message="No ED resource data available for the current window." />
                        </Card.Content>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        {/* KPI tiles */}
                        <MetricsCardGroup cols={4}>
                            <MetricsCard
                                title="Current Census"
                                value={kpis.currentCensus}
                                formatter={(v) => `${v} / ${kpis.capacity}`}
                                trend={occStatus.trend}
                                icon="heroicons:user-group"
                                description={`${kpis.capacity} staffed beds`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Avg Occupancy"
                                value={kpis.avgOccupancy}
                                formatter={(v) => `${v}%`}
                                trend={occStatus.trend}
                                icon={occStatus.icon}
                                description={occStatus.label}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Peak Occupancy"
                                value={kpis.peakOccupancy}
                                formatter={(v) => `${v}%`}
                                trend={kpis.peakOccupancy >= 90 ? 'down' : 'neutral'}
                                icon="heroicons:arrow-trending-up"
                                description={`at ${kpis.peakHour}`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Bed-Hours Used"
                                value={kpis.bedHours}
                                formatter={(v) => v.toLocaleString()}
                                trend="neutral"
                                icon="heroicons:clock"
                                description={`last ${kpis.windowHours}h`}
                                comparison={null}
                            />
                        </MetricsCardGroup>

                        <MetricsCardGroup cols={4}>
                            <MetricsCard
                                title="Arrivals"
                                value={kpis.totalArrivals}
                                trend="neutral"
                                icon="heroicons:arrow-down-on-square"
                                description={`last ${kpis.windowHours}h`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Departures"
                                value={kpis.totalDischarges}
                                trend="neutral"
                                icon="heroicons:arrow-up-on-square"
                                description={`last ${kpis.windowHours}h`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Bed Turnover"
                                value={kpis.turnoverRate}
                                formatter={(v) => `${v}×`}
                                trend="neutral"
                                icon="heroicons:arrow-path"
                                description="departures per bed"
                                comparison={null}
                            />
                            <MetricsCard
                                title="Median LOS"
                                value={kpis.avgLos}
                                formatter={(v) => `${v} min`}
                                trend={kpis.avgLos > 240 ? 'down' : 'up'}
                                icon="heroicons:clock"
                                description="completed visits"
                                comparison={null}
                            />
                        </MetricsCardGroup>

                        {/* Utilization + throughput charts */}
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <Card>
                                <Card.Header>
                                    <Card.Title>
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center space-x-2">
                                                <Icon icon="heroicons:chart-bar" className="h-5 w-5" />
                                                <span>Occupancy Over Time</span>
                                            </div>
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${TONE_BADGE[occStatus.tone]}`}
                                            >
                                                <Icon icon={occStatus.icon} className="mr-1 h-3.5 w-3.5" />
                                                {occStatus.label}
                                            </span>
                                        </div>
                                    </Card.Title>
                                    <Card.Description>
                                        Hourly census as a share of staffed-bed capacity
                                    </Card.Description>
                                </Card.Header>
                                <Card.Content>
                                    {hasUtilization ? (
                                        <div className="h-64">
                                            <BarChart data={utilizationChart} options={utilizationOptions} />
                                        </div>
                                    ) : (
                                        <EmptyState message="No occupancy data for this window." />
                                    )}
                                </Card.Content>
                            </Card>

                            <Card>
                                <Card.Header>
                                    <Card.Title>
                                        <div className="flex items-center space-x-2">
                                            <Icon icon="heroicons:arrows-right-left" className="h-5 w-5" />
                                            <span>Throughput</span>
                                        </div>
                                    </Card.Title>
                                    <Card.Description>
                                        Arrivals vs completed departures by hour
                                    </Card.Description>
                                </Card.Header>
                                <Card.Content>
                                    {hasThroughput ? (
                                        <div className="h-64">
                                            <BarChart data={throughputChart} options={throughputOptions} />
                                        </div>
                                    ) : (
                                        <EmptyState message="No throughput data for this window." />
                                    )}
                                </Card.Content>
                            </Card>
                        </div>

                        {/* Bed-hours consumption by acuity */}
                        <Card>
                            <Card.Header>
                                <Card.Title>
                                    <div className="flex items-center space-x-2">
                                        <Icon icon="heroicons:rectangle-stack" className="h-5 w-5" />
                                        <span>Bed-Hours by Acuity</span>
                                    </div>
                                </Card.Title>
                                <Card.Description>
                                    Occupied bed-hours consumed per ESI tier over the last {kpis.windowHours} hours
                                </Card.Description>
                            </Card.Header>
                            <Card.Content>
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
                                    <EmptyState message="No bed-hours recorded for this window." />
                                )}
                            </Card.Content>
                        </Card>
                    </div>
                )}
            </PageContentLayout>
        </DashboardLayout>
    );
}
