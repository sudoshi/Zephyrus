import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const GlobalHuddle = () => {
    // Placeholder metrics
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

                {/* Active Alerts */}
                <Card className="lg:col-span-3">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:bell-alert" className="w-5 h-5" />
                                <span>Active Alerts</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {metrics.alerts.map((alert) => (
                                <div key={alert.id} className="flex items-center justify-between p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <div className="flex items-center space-x-4">
                                        <div className={`p-2 rounded-lg ${
                                            alert.type === 'critical' ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                            alert.type === 'warning' ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                            'bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark'
                                        }`}>
                                            <Icon 
                                                icon={
                                                    alert.type === 'critical' ? 'heroicons:exclamation-triangle' :
                                                    alert.type === 'warning' ? 'heroicons:exclamation-circle' :
                                                    'heroicons:information-circle'
                                                }
                                                className="w-5 h-5"
                                            />
                                        </div>
                                        <div>
                                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {alert.message}
                                            </div>
                                            <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                {alert.unit} • {alert.time}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Capacity Timeline */}
                <Card className="lg:col-span-3">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                <span>Capacity Timeline</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="h-96 flex items-center justify-center bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Capacity timeline visualization coming soon
                            </p>
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default GlobalHuddle;
