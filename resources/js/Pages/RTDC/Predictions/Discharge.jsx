import React from 'react';
import RTDCPageLayout from '../RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const Discharge = () => {
    const predictedDischarges = [
        {
            department: 'Medical/Surgical',
            count: 12,
            confidence: 85,
            timeframe: 'Next 24 hours',
            barriers: ['Transportation', 'Medication Reconciliation']
        },
        {
            department: 'ICU',
            count: 3,
            confidence: 92,
            timeframe: 'Next 24 hours',
            barriers: ['Care Coordination', 'Family Meeting']
        },
        {
            department: 'Telemetry',
            count: 8,
            confidence: 78,
            timeframe: 'Next 24 hours',
            barriers: ['DME Delivery', 'SNF Placement']
        }
    ];

    return (
        <RTDCPageLayout
            title="Discharge Prediction"
            subtitle="AI-powered discharge planning and capacity management"
        >
            {/* Prediction Overview */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:arrow-trending-up" className="w-5 h-5" />
                            <span>Prediction Overview</span>
                        </div>
                    </Card.Title>
                    <Card.Description>24-hour discharge forecast</Card.Description>
                </Card.Header>
                <Card.Content>
                    <MetricsCardGroup cols={4}>
                        <MetricsCard
                            title="Total Predicted"
                            value="23"
                            trend="up"
                            trendValue="4"
                            icon="heroicons:arrow-right"
                            description="Next 24 hours"
                        />
                        <MetricsCard
                            title="Model Confidence"
                            value="85%"
                            trend="up"
                            trendValue="2%"
                            icon="heroicons:chart-bar-square"
                            description="Average accuracy"
                        />
                        <MetricsCard
                            title="Capacity Impact"
                            value="+5.2%"
                            trend="up"
                            trendValue="1.2%"
                            icon="heroicons:building-office-2"
                            description="Available beds"
                        />
                        <MetricsCard
                            title="Risk Score"
                            value="Low"
                            icon="heroicons:shield-check"
                            description="Prediction confidence"
                        />
                    </MetricsCardGroup>
                </Card.Content>
            </Card>

            {/* Prediction Timeline */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:clock" className="w-5 h-5" />
                            <span>Discharge Timeline</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Hourly discharge distribution</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="h-80">
                        <TrendChart
                            data={[
                                { hour: '08:00', count: 2 },
                                { hour: '10:00', count: 4 },
                                { hour: '12:00', count: 6 },
                                { hour: '14:00', count: 5 },
                                { hour: '16:00', count: 4 },
                                { hour: '18:00', count: 2 }
                            ]}
                            xKey="hour"
                            yKey="count"
                            yAxisLabel="Predicted Discharges"
                        />
                    </div>
                </Card.Content>
            </Card>

            {/* Department Predictions */}
            <div className="space-y-4">
                {predictedDischarges.map((dept) => (
                    <Card key={dept.department}>
                        <div className="p-6">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {dept.department}
                                </h3>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:signal" className="w-4 h-4" />
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {dept.confidence}% confidence
                                    </span>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
                                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Predicted Discharges
                                    </div>
                                    <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mt-1">
                                        {dept.count}
                                    </div>
                                    <div className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark mt-1">
                                        {dept.timeframe}
                                    </div>
                                </div>

                                <div className="md:col-span-2">
                                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                                        Potential Barriers
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {dept.barriers.map((barrier) => (
                                            <span
                                                key={barrier}
                                                className="px-3 py-1 text-xs rounded-full bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                            >
                                                {barrier}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="mt-4 flex justify-end">
                                <button className="px-4 py-2 text-sm bg-healthcare-primary dark:bg-healthcare-primary-dark text-white rounded-md hover:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary transition-colors duration-300">
                                    View Details
                                </button>
                            </div>
                        </div>
                    </Card>
                ))}
            </div>

            {/* Model Insights */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:light-bulb" className="w-5 h-5" />
                            <span>Model Insights</span>
                        </div>
                    </Card.Title>
                    <Card.Description>Key factors influencing predictions</Card.Description>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-4">
                        {[
                            {
                                factor: 'Length of Stay',
                                impact: 'High',
                                description: 'Current LOS relative to expected LOS for diagnosis'
                            },
                            {
                                factor: 'Care Milestones',
                                impact: 'Medium',
                                description: 'Completion rate of required care steps'
                            },
                            {
                                factor: 'Resource Availability',
                                impact: 'Low',
                                description: 'Staffing and support services capacity'
                            }
                        ].map((insight) => (
                            <div key={insight.factor} className="flex items-start space-x-4 p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                <div className={`px-2 py-1 rounded text-xs ${
                                    insight.impact === 'High' ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                    insight.impact === 'Medium' ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                    'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    {insight.impact}
                                </div>
                                <div>
                                    <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {insight.factor}
                                    </h4>
                                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                        {insight.description}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </Card.Content>
            </Card>
        </RTDCPageLayout>
    );
};

export default Discharge;
