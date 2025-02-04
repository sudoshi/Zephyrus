import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const BedTracking = () => {
    // Placeholder metrics
    const metrics = {
        totalBeds: 500,
        occupied: 425,
        available: 75,
        pending: {
            admissions: 15,
            discharges: 20,
            transfers: 8
        },
        cleaning: 12
    };

    return (
        <RTDCPageLayout
            title="Bed Tracking"
            subtitle="Real-time bed status and capacity management"
        >
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:home" className="w-5 h-5" />
                                <span>Current Status</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Total Beds"
                                value={metrics.totalBeds.toString()}
                                icon="heroicons:home"
                                description="Hospital-wide capacity"
                            />
                            <MetricsCard
                                title="Occupied"
                                value={metrics.occupied.toString()}
                                icon="heroicons:user-group"
                                description={`${Math.round((metrics.occupied / metrics.totalBeds) * 100)}% occupancy`}
                            />
                            <MetricsCard
                                title="Available"
                                value={metrics.available.toString()}
                                icon="heroicons:check-circle"
                                description="Ready for admission"
                            />
                            <MetricsCard
                                title="Cleaning"
                                value={metrics.cleaning.toString()}
                                icon="heroicons:arrow-path"
                                description="In turnover process"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:arrow-path" className="w-5 h-5" />
                                <span>Pending Activity</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Pending Admissions"
                                value={metrics.pending.admissions.toString()}
                                icon="heroicons:arrow-right-circle"
                                description="Awaiting bed assignment"
                            />
                            <MetricsCard
                                title="Pending Discharges"
                                value={metrics.pending.discharges.toString()}
                                icon="heroicons:arrow-left-circle"
                                description="In discharge process"
                            />
                            <MetricsCard
                                title="Pending Transfers"
                                value={metrics.pending.transfers.toString()}
                                icon="heroicons:arrows-right-left"
                                description="Internal movements"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Placeholder for future bed map visualization */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:map" className="w-5 h-5" />
                                <span>Hospital Bed Map</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="h-96 flex items-center justify-center bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Interactive bed map visualization coming soon
                            </p>
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default BedTracking;
