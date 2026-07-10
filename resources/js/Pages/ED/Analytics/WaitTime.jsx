import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import { formatDurationHours, formatDurationMinutes } from '@/lib/duration';

// ED Wait Time rebuilt on the gold-standard design system: the KPI wall is one
// MetricGrid of KpiTiles, the trend chart / distribution list / per-ESI table
// live in Panels under Section headers. All values are server-computed from the
// live `prod` schema (seeded ed_visits); the page renders zeros / empty states
// rather than fabricating data. Lower-is-better intervals breach to 'warning';
// on/under target reads 'success'. Status is always paired with an icon + label.
const formatMinutes = (value) => formatDurationMinutes(Number.isFinite(value) ? value : null);

const esiBadgeClass = (esi) => {
    switch (esi) {
        case 1:
            return 'bg-healthcare-critical/15 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark';
        case 2:
            return 'bg-healthcare-warning/15 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark';
        case 3:
            return 'bg-healthcare-info/15 text-healthcare-info dark:bg-healthcare-info-dark/20 dark:text-healthcare-info-dark';
        case 4:
            return 'bg-healthcare-success/15 text-healthcare-success dark:bg-healthcare-success-dark/20 dark:text-healthcare-success-dark';
        default:
            return 'bg-healthcare-background text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark';
    }
};

const DEFAULT_KPI = { value: 0, target: 0, trend: 'down', trendValue: 0, withinTarget: true };

// Map a lower-is-better wait-time KPI into the gold-standard metric() contract.
// withinTarget → 'success'; breach → 'warning'. The caption preserves the
// over/under-target delta the bespoke tile surfaced.
const waitMetric = (key, label, kpi = DEFAULT_KPI, definition) => {
    const k = kpi ?? DEFAULT_KPI;
    const within = k.withinTarget;
    const delta = formatMinutes(k.trendValue);
    return metric({
        key,
        label,
        value: Number(k.value ?? 0),
        display: formatMinutes(k.value),
        status: within ? 'success' : 'warning',
        target: Number(k.target ?? 0),
        targetDisplay: `${formatMinutes(k.target)} target`,
        goodWhenDown: true,
        caption: within ? `${delta} under target` : `${delta} over target`,
        definition,
    });
};

const DistributionRow = ({ label, dist }) => {
    const d = dist || { median: 0, p90: 0, target: 0, n: 0 };
    const breached = d.median > d.target;
    return (
        <div className="flex items-center justify-between rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark p-3 transition-colors duration-300">
            <div className="flex items-center space-x-3">
                <Icon
                    icon={breached ? 'heroicons:exclamation-triangle' : 'heroicons:check-circle'}
                    className="w-5 h-5"
                    style={{ color: STATUS_VAR[breached ? 'warning' : 'success'] }}
                    aria-hidden="true"
                />
                <div>
                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {label}
                    </p>
                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        n = <span className="tabular-nums">{d.n}</span> · target{' '}
                        <span className="tabular-nums">{formatMinutes(d.target)}</span>
                    </p>
                </div>
            </div>
            <div className="flex items-center space-x-6 text-right">
                <div>
                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Median</p>
                    <p className="text-base font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {formatMinutes(d.median)}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">90th pct</p>
                    <p className="text-base font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {formatMinutes(d.p90)}
                    </p>
                </div>
            </div>
        </div>
    );
};

