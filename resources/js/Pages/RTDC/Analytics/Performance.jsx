import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard from '@/Components/Common/MetricsCard';
import TrendChart, { formatters } from '@/Components/Common/TrendChart';
import { Icon } from '@iconify/react';

// RTDC Performance Metrics — the throughput & forecast-reliability scorecard
// for the operations bridge. KPI wall (LOS vs GMLOS, discharge-by-noon, ED
// boarding, bed-request turnaround, forecast reliability) over a forecast-
// reliability trend, with a LOS-vs-GMLOS-by-unit-type breakdown and a per-unit
// reconciliation table. All values are server-computed from the live `prod`
// schema (PerformanceAnalyticsService); the page renders zeros / empty states
// rather than fabricating data when a feed is absent.

const STATUS_TEXT = {
    critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
    success: 'text-healthcare-success dark:text-healthcare-success-dark',
    info: 'text-healthcare-info dark:text-healthcare-info-dark',
};

const STATUS_PILL = {
    critical:
        'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark',
    warning:
        'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark',
    success:
        'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark',
    info: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info/20 dark:text-healthcare-info-dark',
};

const pillClass = (status) => STATUS_PILL[status] ?? STATUS_PILL.info;
const textClass = (status) => STATUS_TEXT[status] ?? STATUS_TEXT.info;

const EmptyState = ({ icon, message }) => (
    <div className="flex flex-col items-center justify-center py-12 text-center">
        <Icon
            icon={icon}
            className="w-10 h-10 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3"
        />
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {message}
        </p>
    </div>
);

