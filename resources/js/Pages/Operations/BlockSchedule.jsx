import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';

// Block Schedule rebuilt on the gold-standard design system: the four block-time
// KPIs become one MetricGrid of KpiTiles, the room/day allocation calendar lives
// in a Panel under a Section header, and the service/view filters render as
// Section actions. All values come from the server-provided `metrics` /
// `calendar` props (defaults below are the demo fallback); no data is fabricated.

const DEFAULT_METRICS = {
    totalBlocks: { value: 48, trend: 2, trendLabel: 'vs. last period' },
    utilization: { value: '82%', trend: 3.5, trendLabel: 'vs. target' },
    released: { value: 6, trend: -2, trendLabel: 'vs. last period' },
    requests: { value: 12, trend: 4, trendLabel: 'pending' },
};

const DEFAULT_CALENDAR = { rangeLabel: '', days: [], rows: [] };

const CELL_TIER_STYLES = {
    success: 'border-healthcare-success/40 dark:border-healthcare-success-dark/40 bg-healthcare-success/5 dark:bg-healthcare-success-dark/10',
    info: 'border-healthcare-info/40 dark:border-healthcare-info-dark/40 bg-healthcare-info/5 dark:bg-healthcare-info-dark/10',
    warning: 'border-healthcare-warning/40 dark:border-healthcare-warning-dark/40 bg-healthcare-warning/5 dark:bg-healthcare-warning-dark/10',
};

// statusTier on a calendar cell maps to the four-color status vocabulary so the
// utilization figure is coloured via STATUS_VAR (paired with the visible label).
const TIER_STATUS = { success: 'success', info: 'info', warning: 'warning' };

