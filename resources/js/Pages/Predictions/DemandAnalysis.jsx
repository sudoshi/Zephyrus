import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';

const DemandAnalysis = () => {
    const [selectedService, setSelectedService] = useState('all');

    return (
        <DashboardLayout>
            <Head title="Demand Analysis - ZephyrusOR" />
            <PageContentLayout
                title="Demand Analysis"
                subtitle="Analyze and predict surgical service demand patterns"
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
                    <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <MetricsCard
                            title="Predicted Volume"
                            value="432"
                            trend={3.8}
                            trendLabel="vs. current"
                            icon="heroicons:chart-bar"
                        />
                        <MetricsCard
                            title="Growth Rate"
                            value="5.2%"
                            trend={0.8}
                            trendLabel="vs. last year"
                            icon="heroicons:arrow-trending-up"
                        />
                        <MetricsCard
                            title="Seasonality"
                            value="High"
                            trend={2.1}
                            trendLabel="impact score"
                            icon="heroicons:calendar"
                        />
                        <MetricsCard
                            title="Model Accuracy"
                            value="91%"
                            trend={1.5}
                            trendLabel="improvement"
                            icon="heroicons:check-circle"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Volume Forecast</h3>
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
                                        <h3 className="text-lg font-semibold mb-4">Growth Analysis</h3>
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

export default DemandAnalysis;