export default function Performance({
    kpis = null,
    reliabilityTrend = [],
    losByType = [],
    reconciliationRows = [],
    meta = null,
}) {
    const k = kpis ?? {};
    const hasData = meta?.hasData ?? false;
    const windowDays = meta?.windowDays ?? 14;

    // LOS index drives the headline status: at/under GMLOS is good.
    const losStatus =
        !k.losIndex || k.losIndex === 0
            ? 'info'
            : k.losIndex >= 1.2
              ? 'critical'
              : k.losIndex >= 1.05
                ? 'warning'
                : 'success';

    // The reliability trend chart uses two series (predicted vs actual
    // discharges) plus a derived reliability line; the discharge-by-noon goal
    // and reliability target are surfaced in copy rather than as chart noise.
    const reliabilityChartData = (reliabilityTrend ?? []).map((row) => ({
        date: row.date,
        reliability: row.reliability,
        predicted: row.predicted,
        actual: row.actual,
    }));

    if (!hasData) {
        return (
            <RTDCPageLayout
                title="Performance Metrics"
                subtitle="Throughput and forecast-reliability scorecard"
            >
                <Card>
                    <Card.Content>
                        <EmptyState
                            icon="heroicons:chart-bar-square"
                            message="No performance data is available yet. Metrics will populate once census, encounter, and reconciliation feeds report."
                        />
                    </Card.Content>
                </Card>
            </RTDCPageLayout>
        );
    }

    return (
        <RTDCPageLayout
            title="Performance Metrics"
            subtitle={`Throughput and forecast reliability · trailing ${windowDays} days`}
        >
            {/* KPI wall */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <MetricsCard
                    title="Avg LOS vs GMLOS"
                    icon="heroicons:clock"
                    value={k.avgLos ?? 0}
                    formatter={(v) => `${Number(v).toFixed(2)}d`}
                    trend={losStatus === 'success' ? 'down' : losStatus === 'critical' ? 'up' : 'neutral'}
                    trendValue={k.losIndex ?? 0}
                    trendFormatter={(v) => `${Number(v).toFixed(2)}× index`}
                    comparison={`${(k.gmlos ?? 0).toFixed(2)}d GMLOS`}
                    description={`${k.dischargedTotal ?? 0} discharges · ${
                        (k.losDelta ?? 0) >= 0 ? '+' : ''
                    }${(k.losDelta ?? 0).toFixed(2)}d vs reference`}
                />
                <MetricsCard
                    title="Discharge by Noon"
                    icon="heroicons:sun"
                    value={k.dischargeByNoonRate ?? 0}
                    formatter={(v) => `${v}%`}
                    trend={
                        (k.dischargeByNoonRate ?? 0) >= 50
                            ? 'up'
                            : (k.dischargeByNoonRate ?? 0) >= 35
                              ? 'neutral'
                              : 'down'
                    }
                    comparison="50% target"
                    description={`Discharges completed before 12:00 (n=${k.dischargedTotal ?? 0})`}
                />
                <MetricsCard
                    title="ED Boarding"
                    icon="heroicons:arrow-right-on-rectangle"
                    value={k.avgBoardingHours ?? 0}
                    formatter={(v) => `${Number(v).toFixed(1)}h`}
                    trend={
                        (k.avgBoardingHours ?? 0) <= 2
                            ? 'up'
                            : (k.avgBoardingHours ?? 0) <= 4
                              ? 'neutral'
                              : 'down'
                    }
                    comparison="2h target"
                    description={`${k.boardedCount ?? 0} admits · ${(k.totalBoardingHours ?? 0).toFixed(
                        1,
                    )}h total board time`}
                />
                <MetricsCard
                    title="Bed-Request Turnaround"
                    icon="heroicons:inbox-arrow-down"
                    value={k.avgTurnaroundHours ?? 0}
                    formatter={(v) => ((k.placedCount ?? 0) > 0 ? `${Number(v).toFixed(1)}h` : '—')}
                    trend="neutral"
                    comparison="request → placement"
                    description={
                        (k.placedCount ?? 0) > 0
                            ? `${k.placedCount} requests placed in window`
                            : 'No completed placements in window'
                    }
                />
                <MetricsCard
                    title="Forecast Reliability"
                    icon="heroicons:check-badge"
                    value={k.forecastReliability ?? 0}
                    formatter={(v) => `${v}%`}
                    trend={k.reliabilityTrend === 'up' ? 'up' : k.reliabilityTrend === 'down' ? 'down' : 'neutral'}
                    comparison="vs prior day"
                    description="Mean reconciliation reliability (latest day)"
                />
                <MetricsCard
                    title="LOS Index"
                    icon="heroicons:scale"
                    value={k.losIndex ?? 0}
                    formatter={(v) => `${Number(v).toFixed(2)}×`}
                    trend={losStatus === 'success' ? 'up' : losStatus === 'critical' ? 'down' : 'neutral'}
                    comparison="1.00× = at GMLOS"
                    description={
                        (k.losIndex ?? 0) <= 1
                            ? 'At or under expected length of stay'
                            : 'Length of stay running over reference'
                    }
                />
            </div>

            {/* Forecast-reliability trend */}
            <div className="grid grid-cols-1 gap-4">
                {reliabilityChartData.length > 0 ? (
                    <TrendChart
                        title="Forecast reliability & discharge accuracy"
                        description={`Daily predicted vs actual discharges and mean reliability · trailing ${windowDays} days`}
                        data={reliabilityChartData}
                        series={[
                            { dataKey: 'reliability', name: 'Reliability %' },
                            { dataKey: 'predicted', name: 'Predicted discharges' },
                            { dataKey: 'actual', name: 'Actual discharges' },
                        ]}
                        xAxis={{
                            dataKey: 'date',
                            type: 'category',
                            formatter: (value) =>
                                new Date(value).toLocaleDateString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                }),
                        }}
                        yAxis={{ formatter: formatters.number }}
                        tooltip={{ formatter: formatters.number }}
                    />
                ) : (
                    <Card>
                        <Card.Header>
                            <Card.Title>Forecast reliability</Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <EmptyState
                                icon="heroicons:presentation-chart-line"
                                message="No reconciliation history in the trailing window."
                            />
                        </Card.Content>
                    </Card>
                )}
            </div>

            {/* LOS vs GMLOS by unit type + per-unit reliability */}
            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <Card>
                    <Card.Header>
                        <Card.Title>Length of stay by unit type</Card.Title>
                        <Card.Description>
                            Observed average LOS against the GMLOS reference
                        </Card.Description>
                    </Card.Header>
                    <Card.Content>
                        {losByType.length > 0 ? (
                            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                <thead>
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Unit type
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Avg LOS
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            GMLOS
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Index
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            n
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    {losByType.map((row) => (
                                        <tr
                                            key={row.type}
                                            className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200"
                                        >
                                            <td className="px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark whitespace-nowrap">
                                                {row.label}
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {row.avgLos.toFixed(2)}d
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {row.gmlos.toFixed(2)}d
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium tabular-nums ${pillClass(
                                                        row.status,
                                                    )}`}
                                                >
                                                    {row.index.toFixed(2)}×
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {row.discharged}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <EmptyState
                                icon="heroicons:clock"
                                message="No discharged encounters in the trailing window."
                            />
                        )}
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>Forecast reliability by unit</Card.Title>
                        <Card.Description>
                            Latest reconciliation per unit — lowest reliability first
                        </Card.Description>
                    </Card.Header>
                    <Card.Content>
                        {reconciliationRows.length > 0 ? (
                            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                <thead>
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Unit
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Pred / Act DC
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Pred / Act Adm
                                        </th>
                                        <th className="px-3 py-2 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Reliability
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    {reconciliationRows.map((row) => (
                                        <tr
                                            key={row.unitId}
                                            className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-200"
                                        >
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {row.unit}
                                                </div>
                                                <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {row.type}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {row.predictedDischarges} / {row.actualDischarges}
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {row.predictedAdmissions} / {row.actualAdmissions}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center gap-1 text-sm font-semibold tabular-nums ${textClass(
                                                        row.status,
                                                    )}`}
                                                >
                                                    <Icon
                                                        icon={
                                                            row.status === 'success'
                                                                ? 'heroicons:arrow-trending-up'
                                                                : row.status === 'critical'
                                                                  ? 'heroicons:arrow-trending-down'
                                                                  : 'heroicons:minus'
                                                        }
                                                        className="w-4 h-4"
                                                    />
                                                    {row.reliability}%
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <EmptyState
                                icon="heroicons:check-badge"
                                message="No reconciliation rows available."
                            />
                        )}
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
}