export default function WaitTime({ window: win, kpis, distributions, byEsi, trend }) {
    // Loading guard: Inertia always sends props, but tolerate a partial/initial
    // render (and any future deferred-prop usage) without throwing.
    if (!kpis || !distributions) {
        return (
            <DashboardLayout>
                <Head title="ED Wait Time - Emergency" />
                <PageContentLayout title="Wait Time" subtitle="Monitor and analyze ED patient wait times">
                    <Panel className="p-4">
                        <EmptyState message="Loading wait-time analytics…" icon="heroicons:clock" />
                    </Panel>
                </PageContentLayout>
            </DashboardLayout>
        );
    }

    const visitCount = win?.visitCount ?? 0;
    const hasTrend = Array.isArray(trend) && trend.length > 0;
    const esiRows = Array.isArray(byEsi) ? byEsi : [];
    const windowHours = win?.hours ?? 24;

    const kpiMetrics = [
        waitMetric('door-to-provider', 'Door to Provider', kpis.doorToProvider,
            'Median minutes from ED arrival to first provider contact.'),
        waitMetric('door-to-disposition', 'Door to Disposition', kpis.doorToDisposition,
            'Median minutes from ED arrival to disposition decision.'),
        waitMetric('length-of-stay', 'Total Length of Stay', kpis.lengthOfStay,
            'Median total ED length of stay for completed visits.'),
        waitMetric('p90-door-to-provider', 'P90 Door to Provider', kpis.p90DoorToProvider,
            '90th-percentile minutes from arrival to provider — the tail experience.'),
    ];

    return (
        <DashboardLayout>
            <Head title="ED Wait Time - Emergency" />
            <PageContentLayout
                title="Wait Time"
                subtitle={`Door-to-provider, door-to-disposition, and length-of-stay across the last ${formatDurationHours(windowHours)}`}
                headerContent={
                    <div className="flex items-center space-x-2 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark px-3 py-2">
                        <Icon icon="heroicons:users" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            <span className="font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{visitCount}</span> visits
                        </span>
                    </div>
                }
            >
                <div className="flex flex-col gap-5">
                    {/* KPI wall */}
                    <Section
                        title="Wait-time intervals"
                        icon="heroicons:clock"
                        summary={`Median intervals vs target · last ${formatDurationHours(windowHours)} · ${visitCount} visits`}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        {/* Wait-time trend */}
                        <Section
                            className="lg:col-span-2"
                            title="Wait Time Trend"
                            icon="heroicons:presentation-chart-line"
                            summary="Hourly mean intervals by arrival hour"
                        >
                            <Panel className="p-4">
                                {hasTrend ? (
                                    <div className="h-72">
                                        <TrendChart
                                            data={trend}
                                            xAxis={{ dataKey: 'hour', type: 'category' }}
                                            yAxis={{ formatter: (v) => formatDurationMinutes(Number(v)) }}
                                            series={[
                                                {
                                                    dataKey: 'doorToProvider',
                                                    name: 'Door to Provider',
                                                    color: 'rgb(var(--color-healthcare-primary))',
                                                },
                                                {
                                                    dataKey: 'doorToDisposition',
                                                    name: 'Door to Disposition',
                                                    color: 'rgb(var(--color-healthcare-warning))',
                                                },
                                            ]}
                                            referenceLines={[
                                                {
                                                    y: kpis.doorToProvider?.target ?? 30,
                                                    label: 'D2P target',
                                                    color: 'rgb(var(--color-healthcare-success))',
                                                    strokeDasharray: '4 4',
                                                },
                                            ]}
                                        />
                                    </div>
                                ) : (
                                    <EmptyState message="No completed visits in the current window." icon="heroicons:presentation-chart-line" />
                                )}
                            </Panel>
                        </Section>

                        {/* Distribution summary */}
                        <Section
                            title="Interval Distributions"
                            icon="heroicons:chart-pie"
                            summary="Median vs 90th percentile"
                        >
                            <Panel className="p-4">
                                <div className="space-y-3">
                                    <DistributionRow label="Door to Provider" dist={distributions.doorToProvider} />
                                    <DistributionRow label="Door to Disposition" dist={distributions.doorToDisposition} />
                                    <DistributionRow label="Length of Stay" dist={distributions.lengthOfStay} />
                                </div>
                            </Panel>
                        </Section>
                    </div>

                    {/* Per-ESI breakdown */}
                    <Section
                        title="Wait Time by Acuity (ESI)"
                        icon="heroicons:rectangle-stack"
                        summary="Median minutes per interval, broken out by triage acuity"
                    >
                        <Panel className="p-4">
                            {esiRows.some((r) => r.n > 0) ? (
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
                                                    Door → Provider
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Door → Disposition
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Length of Stay
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {esiRows.map((row) => (
                                                <tr
                                                    key={row.esi}
                                                    className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-300"
                                                >
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ${esiBadgeClass(row.esi)}`}
                                                        >
                                                            {row.label}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {row.n}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {row.n > 0 ? formatMinutes(row.doorToProvider) : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {row.doorToDisposition > 0 ? formatMinutes(row.doorToDisposition) : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {row.los > 0 ? formatMinutes(row.los) : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState message="No triaged visits with recorded intervals in this window." icon="heroicons:rectangle-stack" />
                            )}
                        </Panel>
                    </Section>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
