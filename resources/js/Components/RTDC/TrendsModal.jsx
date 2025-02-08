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
                <div className="relative w-full max-w-6xl bg-white dark:bg-gray-800 rounded-xl shadow-2xl transform transition-all">
                    {/* Header */}
                    <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-3">
                                <Icon 
                                    icon="heroicons:chart-bar" 
                                    className="w-6 h-6 text-indigo-600 dark:text-indigo-400" 
                                />
                                <h2 className="text-xl font-semibold text-gray-900 dark:text-gray-100">
                                    Service Wait Time Trends
                                </h2>
                            </div>
                            <button
                                onClick={onClose}
                                className="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 transition-colors"
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
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
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
                                            className="w-full pl-10 pr-4 py-2 text-sm font-medium rounded-md border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors appearance-none"
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
                                            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
                                        />
                                        <Icon 
                                            icon="heroicons:chevron-down" 
                                            className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
                                        />
                                    </div>
                                </div>

                                {/* Service Selection */}
                                <div className="flex-1">
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Select Service
                                    </label>
                                    <div className="relative">
                                        <select
                                            value={selectedService || ''}
                                            onChange={(e) => setSelectedService(e.target.value)}
                                            disabled={!selectedUnit}
                                            className={`w-full pl-10 pr-4 py-2 text-sm font-medium rounded-md border transition-colors appearance-none ${
                                                !selectedUnit
                                                    ? 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed'
                                                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
                                            } border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500`}
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
                                            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
                                        />
                                        <Icon 
                                            icon="heroicons:chevron-down" 
                                            className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
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
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'
                                        } border border-gray-200 dark:border-gray-600 first:rounded-l-lg last:rounded-r-lg -ml-px first:ml-0 transition-colors`}
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
                                                : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'
                                        } border border-gray-200 dark:border-gray-600 first:rounded-l-lg last:rounded-r-lg -ml-px first:ml-0 transition-colors`}
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
                                                className="block pl-10 pr-4 py-2 text-sm font-medium rounded-md border bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors appearance-none"
                                            >
                                                <option value="time">Sort by Time</option>
                                                <option value="value">Sort by Wait Time</option>
                                                <option value="trend">Sort by Trend</option>
                                            </select>
                                            <Icon 
                                                icon="heroicons:arrows-up-down" 
                                                className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
                                            />
                                            <Icon 
                                                icon="heroicons:chevron-down" 
                                                className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500 dark:text-gray-400 pointer-events-none"
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
                                <div className="lg:col-span-3 bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                                    {viewMode === 'chart' ? (
                                        <div className="h-[400px]">
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
                                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead>
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        Time
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        Wait Time (min)
                                                    </th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                                {sortedData.map((point, index) => (
                                                    <tr key={index}>
                                                        <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                                            {new Date(point.time).toLocaleTimeString()}
                                                        </td>
                                                        <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                                            {point.value}
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                                point.value > 120 ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' :
                                                                point.value > 90 ? 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' :
                                                                point.value > 60 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                                                                'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
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
                                    <div className="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                                        <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                                            Summary Statistics
                                        </h3>
                                        <dl className="space-y-4">
                                            <div>
                                                <dt className="text-xs text-gray-500 dark:text-gray-400">
                                                    Average Wait Time
                                                </dt>
                                                <dd className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                                    {stats?.avg} min
                                                </dd>
                                            </div>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <dt className="text-xs text-gray-500 dark:text-gray-400">
                                                        Min
                                                    </dt>
                                                    <dd className="text-lg font-medium text-gray-900 dark:text-gray-100">
                                                        {stats?.min} min
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-gray-500 dark:text-gray-400">
                                                        Max
                                                    </dt>
                                                    <dd className="text-lg font-medium text-gray-900 dark:text-gray-100">
                                                        {stats?.max} min
                                                    </dd>
                                                </div>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-gray-500 dark:text-gray-400">
                                                    Utilization Rate
                                                </dt>
                                                <dd className="text-lg font-medium text-gray-900 dark:text-gray-100">
                                                    {stats?.utilization}%
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-gray-500 dark:text-gray-400">
                                                    Trend
                                                </dt>
                                                <dd className="text-sm font-medium text-gray-900 dark:text-gray-100 flex items-center">
                                                    <Icon 
                                                        icon={
                                                            stats?.trend === 'increasing' ? 'heroicons:arrow-trending-up' :
                                                            stats?.trend === 'decreasing' ? 'heroicons:arrow-trending-down' :
                                                            'heroicons:minus'
                                                        }
                                                        className={`w-5 h-5 mr-1 ${
                                                            stats?.trend === 'increasing' ? 'text-red-500' :
                                                            stats?.trend === 'decreasing' ? 'text-green-500' :
                                                            'text-gray-500'
                                                        }`}
                                                    />
                                                    {stats?.trend.charAt(0).toUpperCase() + stats?.trend.slice(1)}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>

                                    {/* Peak Times */}
                                    <div className="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                                        <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                                            Peak Times
                                        </h3>
                                        <div className="space-y-2">
                                            {stats?.peakTimes.map((time, index) => (
                                                <div
                                                    key={index}
                                                    className="flex items-center text-sm text-gray-600 dark:text-gray-300"
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
                                    <div className="bg-white dark:bg-gray-900 rounded-lg shadow p-4">
                                    <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                                        Service Thresholds
                                    </h3>
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                                Critical
                                            </span>
                                            <span className="text-sm font-medium text-red-600 dark:text-red-400">
                                                &gt; 120 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                                Warning
                                            </span>
                                            <span className="text-sm font-medium text-orange-600 dark:text-orange-400">
                                                90-120 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                                Moderate
                                            </span>
                                            <span className="text-sm font-medium text-yellow-600 dark:text-yellow-400">
                                                60-90 min
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                                Normal
                                            </span>
                                            <span className="text-sm font-medium text-green-600 dark:text-green-400">
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
                    <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                            Last updated: {new Date().toLocaleTimeString()}
                        </div>
                        <div className="flex items-center space-x-3">
                            <button
                                className="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
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
