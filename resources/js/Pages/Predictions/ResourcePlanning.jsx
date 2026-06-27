import React, { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
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
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import LineChart from '@/Components/Dashboard/Charts/LineChart';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

// Resource Planning rebuilt on the gold-standard design system: the KPI wall is
// one MetricGrid of KpiTiles (status + target + forecast sparkline), the forecast
// charts live in Panels under Section headers. Values are server-computed; empty
// states render rather than fabricating data.

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

    const reqStaff = metrics.requiredStaff ?? {};
    const reqRooms = metrics.requiredRooms ?? {};
    const projUtil = metrics.projectedUtilization ?? {};
    const reqHours = metrics.requiredOrHours ?? {};

    // Real projected OR-hours trajectory from the forecast series (actual where
    // present, otherwise projected). Only emitted when ≥2 points exist.
    const orHoursTrajectory = useMemo(() => {
        const pts = (series || [])
            .map((p) => {
                const v = p.forecast ?? p.actual;
                return typeof v === 'number' ? v : null;
            })
            .filter((v) => v !== null);
        return pts.length >= 2 ? pts : null;
    }, [series]);

    // Real projected staff/room trajectories from the monthly plan.
    const staffTrajectory = useMemo(() => {
        const pts = (byMonth || []).map((m) => m.staff).filter((v) => typeof v === 'number');
        return pts.length >= 2 ? pts : null;
    }, [byMonth]);
    const roomsTrajectory = useMemo(() => {
        const pts = (byMonth || []).map((m) => m.rooms).filter((v) => typeof v === 'number');
        return pts.length >= 2 ? pts : null;
    }, [byMonth]);

    const staffDelta = (reqStaff.value ?? 0) - (reqStaff.previousValue ?? 0);
    const roomsDelta = (reqRooms.value ?? 0) - (reqRooms.previousValue ?? 0);
    const utilDelta = Math.round(((projUtil.value ?? 0) - (projUtil.previousValue ?? 0)) * 10) / 10;
    const hoursDelta = (reqHours.value ?? 0) - (reqHours.previousValue ?? 0);
    const sign = (v) => `${v > 0 ? '+' : ''}${v}`;
    const utilValue = Number(projUtil.value ?? 0);

    const kpiMetrics = [
        metric({
            key: 'required-staff',
            label: 'Required Staff',
            value: Number(reqStaff.value ?? 0),
            status: (reqStaff.value ?? 0) > (available.staff ?? Infinity) ? 'warning' : 'info',
            target: available.staff != null ? Number(available.staff) : null,
            targetDisplay: available.staff != null ? `${available.staff} staffed` : null,
            trajectory: staffTrajectory,
            caption: `${sign(staffDelta)} vs current · ${available.staff ?? 0} staffed for the active suite`,
            definition: 'Projected staff required to cover forecasted demand across the active suite.',
        }),
        metric({
            key: 'required-rooms',
            label: 'Required Rooms',
            value: Number(reqRooms.value ?? 0),
            status: (reqRooms.value ?? 0) > (reqRooms.available ?? Infinity) ? 'warning' : 'info',
            target: reqRooms.available != null ? Number(reqRooms.available) : null,
            targetDisplay: reqRooms.available != null ? `${reqRooms.available} available` : null,
            trajectory: roomsTrajectory,
            caption: `${sign(roomsDelta)} vs current · ${reqRooms.available ?? 0} rooms available`,
            definition: 'Projected operating rooms required to meet forecasted case volume.',
        }),
        metric({
            key: 'projected-utilization',
            label: 'Projected Room Utilization',
            value: utilValue,
            unit: '%',
            status: utilValue >= 90 ? 'critical' : utilValue >= 75 ? 'success' : 'warning',
            target: 80,
            caption: `${utilDelta > 0 ? '+' : ''}${utilDelta}% vs current`,
            definition: 'Projected operating-room utilization for the forecast horizon. Target 80%.',
        }),
        metric({
            key: 'required-or-hours',
            label: 'Required OR-Hours',
            value: Number(reqHours.value ?? 0),
            display: `${Number(reqHours.value ?? 0).toLocaleString()} hrs`,
            status: 'info',
            trajectory: orHoursTrajectory,
            caption: `${sign(hoursDelta)} hrs vs current`,
            definition: 'Projected operating-room hours required to meet forecasted demand.',
        }),
    ];

    const filterControls = (
        <div className="flex items-center gap-3">
            <div className="relative">
                <select
                    value={selectedResource}
                    onChange={(e) => setSelectedResource(e.target.value)}
                    aria-label="Filter by resource"
                    className="appearance-none rounded-md border-healthcare-border bg-healthcare-surface py-1.5 pl-7 pr-3 text-xs transition-colors duration-300 hover:border-healthcare-info dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:hover:border-healthcare-info-dark"
                >
                    <option value="all">All Resources</option>
                    <option value="staff">Staff</option>
                    <option value="equipment">Equipment</option>
                    <option value="rooms">Rooms</option>
                </select>
            </div>
            <DateRangeSelector />
        </div>
    );

    return (
        <DashboardLayout>
            <Head title="Resource Planning - ZephyrusOR" />
            <PageContentLayout
                title="Resource Planning"
                subtitle="Plan and optimize resource allocation based on predictions"
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="Resource outlook"
                        icon="heroicons:users"
                        summary="Projected staff, rooms, utilization & OR-hours vs current"
                        actions={filterControls}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <Section
                        title="Resource requirements"
                        icon="heroicons:clock"
                        summary="Realized vs projected OR-hours"
                        actions={plan.projectionMethod ? (
                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{plan.projectionMethod}</span>
                        ) : undefined}
                    >
                        <Panel className="p-4">
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
                                <EmptyState message="No forecast data available yet" icon="heroicons:presentation-chart-line" />
                            )}
                        </Panel>
                    </Section>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Section
                            title="Room capacity outlook"
                            icon="heroicons:building-office-2"
                            summary="Projected required rooms vs available capacity"
                        >
                            <Panel className="p-4">
                                {hasData && requirements.length > 0 ? (
                                    <LineChart
                                        data={requirements.map((r) => ({ date: r.date, demand: r.demand, capacity: r.capacity }))}
                                        height={350}
                                        criticalThreshold={0}
                                        warningThreshold={1}
                                        ariaLabel="Projected required rooms versus available room capacity"
                                    />
                                ) : (
                                    <EmptyState message="No forecast data available yet" icon="heroicons:building-office-2" />
                                )}
                            </Panel>
                        </Section>

                        <Section
                            title="Projected staffing & sessions"
                            icon="heroicons:chart-bar-square"
                            summary="Required staff and rooms by month vs staffed line"
                        >
                            <Panel className="p-4">
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
                                    <EmptyState message="No forecast data available yet" icon="heroicons:chart-bar-square" />
                                )}
                            </Panel>
                        </Section>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default ResourcePlanning;
