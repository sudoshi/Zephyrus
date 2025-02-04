import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const AncillaryServices = () => {
    // Placeholder metrics
    const metrics = {
        radiology: {
            pending: 25,
            inProgress: 8,
            completed: 142,
            avgTurnaround: 45
        },
        laboratory: {
            pending: 56,
            inProgress: 34,
            completed: 378,
            avgTurnaround: 120
        },
        therapy: {
            pending: 18,
            inProgress: 12,
            completed: 89,
            avgTurnaround: 30
        }
    };

    return (
        <RTDCPageLayout
            title="Ancillary Services"
            subtitle="Monitor and track hospital support services"
        >
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Radiology Section */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:photo" className="w-5 h-5" />
                                <span>Radiology</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Pending"
                                value={metrics.radiology.pending.toString()}
                                icon="heroicons:clock"
                                description="Awaiting service"
                            />
                            <MetricsCard
                                title="In Progress"
                                value={metrics.radiology.inProgress.toString()}
                                icon="heroicons:arrow-path"
                                description="Currently active"
                            />
                            <MetricsCard
                                title="Completed"
                                value={metrics.radiology.completed.toString()}
                                icon="heroicons:check-circle"
                                description="Last 24 hours"
                            />
                            <MetricsCard
                                title="Avg Time"
                                value={`${metrics.radiology.avgTurnaround}min`}
                                icon="heroicons:clock-circle"
                                description="Turnaround time"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Laboratory Section */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:beaker" className="w-5 h-5" />
                                <span>Laboratory</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Pending"
                                value={metrics.laboratory.pending.toString()}
                                icon="heroicons:clock"
                                description="Awaiting processing"
                            />
                            <MetricsCard
                                title="In Progress"
                                value={metrics.laboratory.inProgress.toString()}
                                icon="heroicons:arrow-path"
                                description="Being processed"
                            />
                            <MetricsCard
                                title="Completed"
                                value={metrics.laboratory.completed.toString()}
                                icon="heroicons:check-circle"
                                description="Last 24 hours"
                            />
                            <MetricsCard
                                title="Avg Time"
                                value={`${metrics.laboratory.avgTurnaround}min`}
                                icon="heroicons:clock-circle"
                                description="Processing time"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Therapy Section */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:heart" className="w-5 h-5" />
                                <span>Therapy Services</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Pending"
                                value={metrics.therapy.pending.toString()}
                                icon="heroicons:clock"
                                description="Awaiting service"
                            />
                            <MetricsCard
                                title="In Progress"
                                value={metrics.therapy.inProgress.toString()}
                                icon="heroicons:arrow-path"
                                description="In therapy"
                            />
                            <MetricsCard
                                title="Completed"
                                value={metrics.therapy.completed.toString()}
                                icon="heroicons:check-circle"
                                description="Last 24 hours"
                            />
                            <MetricsCard
                                title="Avg Time"
                                value={`${metrics.therapy.avgTurnaround}min`}
                                icon="heroicons:clock-circle"
                                description="Session duration"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Service Timeline */}
                <Card className="lg:col-span-3">
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

export default AncillaryServices;
