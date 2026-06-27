import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
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
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { useDarkMode } from '@/hooks/useDarkMode';

const EmptyChart = ({ message = 'No forecast data available yet' }) => (
    <div className="h-[350px] flex flex-col items-center justify-center gap-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <Icon icon="heroicons:chart-bar" className="w-8 h-8 opacity-60" />
        <span className="text-sm">{message}</span>
    </div>
);

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

    return (
        <DashboardLayout>
            <Head title="Demand Analysis - ZephyrusOR" />
            <PageContentLayout
                title="Demand Analysis"
                subtitle="Analyze and predict surgical service demand patterns"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedService}
                                            onChange={(e) => setSelectedService(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Services</option>
                                            <option value="orthopedics">Orthopedics</option>
                                            <option value="cardiology">Cardiology</option>
                                            <option value="general">General Surgery</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:squares-2x2" 
                                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                        />
                                    </div>
                                </div>
                                <DateRangeSelector />
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Metrics Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <MetricsCard
                            title="Predicted Volume"
                            value={metrics.projectedVolume ?? 0}
                            formatter={(v) => <span className="tabular-nums">{v}</span>}
                            icon="heroicons:chart-bar"
                            trend={metrics.projectedTrend}
                            trendValue={metrics.projectedDeltaPct}
                            trendFormatter={fmtPct}
                            comparison="current volume"
                        />
                        <MetricsCard
                            title="Growth Rate"
                            value={metrics.growthRatePct ?? 0}
                            formatter={(v) => <span className="tabular-nums">{fmtPct(v)}</span>}
                            icon="heroicons:arrow-trending-up"
                            trend={metrics.growthTrend}
                            comparison="trailing average"
                        />
                        <MetricsCard
                            title="Seasonality"
                            value={metrics.seasonalityLabel ?? '—'}
                            icon="heroicons:calendar"
                            trend={metrics.seasonalityScore >= 5 ? 'up' : undefined}
                            trendValue={metrics.seasonalityScore}
                            trendFormatter={(v) => `${v}/10`}
                            comparison={metrics.peakMonth ? `peak ${metrics.peakMonth}` : 'impact score'}
                        />
                        <MetricsCard
                            title="Model Accuracy"
                            value={metrics.modelAccuracyPct ?? 0}
                            formatter={(v) => <span className="tabular-nums">{v}%</span>}
                            icon="heroicons:check-circle"
                            trend={metrics.modelAccuracyTrend}
                            comparison="in-sample fit"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <div className="flex items-baseline justify-between mb-4">
                                        <h3 className="text-lg font-semibold">Volume Forecast</h3>
                                        {projectionMethod && (
                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {projectionMethod}
                                            </span>
                                        )}
                                    </div>
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
                                        <EmptyChart message="No case history available to forecast demand" />
                                    )}
                                </div>
                            </Card.Content>
                        </Card>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Seasonal Patterns</h3>
                                        {hasData && seasonalSeries.length > 0 ? (
                                            <LineChart
                                                data={seasonalSeries}
                                                height={350}
                                                target={metrics.currentVolume ?? 0}
                                                ariaLabel="Monthly case volume showing seasonal demand patterns"
                                            />
                                        ) : (
                                            <EmptyChart message="No monthly history to chart" />
                                        )}
                                    </div>
                                </Card.Content>
                            </Card>
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Growth Analysis by Service</h3>
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
                                            <EmptyChart message="No service-level demand to compare" />
                                        )}
                                    </div>
                                </Card.Content>
                            </Card>
                        </div>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default DemandAnalysis;
