import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';

const UtilizationForecast = () => {
    const [selectedTimeframe, setSelectedTimeframe] = useState('month');

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
                                            onChange={(e) => setSelectedTimeframe(e.target.value)}
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
                            value="84%"
                            trend={2.5}
                            trendLabel="vs. current"
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Confidence Level"
                            value="92%"
                            trend={1.5}
                            trendLabel="accuracy"
                            icon="heroicons:check-circle"
                        />
                        <MetricsCard
                            title="Prediction Range"
                            value="±3%"
                            trend={-0.5}
                            trendLabel="uncertainty"
                            icon="heroicons:variable"
                        />
                        <MetricsCard
                            title="Historical Accuracy"
                            value="89%"
                            trend={1.2}
                            trendLabel="improvement"
                            icon="heroicons:chart-bar-square"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Utilization Forecast</h3>
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
                                        <h3 className="text-lg font-semibold mb-4">Prediction Intervals</h3>
                                        <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Chart placeholder
                                        </div>
                                    </div>
                                </Card.Content>
                            </Card>
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Contributing Factors</h3>
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

export default UtilizationForecast;
