import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const BedOccupancyChart = ({ data, serviceLines }) => {
    // Calculate total occupancy for the most recent day
    const currentData = data[data.length - 1];
    const totalOccupancy = serviceLines.reduce((sum, service) => sum + currentData[service], 0);
    const previousData = data[data.length - 2];
    const previousTotal = serviceLines.reduce((sum, service) => sum + previousData[service], 0);
    const trend = ((totalOccupancy - previousTotal) / previousTotal) * 100;

    // Generate colors for each service line
    const colors = {
        Medicine: '#4F46E5',
        Surgery: '#10B981',
        ICU: '#EF4444',
        Pediatrics: '#F59E0B',
        Obstetrics: '#8B5CF6',
    };

    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const total = payload.reduce((sum, entry) => sum + entry.value, 0);
            
            return (
                <div className="bg-white dark:bg-healthcare-background-dark p-4 border rounded shadow">
                    <p className="font-medium text-gray-900 dark:text-white mb-2">
                        {new Date(label).toLocaleDateString('en-US', {
                            month: 'short',
                            day: 'numeric',
                        })}
                    </p>
                    <div className="space-y-1">
                        {payload.map((entry) => (
                            <div key={entry.name} className="flex items-center justify-between space-x-4">
                                <div className="flex items-center space-x-2">
                                    <div
                                        className="w-3 h-3 rounded-full"
                                        style={{ backgroundColor: entry.color }}
                                    />
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {entry.name}
                                    </span>
                                </div>
                                <span className="text-sm font-medium">
                                    {Math.round(entry.value)} beds
                                </span>
                            </div>
                        ))}
                        <div className="border-t border-healthcare-border dark:border-healthcare-border-dark mt-2 pt-2">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">Total</span>
                                <span className="text-sm font-medium">{Math.round(total)} beds</span>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <Card>
            <Card.Header className="flex items-center justify-between">
                    <div className="flex items-center space-x-2">
                        <Icon icon="heroicons:home" className="w-5 h-5" />
                        <h3 className="font-medium">Bed Occupancy by Service</h3>
                    </div>
                    <div className="flex items-center space-x-2">
                        <span className="text-2xl font-bold">{Math.round(totalOccupancy)}</span>
                        <div className="flex flex-col">
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                occupied beds
                            </span>
                            <div className={`flex items-center space-x-1 text-sm ${
                                trend > 0 
                                    ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                    : 'text-healthcare-success dark:text-healthcare-success-dark'
                            }`}>
                                <Icon 
                                    icon={trend > 0 ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                    className="w-4 h-4" 
                                />
                                <span>{Math.abs(trend).toFixed(1)}%</span>
                            </div>
                        </div>
                    </div>
            </Card.Header>
                <Card.Content className="h-[350px]">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={data}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis
                                dataKey="date"
                                tickFormatter={(value) =>
                                    new Date(value).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                    })
                                }
                            />
                            <YAxis />
                            <Tooltip content={<CustomTooltip />} />
                            <Legend />
                            {serviceLines.map((service) => (
                                <Area
                                    key={service}
                                    type="monotone"
                                    dataKey={service}
                                    name={service}
                                    stackId="1"
                                    stroke={colors[service]}
                                    fill={colors[service]}
                                />
                            ))}
                        </AreaChart>
                    </ResponsiveContainer>
            </Card.Content>
        </Card>
    );
};

export default BedOccupancyChart;
