import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import { BarChart } from '@/Components/Dashboard/Charts/BarChart.jsx';

const MetricCard = ({ title, value, trend, description, icon }) => (
    <div className="p-4 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark">
        <div className="flex items-center justify-between mb-2">
            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {title}
            </div>
            <Icon icon={icon} className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
        </div>
        <div className="flex items-end justify-between">
            <div className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {value}
            </div>
            {trend && (
                <div className={`flex items-center text-sm ${
                    trend > 0 
                        ? 'text-healthcare-success' 
                        : 'text-healthcare-critical'
                }`}>
                    <Icon 
                        icon={trend > 0 ? 'heroicons:arrow-trending-up' : 'heroicons:arrow-trending-down'} 
                        className="w-4 h-4 mr-1"
                    />
                    {Math.abs(trend)}%
                </div>
            )}
        </div>
        {description && (
            <div className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {description}
            </div>
        )}
    </div>
);

const ExecutiveMetrics = ({ metrics }) => {

    return (
        <div className="space-y-6">
            {/* Key Metrics */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                            <span>Today's Performance</span>
                        </div>
                    </Card.Title>
                </Card.Header>
                <Card.Content>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <MetricCard
                            title="Discharge Target"
                            value={metrics.summary.dischargeTarget.value}
                            trend={metrics.summary.dischargeTarget.trend}
                            description={metrics.summary.dischargeTarget.description}
                            icon="heroicons:arrow-right-circle"
                        />
                        <MetricCard
                            title="Bed Utilization"
                            value={metrics.summary.bedUtilization.value}
                            trend={metrics.summary.bedUtilization.trend}
                            description={metrics.summary.bedUtilization.description}
                            icon="heroicons:home"
                        />
                        <MetricCard
                            title="Average LOS"
                            value={metrics.summary.avgLOS.value}
                            trend={metrics.summary.avgLOS.trend}
                            description={metrics.summary.avgLOS.description}
                            icon="heroicons:clock"
                        />
                        <MetricCard
                            title="Red Plans Executed"
                            value={metrics.summary.redPlans.value}
                            description={metrics.summary.redPlans.description}
                            icon="heroicons:exclamation-triangle"
                        />
                    </div>
                </Card.Content>
            </Card>

            {/* Improvement Initiatives */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:arrow-path" className="w-5 h-5" />
                            <span>Active Improvements</span>
                        </div>
                    </Card.Title>
                </Card.Header>
                <Card.Content>
                    <div className="space-y-4">
                        {metrics.improvements.map(improvement => (
                            <div 
                                key={improvement.id}
                                className="p-4 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark"
                            >
                                <div className="flex items-center justify-between mb-3">
                                    <div className="flex items-center space-x-3">
                                        <div className={`px-2 py-1 rounded text-xs font-medium
                                            ${improvement.status === 'completed' 
                                                ? 'bg-healthcare-success/10 text-healthcare-success'
                                                : 'bg-healthcare-warning/10 text-healthcare-warning'
                                            }`}
                                        >
                                            {improvement.status.toUpperCase()}
                                        </div>
                                        <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {improvement.title}
                                        </span>
                                    </div>
                                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Impact: {improvement.impact}
                                    </div>
                                </div>
                                <div className="flex items-center justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    <div>Metrics: {improvement.metrics}</div>
                                    <div>Owner: {improvement.owner}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </Card.Content>
            </Card>

            {/* Delay Analysis */}
            <Card>
                <Card.Header>
                    <Card.Title>
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:clock" className="w-5 h-5" />
                            <span>Delay Analysis</span>
                        </div>
                    </Card.Title>
                </Card.Header>
                <Card.Content>
                    <div className="h-64 w-full">
                        <BarChart 
                            data={metrics.delays} 
                            options={{
                                indexAxis: 'y',
                                plugins: {
                                    legend: {
                                        display: false
                                    }
                                },
                                scales: {
                                    x: {
                                        grid: {
                                            display: false
                                        }
                                    },
                                    y: {
                                        grid: {
                                            display: false
                                        }
                                    }
                                }
                            }}
                        />
                    </div>
                </Card.Content>
            </Card>
        </div>
    );
};

export default ExecutiveMetrics;
