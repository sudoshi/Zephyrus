import React from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';

const DischargePrediction = () => {
    // Placeholder metrics
    const metrics = {
        today: {
            predicted: 45,
            completed: 28,
            pending: 17,
            accuracy: 92
        },
        byUnit: {
            medical: { predicted: 20, completed: 12 },
            surgical: { predicted: 15, completed: 10 },
            critical: { predicted: 5, completed: 3 },
            specialty: { predicted: 5, completed: 3 }
        },
        barriers: [
            { type: 'Transportation', count: 8 },
            { type: 'Placement', count: 5 },
            { type: 'Social Work', count: 3 },
            { type: 'Pharmacy', count: 1 }
        ]
    };

    return (
        <RTDCPageLayout
            title="Discharge Prediction"
            subtitle="ML-powered discharge forecasting and planning"
        >
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Today's Overview */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:calendar" className="w-5 h-5" />
                                <span>Today's Overview</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <MetricsCardGroup cols={2}>
                            <MetricsCard
                                title="Predicted"
                                value={metrics.today.predicted.toString()}
                                icon="heroicons:chart-bar"
                                description="Expected discharges"
                            />
                            <MetricsCard
                                title="Completed"
                                value={metrics.today.completed.toString()}
                                icon="heroicons:check-circle"
                                description="Discharged patients"
                            />
                            <MetricsCard
                                title="Pending"
                                value={metrics.today.pending.toString()}
                                icon="heroicons:clock"
                                description="Still in process"
                            />
                            <MetricsCard
                                title="Accuracy"
                                value={`${metrics.today.accuracy}%`}
                                icon="heroicons:chart-pie"
                                description="Prediction accuracy"
                            />
                        </MetricsCardGroup>
                    </Card.Content>
                </Card>

                {/* Discharge Barriers */}
                <Card>
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5" />
                                <span>Discharge Barriers</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="space-y-4">
                            {metrics.barriers.map((barrier, index) => (
                                <div key={index} className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <div className="flex items-center space-x-3">
                                        <div className={`w-2 h-2 rounded-full ${
                                            barrier.count > 5 ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                            barrier.count > 2 ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                            'bg-healthcare-success dark:bg-healthcare-success-dark'
                                        }`} />
                                        <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {barrier.type}
                                        </span>
                                    </div>
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {barrier.count} patients
                                    </span>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Unit Breakdown */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                                <span>Unit Breakdown</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            {Object.entries(metrics.byUnit).map(([unit, data]) => (
                                <div key={unit} className="p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                    <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize mb-3">
                                        {unit} Unit
                                    </h4>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {data.predicted}
                                            </div>
                                            <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                Predicted
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {data.completed}
                                            </div>
                                            <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                Completed
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card.Content>
                </Card>

                {/* Prediction Timeline */}
                <Card className="lg:col-span-2">
                    <Card.Header>
                        <Card.Title>
                            <div className="flex items-center space-x-2">
                                <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                <span>Prediction Timeline</span>
                            </div>
                        </Card.Title>
                    </Card.Header>
                    <Card.Content>
                        <div className="h-96 flex items-center justify-center bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                            <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Discharge prediction timeline visualization coming soon
                            </p>
                        </div>
                    </Card.Content>
                </Card>
            </div>
        </RTDCPageLayout>
    );
};

export default DischargePrediction;
