import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';

const HistoricalTrends = () => {
    const [selectedMetric, setSelectedMetric] = useState('utilization');

    return (
        <DashboardLayout>
            <Head title="Historical Trends - ZephyrusOR" />
            <PageContentLayout
                title="Historical Trends"
                subtitle="Analyze long-term performance trends and patterns"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedMetric}
                                            onChange={(e) => setSelectedMetric(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="utilization">Block Utilization</option>
                                            <option value="cases">Case Volume</option>
                                            <option value="ontime">On-Time Starts</option>
                                            <option value="turnover">Turnover Time</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:chart-bar" 
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
                            title="Average"
                            value="78%"
                            trend={2.5}
                            trendLabel="vs. previous period"
                            icon="heroicons:calculator"
                        />
                        <MetricsCard
                            title="Maximum"
                            value="92%"
                            trend={0}
                            trendLabel="unchanged"
                            icon="heroicons:arrow-trending-up"
                        />
                        <MetricsCard
                            title="Minimum"
                            value="65%"
                            trend={5.0}
                            trendLabel="vs. previous period"
                            icon="heroicons:arrow-trending-down"
                        />
                        <MetricsCard
                            title="Trend"
                            value="↗ Up"
                            trend={1.8}
                            trendLabel="slope"
                            icon="heroicons:chart-bar-square"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Historical Performance</h3>
                                    <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Chart placeholder
                                    </div>
                                </div>
                            </Card.Content>
                        </Card>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Seasonal Patterns</h3>
                                        <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Chart placeholder
                                        </div>
                                    </div>
                                </Card.Content>
                            </Card>
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Distribution Analysis</h3>
                                        <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Chart placeholder
                                        </div>
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

export default HistoricalTrends;
