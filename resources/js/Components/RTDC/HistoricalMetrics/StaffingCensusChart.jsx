import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import TrendChart from '@/Components/Analytics/Common/TrendChart';

const StaffingCensusChart = ({ data }) => {
    const currentData = data[data.length - 1];
    const previousData = data[data.length - 2];

    // Calculate trends
    const calculateTrend = (current, previous) => ((current - previous) / previous) * 100;
    const censusChange = calculateTrend(currentData.census, previousData.census);
    const ratioChange = calculateTrend(currentData.nurseRatio, previousData.nurseRatio);

    // Format ratio for display (e.g., 0.25 -> "1:4")
    const formatRatio = (ratio) => {
        const nurses = 1;
        const patients = Math.round(1 / ratio);
        return `${nurses}:${patients}`;
    };

    return (
        <Card>
            <Card.Header className="flex items-center justify-between">
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:user-group" className="w-5 h-5" />
                        <h3 className="font-medium">Staffing vs Census</h3>
                    </div>
                    <div className="flex items-center space-x-4">
                        {/* Current Census */}
                        <div className="flex flex-col items-end">
                            <div className="flex items-center space-x-2">
                                <span className="text-2xl font-bold">{Math.round(currentData.census)}</span>
                                <div className={`flex items-center space-x-1 text-sm ${
                                    censusChange > 0 
                                        ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                        : 'text-healthcare-success dark:text-healthcare-success-dark'
                                }`}>
                                    <Icon 
                                        icon={censusChange > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                        className="w-4 h-4" 
                                    />
                                    <span>{Math.abs(censusChange).toFixed(1)}%</span>
                                </div>
                            </div>
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                current census
                            </span>
                        </div>

                        {/* Nurse Ratio */}
                        <div className="flex flex-col items-end">
                            <div className="flex items-center space-x-2">
                                <span className="text-2xl font-bold">{formatRatio(currentData.nurseRatio)}</span>
                                <div className={`flex items-center space-x-1 text-sm ${
                                    ratioChange > 0 
                                        ? 'text-healthcare-success dark:text-healthcare-success-dark'
                                        : 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                }`}>
                                    <Icon 
                                        icon={ratioChange > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                        className="w-4 h-4" 
                                    />
                                    <span>{Math.abs(ratioChange).toFixed(1)}%</span>
                                </div>
                            </div>
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                nurse ratio
                            </span>
                        </div>
                    </div>
            </Card.Header>
            <Card.Content className="h-[350px]">
            <TrendChart
                data={data}
                        series={[
                            {
                                dataKey: 'census',
                                name: 'Census',
                                color: '#4F46E5',
                                yAxisId: 'census',
                            },
                            {
                                dataKey: 'nurseRatio',
                                name: 'Nurse Ratio',
                                color: '#10B981',
                                yAxisId: 'ratio',
                                formatter: (value) => formatRatio(value),
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
                                id: 'census',
                                formatter: (value) => Math.round(value),
                                orientation: 'left',
                            },
                            {
                                id: 'ratio',
                                formatter: (value) => formatRatio(value),
                                orientation: 'right',
                            },
                        ]}
                    />
            </Card.Content>
        </Card>
    );
};

export default StaffingCensusChart;
