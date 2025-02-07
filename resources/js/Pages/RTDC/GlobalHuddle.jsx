import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import SystemCapacityOverview from '@/Components/RTDC/SystemCapacity/SystemCapacityOverview';
import BedDistributionChart from '@/Components/RTDC/SystemCapacity/BedDistributionChart';
import UnitStatusTable from '@/Components/RTDC/SystemCapacity/UnitStatusTable';
import CapacityTimelinePanel from '@/Components/RTDC/CapacityTimeline/CapacityTimelinePanel';
import ExecutiveMetrics from '@/Components/RTDC/CapacityTimeline/ExecutiveMetrics';
import { systemCapacityData, capacityTimelineData } from '@/mock-data/rtdc';
import AlertCard from '@/Components/RTDC/AlertCard';

const GlobalHuddle = () => {
    // General metrics
    const metrics = {
        census: {
            current: 485,
            capacity: 550,
            occupancy: 88,
            trend: 'up'
        },
        admissions: {
            expected: 45,
            confirmed: 32,
            pending: 13,
            fromED: 18
        },
        discharges: {
            expected: 52,
            completed: 28,
            inProgress: 24
        },
        alerts: [
            { id: 1, type: 'critical', message: 'ICU approaching capacity', unit: 'ICU', time: '10 min ago' },
            { id: 2, type: 'warning', message: 'ED boarding 6 patients', unit: 'ED', time: '15 min ago' },
            { id: 3, type: 'info', message: 'Extra staff arriving for evening', unit: 'Staffing', time: '20 min ago' },
            { id: 4, type: 'warning', message: 'High volume in Radiology', unit: 'Radiology', time: '25 min ago' }
        ]
    };

    return (
        <RTDCPageLayout
            title="Global Huddle"
            subtitle="Hospital-wide coordination and status overview"
        >
            <div className="space-y-6">
                {/* Active Alerts */}
                <Card className="shadow-lg sticky top-0 z-50 bg-white dark:bg-healthcare-background-dark">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:bell-alert" className="w-5 h-5" />
                                    <span>Active Alerts</span>
                                </div>
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {metrics.alerts.filter(a => a.type === 'critical').length > 0 && (
                                        <span className="text-healthcare-critical dark:text-healthcare-critical-dark font-medium">
                                            {metrics.alerts.filter(a => a.type === 'critical').length} Critical
                                        </span>
                                    )}
                                </div>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div 
                            className="space-y-4"
                            role="log"
                            aria-label="Active system alerts"
                        >
                            {/* Alert summary for screen readers */}
                            <div className="sr-only">
                                {metrics.alerts.filter(a => a.type === 'critical').length > 0 && (
                                    <p>There are critical alerts that require immediate attention.</p>
                                )}
                            </div>
                            
                            {/* Sort alerts by type (critical first) and time */}
                            {metrics.alerts
                                .sort((a, b) => {
                                    // Sort by type priority
                                    const typePriority = { critical: 0, warning: 1, info: 2 };
                                    const typeDiff = typePriority[a.type] - typePriority[b.type];
                                    if (typeDiff !== 0) return typeDiff;
                                    
                                    // Then by time (assuming format "X min ago")
                                    const aTime = parseInt(a.time);
                                    const bTime = parseInt(b.time);
                                    return aTime - bTime;
                                })
                                .map((alert) => (
                                    <AlertCard 
                                        key={alert.id} 
                                        alert={alert}
                                    />
                                ))
                            }
                        </div>
                    </Card.Content>
                </Card>

                {/* System Capacity Assessment */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                                    <span>System Capacity Assessment</span>
                                </div>
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Last Updated: 7:16:33 PM
                                </div>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <SystemCapacityOverview metrics={systemCapacityData.overview} />
                        <div className="mb-6">
                            <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
                                Bed Availability Distribution
                            </h3>
                            <BedDistributionChart distribution={systemCapacityData.bedDistribution} />
                        </div>
                        <UnitStatusTable units={systemCapacityData.units} />
                    </Card.Content>
                </Card>

                {/* Capacity Timeline */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                <span>Capacity Timeline</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <CapacityTimelinePanel data={capacityTimelineData} />
                    </Card.Content>
                </Card>

                {/* Executive Metrics */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:presentation-chart-bar" className="w-5 h-5" />
                                <span>Executive Metrics</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <ExecutiveMetrics metrics={capacityTimelineData.executiveReport} />
                    </Card.Content>
                </Card>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Census Overview */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                                    <span>Census Overview</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Current Census"
                                    value={metrics.census.current.toString()}
                                    trend={metrics.census.trend}
                                    trendValue={metrics.census.occupancy}
                                    icon="heroicons:users"
                                    description={`${metrics.census.occupancy}% occupancy`}
                                />
                                <MetricsCard
                                    title="Capacity"
                                    value={metrics.census.capacity.toString()}
                                    icon="heroicons:home"
                                    description="Total beds"
                                />
                            </MetricsCardGroup>
                        </Card.Content>
                    </Card>

                    {/* Admissions */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:arrow-right-circle" className="w-5 h-5" />
                                    <span>Admissions</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Expected"
                                    value={metrics.admissions.expected.toString()}
                                    icon="heroicons:clock"
                                    description="Today's admissions"
                                />
                                <MetricsCard
                                    title="From ED"
                                    value={metrics.admissions.fromED.toString()}
                                    icon="heroicons:arrow-up-circle"
                                    description="ED admissions"
                                />
                            </MetricsCardGroup>
                        </Card.Content>
                    </Card>

                    {/* Discharges */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:arrow-left-circle" className="w-5 h-5" />
                                    <span>Discharges</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Expected"
                                    value={metrics.discharges.expected.toString()}
                                    icon="heroicons:clock"
                                    description="Today's discharges"
                                />
                                <MetricsCard
                                    title="Completed"
                                    value={metrics.discharges.completed.toString()}
                                    icon="heroicons:check-circle"
                                    description="Processed today"
                                />
                            </MetricsCardGroup>
                        </Card.Content>
                    </Card>

                </div>
            </div>
        </RTDCPageLayout>
    );
};

export default GlobalHuddle;