const BlockSchedule = ({ metrics = DEFAULT_METRICS, calendar = DEFAULT_CALENDAR }) => {
    const [selectedService, setSelectedService] = useState('all');
    const [selectedView, setSelectedView] = useState('month');

    const m = metrics ?? DEFAULT_METRICS;
    const cal = calendar ?? DEFAULT_CALENDAR;
    const hasCalendar = (cal.rows?.length ?? 0) > 0 && (cal.days?.length ?? 0) > 0;

    // Signed trend → status: a positive utilization/blocks delta reads as
    // success, a negative one as warning; neutral when flat.
    const trendStatus = (trend) => (trend > 0 ? 'success' : trend < 0 ? 'warning' : 'neutral');
    const trendDisplay = (trend, label) =>
        `${trend > 0 ? '+' : ''}${trend} ${label}`;

    const utilizationValue = Number(String(m.utilization.value).replace('%', '')) || 0;

    const kpiMetrics = [
        metric({
            key: 'total-blocks', label: 'Total Blocks', value: Number(m.totalBlocks.value) || 0,
            status: trendStatus(m.totalBlocks.trend),
            caption: trendDisplay(m.totalBlocks.trend, m.totalBlocks.trendLabel),
            definition: 'OR block-time allocations in the selected period.',
        }),
        metric({
            key: 'utilization', label: 'Utilization', value: utilizationValue, unit: '%',
            status: trendStatus(m.utilization.trend),
            caption: trendDisplay(m.utilization.trend, m.utilization.trendLabel),
            definition: 'Share of allocated block time actually used by booked cases.',
        }),
        metric({
            key: 'released', label: 'Released', value: Number(m.released.value) || 0,
            status: trendStatus(m.released.trend),
            caption: trendDisplay(m.released.trend, m.released.trendLabel),
            definition: 'Blocks released back to the pool this period.',
        }),
        metric({
            key: 'requests', label: 'Requests', value: Number(m.requests.value) || 0,
            status: trendStatus(m.requests.trend),
            caption: trendDisplay(m.requests.trend, m.requests.trendLabel),
            definition: 'Pending block-time requests awaiting allocation.',
        }),
    ];

    const filterActions = (
        <div className="flex items-center gap-3">
            <div className="relative">
                <select
                    value={selectedService}
                    onChange={(e) => setSelectedService(e.target.value)}
                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-1.5 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                >
                    <option value="all">All Services</option>
                    <option value="ortho">Orthopedics</option>
                    <option value="cardio">Cardiology</option>
                    <option value="general">General Surgery</option>
                </select>
                <Icon
                    icon="heroicons:squares-2x2"
                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                />
            </div>
            <div className="flex items-center space-x-1 p-1 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                {['month', 'week', 'day'].map((view) => (
                    <button
                        key={view}
                        onClick={() => setSelectedView(view)}
                        className={`px-3 py-1 rounded-md text-sm font-medium transition-colors duration-300 ${
                            selectedView === view
                                ? 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark'
                        }`}
                    >
                        {view.charAt(0).toUpperCase() + view.slice(1)}
                    </button>
                ))}
            </div>
            <DateRangeSelector />
        </div>
    );

    return (
        <DashboardLayout>
            <Head title="Block Schedule - ZephyrusOR" />
            <PageContentLayout
                title="Block Schedule"
                subtitle="Manage and view OR block time allocations"
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="Block time"
                        icon="heroicons:squares-2x2"
                        summary="Allocation, utilization & request volume for the selected period"
                        actions={filterActions}
                    >
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <Section
                        title="Block calendar"
                        icon="heroicons:calendar-days"
                        summary={cal.rangeLabel || undefined}
                        actions={(
                            <button className="inline-flex items-center px-4 py-1.5 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 rounded-lg hover:bg-opacity-20 dark:hover:bg-opacity-30 transition-all duration-300">
                                <Icon icon="heroicons:plus" className="w-4 h-4 mr-1" />
                                Add Block
                            </button>
                        )}
                    >
                        <Panel className="p-4">
                            {hasCalendar ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse">
                                        <thead>
                                            <tr>
                                                <th className="sticky left-0 z-10 bg-healthcare-surface dark:bg-healthcare-surface-dark p-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-b border-healthcare-border dark:border-healthcare-border-dark">
                                                    Room
                                                </th>
                                                {cal.days.map((day) => (
                                                    <th
                                                        key={day.date}
                                                        className="p-2 text-center text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-b border-healthcare-border dark:border-healthcare-border-dark"
                                                    >
                                                        <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {day.weekday}
                                                        </div>
                                                        <div>{day.label}</div>
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {cal.rows.map((row) => (
                                                <tr key={row.room}>
                                                    <td className="sticky left-0 z-10 bg-healthcare-surface dark:bg-healthcare-surface-dark p-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-b border-healthcare-border dark:border-healthcare-border-dark whitespace-nowrap">
                                                        {row.room}
                                                    </td>
                                                    {row.cells.map((cell) => (
                                                        <td
                                                            key={`${row.room}-${cell.date}`}
                                                            className="p-1 align-top border-b border-healthcare-border dark:border-healthcare-border-dark"
                                                        >
                                                            {cell.hasBlock ? (
                                                                <div className={`rounded-md border p-2 ${CELL_TIER_STYLES[cell.statusTier] ?? 'border-healthcare-border dark:border-healthcare-border-dark'}`}>
                                                                    <div className="text-xs font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                        {cell.service}
                                                                    </div>
                                                                    {cell.surgeon && (
                                                                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                            {cell.surgeon}
                                                                        </div>
                                                                    )}
                                                                    {cell.timeRange && (
                                                                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark tabular-nums">
                                                                            {cell.timeRange}
                                                                        </div>
                                                                    )}
                                                                    {cell.utilization !== null && (
                                                                        <div
                                                                            className="mt-1 flex items-center justify-between text-xs font-semibold"
                                                                            style={{ color: STATUS_VAR[TIER_STATUS[cell.statusTier]] ?? undefined }}
                                                                        >
                                                                            <span className="tabular-nums">{cell.utilization}%</span>
                                                                            {cell.statusLabel && <span>{cell.statusLabel}</span>}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                <div className="h-full min-h-[3rem] rounded-md border border-dashed border-healthcare-border dark:border-healthcare-border-dark" />
                                                            )}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState
                                    message="No block allocations for this period"
                                    icon="heroicons:calendar-days"
                                />
                            )}
                        </Panel>
                    </Section>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default BlockSchedule;
