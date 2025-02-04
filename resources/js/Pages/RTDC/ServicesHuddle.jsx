import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const ServicesHuddle = () => {
    // Placeholder metrics
    const metrics = {
        overview: {
            activeConsults: 45,
            pendingOrders: 28,
            completedToday: 156,
            avgResponseTime: 32
        },
        services: [
            {
                name: 'Cardiology',
                stats: {
                    activePatients: 24,
                    newConsults: 8,
                    pendingTests: 12,
                    avgWaitTime: 45
                }
            },
            {
                name: 'Neurology',
                stats: {
                    activePatients: 18,
                    newConsults: 5,
                    pendingTests: 7,
                    avgWaitTime: 60
                }
            },
            {
                name: 'Pulmonology',
                stats: {
                    activePatients: 15,
                    newConsults: 4,
                    pendingTests: 6,
                    avgWaitTime: 30
                }
            }
        ],
        priorities: [
            { id: 1, service: 'Cardiology', patient: 'Room 412', task: 'STAT Echo', priority: 'high', waitTime: 15 },
            { id: 2, service: 'Neurology', patient: 'Room 308', task: 'Stroke Assessment', priority: 'high', waitTime: 10 },
            { id: 3, service: 'Pulmonology', patient: 'Room 215', task: 'Vent Settings', priority: 'medium', waitTime: 25 },
            { id: 4, service: 'Cardiology', patient: 'ED Bay 3', task: 'Consult', priority: 'medium', waitTime: 30 }
        ]
    };

    return (
        <RTDCPageLayout
            title="Services Huddle"
            subtitle="Service-line metrics and coordination"
        >
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Overview Metrics */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:presentation-chart-line" className="w-5 h-5" />
                                <span>Service Overview</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={4}>
                            <MetricsCard
                                title="Active Consults"
                                value={metrics.overview.activeConsults.toString()}
                                icon="heroicons:user-group"
                                description="Currently active"
                            />
                            <MetricsCard
                                title="Pending Orders"
                                value={metrics.overview.pendingOrders.toString()}
                                icon="heroicons:clock"
                                description="Awaiting action"
                            />
                            <MetricsCard
                                title="Completed Today"
                                value={metrics.overview.completedToday.toString()}
                                icon="heroicons:check-circle"
                                description="Total completed"
                            />
                            <MetricsCard
                                title="Avg Response"
                                value={`${metrics.overview.avgResponseTime}min`}
                                icon="heroicons:clock-circle"
                                description="Response time"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Service Cards */}
                {metrics.services.map((service) => (
                    <Card key={service.name}>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:academic-cap" className="w-5 h-5" />
                                    <span>{service.name}</span>
                                </div>
                            </Card.Title>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Active Patients"
                                    value={service.stats.activePatients.toString()}
                                    icon="heroicons:users"
                                    description="Under care"
                                />
                                <MetricsCard
                                    title="New Consults"
                                    value={service.stats.newConsults.toString()}
                                    icon="heroicons:user-plus"
                                    description="Today's requests"
                                />
                                <MetricsCard
                                    title="Pending Tests"
                                    value={service.stats.pendingTests.toString()}
                                    icon="heroicons:clock"
                                    description="Awaiting completion"
                                />
                                <MetricsCard
                                    title="Avg Wait Time"
                                    value={`${service.stats.avgWaitTime}min`}
                                    icon="heroicons:clock-circle"
                                    description="Response time"
                                />
                            </MetricsCardGroup>
                        </Card.Content>
                    </Card>
                ))}

                {/* Priority Tasks */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:exclamation-circle" className="w-5 h-5" />
                                <span>Priority Tasks</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {metrics.priorities.map((task) => (
                                <div key={task.id} className="flex items-center justify-between p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <div className="flex items-center space-x-4">
                                        <div className={`p-2 rounded-lg ${
                                            task.priority === 'high' ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                            'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark'
                                        }`}>
                                            <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {task.task}
                                            </div>
                                            <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                {task.service} • {task.patient}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {task.waitTime} min wait
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Service Timeline */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                <span>Service Timeline</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="h-96 flex items-center justify-center bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Service timeline visualization coming soon
                            </p>
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default ServicesHuddle;
