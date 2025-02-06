import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import DrillDownModal from '@/Components/Dashboard/DrillDownModal';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, ReferenceLine } from 'recharts';

// Utility function for status colors
const getStatusColor = (rate) => {
    if (rate >= 90) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
    if (rate >= 80) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
    return 'text-healthcare-success dark:text-healthcare-success-dark';
};

const CapacityDetailsModal = ({ bedTypes, isOpen, onClose }) => {
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

    return (
        <DrillDownModal
            isOpen={isOpen}
            onClose={onClose}
            title={
                <div className="flex items-center space-x-2">
                    <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                    <span>Capacity Details</span>
                </div>
            }
        >
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
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </DrillDownModal>
    );
};

const CompactCapacityOverview = ({ bedTypes }) => {
    const [isExpanded, setIsExpanded] = useState(false);
    const [showDetails, setShowDetails] = useState(false);

    // Calculate total metrics
    const totalMetrics = Object.values(bedTypes).reduce(
        (acc, data) => ({
            total: acc.total + data.total,
            occupied: acc.occupied + data.occupied,
            pending: acc.pending + data.pending,
        }),
        { total: 0, occupied: 0, pending: 0 }
    );

    const occupancyRate = Math.round((totalMetrics.occupied / totalMetrics.total) * 100);

    // Find most critical bed type
    const criticalBedType = Object.entries(bedTypes).reduce((critical, [type, data]) => {
        const rate = Math.round((data.occupied / data.total) * 100);
        if (!critical || rate > critical.rate) {
            return { type, rate };
        }
        return critical;
    }, null);

    return (
        <Card>
            <div className="p-4">
                {/* Summary Bar */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:building-office-2" className="w-5 h-5" />
                            <span className="font-medium">Capacity:</span>
                        </div>
                        <div className="flex items-center space-x-3 text-sm">
                            <span className={getStatusColor(occupancyRate)}>
                                {occupancyRate}% Occupied
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                ({totalMetrics.occupied}/{totalMetrics.total} beds)
                            </span>
                        </div>
                    </div>
                    <div className="flex items-center space-x-2">
                        <button
                            onClick={() => setShowDetails(true)}
                            className="p-1 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-150"
                        >
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                        </button>
                        <button
                            onClick={() => setIsExpanded(!isExpanded)}
                            className="p-1 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark rounded-md transition-colors duration-150"
                        >
                            <Icon
                                icon={isExpanded ? 'heroicons:chevron-up' : 'heroicons:chevron-down'}
                                className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                            />
                        </button>
                    </div>
                </div>

                {/* Critical Status Preview */}
                {!isExpanded && criticalBedType && (
                    <div className="mt-2">
                        <div className={`px-3 py-2 rounded-md ${
                            criticalBedType.rate >= 90 ? 'bg-healthcare-critical/5 dark:bg-healthcare-critical-dark/5' : 'bg-healthcare-warning/5 dark:bg-healthcare-warning-dark/5'
                        }`}>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-2">
                                    <Icon 
                                        icon={criticalBedType.rate >= 90 ? 'heroicons:exclamation-triangle' : 'heroicons:exclamation-circle'} 
                                        className={`w-4 h-4 ${getStatusColor(criticalBedType.rate)}`}
                                    />
                                    <span className={`text-sm font-medium ${getStatusColor(criticalBedType.rate)}`}>
                                        {criticalBedType.type} beds at {criticalBedType.rate}% capacity
                                    </span>
                                </div>
                                <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                    {totalMetrics.pending} pending transfers
                                </span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Expanded View */}
                {isExpanded && (
                    <div className="mt-3 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                        {Object.entries(bedTypes).map(([type, data]) => {
                            const rate = Math.round((data.occupied / data.total) * 100);
                            return (
                                <div key={type} className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-sm font-medium capitalize">{type}</span>
                                        <span className={`text-sm font-medium ${getStatusColor(rate)}`}>
                                            {rate}%
                                        </span>
                                    </div>
                                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {data.occupied}/{data.total} beds
                                        {data.pending > 0 && (
                                            <span className="ml-1 text-healthcare-warning dark:text-healthcare-warning-dark">
                                                (+{data.pending} pending)
                                            </span>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Details Modal */}
            <CapacityDetailsModal
                bedTypes={bedTypes}
                isOpen={showDetails}
                onClose={() => setShowDetails(false)}
            />
        </Card>
    );
};

export default CompactCapacityOverview;
