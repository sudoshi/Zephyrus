import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard from '@/Components/Common/MetricsCard';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';

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

const CELL_TIER_TEXT = {
    success: 'text-healthcare-success dark:text-healthcare-success-dark',
    info: 'text-healthcare-info dark:text-healthcare-info-dark',
    warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
};

const BlockSchedule = ({ metrics = DEFAULT_METRICS, calendar = DEFAULT_CALENDAR }) => {
    const [selectedService, setSelectedService] = useState('all');
    const [selectedView, setSelectedView] = useState('month');

    const m = metrics ?? DEFAULT_METRICS;
    const cal = calendar ?? DEFAULT_CALENDAR;
    const hasCalendar = (cal.rows?.length ?? 0) > 0 && (cal.days?.length ?? 0) > 0;

    return (
        <DashboardLayout>
            <Head title="Block Schedule - ZephyrusOR" />
            <PageContentLayout
                title="Block Schedule"
                subtitle="Manage and view OR block time allocations"
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
                                </div>
                                <DateRangeSelector />
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Metrics Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <MetricsCard
                            title="Total Blocks"
                            value={String(m.totalBlocks.value)}
                            trend={m.totalBlocks.trend}
                            trendLabel={m.totalBlocks.trendLabel}
                            icon="heroicons:squares-2x2"
                        />
                        <MetricsCard
                            title="Utilization"
                            value={String(m.utilization.value)}
                            trend={m.utilization.trend}
                            trendLabel={m.utilization.trendLabel}
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Released"
                            value={String(m.released.value)}
                            trend={m.released.trend}
                            trendLabel={m.released.trendLabel}
                            icon="heroicons:arrow-trending-down"
                        />
                        <MetricsCard
                            title="Requests"
                            value={String(m.requests.value)}
                            trend={m.requests.trend}
                            trendLabel={m.requests.trendLabel}
                            icon="heroicons:clipboard-document-list"
                        />
                    </div>

                    {/* Calendar View */}
                    <Card>
                        <Card.Content>
                            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                <div className="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 className="text-lg font-semibold">Block Calendar</h3>
                                        {cal.rangeLabel && (
                                            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {cal.rangeLabel}
                                            </p>
                                        )}
                                    </div>
                                    <button className="inline-flex items-center px-4 py-2 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 rounded-lg hover:bg-opacity-20 dark:hover:bg-opacity-30 transition-all duration-300">
                                        <Icon icon="heroicons:plus" className="w-4 h-4 mr-1" />
                                        Add Block
                                    </button>
                                </div>
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
                                                                            <div className={`mt-1 flex items-center justify-between text-xs ${CELL_TIER_TEXT[cell.statusTier] ?? 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}`}>
                                                                                <span className="tabular-nums font-semibold">{cell.utilization}%</span>
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
                                    <div className="h-96 flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        No block allocations for this period
                                    </div>
                                )}
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default BlockSchedule;
