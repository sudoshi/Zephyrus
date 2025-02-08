
import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const QualityIndicatorsChart = ({ data }) => {
    const currentData = data[data.length - 1];
    const previousData = data[data.length - 2];

    // Calculate trends for each metric
    const calculateTrend = (current, previous) => ((current - previous) / previous) * 100;

    const metrics = [
        {
            key: 'readmissionRate',
            label: 'Readmission Rate',
            icon: 'heroicons:arrow-path',
            current: currentData.readmissionRate,
            trend: calculateTrend(currentData.readmissionRate, previousData.readmissionRate),
            unit: '%',
            color: '#4F46E5',
            isNegative: true, // Lower is better
        },
        {
            key: 'hacRate',
            label: 'HAC Rate',
            icon: 'heroicons:exclamation-circle',
            current: currentData.hacRate,
            trend: calculateTrend(currentData.hacRate, previousData.hacRate),
            unit: '%',
            color: '#EF4444',
            isNegative: true, // Lower is better
        },
        {
            key: 'satisfactionScore',
            label: 'Patient Satisfaction',
            icon: 'heroicons:heart',
            current: currentData.satisfactionScore,
            trend: calculateTrend(currentData.satisfactionScore, previousData.satisfactionScore),
            unit: '%',
            color: '#10B981',
            isNegative: false, // Higher is better
        },
    ];

    return (
        <Card>
            <Card.Header>
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                        <h3 className="font-medium">Quality Indicators</h3>
                    </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                    {metrics.map((metric) => (
                        <div key={metric.key} className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                            <div className="flex items-center space-x-2 mb-2">
                                <Icon icon={metric.icon} className="w-4 h-4" style={{ color: metric.color }} />
                                <span className="text-sm font-medium">{metric.label}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-1">
                                    <span className="text-xl font-bold">{metric.current.toFixed(1)}</span>
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {metric.unit}
                                    </span>
                                </div>
                                <div className={`flex items-center space-x-1 text-sm ${
                                    (metric.trend > 0) === !metric.isNegative
                                        ? 'text-healthcare-success dark:text-healthcare-success-dark'
                                        : 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                }`}>
                                    <Icon 
                                        icon={metric.trend > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                        className="w-4 h-4" 
                                    />
                                    <span>{Math.abs(metric.trend).toFixed(1)}%</span>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </Card.Header>
                <Card.Content className="h-[350px]">
                        <TrendChart
                            data={data}
                        series={metrics.map(metric => ({
                            dataKey: metric.key,
                            name: metric.label,
                            color: metric.color,
                        }))}
                        xAxis={{
                            dataKey: 'date',
                            type: 'category',
                            formatter: (value) =>
                                new Date(value).toLocaleDateString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                }),
                        }}
                        yAxis={{
                            formatter: (value) => `${value.toFixed(1)}%`,
                        }}
                    />
            </Card.Content>
        </Card>
    );
};

export default QualityIndicatorsChart;
