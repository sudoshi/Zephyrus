import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    ComposedChart,
    Area,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ReferenceLine,
    ResponsiveContainer,
} from 'recharts';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import { BarChart } from '@/Components/Dashboard/Charts/BarChart';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

// Utilization Forecast rebuilt on the gold-standard design system: the KPI wall is
// one MetricGrid of KpiTiles (status + target + forecast sparkline), the forecast
// charts live in Panels under Section headers, and the horizon toggle moves into
// the Section actions slot. Values are server-computed; empty states render rather
// than fabricating data.

const EMPTY_FORECAST = {
    metrics: {
        predictedUtilization: 0,
        predictedUtilizationTrend: 0,
        currentUtilization: 0,
        confidence: 0,
        predictionRange: 0,
        historicalAccuracy: 0,
        projectedCaseVolume: 0,
        projectedCaseTrend: 0,
        bottleneckRisk: 'Unknown',
        bottleneckRiskLevel: 'info',
        targetUtilization: 80,
        horizonMonths: 1,
        timeframe: 'month',
    },
    series: [],
    factors: [],
    hasData: false,
};

const UtilizationForecast = ({ forecast = EMPTY_FORECAST }) => {
    const { metrics, series, factors, hasData } = forecast;
    const [isDarkMode] = useDarkMode();
    const themeColors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];
    const [selectedTimeframe, setSelectedTimeframe] = useState(metrics.timeframe || 'month');

    const onTimeframeChange = (event) => {
        const value = event.target.value;
        setSelectedTimeframe(value);
        router.reload({
            data: { timeframe: value },
            only: ['forecast'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Forecast-only points for the project LineChart (hasSingleValue format).
    const intervalData = useMemo(
        () =>
            series
                .filter((point) => point.type === 'forecast')
                .map((point) => ({ month: point.label, value: point.forecast })),
        [series],
    );

    // Real predicted-utilization trajectory from the forecast series (actual where
    // present, otherwise forecast). Only emitted when ≥2 points exist.
    const utilizationTrajectory = useMemo(() => {
        const pts = (series || [])
            .map((p) => {
                const v = p.actual ?? p.forecast;
                return typeof v === 'number' ? v : null;
            })
            .filter((v) => v !== null);
        return pts.length >= 2 ? pts : null;
    }, [series]);

    // Contributing-factor weights as a horizontal chart.js bar chart.
    const factorChart = useMemo(
        () => ({
            labels: factors.map((factor) => factor.name),
            datasets: [
                {
                    label: 'Relative impact',
                    data: factors.map((factor) => factor.impact),
                    backgroundColor: 'rgba(96, 165, 250, 0.55)',
                    borderColor: 'rgb(96, 165, 250)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
            ],
        }),
        [factors],
    );

    const ForecastTooltip = ({ active, payload, label }) => {
        if (!active || !payload || payload.length === 0) return null;
        const datum = payload[0].payload;
        return (
            <div
                className="p-3 rounded-lg border shadow-lg bg-healthcare-surface dark:bg-healthcare-surface-dark border-healthcare-border dark:border-healthcare-border-dark"
            >
                <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {label}
                </p>
                <div className="mt-1 space-y-0.5 text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {datum.actual != null && <p>Actual: {datum.actual}%</p>}
                    {datum.forecast != null && datum.actual == null && (
                        <>
                            <p>Forecast: {datum.forecast}%</p>
                            <p>
                                Range: {datum.lower}% – {datum.upper}%
                            </p>
                        </>
                    )}
                </div>
            </div>
        );
    };

    const utilTrend = Number(metrics.predictedUtilizationTrend ?? 0);
    const caseTrend = Number(metrics.projectedCaseTrend ?? 0);
    const predictedUtil = Number(metrics.predictedUtilization ?? 0);
    const target = Number(metrics.targetUtilization ?? 80);
    const riskLevel = ['critical', 'warning', 'success', 'info', 'neutral'].includes(metrics.bottleneckRiskLevel)
        ? metrics.bottleneckRiskLevel
        : 'info';

    const kpiMetrics = [
        metric({
            key: 'predicted-utilization',
            label: 'Predicted Utilization',
            value: predictedUtil,
            unit: '%',
            status: predictedUtil >= 90 ? 'critical' : predictedUtil >= target ? 'success' : 'warning',
            target,
            trajectory: utilizationTrajectory,
            caption: `${utilTrend > 0 ? '+' : ''}${utilTrend}% vs current`,
            definition: 'Projected operating-room utilization for the selected horizon.',
        }),
        metric({
            key: 'confidence',
            label: 'Confidence Level',
            value: Number(metrics.confidence ?? 0),
            unit: '%',
            status: (metrics.confidence ?? 0) >= 80 ? 'success' : (metrics.confidence ?? 0) >= 60 ? 'warning' : 'critical',
            caption: `±${metrics.predictionRange ?? 0}% prediction range`,
            definition: 'Model confidence in the utilization forecast, with the prediction interval.',
        }),
        metric({
            key: 'projected-case-volume',
            label: 'Projected Case Volume',
            value: Number(metrics.projectedCaseVolume ?? 0),
            status: 'info',
            caption: `${caseTrend > 0 ? '+' : ''}${caseTrend}% vs current/mo`,
            definition: 'Projected surgical case volume for the selected horizon.',
        }),
        metric({
            key: 'bottleneck-risk',
            label: 'Bottleneck Risk',
            value: 0,
            display: metrics.bottleneckRisk ?? 'Unknown',
            status: riskLevel,
            caption: `${metrics.historicalAccuracy ?? 0}% historical accuracy`,
            definition: 'Forecasted risk of an OR throughput bottleneck over the horizon.',
        }),
    ];

    const horizonControls = (
        <div className="flex items-center gap-3">
            <div className="relative">
                <select
                    value={selectedTimeframe}
                    onChange={onTimeframeChange}
                    aria-label="Forecast horizon"
                    className="appearance-none rounded-md border-healthcare-border bg-healthcare-surface py-1.5 pl-7 pr-3 text-xs transition-colors duration-300 hover:border-healthcare-info dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:hover:border-healthcare-info-dark"
                >
                    <option value="month">Next Month</option>
                    <option value="quarter">Next Quarter</option>
                    <option value="year">Next Year</option>
                </select>
            </div>
            <DateRangeSelector />
        </div>
    );

    return (
        <DashboardLayout>
            <Head title="Utilization Forecast - ZephyrusOR" />
            <PageContentLayout
                title="Utilization Forecast"
                subtitle="Predict future OR utilization patterns and trends"
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="Forecast outlook"
                        icon="heroicons:chart-bar"
                        summary="Projected utilization, confidence, volume & bottleneck risk"
                        actions={horizonControls}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <Section
                        title="Utilization forecast"
                        icon="heroicons:presentation-chart-line"
                        summary="Historical actuals with projected utilization and confidence band"
                    >
                        <Panel className="p-4">
                            {hasData && series.length > 0 ? (
                                <div className="h-[350px]">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <ComposedChart
                                            data={series}
                                            margin={{ top: 20, right: 32, left: 8, bottom: 24 }}
                                        >
                                            <defs>
                                                <linearGradient id="forecastBand" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="0%" stopColor="var(--healthcare-info)" stopOpacity={0.22} />
                                                    <stop offset="100%" stopColor="var(--healthcare-info)" stopOpacity={0.04} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke={themeColors.border}
                                                strokeOpacity={isDarkMode ? 0.25 : 1}
                                            />
                                            <XAxis
                                                dataKey="label"
                                                tick={{ fill: themeColors.text, fontSize: 12 }}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                domain={['dataMin - 8', 'dataMax + 8']}
                                                tick={{ fill: themeColors.text, fontSize: 12 }}
                                                tickLine={false}
                                                width={44}
                                                tickFormatter={(value) => `${value}%`}
                                            />
                                            <Tooltip content={<ForecastTooltip />} />
                                            <Legend wrapperStyle={{ fontSize: '12px', color: themeColors.text }} />
                                            <ReferenceLine
                                                y={metrics.targetUtilization}
                                                stroke="var(--healthcare-warning)"
                                                strokeDasharray="4 4"
                                                label={{
                                                    value: `Target ${metrics.targetUtilization}%`,
                                                    position: 'right',
                                                    fill: 'var(--healthcare-warning)',
                                                    fontSize: 11,
                                                }}
                                            />
                                            {/* Confidence band: upper bound area down to lower bound */}
                                            <Area
                                                type="monotone"
                                                dataKey="upper"
                                                name="Confidence range"
                                                stroke="none"
                                                fill="url(#forecastBand)"
                                                connectNulls
                                                isAnimationActive={false}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="lower"
                                                stroke="none"
                                                fill={isDarkMode ? '#0E0E11' : '#FFFFFF'}
                                                connectNulls
                                                legendType="none"
                                                isAnimationActive={false}
                                            />
                                            {/* Historical actuals: solid line */}
                                            <Line
                                                type="monotone"
                                                dataKey="actual"
                                                name="Actual"
                                                stroke="var(--healthcare-info)"
                                                strokeWidth={2.5}
                                                dot={{ r: 3 }}
                                                activeDot={{ r: 5 }}
                                                connectNulls={false}
                                                isAnimationActive={false}
                                            />
                                            {/* Forecast: dashed line */}
                                            <Line
                                                type="monotone"
                                                dataKey="forecast"
                                                name="Forecast"
                                                stroke="var(--healthcare-success)"
                                                strokeWidth={2.5}
                                                strokeDasharray="5 4"
                                                dot={{ r: 3 }}
                                                activeDot={{ r: 5 }}
                                                connectNulls
                                                isAnimationActive={false}
                                            />
                                        </ComposedChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <EmptyState message="No utilization history available to forecast." icon="heroicons:chart-bar" />
                            )}
                        </Panel>
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Section
                            title="Prediction intervals"
                            icon="heroicons:chart-bar-square"
                            summary="Projected OR utilization by forecast period"
                        >
                            <Panel className="p-4">
                                {intervalData.length > 0 ? (
                                    <LineChart
                                        data={intervalData}
                                        height={350}
                                        target={metrics.targetUtilization}
                                        ariaLabel="Projected OR utilization by forecast period"
                                    />
                                ) : (
                                    <EmptyState message="No forecast periods to display." icon="heroicons:chart-bar-square" />
                                )}
                            </Panel>
                        </Section>

                        <Section
                            title="Contributing factors"
                            icon="heroicons:scale"
                            summary="Relative impact of demand drivers"
                        >
                            <Panel className="p-4">
                                {factors.length > 0 ? (
                                    <div className="h-[350px]">
                                        <BarChart
                                            data={factorChart}
                                            options={{
                                                indexAxis: 'y',
                                                plugins: { legend: { display: false } },
                                            }}
                                        />
                                    </div>
                                ) : (
                                    <EmptyState message="No contributing-factor data available." icon="heroicons:scale" />
                                )}
                            </Panel>
                        </Section>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default UtilizationForecast;
