import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const EDConversionChart = ({ data }) => {
    const currentRate = data[data.length - 1].conversionRate;
    const previousRate = data[data.length - 2].conversionRate;
    const trend = ((currentRate - previousRate) / previousRate) * 100;

    const currentVolume = data[data.length - 1].visitVolume;
    const previousVolume = data[data.length - 2].visitVolume;
    const volumeTrend = ((currentVolume - previousVolume) / previousVolume) * 100;

    return (
        <Card>
            <Card.Header className="flex items-center justify-between">
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:arrow-path" className="w-5 h-5" />
                        <h3 className="font-medium">ED-to-Inpatient Conversion</h3>
                    </div>
                    <div className="flex items-center space-x-4">
                        {/* Conversion Rate */}
                        <div className="flex flex-col items-end">
                            <div className="flex items-center space-x-2">
                                <span className="text-2xl font-bold">{currentRate.toFixed(1)}%</span>
                                <div className={`flex items-center space-x-1 text-sm ${
                                    trend < 0 
                                        ? 'text-healthcare-success dark:text-healthcare-success-dark' 
                                        : 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                }`}>
                                    <Icon 
                                        icon={trend < 0 ? 'heroicons:arrow-down' : 'heroicons:arrow-up'} 
                                        className="w-4 h-4" 
                                    />
                                    <span>{Math.abs(trend).toFixed(1)}%</span>
                                </div>
                            </div>
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                conversion rate
                            </span>
                        </div>

                        {/* Visit Volume */}
                        <div className="flex flex-col items-end">
                            <div className="flex items-center space-x-2">
                                <span className="text-2xl font-bold">{currentVolume}</span>
                                <div className={`flex items-center space-x-1 text-sm ${
                                    volumeTrend > 0 
                                        ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                        : 'text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    <Icon 
                                        icon={volumeTrend > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                        className="w-4 h-4" 
                                    />
                                    <span>{Math.abs(volumeTrend).toFixed(1)}%</span>
                                </div>
                            </div>
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                daily visits
                            </span>
                        </div>
                    </div>
            </Card.Header>
                <Card.Content className="h-[350px]">
                    <TrendChart
                        data={data}
                        series={[
                            {
                                dataKey: 'conversionRate',
                                name: 'Conversion Rate',
                                color: '#4F46E5',
                                yAxisId: 'rate',
                            },
                            {
                                dataKey: 'visitVolume',
                                name: 'Visit Volume',
                                color: '#10B981',
                                yAxisId: 'volume',
                            },
                        ]}
                        xAxis={{
                            dataKey: 'date',
                            type: 'category',
                            formatter: (value) =>
                                new Date(value).toLocaleDateString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                }),
                        }}
                        yAxis={[
                            {
                                id: 'rate',
                                formatter: (value) => `${value.toFixed(1)}%`,
                                orientation: 'left',
                            },
                            {
                                id: 'volume',
                                formatter: (value) => value,
                                orientation: 'right',
                            },
                        ]}
                    />
            </Card.Content>
        </Card>
    );
};

export default EDConversionChart;
