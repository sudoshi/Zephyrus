import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const ALOSChart = ({ data }) => {
    const currentALOS = data[data.length - 1].alos;
    const previousALOS = data[data.length - 2].alos;
    const trend = ((currentALOS - previousALOS) / previousALOS) * 100;

    return (
        <Card>
            <Card.Header className="flex items-center justify-between">
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:clock" className="w-5 h-5" />
                        <h3 className="font-medium">Average Length of Stay</h3>
                    </div>
                    <div className="flex items-center space-x-2">
                        <span className="text-2xl font-bold">{currentALOS.toFixed(1)}</span>
                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">days</span>
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
            </Card.Header>
            <Card.Content className="h-[350px]">
                <TrendChart
                    data={data}
                    series={[
                            {
                                dataKey: 'alos',
                                name: 'ALOS',
                                color: '#4F46E5',
                            },
                            {
                                dataKey: 'target',
                                name: 'Target',
                                color: '#10B981',
                                strokeDasharray: '5 5',
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
                        yAxis={{
                            formatter: (value) => `${value.toFixed(1)}d`,
                        }}
                    />
            </Card.Content>
        </Card>
    );
};

export default ALOSChart;
