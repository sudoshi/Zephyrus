import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const UnitHuddle = () => {
    // Placeholder metrics
    const metrics = {
        unitStatus: {
            census: 32,
            capacity: 36,
            occupancy: 89,
            staffed: 34
        },
        staffing: {
            nurses: { scheduled: 12, present: 11, required: 12 },
            techs: { scheduled: 6, present: 5, required: 6 },
            support: { scheduled: 4, present: 4, required: 4 }
        },
        patients: {
            acuity: {
                high: 8,
                medium: 15,
                low: 9
            },
            tasks: {
                pending: 24,
                inProgress: 18,
                completed: 156
            }
        },
        goals: [
            { id: 1, status: 'on-track', text: 'Complete morning assessments by 10am', progress: 85 },
            { id: 2, status: 'at-risk', text: 'Update care plans for high acuity patients', progress: 60 },
            { id: 3, status: 'completed', text: 'Medication reconciliation for new admits', progress: 100 },
            { id: 4, status: 'behind', text: 'Discharge planning documentation', progress: 45 }
        ]
    };

    return (
        <RTDCPageLayout
            title="Unit Huddle"
            subtitle="Unit-specific metrics and coordination"
        >
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Unit Status */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:building-office" className="w-5 h-5" />
                                <span>Unit Status</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Census"
                                value={metrics.unitStatus.census.toString()}
                                icon="heroicons:users"
                                description={`${metrics.unitStatus.occupancy}% occupancy`}
                            />
                            <MetricsCard
                                title="Staffed Beds"
                                value={metrics.unitStatus.staffed.toString()}
                                icon="heroicons:home"
                                description={`${metrics.unitStatus.capacity} total beds`}
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Staffing */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:user-group" className="w-5 h-5" />
                                <span>Staffing</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {Object.entries(metrics.staffing).map(([role, data]) => (
                                <div key={role} className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                                        {role}
                                    </span>
                                    <div className="flex items-center space-x-4">
                                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {data.present}/{data.scheduled}
                                        </span>
                                        <div className={`w-2 h-2 rounded-full ${
                                            data.present < data.required ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                            data.present === data.required ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                                            'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                                        }`} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Patient Acuity */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:heart" className="w-5 h-5" />
                                <span>Patient Acuity</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="High Acuity"
                                value={metrics.patients.acuity.high.toString()}
                                icon="heroicons:exclamation-circle"
                                description="Critical care needed"
                            />
                            <MetricsCard
                                title="Medium Acuity"
                                value={metrics.patients.acuity.medium.toString()}
                                icon="heroicons:exclamation-triangle"
                                description="Intermediate care"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Unit Goals */}
                <Card className="lg:col-span-3">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:flag" className="w-5 h-5" />
                                <span>Unit Goals</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {metrics.goals.map((goal) => (
                                <div key={goal.id} className="p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <div className="flex items-center justify-between mb-2">
                                        <div className="flex items-center space-x-3">
                                            <div className={`w-2 h-2 rounded-full ${
                                                goal.status === 'completed' ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                                                goal.status === 'on-track' ? 'bg-healthcare-info dark:bg-healthcare-info-dark' :
                                                goal.status === 'at-risk' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                                'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                                            }`} />
                                            <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {goal.text}
                                            </span>
                                        </div>
                                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {goal.progress}%
                                        </span>
                                    </div>
                                    <div className="w-full bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2">
                                        <div
                                            className={`h-2 rounded-full ${
                                                goal.status === 'completed' ? 'bg-healthcare-success dark:bg-healthcare-success-dark' :
                                                goal.status === 'on-track' ? 'bg-healthcare-info dark:bg-healthcare-info-dark' :
                                                goal.status === 'at-risk' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                                'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                                            }`}
                                            style={{ width: `${goal.progress}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Task Timeline */}
                <Card className="lg:col-span-3">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                <span>Task Timeline</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="h-96 flex items-center justify-center bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Task timeline visualization coming soon
                            </p>
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default UnitHuddle;
