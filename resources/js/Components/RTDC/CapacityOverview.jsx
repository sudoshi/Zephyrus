import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, ReferenceLine } from 'recharts';
import { Icon } from '@iconify/react';

const CapacityOverview = ({ bedTypes }) => {
    // Transform bed types data for the stacked bar chart
    const chartData = Object.entries(bedTypes).map(([type, data]) => ({
        name: type.charAt(0).toUpperCase() + type.slice(1),
        occupied: data.occupied,
        available: data.total - data.occupied,
        pending: data.pending,
        total: data.total,
        occupancyRate: Math.round((data.occupied / data.total) * 100)
    }));

    // Custom tooltip for the stacked bar chart
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            return (
                <div className="bg-white p-4 border rounded shadow">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="space-y-1 mt-2">
                        <p className="text-healthcare-primary">Occupied: {data.occupied} beds</p>
                        <p className="text-healthcare-success">Available: {data.available} beds</p>
                        <p className="text-healthcare-warning">Pending: {data.pending} beds</p>
                        <p className="text-healthcare-info mt-2">Occupancy Rate: {data.occupancyRate}%</p>
                    </div>
                </div>
            );
        }
        return null;
    };

    // Status indicator based on occupancy rate
    const getStatusColor = (rate) => {
        if (rate >= 90) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
        if (rate >= 80) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
        return 'text-healthcare-success dark:text-healthcare-success-dark';
    };

    return (
        <div className="space-y-6">
            {/* Stacked Bar Chart */}
            <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
                <div className="w-full h-[350px] min-h-[350px] aspect-[16/9]">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={chartData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                            <CartesianGrid strokeDasharray="3 3" />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip content={<CustomTooltip />} />
                            <Legend />
                            <ReferenceLine y={80} stroke="#F59E0B" strokeDasharray="3 3" label="80% Threshold" />
                            <ReferenceLine y={90} stroke="#EF4444" strokeDasharray="3 3" label="90% Threshold" />
                            <Bar dataKey="occupied" stackId="a" fill="#4F46E5" name="Occupied" />
                            <Bar dataKey="available" stackId="a" fill="#10B981" name="Available" />
                            <Bar dataKey="pending" fill="#F59E0B" name="Pending" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </div>

            {/* Detailed Metrics Grid */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                {chartData.map((type) => (
                    <div key={type.name} className="bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
                        <div className="flex items-center justify-between mb-2">
                            <h4 className="font-medium capitalize">{type.name}</h4>
                            <Icon 
                                icon="heroicons:circle-stack" 
                                className={`w-5 h-5 ${getStatusColor(type.occupancyRate)}`}
                            />
                        </div>
                        <div className="space-y-2">
                            <div className="flex justify-between items-center">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Occupancy
                                </span>
                                <span className="font-medium">
                                    {type.occupancyRate}%
                                </span>
                            </div>
                            <div className="flex justify-between items-center">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Available
                                </span>
                                <span className="font-medium">
                                    {type.available}
                                </span>
                            </div>
                            <div className="flex justify-between items-center">
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Pending
                                </span>
                                <span className="font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                                    {type.pending}
                                </span>
                            </div>
                            <div className="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <div className="flex justify-between items-center">
                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Total Beds
                                    </span>
                                    <span className="font-medium">
                                        {type.total}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default CapacityOverview;
