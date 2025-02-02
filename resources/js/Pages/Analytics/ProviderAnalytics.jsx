import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';

const ProviderAnalytics = () => {
    const [selectedProvider, setSelectedProvider] = useState('all');

    return (
        <DashboardLayout>
            <Head title="Provider Analytics - ZephyrusOR" />
            <PageContentLayout
                title="Provider Analytics"
                subtitle="Analyze performance metrics by provider"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedProvider}
                                            onChange={(e) => setSelectedProvider(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Providers</option>
                                            <option value="dr-smith">Dr. Smith</option>
                                            <option value="dr-jones">Dr. Jones</option>
                                            <option value="dr-wilson">Dr. Wilson</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:funnel" 
                                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                        />
                                    </div>
                                    <div className="relative">
                                        <select 
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Specialties</option>
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
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <MetricsCard
                            title="Total Cases"
                            value="156"
                            trend={3.8}
                            trendLabel="vs. last period"
                            icon="heroicons:clipboard-document-list"
                        />
                        <MetricsCard
                            title="Block Utilization"
                            value="82%"
                            trend={1.5}
                            trendLabel="vs. last period"
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Case Length Accuracy"
                            value="75%"
                            trend={-1.2}
                            trendLabel="vs. last period"
                            icon="heroicons:clock"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Case Volume Distribution</h3>
                                    <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Chart placeholder
                                    </div>
                                </div>
                            </Card.Content>
                        </Card>
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Performance Trends</h3>
                                    <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Chart placeholder
                                    </div>
                                </div>
                            </Card.Content>
                        </Card>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default ProviderAnalytics;
