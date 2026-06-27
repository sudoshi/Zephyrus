import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import TrendChart from '@/Components/Common/TrendChart';

const EMPTY_KPIS = {
    next12h: { value: 0, label: 'Predicted arrivals (next 12h)', trend: 'neutral', trendValue: null, description: '' },
    next24h: { value: 0, label: 'Predicted arrivals (next 24h)', trend: 'neutral', trendValue: null, description: '' },
    peakHour: { value: '--:--', count: 0, label: 'Forecast peak hour', trend: 'neutral', trendValue: null, description: '' },
    currentRate: { value: 0, expected: 0, label: 'Arrivals (last 60 min)', trend: 'neutral', trendValue: null, description: '' },
};

// Categorical chart palette (data-driven, not status) — a sanctioned exception
// to the raw-color rule. Forecast = blue/info, band = muted, historical = gold.
const FORECAST_COLORS = ['#2563EB', '#94A3B8', '#94A3B8', '#C9A227'];
const PROFILE_COLORS = ['#2563EB', '#C9A227'];

const integerFormatter = (value) => Math.round(Number(value) || 0).toLocaleString();

// ESI-style intensity badge for a forecast hour, keyed to predicted load.
// Status is paired with a label, never color alone.
const loadBadge = (predicted) => {
    if (predicted >= 4) {
        return {
            label: 'High',
            icon: 'heroicons:arrow-trending-up',
            className: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/15 dark:text-healthcare-critical-dark',
        };
    }
    if (predicted >= 2) {
        return {
            label: 'Moderate',
            icon: 'heroicons:minus-small',
            className: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/15 dark:text-healthcare-warning-dark',
        };
    }
    return {
        label: 'Low',
        icon: 'heroicons:arrow-trending-down',
        className: 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/15 dark:text-healthcare-success-dark',
    };
};

