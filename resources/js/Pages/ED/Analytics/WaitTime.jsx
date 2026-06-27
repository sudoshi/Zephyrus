import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

// Lower-is-better intervals: a KPI that breaches its target reads as the
// 'warning' system color; on/under target reads as 'success'. Status is always
// paired with an icon + label, never color alone.
const formatMinutes = (value) => {
    const v = Number.isFinite(value) ? value : 0;
    if (v < 60) return `${v}m`;
    const h = Math.floor(v / 60);
    const m = v % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
};

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

const KpiTile = ({ title, kpi = DEFAULT_KPI, icon }) => (
    <MetricsCard
        title={title}
        value={formatMinutes(kpi.value)}
        // 'up' on a wait-time metric means slower than target → render as worsening.
        trend={kpi.withinTarget ? 'down' : 'up'}
        trendValue={formatMinutes(kpi.trendValue)}
        trendFormatter={(v) => (kpi.withinTarget ? `${v} under` : `${v} over`)}
        icon={icon}
        description={`Target ${formatMinutes(kpi.target)}`}
        comparison="target"
    />
);

const DistributionRow = ({ label, dist }) => {
    const d = dist || { median: 0, p90: 0, target: 0, n: 0 };
    const breached = d.median > d.target;
    return (
        <div className="flex items-center justify-between rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark p-3 transition-colors duration-300">
            <div className="flex items-center space-x-3">
                <Icon
                    icon={breached ? 'heroicons:exclamation-triangle' : 'heroicons:check-circle'}
                    className={`w-5 h-5 ${breached ? 'text-healthcare-warning dark:text-healthcare-warning-dark' : 'text-healthcare-success dark:text-healthcare-success-dark'}`}
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

const EmptyState = ({ message }) => (
    <div className="flex flex-col items-center justify-center py-10 text-center">
        <div className="mb-3 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/10 p-3">
            <Icon icon="heroicons:clock" className="w-6 h-6 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />
        </div>
        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{message}</p>
    </div>
);

export default function WaitTime({ window: win, kpis, distributions, byEsi, trend }) {
    // Loading guard: Inertia always sends props, but tolerate a partial/initial
    // render (and any future deferred-prop usage) without throwing.
    if (!kpis || !distributions) {
        return (
            <DashboardLayout>
                <Head title="ED Wait Time - Emergency" />
                <PageContentLayout title="Wait Time" subtitle="Monitor and analyze ED patient wait times">
                    <Card>
                        <Card.Content>
                            <EmptyState message="Loading wait-time analytics…" />
                        </Card.Content>
                    </Card>
                </PageContentLayout>
            </DashboardLayout>
        );
    }

    const visitCount = win?.visitCount ?? 0;
    const hasTrend = Array.isArray(trend) && trend.length > 0;
    const esiRows = Array.isArray(byEsi) ? byEsi : [];
    const windowHours = win?.hours ?? 24;

    return (
        <DashboardLayout>
            <Head title="ED Wait Time - Emergency" />
            <PageContentLayout
                title="Wait Time"
                subtitle={`Door-to-provider, door-to-disposition, and length-of-stay across the last ${windowHours}h`}
                headerContent={
                    <div className="flex items-center space-x-2 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark px-3 py-2">
                        <Icon icon="heroicons:users" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            <span className="font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{visitCount}</span> visits
                        </span>
                    </div>
                }
            >
                {/* KPI tiles */}
                <MetricsCardGroup cols={4}>
                    <KpiTile title="Door to Provider" kpi={kpis.doorToProvider} icon="heroicons:user-plus" />
                    <KpiTile title="Door to Disposition" kpi={kpis.doorToDisposition} icon="heroicons:clipboard-document-check" />
                    <KpiTile title="Total Length of Stay" kpi={kpis.lengthOfStay} icon="heroicons:clock" />
                    <KpiTile title="P90 Door to Provider" kpi={kpis.p90DoorToProvider} icon="heroicons:chart-bar-square" />
                </MetricsCardGroup>

                <div className="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Wait-time trend */}
                    <Card className="lg:col-span-2">
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:presentation-chart-line" className="w-5 h-5" />
                                    <span>Wait Time Trend</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Hourly mean intervals by arrival hour</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {hasTrend ? (
                                <div className="h-72">
                                    <TrendChart
                                        data={trend}
                                        xAxis={{ dataKey: 'hour', type: 'category' }}
                                        yAxis={{ formatter: (v) => `${v}m` }}
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
                                <EmptyState message="No completed visits in the current window." />
                            )}
                        </Card.Content>
                    </Card>

                    {/* Distribution summary */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:chart-pie" className="w-5 h-5" />
                                    <span>Interval Distributions</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Median vs 90th percentile</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-3">
                                <DistributionRow label="Door to Provider" dist={distributions.doorToProvider} />
                                <DistributionRow label="Door to Disposition" dist={distributions.doorToDisposition} />
                                <DistributionRow label="Length of Stay" dist={distributions.lengthOfStay} />
                            </div>
                        </Card.Content>
                    </Card>
                </div>

                {/* Per-ESI breakdown */}
                <Card className="mt-6">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:rectangle-stack" className="w-5 h-5" />
                                <span>Wait Time by Acuity (ESI)</span>
                            </div>
                        </Card.Title>
                        <Card.Description>Median minutes per interval, broken out by triage acuity</Card.Description>
                    </Card.Header>
                    <Card.Content>
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
                            <EmptyState message="No triaged visits with recorded intervals in this window." />
                        )}
                    </Card.Content>
                </Card>
            </PageContentLayout>
        </DashboardLayout>
    );
}
