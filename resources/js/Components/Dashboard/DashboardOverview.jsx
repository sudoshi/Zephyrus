import React, { useState } from 'react';
import LastMonthSection from './LastMonthSection';
import MonthToDateSection from './MonthToDateSection';
import { Icon } from '@iconify/react';
import { syntheticData } from '../../mock-data/dashboard';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';

// Perioperative ("OR Manager Home") body rebuilt on the gold-standard design
// system: the quick-stats KPI wall is now one MetricGrid of KpiTiles and the
// filter bar is a Panel — matching the Command Center vocabulary. The live
// `overview` prop is preserved untouched and forwarded to LastMonthSection /
// MonthToDateSection (which read data.lastMonth, data.monthToDate, and
// data.workbenchReports). The four quick stats are authored summary values
// with no underlying series, so no sparkline is fabricated.

const DashboardOverview = ({ overview = syntheticData }) => {
    const data = overview ?? syntheticData;
    const [selectedLocation, setSelectedLocation] = useState('all');
    const [selectedService, setSelectedService] = useState('all');
    const [selectedSurgeon, setSelectedSurgeon] = useState('all');
    const [dateRange, setDateRange] = useState('mtd'); // mtd, last-month, custom

    // Quick stats — authored summary values (no underlying series → no sparkline).
    // trend → status (improving = success, regressing = critical), delta → caption.
    const quickStats = [
        metric({
            key: 'on-time-starts', label: 'On-Time Starts', value: 85, unit: '%',
            status: 'success', caption: '+3% vs. last month',
            definition: 'Share of cases that started on time this period.',
        }),
        metric({
            key: 'block-utilization', label: 'Block Utilization', value: 78, unit: '%',
            status: 'success', caption: '+5% vs. last month',
            definition: 'Share of allocated block time used for cases.',
        }),
        metric({
            key: 'cases-today', label: 'Cases Today', value: 24, display: '24',
            status: 'warning', caption: '−2 vs. last month',
            definition: 'Total cases scheduled or performed today.',
        }),
        metric({
            key: 'avg-turnover', label: 'Avg Turnover', value: 32, display: '32m',
            status: 'critical', caption: '+4m vs. last month', goodWhenDown: true,
            definition: 'Average room turnover time between cases.',
        }),
    ];

    return (
        <div className="flex flex-col gap-5">
            {/* Filters */}
            <Panel>
                <div className="p-4">
                    <div className="flex items-center justify-between">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                            OR Manager Home
                        </h1>
                        <div className="flex items-center space-x-4">
                            <div className="relative">
                                <select
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedLocation}
                                    onChange={(e) => setSelectedLocation(e.target.value)}
                                >
                                    <option value="all">All Locations</option>
                                    <option value="loc1">Location A</option>
                                    <option value="loc2">Location B</option>
                                </select>
                                <Icon
                                    icon="heroicons:building-office"
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedService}
                                    onChange={(e) => setSelectedService(e.target.value)}
                                >
                                    <option value="all">All Services</option>
                                    <option value="ortho">Orthopedics</option>
                                    <option value="cardio">Cardiology</option>
                                </select>
                                <Icon
                                    icon="heroicons:rectangle-stack"
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={selectedSurgeon}
                                    onChange={(e) => setSelectedSurgeon(e.target.value)}
                                >
                                    <option value="all">All Surgeons</option>
                                    <option value="surg1">Dr. Smith</option>
                                    <option value="surg2">Dr. Johnson</option>
                                </select>
                                <Icon
                                    icon="heroicons:user"
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                            <div className="relative">
                                <select
                                    className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                    value={dateRange}
                                    onChange={(e) => setDateRange(e.target.value)}
                                >
                                    <option value="mtd">Month to Date</option>
                                    <option value="last-month">Last Month</option>
                                    <option value="custom">Custom Range</option>
                                </select>
                                <Icon
                                    icon="heroicons:calendar"
                                    className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </Panel>

            {/* Quick Stats */}
            <Section title="Today at a glance" icon="heroicons:bolt"
                     summary="Operational headline metrics vs. last month">
                <MetricGrid metrics={quickStats} />
            </Section>

            {/* Main Content */}
            <LastMonthSection data={data.lastMonth} />
            <MonthToDateSection data={data.monthToDate} reports={data.workbenchReports} />
        </div>
    );
};

export default DashboardOverview;
