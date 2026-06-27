import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
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
    ReferenceLine,
    ResponsiveContainer,
} from 'recharts';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import { BarChart } from '@/Components/Dashboard/Charts/BarChart';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

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

const trendDirection = (value) => {
    if (value > 0) return 'up';
    if (value < 0) return 'down';
    return 'neutral';
};

const ChartEmptyState = ({ message }) => (
    <div className="h-[350px] flex flex-col items-center justify-center gap-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <Icon icon="heroicons:chart-bar" className="w-8 h-8 opacity-50" />
        <p className="text-sm font-medium">{message}</p>
    </div>
);

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

    return (
        <DashboardLayout>
            <Head title="Utilization Forecast - ZephyrusOR" />
            <PageContentLayout
                title="Utilization Forecast"
                subtitle="Predict future OR utilization patterns and trends"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select
                                            value={selectedTimeframe}
                                            onChange={onTimeframeChange}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="month">Next Month</option>
                                            <option value="quarter">Next Quarter</option>
                                            <option value="year">Next Year</option>
                                        </select>
                                        <Icon
                                            icon="heroicons:calendar"
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
                            title="Predicted Utilization"
                            value={`${metrics.predictedUtilization}%`}
                            trend={trendDirection(metrics.predictedUtilizationTrend)}
                            trendValue={`${metrics.predictedUtilizationTrend > 0 ? '+' : ''}${metrics.predictedUtilizationTrend}%`}
                            comparison="current"
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Confidence Level"
                            value={`${metrics.confidence}%`}
                            trend="neutral"
                            description={`±${metrics.predictionRange}% prediction range`}
                            comparison={null}
                            icon="heroicons:check-circle"
                        />
                        <MetricsCard
                            title="Projected Case Volume"
                            value={metrics.projectedCaseVolume.toLocaleString()}
                            trend={trendDirection(metrics.projectedCaseTrend)}
                            trendValue={`${metrics.projectedCaseTrend > 0 ? '+' : ''}${metrics.projectedCaseTrend}%`}
                            comparison="current/mo"
                            icon="heroicons:rectangle-stack"
                        />
                        <MetricsCard
                            title="Bottleneck Risk"
                            value={metrics.bottleneckRisk}
                            trend={metrics.bottleneckRiskLevel === 'critical' || metrics.bottleneckRiskLevel === 'warning' ? 'down' : 'up'}
                            description={`${metrics.historicalAccuracy}% historical accuracy`}
                            comparison={null}
                            icon="heroicons:exclamation-triangle"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Utilization Forecast</h3>
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
                                        <ChartEmptyState message="No utilization history available to forecast." />
                                    )}
                                </div>
                            </Card.Content>
                        </Card>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Prediction Intervals</h3>
                                        {intervalData.length > 0 ? (
                                            <LineChart
                                                data={intervalData}
                                                height={350}
                                                target={metrics.targetUtilization}
                                                ariaLabel="Projected OR utilization by forecast period"
                                            />
                                        ) : (
                                            <ChartEmptyState message="No forecast periods to display." />
                                        )}
                                    </div>
                                </Card.Content>
                            </Card>
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Contributing Factors</h3>
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
                                            <ChartEmptyState message="No contributing-factor data available." />
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

export default UtilizationForecast;
