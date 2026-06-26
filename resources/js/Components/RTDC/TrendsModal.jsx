import React, { useState, useMemo, useEffect } from 'react';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import { trendUtils } from '@/mock-data/rtdc-trends';
import { services, serviceCategories } from '@/mock-data/rtdc';

const timeRanges = [
    { id: '24h', label: 'Last 24 Hours' },
    { id: '7d', label: 'Last 7 Days' },
    { id: '30d', label: 'Last 30 Days' }
];

const viewModes = [
    { id: 'chart', label: 'Chart View', icon: 'heroicons:chart-bar' },
    { id: 'table', label: 'Table View', icon: 'heroicons:table-cells' }
];

const TrendsModal = ({ isOpen, onClose, data, units }) => {
    const [timeRange, setTimeRange] = useState('24h');
    const [viewMode, setViewMode] = useState('chart');
    const [selectedUnit, setSelectedUnit] = useState(null);
    const [selectedService, setSelectedService] = useState(null);
    const [trendData, setTrendData] = useState(null);
    const [sortBy, setSortBy] = useState('time'); // 'time', 'value', 'trend'

    // Initialize data when modal opens
    useEffect(() => {
        if (isOpen && data?.allTrends?.length > 0) {
            const firstTrend = data.allTrends[0];
            setTrendData(firstTrend.trend);
            
            // Find and set the initial unit and service
            const unit = units?.find(u => u.name === firstTrend.unitName);
            if (unit) {
                setSelectedUnit(unit);
                const serviceId = Object.entries(unit.services).find(([id, _]) => {
                    const serviceInfo = services.find(s => s.id === id);
                    return serviceInfo?.name === firstTrend.serviceName;
                })?.[0];
                if (serviceId) {
                    setSelectedService(serviceId);
                }
            }
            
            setTimeRange('24h');
        }
    }, [isOpen, data, units]);

    // Generate trend data when selections change
    useEffect(() => {
        if (selectedUnit && selectedService) {
            const serviceInfo = services.find(s => s.id === selectedService);
            if (serviceInfo) {
                // Try to find existing trend data first
                const existingTrend = data?.allTrends?.find(t => 
                    t.unitName === selectedUnit.name && 
                    t.serviceName === serviceInfo.name
                )?.trend;

                if (existingTrend) {
                    setTrendData(existingTrend);
                } else {
                    // Generate new trend data if not found
                    const newTrendData = trendUtils.generateServiceTrend(serviceInfo.category, timeRange);
                    setTrendData(newTrendData);
                }
            }
        }
    }, [selectedUnit, selectedService, timeRange, data]);

    // Available services for selected unit
    const availableServices = useMemo(() => {
        if (!selectedUnit) return [];
        return Object.entries(selectedUnit.services)
            .filter(([_, service]) => service)
            .map(([serviceId]) => {
                const serviceInfo = services.find(s => s.id === serviceId);
                return {
                    id: serviceId,
                    name: serviceInfo?.name || serviceId,
                    category: serviceInfo?.category
                };
            });
    }, [selectedUnit]);

    // Sort trend data
    const sortedData = useMemo(() => {
        if (!trendData) return [];
        const data = [...trendData];
        switch (sortBy) {
            case 'value':
                return data.sort((a, b) => b.value - a.value);
            case 'trend':
                const trend = trendUtils.analyzeTrend(data);
                return trend === 'increasing' ? data.sort((a, b) => b.value - a.value) :
                       trend === 'decreasing' ? data.sort((a, b) => a.value - b.value) :
                       data;
            default: // 'time'
                return data.sort((a, b) => new Date(a.time) - new Date(b.time));
        }
    }, [trendData, sortBy]);

    // Calculate statistics from sorted data
    const stats = useMemo(() => {
        if (!sortedData?.length) return null;
        
        const values = sortedData.map(d => d.value);
        return {
            min: Math.min(...values),
            max: Math.max(...values),
            avg: Math.round(values.reduce((a, b) => a + b, 0) / values.length),
            peak: sortedData.reduce((peak, point) => 
                point.value > peak.value ? point : peak
            , sortedData[0]),
            utilization: trendUtils.calculateUtilization(sortedData),
            trend: trendUtils.analyzeTrend(sortedData),
            peakTimes: trendUtils.getPeakTimes(sortedData)
        };
    }, [sortedData]);

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            {/* Backdrop */}
            <div className="fixed inset-0 backdrop-blur-sm bg-black/30 transition-opacity" />

            {/* Modal */}
            <div className="flex min-h-screen items-center justify-center p-4">
                <div className="relative w-full max-w-6xl bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-xl shadow-2xl transform transition-all">
                    {/* Header */}
                    <div className="px-6 py-4 border-b border-healthcare-border dark:border-healthcare-border-dark">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <Icon 
                                    icon="heroicons:chart-bar" 
                                    className="w-6 h-6 text-indigo-600 dark:text-indigo-400" 
                                />
                                <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    Service Wait Time Trends
                                </h2>
                            </div>
                            <button
                                onClick={onClose}
                                className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors"
                            >
                                <span className="sr-only">Close</span>
                                <Icon icon="heroicons:x-mark" className="w-6 h-6" />
                            </button>
                        </div>

                        {/* Filtering Steps */}
                        <div className="mt-4 space-y-4">
                            {/* Unit and Service Selection */}
                            <div className="flex gap-4">
                                {/* Unit Selection */}
                                <div className="flex-1">
                                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                                        Select Unit
                                    </label>
                                    <div className="relative">
                                        <select
                                            value={selectedUnit?.id || ''}
                                            onChange={(e) => {
                                                const unit = units?.find(u => u.id.toString() === e.target.value);
                                                setSelectedUnit(unit);
                                                setSelectedService(null);
                                            }}
                                            className="w-full pl-10 pr-4 py-2 text-sm font-medium rounded-md border bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors appearance-none"
                                        >
                                            <option value="">Select a unit</option>
                                            {units?.map((unit) => (
                                                <option key={unit.id} value={unit.id}>
                                                    {unit.name}
                                                </option>
                                            ))}
                                        </select>
                                        <Icon 
                                            icon="heroicons:building-office-2" 
                                            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                        />
                                        <Icon 
                                            icon="heroicons:chevron-down" 
                                            className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                        />
                                    </div>
                                </div>

                                {/* Service Selection */}
                                <div className="flex-1">
                                    <label className="block text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                                        Select Service
                                    </label>
                                    <div className="relative">
                                        <select
                                            value={selectedService || ''}
                                            onChange={(e) => setSelectedService(e.target.value)}
                                            disabled={!selectedUnit}
                                            className={`w-full pl-10 pr-4 py-2 text-sm font-medium rounded-md border transition-colors appearance-none ${
                                                !selectedUnit
                                                    ? 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark cursor-not-allowed'
                                                    : 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark'
                                            } border-healthcare-border dark:border-healthcare-border-dark focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500`}
                                        >
                                            <option value="">Select a service</option>
                                            {Object.entries(serviceCategories).map(([categoryKey, category]) => (
                                                <optgroup key={categoryKey} label={category.name}>
                                                    {category.services.map((serviceId) => {
                                                        const serviceInfo = services.find(s => s.id === serviceId);
                                                        return (
                                                            <option key={serviceId} value={serviceId}>
                                                                {serviceInfo?.name || serviceId}
                                                            </option>
                                                        );
                                                    })}
                                                </optgroup>
                                            ))}
                                        </select>
                                        <Icon 
                                            icon={
                                                selectedService ? 
                                                    services.find(s => s.id === selectedService)?.category === 'imaging' ? 'heroicons:photo' :
                                                    services.find(s => s.id === selectedService)?.category === 'therapy' ? 'heroicons:heart' :
                                                    'heroicons:cog'
                                                : 'heroicons:squares-2x2'
                                            }
                                            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                        />
                                        <Icon 
                                            icon="heroicons:chevron-down" 
                                            className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Time Range and View Controls */}
                            {selectedUnit && selectedService && (
                                <div className="flex flex-wrap items-center justify-between gap-4">
                                    {/* Time Range Selector */}
                                    <div className="flex rounded-lg shadow-sm">
                                {timeRanges.map((range) => (
                                    <button
                                        key={range.id}
                                        onClick={() => setTimeRange(range.id)}
                                        className={`px-4 py-2 text-sm font-medium ${
                                            timeRange === range.id
                                                ? 'bg-indigo-600 text-white'
                                                : 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark'
                                        } border border-healthcare-border dark:border-healthcare-border-dark first:rounded-l-lg last:rounded-r-lg -ml-px first:ml-0 transition-colors`}
                                    >
                                        {range.label}
                                    </button>
                                ))}
                            </div>

                                    {/* View Mode and Sort Controls */}
                                    <div className="flex items-center space-x-4">
                                        {/* View Mode Toggle */}
                                        <div className="flex rounded-lg shadow-sm">
                                {viewModes.map((mode) => (
                                    <button
                                        key={mode.id}
                                        onClick={() => setViewMode(mode.id)}
                                        className={`inline-flex items-center px-4 py-2 text-sm font-medium ${
                                            viewMode === mode.id
                                                ? 'bg-indigo-600 text-white'
                                                : 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark'
                                        } border border-healthcare-border dark:border-healthcare-border-dark first:rounded-l-lg last:rounded-r-lg -ml-px first:ml-0 transition-colors`}
                                    >
                                        <Icon icon={mode.icon} className="w-5 h-5 mr-2" />
                                        {mode.label}
                                    </button>
                                ))}
                                        </div>
                                        {/* Sort Control */}
                                        <div className="relative ml-4">
                                            <select
                                                value={sortBy}
                                                onChange={(e) => setSortBy(e.target.value)}
                                                className="block pl-10 pr-4 py-2 text-sm font-medium rounded-md border bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors appearance-none"
                                            >
                                                <option value="time">Sort by Time</option>
                                                <option value="value">Sort by Wait Time</option>
                                                <option value="trend">Sort by Trend</option>
                                            </select>
                                            <Icon 
                                                icon="heroicons:arrows-up-down" 
                                                className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                            />
                                            <Icon 
                                                icon="heroicons:chevron-down" 
                                                className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark pointer-events-none"
                                            />
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Content - Only show if unit and service are selected */}
                    {selectedUnit && selectedService && trendData && (
                        <div className="p-6">
                            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                                {/* Main Chart/Table */}
                                <div className="lg:col-span-3 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
                                    {viewMode === 'chart' ? (
                                        <div className="h-[340px]">
                                            <TrendChart
                                                data={sortedData}
                                                series={[{
                                                    dataKey: 'value',
                                                    name: 'Wait Time',
                                                    color: '#3B82F6'
                                                }]}
                                                xAxis={{ dataKey: 'time' }}
                                                yAxis={{ 
                                                    domain: ['auto', 'auto'],
                                                    label: 'Minutes'
                                                }}
                                                referenceLines={[
                                                    {
                                                        y: 60,
                                                        color: 'rgb(34, 197, 94)',
                                                        strokeDasharray: '3 3',
                                                        label: 'Goal: 60 min'
                                                    }
                                                ]}
                                            />
                                        </div>
                                    ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            <thead>
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Time
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Wait Time (min)
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                                {sortedData.map((point, index) => (
                                                    <tr key={index}>
                                                        <td className="px-4 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {new Date(point.time).toLocaleTimeString()}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {point.value}
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                                point.value > 120 ? 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark' :
                                                                point.value > 90 ? 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark' :
                                                                point.value > 60 ? 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark' :
                                                                'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/20 dark:text-healthcare-success-dark'
                                                            }`}>
                                                                {point.value > 120 ? 'Critical' :
                                                                point.value > 90 ? 'Warning' :
                                                                point.value > 60 ? 'Moderate' :
                                                                'Normal'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    )}
                                </div>

                                {/* Stats Panel */}
                                <div className="lg:col-span-1 space-y-4">
                                    {/* Summary Stats */}
                                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
                                        <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">
                                            Summary Statistics
                                        </h3>
                                        <dl className="space-y-4">
                                            <div>
                                                <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Average Wait Time
                                                </dt>
                                                <dd className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {stats?.avg} min
                                                </dd>
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Min
                                                    </dt>
                                                    <dd className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {stats?.min} min
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        Max
                                                    </dt>
                                                    <dd className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {stats?.max} min
                                                    </dd>
                                                </div>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Utilization Rate
                                                </dt>
                                                <dd className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {stats?.utilization}%
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Trend
                                                </dt>
                                                <dd className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center">
                                                    <Icon 
                                                        icon={
                                                            stats?.trend === 'increasing' ? 'heroicons:arrow-trending-up' :
                                                            stats?.trend === 'decreasing' ? 'heroicons:arrow-trending-down' :
                                                            'heroicons:minus'
                                                        }
                                                        className={`w-5 h-5 mr-1 ${
                                                            stats?.trend === 'increasing' ? 'text-healthcare-critical dark:text-healthcare-critical-dark' :
                                                            stats?.trend === 'decreasing' ? 'text-healthcare-success dark:text-healthcare-success-dark' :
                                                            'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                        }`}
                                                    />
                                                    {stats?.trend.charAt(0).toUpperCase() + stats?.trend.slice(1)}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>

                                    {/* Peak Times */}
                                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
                                        <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">
                                            Peak Times
                                        </h3>
                                        <div className="space-y-2">
                                            {stats?.peakTimes.map((time, index) => (
                                                <div
                                                    key={index}
                                                    className="flex items-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                >
                                                    <Icon
                                                        icon="heroicons:clock"
                                                        className="w-4 h-4 mr-2 text-indigo-500"
                                                    />
                                                    {time}
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Thresholds */}
                                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
                                    <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-3">
                                        Service Thresholds
                                    </h3>
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Critical
                                            </span>
                                            <span className="text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                                                &gt; 120 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Warning
                                            </span>
                                            <span className="text-sm font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                                                90-120 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Moderate
                                            </span>
                                            <span className="text-sm font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                                                60-90 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Normal
                                            </span>
                                            <span className="text-sm font-medium text-healthcare-success dark:text-healthcare-success-dark">
                                                &lt; 60 min
                                            </span>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Footer */}
                    <div className="px-6 py-4 border-t border-healthcare-border dark:border-healthcare-border-dark flex items-center justify-between">
                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Last updated: {new Date().toLocaleTimeString()}
                        </div>
                        <div className="flex items-center space-x-3">
                            <button
                                className="inline-flex items-center px-4 py-2 border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark bg-healthcare-surface dark:bg-healthcare-surface-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                onClick={() => {
                                    // Export functionality would go here
                                }}
                            >
                                <Icon icon="heroicons:document-arrow-down" className="w-5 h-5 mr-2" />
                                Export Data
                            </button>
                            <button
                                className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                onClick={onClose}
                            >
                                Done
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default TrendsModal;
