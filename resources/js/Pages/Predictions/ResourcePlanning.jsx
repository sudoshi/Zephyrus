import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import DateRangeSelector from '@/Components/Common/DateRangeSelector';
import MetricsCard from '@/Components/Common/MetricsCard';

const ResourcePlanning = () => {
    const [selectedResource, setSelectedResource] = useState('all');

    return (
        <DashboardLayout>
            <Head title="Resource Planning - ZephyrusOR" />
            <PageContentLayout
                title="Resource Planning"
                subtitle="Plan and optimize resource allocation based on predictions"
            >
                <div className="space-y-6">
                    {/* Filter Panel */}
                    <Card>
                        <Card.Content>
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select 
                                            value={selectedResource}
                                            onChange={(e) => setSelectedResource(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Resources</option>
                                            <option value="staff">Staff</option>
                                            <option value="equipment">Equipment</option>
                                            <option value="rooms">Rooms</option>
                                        </select>
                                        <Icon 
                                            icon="heroicons:users" 
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
                            title="Required Staff"
                            value="42"
                            trend={2}
                            trendLabel="vs. current"
                            icon="heroicons:users"
                        />
                        <MetricsCard
                            title="Equipment Needs"
                            value="15"
                            trend={-1}
                            trendLabel="vs. current"
                            icon="heroicons:wrench-screwdriver"
                        />
                        <MetricsCard
                            title="Room Utilization"
                            value="88%"
                            trend={3.5}
                            trendLabel="efficiency"
                            icon="heroicons:building-office-2"
                        />
                        <MetricsCard
                            title="Cost Impact"
                            value="$245K"
                            trend={-2.1}
                            trendLabel="savings"
                            icon="heroicons:banknotes"
                        />
                    </div>

                    {/* Charts Section */}
                    <div className="grid grid-cols-1 gap-6">
                        <Card>
                            <Card.Content>
                                <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    <h3 className="text-lg font-semibold mb-4">Resource Requirements</h3>
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
                                        <h3 className="text-lg font-semibold mb-4">Staffing Distribution</h3>
                                        <div className="h-[350px] flex items-center justify-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Chart placeholder
                                        </div>
                                    </div>
                                </Card.Content>
                            </Card>
                            <Card>
                                <Card.Content>
                                    <div className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                        <h3 className="text-lg font-semibold mb-4">Cost Analysis</h3>
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

export default ResourcePlanning;
