import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { useDarkMode } from '@/hooks/useDarkMode';
import { formatDurationHours } from '@/lib/duration';

/**
 * ED › Predictions › Acuity Prediction
 *
 * Predicted ESI acuity mix for incoming patients over the next four hours,
 * computed live by App\Services\Ed\AcuityPredictionService from the historical
 * ESI-level proportions in prod.ed_visits plus a deterministic hourly arrival
 * profile. Rebuilt on the gold-standard design system: the KPI wall is one
 * MetricGrid of KpiTiles, charts/tables/mix-lists live in Panels under Section
 * headers. Status is always paired with an icon + label, never colour alone.
 * All values are server-computed; the page renders empty states rather than
 * fabricating data.
 */

// ESI acuity → design-system status tone (high acuity escalates the tone).
// Mirrors the shipped esiBadge mapping on Pages/ED/Analytics/WaitTime.jsx.
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

// CSS custom property each ESI tone resolves to. These hex vars are defined in
// resources/css/app.css and auto-flip under `.dark`, so reading them gives the
// theme-correct chart colour without hardcoding palette values.
const ESI_COLOR_VAR = {
    1: '--healthcare-critical',
    2: '--healthcare-warning',
    3: '--healthcare-info',
    4: '--healthcare-success',
    5: '--healthcare-success',
};

