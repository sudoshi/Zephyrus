import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import TrendChart from '@/Components/Common/TrendChart';

// ED Arrival Prediction rebuilt on the gold-standard design system: the KPI wall
// is one MetricGrid of KpiTiles, the forecast curve / diurnal profile / hourly
// board live in Panels under Section headers. This is a FORECAST page — the
// next-12h / next-24h tiles carry the real per-hour predicted series as a
// sparkline trajectory so the tile shows the forecast shape. All values are
// server-computed (population diurnal model until live ED visit data accrues);
// the page never fabricates a series.

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

    // Real predicted series (oldest→newest) drives the forecast sparklines on the
    // next-12h / next-24h tiles. Never fabricated — only present when forecast rows exist.
    const predictedSeries = hasForecast
        ? forecast.map((r) => Number(r.predicted) || 0)
        : null;
    const predicted12hSeries = predictedSeries ? predictedSeries.slice(0, 12) : null;

    const kpiMetrics = [
        metric({
            key: 'next-12h',
            label: kpis.next12h.label,
            value: Number(kpis.next12h.value ?? 0),
            display: integerFormatter(kpis.next12h.value),
            status: 'info',
            trajectory: predicted12hSeries,
            caption: kpis.next12h.description || undefined,
            definition: 'Total predicted ED arrivals across the next 12 hours.',
        }),
        metric({
            key: 'next-24h',
            label: kpis.next24h.label,
            value: Number(kpis.next24h.value ?? 0),
            display: integerFormatter(kpis.next24h.value),
            status: 'info',
            trajectory: predictedSeries,
            caption: kpis.next24h.description || undefined,
            definition: 'Total predicted ED arrivals across the next 24 hours.',
        }),
        metric({
            key: 'peak-hour',
            label: kpis.peakHour.label,
            value: Number(kpis.peakHour.count ?? 0),
            display: String(kpis.peakHour.value ?? '--:--'),
            status: 'warning',
            caption: kpis.peakHour.description || (kpis.peakHour.count ? `${kpis.peakHour.count} predicted arrivals` : undefined),
            definition: 'Hour of day with the highest predicted arrival volume in the horizon.',
        }),
        metric({
            key: 'current-rate',
            label: kpis.currentRate.label,
            value: Number(kpis.currentRate.value ?? 0),
            display: integerFormatter(kpis.currentRate.value),
            status: 'info',
            caption: kpis.currentRate.description || undefined,
            definition: 'Observed ED arrivals over the last 60 minutes, against the expected rate.',
        }),
    ];

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
                <div className="flex flex-col gap-5">
                    {!hasData && (
                        <Panel className="flex items-start gap-3 p-4">
                            <Icon icon="heroicons:information-circle" className="mt-0.5 h-5 w-5 shrink-0 text-healthcare-info dark:text-healthcare-info-dark" />
                            <div>
                                <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    No arrival history available
                                </p>
                                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    The forecast below uses a population-level diurnal model until ED visit data is recorded.
                                </p>
                            </div>
                        </Panel>
                    )}

                    {/* KPI wall */}
                    <Section
                        title="Forecast roll-up"
                        icon="heroicons:chart-bar-square"
                        summary={`${meta?.horizonHours ?? 24}h horizon${meta?.historyDays ? ` · ${meta.historyDays}d history` : ''}`}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    {/* Forecast curve */}
                    <Section
                        title={`Arrival Forecast — Next ${meta?.horizonHours ?? 24} Hours`}
                        icon="heroicons:presentation-chart-line"
                        summary="Predicted arrivals per hour with a 90% confidence band, overlaid on the historical hourly average"
                    >
                        <Panel className="p-4">
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
                                <EmptyState message="Forecast unavailable." icon="heroicons:presentation-chart-line" />
                            )}
                        </Panel>
                    </Section>

                    {/* Diurnal profile + hourly board */}
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
                        <Section
                            className="lg:col-span-3"
                            title="Diurnal Arrival Profile"
                            icon="heroicons:clock"
                            summary="Expected arrivals by hour of day, with observed history over the look-back window"
                        >
                            <Panel className="p-4">
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
                                    <EmptyState message="No profile data." icon="heroicons:clock" />
                                )}
                            </Panel>
                        </Section>

                        <Section
                            className="lg:col-span-2"
                            title="Hourly Forecast Board"
                            icon="heroicons:table-cells"
                            summary="Next 12 hours, with confidence range"
                        >
                            <Panel>
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
                                    <EmptyState message="No forecast rows." icon="heroicons:table-cells" />
                                )}
                            </Panel>
                        </Section>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Arrival;
