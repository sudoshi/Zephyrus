import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import {
    ComposedChart,
    BarChart as RechartsBarChart,
    Bar,
    Line,
    Area,
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
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const ChartEmptyState = ({ message = 'No forecast data available yet' }) => (
    <div className="h-[350px] flex flex-col items-center justify-center gap-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <Icon icon="heroicons:presentation-chart-line" className="w-8 h-8 opacity-60" />
        <span className="text-sm">{message}</span>
    </div>
);

const ResourcePlanning = () => {
    const { resourcePlan } = usePage().props;
    const [selectedResource, setSelectedResource] = useState('all');
    const [isDarkMode] = useDarkMode();

    const plan = resourcePlan ?? { metrics: {}, series: [], requirements: [], byMonth: [], available: {}, hasData: false };
    const metrics = plan.metrics ?? {};
    const series = plan.series ?? [];
    const requirements = plan.requirements ?? [];
    const byMonth = plan.byMonth ?? [];
    const available = plan.available ?? {};
    const hasData = Boolean(plan.hasData);

    const theme = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];
    const gridColor = theme.border;
    const axisColor = theme.text;
    const surfaceColor = theme.surface;

    const fmtTrend = (val) => `${val > 0 ? '+' : ''}${val}`;

    const reqStaff = metrics.requiredStaff ?? {};
    const reqRooms = metrics.requiredRooms ?? {};
    const projUtil = metrics.projectedUtilization ?? {};
    const reqHours = metrics.requiredOrHours ?? {};

    return (
        <DashboardLayout>
            <Head title="Resource Planning - ZephyrusOR" />
            <PageContentLayout
                title="Resource Planning"
                subtitle="Plan and optimize resource allocation based on predictions"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select
                                            value={selectedResource}
                                            onChange={(e) => setSelectedResource(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Resources</option>
                                            <option value="staff">Staff</option>
                                            <option value="equipment">Equipment</option>
                                            <option value="rooms">Rooms</option>
                                        </select>
                                        <Icon
                                            icon="heroicons:users"
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
                            title="Required Staff"
                            value={reqStaff.value ?? 0}
                            trend={reqStaff.trend}
                            trendValue={(reqStaff.value ?? 0) - (reqStaff.previousValue ?? 0)}
                            trendFormatter={fmtTrend}
                            comparison="current"
                            icon="heroicons:users"
                            description={`${available.staff ?? 0} staffed for the active suite`}
                        />
                        <MetricsCard
                            title="Required Rooms"
                            value={reqRooms.value ?? 0}
                            trend={reqRooms.trend}
                            trendValue={(reqRooms.value ?? 0) - (reqRooms.previousValue ?? 0)}
                            trendFormatter={fmtTrend}
                            comparison="current"
                            icon="heroicons:building-office-2"
                            description={`${reqRooms.available ?? 0} rooms available`}
                        />
                        <MetricsCard
                            title="Projected Room Utilization"
                            value={projUtil.value ?? 0}
                            trend={projUtil.trend}
                            trendValue={Math.round(((projUtil.value ?? 0) - (projUtil.previousValue ?? 0)) * 10) / 10}
                            formatter={(val) => `${val}%`}
                            trendFormatter={(val) => `${val > 0 ? '+' : ''}${val}%`}
                            comparison="current"
                            icon="heroicons:chart-bar-square"
                        />
                        <MetricsCard
                            title="Required OR-Hours"
                            value={reqHours.value ?? 0}
                            trend={reqHours.trend}
                            trendValue={(reqHours.value ?? 0) - (reqHours.previousValue ?? 0)}
                            trendFormatter={fmtTrend}
                            formatter={(val) => `${val} hrs`}
                            comparison="current"
                            icon="heroicons:clock"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        {/* OR-hours forecast with confidence band (required resource demand) */}
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <div className="flex items-center justify-between mb-1">
                                        <h3 className="text-lg font-semibold">Resource Requirements</h3>
                                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Realized vs projected OR-hours
                                        </span>
                                    </div>
                                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
                                        {plan.projectionMethod}
                                    </p>
                                    {hasData && series.length > 0 ? (
                                        <div className="h-[350px]">
                                            <ResponsiveContainer width="100%" height="100%">
                                                <ComposedChart data={series} margin={{ top: 20, right: 30, left: 10, bottom: 30 }}>
                                                    <defs>
                                                        <linearGradient id="rpBand" x1="0" y1="0" x2="0" y2="1">
                                                            <stop offset="0%" stopColor={HEALTHCARE_COLORS.info} stopOpacity={0.18} />
                                                            <stop offset="100%" stopColor={HEALTHCARE_COLORS.info} stopOpacity={0.02} />
                                                        </linearGradient>
                                                    </defs>
                                                    <CartesianGrid strokeDasharray="3 3" stroke={gridColor} strokeOpacity={isDarkMode ? 0.25 : 1} />
                                                    <XAxis
                                                        dataKey="label"
                                                        tick={{ fill: axisColor, fontSize: 12 }}
                                                        angle={-30}
                                                        textAnchor="end"
                                                        height={50}
                                                    />
                                                    <YAxis
                                                        tick={{ fill: axisColor, fontSize: 12 }}
                                                        width={56}
                                                        label={{ value: 'OR-hours / mo', angle: -90, position: 'insideLeft', style: { fill: axisColor, fontSize: 12 } }}
                                                    />
                                                    <Tooltip
                                                        contentStyle={{ backgroundColor: surfaceColor, borderColor: gridColor, color: axisColor, borderRadius: 8 }}
                                                        labelStyle={{ color: axisColor }}
                                                        formatter={(value, name) => [`${value} hrs`, name]}
                                                    />
                                                    <Legend wrapperStyle={{ fontSize: 12, color: axisColor }} />
                                                    {/* Confidence band: upper as filled area, lower masks it back to baseline */}
                                                    <Area type="monotone" dataKey="upper" name="Upper bound" stroke="none" fill="url(#rpBand)" connectNulls />
                                                    <Area type="monotone" dataKey="lower" name="Lower bound" stroke="none" fill={surfaceColor} fillOpacity={1} connectNulls />
                                                    <Line type="monotone" dataKey="actual" name="Realized OR-hours" stroke={HEALTHCARE_COLORS.success} strokeWidth={3} dot={{ r: 3 }} connectNulls />
                                                    <Line type="monotone" dataKey="forecast" name="Projected OR-hours" stroke={HEALTHCARE_COLORS.info} strokeWidth={3} strokeDasharray="6 4" dot={{ r: 3 }} connectNulls />
                                                </ComposedChart>
                                            </ResponsiveContainer>
                                        </div>
                                    ) : (
                                        <ChartEmptyState />
                                    )}
                                </div>
                            </Card.Content>
                        </Card>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* Rooms required vs available capacity */}
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Room Capacity Outlook</h3>
                                        {hasData && requirements.length > 0 ? (
                                            <LineChart
                                                data={requirements.map((r) => ({ date: r.date, demand: r.demand, capacity: r.capacity }))}
                                                height={350}
                                                criticalThreshold={0}
                                                warningThreshold={1}
                                                ariaLabel="Projected required rooms versus available room capacity"
                                            />
                                        ) : (
                                            <ChartEmptyState />
                                        )}
                                    </div>
                                </Card.Content>
                            </Card>
                            {/* Projected staffing distribution by month */}
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Projected Staffing &amp; Sessions</h3>
                                        {hasData && byMonth.length > 0 ? (
                                            <div className="h-[350px]">
                                                <ResponsiveContainer width="100%" height="100%">
                                                    <RechartsBarChart data={byMonth} margin={{ top: 20, right: 20, left: 10, bottom: 20 }}>
                                                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} strokeOpacity={isDarkMode ? 0.25 : 1} vertical={false} />
                                                        <XAxis dataKey="month" tick={{ fill: axisColor, fontSize: 12 }} />
                                                        <YAxis tick={{ fill: axisColor, fontSize: 12 }} width={44} />
                                                        <Tooltip
                                                            cursor={{ fill: gridColor, fillOpacity: 0.15 }}
                                                            contentStyle={{ backgroundColor: surfaceColor, borderColor: gridColor, color: axisColor, borderRadius: 8 }}
                                                            labelStyle={{ color: axisColor }}
                                                            formatter={(value, name) => [`${value}`, name]}
                                                        />
                                                        <Legend wrapperStyle={{ fontSize: 12, color: axisColor }} />
                                                        <ReferenceLine y={available.staff ?? 0} stroke={HEALTHCARE_COLORS.warning} strokeDasharray="4 4" label={{ value: 'Staffed', position: 'right', fill: HEALTHCARE_COLORS.warning, fontSize: 11 }} />
                                                        <Bar dataKey="staff" name="Required staff" fill={HEALTHCARE_COLORS.info} radius={[4, 4, 0, 0]} maxBarSize={36} />
                                                        <Bar dataKey="rooms" name="Required rooms" fill={HEALTHCARE_COLORS.success} radius={[4, 4, 0, 0]} maxBarSize={36} />
                                                    </RechartsBarChart>
                                                </ResponsiveContainer>
                                            </div>
                                        ) : (
                                            <ChartEmptyState />
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

export default ResourcePlanning;