const Arrival = ({ kpis = EMPTY_KPIS, forecast = [], hourlyProfile = [], meta = {} }) => {
    const hasForecast = Array.isArray(forecast) && forecast.length > 0;
    const hasData = meta?.hasData ?? hasForecast;

    return (
        <DashboardLayout>
            <Head title="Arrival Prediction - Emergency" />
            <PageContentLayout
                title="Arrival Prediction"
                subtitle="Forecast patient arrivals to the ED"
                headerContent={
                    <div className="flex items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-1.5 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
                        <Icon icon="heroicons:cpu-chip" className="h-4 w-4 text-healthcare-info dark:text-healthcare-info-dark" />
                        <span>
                            {meta?.horizonHours ?? 24}h horizon
                            {meta?.historyDays ? ` · ${meta.historyDays}d history` : ''}
                        </span>
                    </div>
                }
            >
                {!hasData && (
                    <div className="mb-4 flex items-start gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                        <Icon icon="heroicons:information-circle" className="mt-0.5 h-5 w-5 shrink-0 text-healthcare-info dark:text-healthcare-info-dark" />
                        <div>
                            <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                No arrival history available
                            </p>
                            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                The forecast below uses a population-level diurnal model until ED visit data is recorded.
                            </p>
                        </div>
                    </div>
                )}

                {/* KPI tiles */}
                <MetricsCardGroup cols={4}>
                    <MetricsCard
                        title={kpis.next12h.label}
                        value={kpis.next12h.value}
                        formatter={integerFormatter}
                        trend={kpis.next12h.trend}
                        trendValue={kpis.next12h.trendValue ?? undefined}
                        icon="heroicons:user-plus"
                        description={kpis.next12h.description}
                        comparison={null}
                    />
                    <MetricsCard
                        title={kpis.next24h.label}
                        value={kpis.next24h.value}
                        formatter={integerFormatter}
                        trend={kpis.next24h.trend}
                        trendValue={kpis.next24h.trendValue ?? undefined}
                        icon="heroicons:calendar-days"
                        description={kpis.next24h.description}
                        comparison={null}
                    />
                    <MetricsCard
                        title={kpis.peakHour.label}
                        value={kpis.peakHour.value}
                        trend={kpis.peakHour.trend}
                        trendValue={kpis.peakHour.trendValue ?? undefined}
                        icon="heroicons:chart-bar-square"
                        description={kpis.peakHour.description}
                        comparison={null}
                    />
                    <MetricsCard
                        title={kpis.currentRate.label}
                        value={kpis.currentRate.value}
                        formatter={integerFormatter}
                        trend={kpis.currentRate.trend}
                        trendValue={kpis.currentRate.trendValue ?? undefined}
                        icon="heroicons:clock"
                        description={kpis.currentRate.description}
                        comparison={null}
                    />
                </MetricsCardGroup>

                {/* Forecast curve */}
                <div className="mt-6">
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center gap-2">
                                    <Icon icon="heroicons:presentation-chart-line" className="h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark" />
                                    <span>Arrival Forecast — Next {meta?.horizonHours ?? 24} Hours</span>
                                </div>
                            </Card.Title>
                            <Card.Description>
                                Predicted arrivals per hour with a 90% confidence band, overlaid on the historical hourly average.
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {hasForecast ? (
                                <div className="h-[340px]">
                                    <TrendChart
                                        data={forecast}
                                        series={[
                                            { dataKey: 'predicted', name: 'Predicted' },
                                            { dataKey: 'upper', name: 'Upper bound' },
                                            { dataKey: 'lower', name: 'Lower bound' },
                                            { dataKey: 'historical', name: 'Historical avg' },
                                        ]}
                                        colors={FORECAST_COLORS}
                                        xAxis={{ dataKey: 'hour', type: 'category', formatter: (v) => v }}
                                        yAxis={{ formatter: integerFormatter }}
                                        tooltip={{ formatter: integerFormatter }}
                                    />
                                </div>
                            ) : (
                                <div className="flex h-[340px] items-center justify-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Forecast unavailable.
                                </div>
                            )}
                        </Card.Content>
                    </Card>
                </div>

                {/* Diurnal profile + hourly board */}
                <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
                    <div className="lg:col-span-3">
                        <Card>
                            <Card.Header>
                                <Card.Title>
                                    <div className="flex items-center gap-2">
                                        <Icon icon="heroicons:clock" className="h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark" />
                                        <span>Diurnal Arrival Profile</span>
                                    </div>
                                </Card.Title>
                                <Card.Description>
                                    Expected arrivals by hour of day, with observed history over the look-back window.
                                </Card.Description>
                            </Card.Header>
                            <Card.Content>
                                {hourlyProfile.length > 0 ? (
                                    <div className="h-[300px]">
                                        <TrendChart
                                            data={hourlyProfile}
                                            series={[
                                                { dataKey: 'average', name: 'Expected / hr' },
                                                { dataKey: 'arrivals', name: 'Observed total' },
                                            ]}
                                            colors={PROFILE_COLORS}
                                            xAxis={{ dataKey: 'hour', type: 'category', formatter: (v) => v }}
                                            yAxis={{ formatter: integerFormatter }}
                                            tooltip={{ formatter: integerFormatter }}
                                        />
                                    </div>
                                ) : (
                                    <div className="flex h-[300px] items-center justify-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        No profile data.
                                    </div>
                                )}
                            </Card.Content>
                        </Card>
                    </div>

                    <div className="lg:col-span-2">
                        <Card>
                            <Card.Header>
                                <Card.Title>
                                    <div className="flex items-center gap-2">
                                        <Icon icon="heroicons:table-cells" className="h-5 w-5 text-healthcare-info dark:text-healthcare-info-dark" />
                                        <span>Hourly Forecast Board</span>
                                    </div>
                                </Card.Title>
                                <Card.Description>Next 12 hours, with confidence range.</Card.Description>
                            </Card.Header>
                            <Card.Content className="p-0">
                                {hasForecast ? (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-sm">
                                            <thead>
                                                <tr className="border-b border-healthcare-border text-left text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                                                    <th className="px-4 py-2.5">Hour</th>
                                                    <th className="px-4 py-2.5 text-right">Predicted</th>
                                                    <th className="px-4 py-2.5 text-right">Range</th>
                                                    <th className="px-4 py-2.5 text-right">Load</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {forecast.slice(0, 12).map((row) => {
                                                    const badge = loadBadge(row.predicted);
                                                    return (
                                                        <tr
                                                            key={row.hour}
                                                            className="border-b border-healthcare-border last:border-0 transition-colors hover:bg-healthcare-background dark:border-healthcare-border-dark dark:hover:bg-healthcare-background-dark"
                                                        >
                                                            <td className="px-4 py-2.5 font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                {row.hour}
                                                            </td>
                                                            <td className="px-4 py-2.5 text-right tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                {row.predicted}
                                                            </td>
                                                            <td className="px-4 py-2.5 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                {row.lower}–{row.upper}
                                                            </td>
                                                            <td className="px-4 py-2.5 text-right">
                                                                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${badge.className}`}>
                                                                    <Icon icon={badge.icon} className="h-3.5 w-3.5" />
                                                                    {badge.label}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="flex h-[300px] items-center justify-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        No forecast rows.
                                    </div>
                                )}
                            </Card.Content>
                        </Card>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Arrival;
