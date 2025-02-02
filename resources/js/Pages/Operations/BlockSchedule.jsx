import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard from '@/Components/Common/MetricsCard';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';

const BlockSchedule = () => {
    const [selectedService, setSelectedService] = useState('all');
    const [selectedView, setSelectedView] = useState('month');

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
                            value="48"
                            trend={2}
                            trendLabel="vs. last period"
                            icon="heroicons:squares-2x2"
                        />
                        <MetricsCard
                            title="Utilization"
                            value="82%"
                            trend={3.5}
                            trendLabel="vs. target"
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Released"
                            value="6"
                            trend={-2}
                            trendLabel="vs. last period"
                            icon="heroicons:arrow-trending-down"
                        />
                        <MetricsCard
                            title="Requests"
                            value="12"
                            trend={4}
                            trendLabel="pending"
                            icon="heroicons:clipboard-document-list"
                        />
                    </div>

                    {/* Calendar View */}
                    <Card>
                        <Card.Content>
                            <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-semibold">Block Calendar</h3>
                                    <button className="inline-flex items-center px-4 py-2 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 rounded-lg hover:bg-opacity-20 dark:hover:bg-opacity-30 transition-all duration-300">
                                        <Icon icon="heroicons:plus" className="w-4 h-4 mr-1" />
                                        Add Block
                                    </button>
                                </div>
                                <div className="h-[600px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Calendar placeholder
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default BlockSchedule;