const MixBar = ({ row }) => {
    const pct = Math.max(0, Math.min(100, row.percent ?? 0));
    // Reuse the badge tone for the fill so the row's colour matches its ESI badge.
    const fill = esiBadgeClass(row.esi).split(' ').find((c) => c.startsWith('text-')) ?? 'text-healthcare-info';
    const bgFromText = fill.replace('text-', 'bg-');
    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between">
                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${esiBadgeClass(row.esi)}`}>
                    {row.short}
                </span>
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span className="font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.count}</span>
                    {' · '}
                    <span className="tabular-nums">{pct}%</span>
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                <div className={`h-full rounded-full ${bgFromText}`} style={{ width: `${pct}%` }} aria-hidden="true" />
            </div>
        </div>
    );
};

export default function Acuity({
    generatedAt = null,
    kpis = null,
    historicalMix = [],
    liveMix = [],
    predictedMix = { hours: [], series: [], totals: [] },
    forecastRows = [],
}) {
    const [isDarkMode] = useDarkMode();

    // Loading guard: Inertia always sends props, but tolerate a partial/initial
    // render without throwing.
    if (!kpis) {
        return (
            <DashboardLayout>
                <Head title="Acuity Prediction - Emergency" />
                <PageContentLayout title="Acuity Prediction" subtitle="Forecast patient acuity mix">
                    <Panel className="p-4">
                        <EmptyState icon="heroicons:cpu-chip" message="Loading acuity forecast…" />
                    </Panel>
                </PageContentLayout>
            </DashboardLayout>
        );
    }

    // Resolve theme-correct ESI fill colours from CSS custom properties so the
    // stacked chart matches the badge tones in both light and dark themes.
    // `isDarkMode` is a dependency so colours re-read on theme toggle.
    const esiColors = useMemo(() => {
        if (typeof window === 'undefined') return {};
        const styles = getComputedStyle(document.documentElement);
        const out = {};
        Object.entries(ESI_COLOR_VAR).forEach(([esi, varName]) => {
            out[esi] = styles.getPropertyValue(varName).trim() || '#60A5FA';
        });
        return out;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isDarkMode]);

    const hours = predictedMix?.hours ?? [];
    const series = predictedMix?.series ?? [];
    const hasForecast = hours.length > 0 && series.some((s) => (s.total ?? 0) > 0);

    // Chart.js stacked-bar series: one stack segment per ESI level per hour.
    const chartData = useMemo(
        () => ({
            labels: hours,
            datasets: series.map((s) => ({
                label: s.short,
                data: s.data ?? [],
                backgroundColor: esiColors[s.esi] ?? '#60A5FA',
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.7,
                stack: 'esi',
            })),
        }),
        [hours, series, esiColors]
    );

    const chartOptions = useMemo(
        () => ({
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true },
                },
            },
            scales: {
                x: { stacked: true },
                y: { stacked: true, ticks: { precision: 0 } },
            },
        }),
        []
    );

    const liveHasData = liveMix.some((r) => (r.count ?? 0) > 0);
    const histHasData = historicalMix.some((r) => (r.count ?? 0) > 0);

    // KPI wall — predicted-vs-live acuity headline numbers. Status pairs the
    // high-acuity trend with tone; never colour alone (label + value carry it).
    const kpiMetrics = [
        metric({
            key: 'predicted-arrivals', label: 'Predicted arrivals', value: Number(kpis.predictedArrivals ?? 0),
            status: 'info', caption: `Next ${formatDurationHours(kpis.horizonHours ?? null)}`,
            definition: 'Forecast patient arrivals over the prediction horizon.',
        }),
        metric({
            key: 'predicted-high-acuity', label: 'Predicted high acuity', value: Number(kpis.predictedHighAcuityPct ?? 0), unit: '%',
            status: kpis.highAcuityTrend === 'up' ? 'warning' : 'success',
            caption: `${kpis.predictedHighAcuity} ESI 1-2 · baseline ${kpis.baselineHighAcuityPct}%`,
            definition: 'Predicted share of incoming ESI 1-2 patients vs the 30-day baseline.',
        }),
        metric({
            key: 'live-high-acuity', label: 'Live high acuity', value: Number(kpis.liveHighAcuityPct ?? 0), unit: '%',
            status: 'info', caption: `${kpis.liveHighAcuity} ESI 1-2 in department`,
            definition: 'Share of patients currently in the ED triaged ESI 1-2.',
        }),
        metric({
            key: 'dominant-acuity', label: 'Dominant acuity', value: Number(kpis.dominantEsiPct ?? 0), unit: '%',
            display: String(kpis.dominantEsi), status: 'neutral',
            caption: `${kpis.dominantEsiPct}% of historical mix`,
            definition: 'Most common ESI level across the 30-day calibration window.',
        }),
    ];

    return (
        <DashboardLayout>
            <Head title="Acuity Prediction - Emergency" />
            <PageContentLayout
                title="Acuity Prediction"
                subtitle={`Predicted ESI acuity mix for incoming patients over the next ${formatDurationHours(Number(kpis.horizonHours ?? 4))}`}
                headerContent={
                    <div className="flex items-center space-x-2 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark px-3 py-2">
                        <Icon
                            icon="heroicons:cpu-chip"
                            className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                            aria-hidden="true"
                        />
                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Calibrated on{' '}
                            <span className="font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {kpis.sampleSize}
                            </span>{' '}
                            visits
                        </span>
                    </div>
                }
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="Acuity forecast"
                        icon="heroicons:cpu-chip"
                        summary={`Next ${formatDurationHours(kpis.horizonHours ?? null)} · calibrated on ${kpis.sampleSize} visits`}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                        {/* Predicted acuity mix — stacked ESI by forecast hour */}
                        <Section
                            className="lg:col-span-2"
                            title="Predicted acuity mix"
                            icon="heroicons:presentation-chart-bar"
                            summary="Projected ESI breakdown of incoming patients per hour"
                            actions={
                                <span className="flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    <Icon icon="heroicons:cpu-chip" className="h-4 w-4" aria-hidden="true" />
                                    <span className="tabular-nums">{kpis.modelConfidence}%</span> confidence
                                </span>
                            }
                        >
                            <Panel className="p-4">
                                {hasForecast ? (
                                    <div className="h-80">
                                        <BarChart data={chartData} options={chartOptions} />
                                    </div>
                                ) : (
                                    <EmptyState
                                        icon="heroicons:presentation-chart-bar"
                                        message="No arrival history available to project an acuity mix."
                                    />
                                )}
                            </Panel>
                        </Section>

                        {/* Live department acuity mix */}
                        <Section
                            title="Live department mix"
                            icon="heroicons:users"
                            summary="Acuity of patients currently in the ED"
                        >
                            <Panel className="p-4">
                                {liveHasData ? (
                                    <div className="space-y-4">
                                        {liveMix.map((row) => (
                                            <MixBar key={`live-${row.esi}`} row={row} />
                                        ))}
                                    </div>
                                ) : (
                                    <EmptyState icon="heroicons:users" message="No patients currently in the department." />
                                )}
                            </Panel>
                        </Section>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        {/* Per-ESI forecast summary table */}
                        <Section
                            title="Forecast by acuity"
                            icon="heroicons:rectangle-stack"
                            summary="Predicted incoming volume per ESI level"
                        >
                            <Panel className="p-4">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        <thead>
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Acuity
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Predicted
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Share
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {forecastRows.map((row) => (
                                                <tr
                                                    key={`fc-${row.esi}`}
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
                                                        {row.predicted}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {row.percent}%
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Panel>
                        </Section>

                        {/* Calibration: historical acuity mix */}
                        <Section
                            title="Calibration mix (30-day)"
                            icon="heroicons:clock"
                            summary="Observed ESI proportions the forecast is trained on"
                        >
                            <Panel className="p-4">
                                {histHasData ? (
                                    <div className="space-y-4">
                                        {historicalMix.map((row) => (
                                            <MixBar key={`hist-${row.esi}`} row={row} />
                                        ))}
                                    </div>
                                ) : (
                                    <EmptyState icon="heroicons:clock" message="No historical visits to calibrate against." />
                                )}
                            </Panel>
                        </Section>
                    </div>

                    {generatedAt && (
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Forecast generated {new Date(generatedAt).toLocaleString()}
                        </p>
                    )}
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
