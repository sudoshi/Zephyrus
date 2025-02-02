import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard from '@/Components/Common/MetricsCard';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';

const CaseManagement = () => {
    const [selectedStatus, setSelectedStatus] = useState('all');
    const [selectedView, setSelectedView] = useState('list');

    return (
        <DashboardLayout>
            <Head title="Case Management - ZephyrusOR" />
            <PageContentLayout
                title="Case Management"
                subtitle="Schedule and manage surgical cases"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedStatus}
                                            onChange={(e) => setSelectedStatus(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Cases</option>
                                            <option value="scheduled">Scheduled</option>
                                            <option value="pending">Pending</option>
                                            <option value="completed">Completed</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:funnel" 
                                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                        />
                                    </div>
                                    <div className="flex items-center space-x-1 p-1 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                        {['list', 'calendar', 'board'].map((view) => (
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
                                <div className="flex items-center space-x-4">
                                    <DateRangeSelector />
                                    <button className="inline-flex items-center px-4 py-2 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 rounded-lg hover:bg-opacity-20 dark:hover:bg-opacity-30 transition-all duration-300">
                                        <Icon icon="heroicons:plus" className="w-4 h-4 mr-1" />
                                        New Case
                                    </button>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Metrics Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <MetricsCard
                            title="Total Cases"
                            value="324"
                            trend={5}
                            trendLabel="vs. last period"
                            icon="heroicons:clipboard-document-list"
                        />
                        <MetricsCard
                            title="Scheduled"
                            value="156"
                            trend={2.5}
                            trendLabel="vs. last period"
                            icon="heroicons:calendar"
                        />
                        <MetricsCard
                            title="Pending"
                            value="45"
                            trend={-3}
                            trendLabel="vs. last period"
                            icon="heroicons:clock"
                        />
                        <MetricsCard
                            title="Completed"
                            value="123"
                            trend={4}
                            trendLabel="vs. last period"
                            icon="heroicons:check-circle"
                        />
                    </div>

                    {/* Case List */}
                    <Card>
                        <Card.Content>
                            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-semibold">Cases</h3>
                                    <div className="flex items-center space-x-2">
                                        <button className="inline-flex items-center text-sm text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info font-medium transition-colors duration-300">
                                            <Icon icon="heroicons:document-arrow-down" className="w-4 h-4 mr-1" />
                                            Export
                                        </button>
                                    </div>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full">
                                        <thead>
                                            <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Case ID
                                                </th>
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Patient
                                                </th>
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Procedure
                                                </th>
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Date
                                                </th>
                                                <th className="text-left py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Status
                                                </th>
                                                <th className="text-right py-3 px-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider transition-colors duration-300">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {Array.from({ length: 5 }).map((_, index) => (
                                                <tr 
                                                    key={index}
                                                    className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-300"
                                                >
                                                    <td className="py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        #12{index + 1}345
                                                    </td>
                                                    <td className="py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        John Doe
                                                    </td>
                                                    <td className="py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        Total Hip Replacement
                                                    </td>
                                                    <td className="py-4 px-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                                        Mar 15, 2025
                                                    </td>
                                                    <td className="py-4 px-4">
                                                        <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                                                            index % 3 === 0 ? 'bg-healthcare-success bg-opacity-10 dark:bg-opacity-20 text-healthcare-success dark:text-healthcare-success-dark' :
                                                            index % 3 === 1 ? 'bg-healthcare-warning bg-opacity-10 dark:bg-opacity-20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                                            'bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 text-healthcare-info dark:text-healthcare-info-dark'
                                                        }`}>
                                                            {index % 3 === 0 ? 'Scheduled' : index % 3 === 1 ? 'Pending' : 'Completed'}
                                                        </span>
                                                    </td>
                                                    <td className="py-4 px-4 text-right">
                                                        <button className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300">
                                                            <Icon icon="heroicons:pencil-square" className="w-5 h-5" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default CaseManagement;
