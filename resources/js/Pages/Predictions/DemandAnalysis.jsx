import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    ComposedChart,
    Area,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
} from 'recharts';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import { useDarkMode } from '@/hooks/useDarkMode';

// Demand Analysis rebuilt on the gold-standard design system: the KPI wall is one
// MetricGrid of KpiTiles (status + target + forecast sparkline), the forecast
// charts live in Panels under Section headers. Values are server-computed; the
// page renders empty states rather than fabricating data.

const DemandAnalysis = ({
    metrics = {},
    series = [],
    byService = [],
    hasData = false,
    projectionMethod = '',
}) => {
    const [selectedService, setSelectedService] = useState('all');
    const [isDarkMode] = useDarkMode();

    // Match the surrounding card surface so the lower-band area "cuts out" the
    // bottom of the upper band, leaving a shaded confidence interval (same
    // technique as Components/CommandCenter/ForecastCurve).
    const panelFill = isDarkMode ? '#1E293B' : '#FFFFFF';
    const axisColor = isDarkMode ? '#CBD5E1' : '#475569';
    const gridColor = isDarkMode ? '#334155' : '#E2E8F0';

    // Seasonal-patterns panel reuses the single-value LineChart format.
    const seasonalSeries = useMemo(
        () =>
            (series || [])
                .filter((p) => p.actual !== null && p.actual !== undefined)
                .map((p) => ({ month: p.label, value: p.actual })),
        [series]
    );

    // Growth-analysis: current vs projected monthly demand per service.
    const growthData = useMemo(
        () => ({
            labels: (byService || []).map((s) => s.service),
            datasets: [
                {
                    label: 'Current',
                    data: (byService || []).map((s) => s.current),
                    backgroundColor: 'var(--info)',
                    borderRadius: 4,
                    barPercentage: 0.6,
                },
                {
                    label: 'Projected',
                    data: (byService || []).map((s) => s.projected),
                    backgroundColor: 'var(--success)',
                    borderRadius: 4,
                    barPercentage: 0.6,
                },
            ],
        }),
        [byService]
    );

    // Real predicted volume trajectory: the monthly forecast series (actuals where
    // present, otherwise the projected value). Only emitted when ≥2 points exist —
    // never fabricated.
    const volumeTrajectory = useMemo(() => {
        const pts = (series || [])
            .map((p) => {
                const v = p.forecast ?? p.actual;
                return typeof v === 'number' ? v : null;
            })
            .filter((v) => v !== null);
        return pts.length >= 2 ? pts : null;
    }, [series]);

    const forecastTooltipFormatter = (value, name) => {
        const labelMap = {
            actual: 'Actual',
            forecast: 'Forecast',
            upper: 'Upper bound',
            lower: 'Lower bound',
        };
        if (value === null || value === undefined) return [null, null];
        return [`${value} cases`, labelMap[name] || name];
    };

    const fmtPct = (v) =>
        typeof v === 'number' ? `${v > 0 ? '+' : ''}${v.toFixed(1)}%` : v;

    const growth = Number(metrics.growthRatePct ?? 0);
    const accuracy = Number(metrics.modelAccuracyPct ?? 0);
    const seasonalityScore = Number(metrics.seasonalityScore ?? 0);

    const kpiMetrics = [
        metric({
            key: 'predicted-volume',
            label: 'Predicted Volume',
            value: Number(metrics.projectedVolume ?? 0),
            status: 'info',
            target: metrics.currentVolume != null ? Number(metrics.currentVolume) : null,
            targetDisplay: metrics.currentVolume != null ? `${Number(metrics.currentVolume).toLocaleString()} current` : null,
            trajectory: volumeTrajectory,
            caption: `${fmtPct(metrics.projectedDeltaPct)} vs current volume`,
            definition: 'Projected surgical case volume for the forecast horizon.',
        }),
        metric({
            key: 'growth-rate',
            label: 'Growth Rate',
            value: growth,
            display: fmtPct(growth),
            status: growth >= 0 ? 'success' : 'warning',
            caption: 'vs trailing average',
            definition: 'Month-over-month demand growth rate against the trailing average.',
        }),
        metric({
            key: 'seasonality',
            label: 'Seasonality',
            value: seasonalityScore,
            display: metrics.seasonalityLabel ?? '—',
            status: seasonalityScore >= 5 ? 'warning' : 'info',
            target: 10,
            targetDisplay: `${seasonalityScore}/10 impact`,
            caption: metrics.peakMonth ? `Peak ${metrics.peakMonth}` : 'Seasonal impact score',
            definition: 'Strength of seasonal demand variation (0–10) and its peak month.',
        }),
        metric({
            key: 'model-accuracy',
            label: 'Model Accuracy',
            value: accuracy,
            unit: '%',
            status: accuracy >= 85 ? 'success' : accuracy >= 70 ? 'warning' : 'critical',
            caption: 'In-sample fit',
            definition: 'In-sample fit of the demand projection model.',
        }),
    ];

    const filterControls = (
        <div className="flex items-center gap-3">
            <div className="relative">
                <select
                    value={selectedService}
                    onChange={(e) => setSelectedService(e.target.value)}
                    aria-label="Filter by service"
                    className="appearance-none rounded-md border-healthcare-border bg-healthcare-surface py-1.5 pl-7 pr-3 text-xs transition-colors duration-300 hover:border-healthcare-info dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:hover:border-healthcare-info-dark"
                >
                    <option value="all">All Services</option>
                    <option value="orthopedics">Orthopedics</option>
                    <option value="cardiology">Cardiology</option>
                    <option value="general">General Surgery</option>
                </select>
            </div>
            <DateRangeSelector />
        </div>
    );

    return (
        <DashboardLayout>
            <Head title="Demand Analysis - ZephyrusOR" />
            <PageContentLayout
                title="Demand Analysis"
                subtitle="Analyze and predict surgical service demand patterns"
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="Demand outlook"
                        icon="heroicons:chart-bar"
                        summary={metrics.seasonalityLabel ? `Seasonality: ${metrics.seasonalityLabel}` : 'Projected volume, growth & model fit'}
                        actions={filterControls}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <Section
                        title="Volume forecast"
                        icon="heroicons:presentation-chart-line"
                        summary="Historical actuals with projected demand and 95% confidence band"
                        actions={projectionMethod ? (
                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{projectionMethod}</span>
                        ) : undefined}
                    >
                        <Panel className="p-4">
                            {hasData && series.length > 0 ? (
                                <div
                                    className="h-[350px]"
                                    role="img"
                                    aria-label="Monthly surgical case volume: historical actuals with projected demand and 95% confidence band"
                                >
                                    <ResponsiveContainer width="100%" height="100%">
                                        <ComposedChart
                                            data={series}
                                            margin={{ top: 16, right: 24, left: 8, bottom: 8 }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke={gridColor}
                                                strokeOpacity={isDarkMode ? 0.3 : 1}
                                            />
                                            <XAxis
                                                dataKey="label"
                                                tick={{ fill: axisColor, fontSize: 12 }}
                                                tickSize={8}
                                            />
                                            <YAxis
                                                tick={{ fill: axisColor, fontSize: 12 }}
                                                width={48}
                                                label={{
                                                    value: 'Cases',
                                                    angle: -90,
                                                    position: 'insideLeft',
                                                    style: { fill: axisColor, fontSize: 12 },
                                                }}
                                            />
                                            <Tooltip
                                                formatter={forecastTooltipFormatter}
                                                contentStyle={{
                                                    backgroundColor: panelFill,
                                                    border: `1px solid ${gridColor}`,
                                                    borderRadius: 8,
                                                    color: axisColor,
                                                }}
                                            />
                                            <Legend wrapperStyle={{ fontSize: 12, color: axisColor }} />
                                            {/* Confidence band: upper area shaded, lower area cut out with the surface fill */}
                                            <Area
                                                type="monotone"
                                                dataKey="upper"
                                                name="Upper bound"
                                                stroke="none"
                                                fill="var(--info)"
                                                fillOpacity={0.16}
                                                connectNulls
                                                legendType="none"
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="lower"
                                                name="Lower bound"
                                                stroke="none"
                                                fill={panelFill}
                                                fillOpacity={1}
                                                connectNulls
                                                legendType="none"
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="actual"
                                                name="Actual"
                                                stroke="var(--info)"
                                                strokeWidth={2.5}
                                                dot={{ r: 3 }}
                                                connectNulls
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="forecast"
                                                name="Forecast"
                                                stroke="var(--success)"
                                                strokeWidth={2.5}
                                                strokeDasharray="5 4"
                                                dot={{ r: 3 }}
                                                connectNulls
                                            />
                                        </ComposedChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <EmptyState message="No case history available to forecast demand" icon="heroicons:chart-bar" />
                            )}
                        </Panel>
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Section
                            title="Seasonal patterns"
                            icon="heroicons:calendar"
                            summary="Monthly case volume showing seasonal demand"
                        >
                            <Panel className="p-4">
                                {hasData && seasonalSeries.length > 0 ? (
                                    <LineChart
                                        data={seasonalSeries}
                                        height={350}
                                        target={metrics.currentVolume ?? 0}
                                        ariaLabel="Monthly case volume showing seasonal demand patterns"
                                    />
                                ) : (
                                    <EmptyState message="No monthly history to chart" icon="heroicons:calendar" />
                                )}
                            </Panel>
                        </Section>

                        <Section
                            title="Growth by service"
                            icon="heroicons:arrow-trending-up"
                            summary="Current vs projected monthly demand per service"
                        >
                            <Panel className="p-4">
                                {hasData && byService.length > 0 ? (
                                    <div className="h-[350px] w-full">
                                        <BarChart
                                            data={growthData}
                                            options={{
                                                plugins: { legend: { display: true, position: 'top' } },
                                            }}
                                        />
                                    </div>
                                ) : (
                                    <EmptyState message="No service-level demand to compare" icon="heroicons:arrow-trending-up" />
                                )}
                            </Panel>
                        </Section>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default DemandAnalysis;
