import React, { useState, useEffect } from 'react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import {
    unitServicesData,
    serviceCategories,
    services,
    generateDemoData,
} from '@/mock-data/rtdc';
import { trendUtils } from '@/mock-data/rtdc-trends';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import TrendsModal from '@/Components/RTDC/TrendsModal';



const getStatusClasses = (value) => {
    if (value > 120) return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
    if (value > 90) return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
    if (value > 60) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
    return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
};

// Helper function to simulate trend data
const getRandomTrend = () => (Math.random() > 0.5 ? 'up' : 'down');
const getRandomDelta = () => `${Math.floor(Math.random() * 10) + 1}%`;

const AncillaryServices = () => {
    const [viewMode, setViewMode] = useState('table'); // 'table' or 'matrix'
    const [selectedUnit, setSelectedUnit] = useState(null);
    const [expandedService, setExpandedService] = useState(null);
    const [showTrends, setShowTrends] = useState(false);
    const [trendsModalOpen, setTrendsModalOpen] = useState(false);
    const [selectedTrendData, setSelectedTrendData] = useState(null);
    const [demoData, setDemoData] = useState(() => generateDemoData());
    const [lastUpdated, setLastUpdated] = useState(new Date());

    // Real-time updates every 30 seconds
    useEffect(() => {
        // Initial data load
        setDemoData(generateDemoData());
        
        const interval = setInterval(() => {
            setDemoData(generateDemoData());
            setLastUpdated(new Date());
        }, 30000);
        
        return () => clearInterval(interval);
    }, []);

    // Calculate summary metrics
    const calculateMetrics = () => {
        let totalRequests = 0;
        let criticalDelays = 0;
        let resourceUtilization = 0;
        let crossDepartmentImpact = 0;

        const resourceUsageCounts = {};

        if (!demoData || !Array.isArray(demoData)) {
            return {
                totalRequests: 0,
                criticalDelays: 0,
                resourceUtilization: 0,
                crossDepartmentImpact: 0
            };
        }

        // Safely iterate through units and their services
        demoData.forEach((unit) => {
            if (!unit || !unit.services) return;
            Object.entries(unit.services || {}).forEach(([serviceId, service]) => {
                if (service) {
                    totalRequests++;
                    const serviceInfo = services.find((s) => s.id === serviceId);
                    if (serviceInfo) {
                        const delayThreshold = serviceInfo.category === 'imaging' ? 120 : 90;
                        if (service.value > delayThreshold) {
                            criticalDelays++;
                        }
                    }

                    // Simulate resource utilization
                    if (!resourceUsageCounts[serviceId]) {
                        resourceUsageCounts[serviceId] = 0;
                    }
                    resourceUsageCounts[serviceId] += Math.floor(Math.random() * 10) + 1;

                    // Simulate cross-department impact
                    crossDepartmentImpact += Math.floor(Math.random() * 2);
                }
            });
        });

        // Calculate average resource utilization
        if (totalRequests > 0) {
            const totalResourceUsage = Object.values(resourceUsageCounts).reduce(
                (acc, val) => acc + val,
                0
            );
            resourceUtilization = Math.min((totalResourceUsage / totalRequests) * 5, 100);
        }

        return {
            totalRequests,
            criticalDelays,
            resourceUtilization: Math.round(resourceUtilization),
            crossDepartmentImpact,
        };
    };

    const metrics = calculateMetrics();

    // Define the metrics to display
    const quickStats = [
        {
            label: 'Critical Delays',
            value: metrics.criticalDelays.toString(),
            trend: getRandomTrend(),
            delta: getRandomDelta(),
            icon: 'heroicons:exclamation-triangle',
            color: 'healthcare-critical',
            description: 'Services over threshold',
        },
        {
            label: 'Active Requests',
            value: metrics.totalRequests.toString(),
            trend: getRandomTrend(),
            delta: getRandomDelta(),
            icon: 'heroicons:clipboard-document-list',
            color: 'healthcare-info',
            description: 'Current active service requests',
        },
        {
            label: 'Resource Utilization',
            value: `${metrics.resourceUtilization}%`,
            trend: getRandomTrend(),
            delta: getRandomDelta(),
            icon: 'heroicons:chart-pie',
            color: 'healthcare-warning',
            description: 'Average utilization across services',
        },
        {
            label: 'Cross-Dept Impact',
            value: metrics.crossDepartmentImpact.toString(),
            trend: getRandomTrend(),
            delta: getRandomDelta(),
            icon: 'heroicons:building-office-2',
            color: 'healthcare-success',
            description: 'Departments experiencing delays',
        },
    ];

    // Render trend chart
    const renderTrendChart = (data) => {
        if (!data?.trend) return null;
        return (
            <TrendChart
                data={data.trend}
                series={[{ dataKey: 'value', name: 'Wait Time', color: '#3B82F6' }]}
                xAxis={{ dataKey: 'time' }}
                yAxis={{ domain: ['auto', 'auto'] }}
                height={200}
            />
        );
    };

    // Render Matrix View
    const renderMatrixView = () => (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            {demoData.map((unit) => (
                <Card
                    key={unit.id}
                    className="group hover:shadow-xl transition-all duration-300 cursor-pointer healthcare-card border border-gray-200 dark:border-gray-700"
                    onClick={() => setSelectedUnit(unit)}
                >
                    <Card.Header className="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <Card.Title className="text-lg font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors">
                                {unit.name}
                            </Card.Title>
                            <Icon 
                                icon="heroicons:chevron-right" 
                                className="w-5 h-5 text-gray-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-transform group-hover:translate-x-1"
                            />
                        </div>
                    </Card.Header>
                    <Card.Content className="p-4">
                        <div className="space-y-3">
                            {Object.entries(serviceCategories).map(([categoryKey, category]) => {
                                const categoryServices = Object.entries(unit.services)
                                    .filter(([serviceId, service]) => 
                                        service && 
                                        services.find(s => s.id === serviceId)?.category === categoryKey
                                    );
                                
                                if (categoryServices.length === 0) return null;

                                return (
                                    <div key={categoryKey} className="space-y-2">
                                        <h4 className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                            {category.name}
                                        </h4>
                                        <div className="grid gap-2">
                                            {categoryServices.map(([serviceId, service]) => {
                                                const serviceInfo = services.find(
                                                    (s) => s.id === serviceId
                                                );
                                                return (
                                                    <div
                                                        key={serviceId}
                                                        className={`flex items-center p-2.5 rounded-lg ${getStatusClasses(
                                                            service.value
                                                        )} transition-all duration-200 hover:scale-[1.02] cursor-pointer relative group/service`}
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setExpandedService(
                                                                expandedService === serviceId ? null : serviceId
                                                            );
                                                        }}
                                                    >
                                                        <div className="flex items-center space-x-2">
                                                            <Icon 
                                                                icon={
                                                                    service.value > 120 ? 'heroicons:exclamation-circle' :
                                                                    service.value > 90 ? 'heroicons:clock' :
                                                                    'heroicons:check-circle'
                                                                }
                                                                className="w-5 h-5"
                                                            />
                                                            <span className="text-sm font-medium">
                                                                {serviceInfo?.name || serviceId}
                                                            </span>
                                                        </div>
                                                        <div className="ml-auto flex items-center space-x-2">
                                                            <span className="text-sm font-semibold tabular-nums">
                                                                {service.value}
                                                            </span>
                                                            <span className="text-xs opacity-75">
                                                                min
                                                            </span>
                                                        </div>

                                                        {/* Tooltip */}
                                                        <div className="absolute invisible group-hover/service:visible opacity-0 group-hover/service:opacity-100 transition-all duration-200 z-10 bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-gray-900 dark:bg-gray-700 text-white text-xs rounded shadow-lg whitespace-nowrap">
                                                            Avg Time: {serviceInfo?.avgTime}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </Card.Content>
                </Card>
            ))}
        </div>
    );

    // Render Table View
    const renderTableView = () => (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 healthcare-card">
                <thead className="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th className="px-4 py-3 text-left text-sm font-semibold text-gray-900 dark:text-gray-100 w-32">
                            Unit
                        </th>
                        {Object.entries(serviceCategories).map(([catKey, category]) => (
                            <th
                                key={catKey}
                                colSpan={category.services.length}
                                className="px-4 py-3 text-center text-sm font-semibold text-gray-900 dark:text-gray-100 border-l border-gray-200 dark:border-gray-700"
                            >
                                {category.name}
                            </th>
                        ))}
                    </tr>
                    <tr>
                        <th className="px-4 py-3 bg-gray-50 dark:bg-gray-800"></th>
                        {Object.values(serviceCategories).flatMap((category) =>
                            category.services.map((serviceId) => {
                                const serviceInfo = services.find((s) => s.id === serviceId);
                                return (
                                    <th
                                        key={serviceId}
                                        className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700"
                                    >
                                        <div
                                            className="flex items-center cursor-pointer group"
                                            onClick={() =>
                                                setExpandedService(
                                                    expandedService === serviceId ? null : serviceId
                                                )
                                            }
                                        >
                                            <Icon
                                                icon="heroicons:chevron-right"
                                                className={`w-4 h-4 mr-1 transition-transform ${
                                                    expandedService === serviceId ? 'rotate-90' : ''
                                                }`}
                                            />
                                            {serviceInfo?.name || serviceId}
                                        </div>
                                    </th>
                                );
                            })
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                    {demoData.map((unit) => (
<tr
    key={unit.id}
    className="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition-colors"
    onClick={() => setSelectedUnit(unit)}
>
                            <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                {unit.name}
                            </td>
                            {Object.values(serviceCategories).flatMap((category) =>
                                category.services.map((serviceId) => {
                                    const service = unit.services[serviceId];
                                    const serviceInfo = services.find(
                                        (s) => s.id === serviceId
                                    );
                                    return (
                                        <td
                                            key={serviceId}
                                            className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 border-l border-gray-200 dark:border-gray-700"
                                        >
                                            {service ? (
                                                <div className="relative group">
                                                    <div
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setExpandedService(
                                                                expandedService === serviceId
                                                                    ? null
                                                                    : serviceId
                                                            );
                                                        }}
                                                        className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium transition-all duration-200 hover:scale-105 ${getStatusClasses(
                                                            service.value
                                                        )}`}
                                                    >
                                                        <span>{service.value}</span>
                                                        <span className="text-xs opacity-75">
                                                            min
                                                        </span>
                                                    </div>

                                                    {expandedService === serviceId && (
                                                        <div className="absolute z-10 w-64 p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-xl border mt-2">
                                                            <h4 className="text-md font-semibold mb-2">
                                                                {serviceInfo?.name}
                                                            </h4>
                                                            <p className="text-sm text-gray-600 dark:text-gray-300 mb-2">
                                                                {serviceInfo?.description}
                                                            </p>
                                                            <p className="text-sm mb-1">
                                                                <strong>Current Wait:</strong>{' '}
                                                                {service.value} min
                                                            </p>
                                                            <p className="text-sm mb-1">
                                                                <strong>Average Time:</strong>{' '}
                                                                {serviceInfo?.avgTime}
                                                            </p>
                                                            <p className="text-sm mb-2">
                                                                <strong>Criteria:</strong>{' '}
                                                                {serviceInfo?.criteria.join(', ')}
                                                            </p>
                                                            {showTrends && (
                                                            <div className="mt-4">
                                                                <TrendChart
                                                                    data={trendUtils.generateServiceTrend(serviceInfo.category)}
                                                                    series={[{
                                                                        dataKey: 'value',
                                                                        name: 'Wait Time',
                                                                        color: '#3B82F6'
                                                                    }]}
                                                                    xAxis={{ dataKey: 'time' }}
                                                                    yAxis={{ domain: ['auto', 'auto'] }}
                                                                    height={200}
                                                                />
                                                            </div>
                                                        )}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">N/A</span>
                                            )}
                                        </td>
                                    );
                                })
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );

    return (
        <RTDCPageLayout
            title="Ancillary Services"
            subtitle="Monitor and track hospital support services"
        >
            {/* Summary Metrics */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                {quickStats.map((stat, index) => (
                    <Card key={index}>
                        <Card.Content>
                            <div className="flex items-center justify-between group">
                                <div className="flex items-center space-x-3">
                                    <div
                                        className={`bg-${stat.color} bg-opacity-10 dark:bg-opacity-20 p-2 rounded-lg group-hover:bg-opacity-20 dark:group-hover:bg-opacity-30 transition-colors duration-300`}
                                    >
                                        <Icon
                                            icon={stat.icon}
                                            className={`w-6 h-6 text-${stat.color} dark:text-${stat.color}-dark`}
                                        />
                                    </div>
                                    <div>
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                            {stat.label}
                                        </div>
                                        <div className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                            {stat.value}
                                        </div>
                                        <div className="text-xs text-gray-500 dark:text-gray-400">
                                            {stat.description}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex flex-col items-end">
                                    <div
                                        className={`flex items-center ${
                                            stat.trend === 'up'
                                                ? 'text-healthcare-success dark:text-healthcare-success-dark'
                                                : 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                        } transition-colors duration-300`}
                                    >
                                        <Icon
                                            icon={
                                                stat.trend === 'up'
                                                    ? 'heroicons:arrow-up'
                                                    : 'heroicons:arrow-down'
                                            }
                                            className="w-4 h-4 mr-1"
                                        />
                                        <span className="text-sm font-medium">{stat.delta}</span>
                                    </div>
                                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300 mt-1">
                                        vs. last hour
                                    </div>
                                </div>
                            </div>
                        </Card.Content>
                    </Card>
                ))}
            </div>

            {/* Main Grid Wrapped in Card */}
            <Card className="healthcare-card">
                <Card.Header className="flex items-center justify-between">
                    <Card.Title>Service Details</Card.Title>
                    {/* View Mode Toggle */}
                    <div className="flex space-x-2">
                        <button
                            className={`inline-flex items-center px-4 py-2 ${
                                viewMode === 'table'
                                    ? 'healthcare-button-primary'
                                    : 'healthcare-button-secondary'
                            } text-sm font-medium rounded-l-md`}
                            onClick={() => setViewMode('table')}
                        >
                            <Icon icon="heroicons:table" className="w-5 h-5 mr-2" />
                            Table View
                        </button>
                        <button
                            className={`inline-flex items-center px-4 py-2 ${
                                viewMode === 'matrix'
                                    ? 'healthcare-button-primary'
                                    : 'healthcare-button-secondary'
                            } text-sm font-medium rounded-r-md -ml-px`}
                            onClick={() => setViewMode('matrix')}
                        >
                            <Icon icon="heroicons:view-grid" className="w-5 h-5 mr-2" />
                            Matrix View
                        </button>
                        <button
                            className="ml-2 inline-flex items-center px-4 py-2 healthcare-button healthcare-button-primary text-sm font-medium rounded-md"
                            onClick={() => {
                                // Generate trend data for all services
                                const allTrends = [];
                                demoData.forEach(unit => {
                                    Object.entries(unit.services).forEach(([serviceId, service]) => {
                                        if (service) {
                                            const serviceInfo = services.find(s => s.id === serviceId);
                                            if (serviceInfo) {
                                                const trend = trendUtils.generateServiceTrend(serviceInfo.category);
                                                allTrends.push({
                                                    unitName: unit.name,
                                                    serviceName: serviceInfo.name,
                                                    trend
                                                });
                                            }
                                        }
                                    });
                                });

                                if (allTrends.length > 0) {
                                    setSelectedTrendData({
                                        trend: allTrends[0].trend,
                                        title: 'Service Wait Times',
                                        allTrends
                                    });
                                }
                                setTrendsModalOpen(true);
                            }}
                        >
                            <Icon icon="heroicons:chart-bar" className="w-5 h-5 mr-2" />
                            Show Trends
                        </button>
                    </div>
                </Card.Header>
                <Card.Content>
                    {/* Main Content */}
                    {viewMode === 'table' ? renderTableView() : renderMatrixView()}
                </Card.Content>
            </Card>

            {/* Drill-down Modal */}
            {selectedUnit && (
                <div
                    className="fixed inset-0 z-50 overflow-y-auto"
                    onClick={() => setSelectedUnit(null)}
                >
                    <div className="flex items-center justify-center min-h-screen px-4">
<div
    className="relative bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-xl w-full max-w-6xl"
    onClick={(e) => e.stopPropagation()}
>
                            <div className="px-6 py-4 border-b border-healthcare-border dark:border-gray-700 flex justify-between items-center">
                                <h3 className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {selectedUnit.name} - Detailed View
                                </h3>
<button
    className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-200"
    onClick={() => setSelectedUnit(null)}
>
                                    <span className="sr-only">Close</span>
                                    &times;
                                </button>
                            </div>
                            <div className="px-6 py-4">
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    {Object.entries(selectedUnit.services)
                                        .filter(([_, service]) => service)
                                        .map(([serviceId, service]) => {
                                            const serviceInfo = services.find(
                                                (s) => s.id === serviceId
                                            );
                                            return (
                                                <Card key={serviceId} className="healthcare-card">
                                                    <Card.Header>
                                                        <Card.Title>
                                                            {serviceInfo?.name}
                                                        </Card.Title>
                                                    </Card.Header>
                                                    <Card.Content>
                                                        <div className="mb-4">
                                                            <span
                                                                className={`px-2 py-1 rounded-full text-xs font-semibold ${getStatusClasses(
                                                                    service.value
                                                                )}`}
                                                            >
                                                                Current Wait: {service.value} min
                                                            </span>
                                                        </div>
                                                        <p className="text-sm mb-2">
                                                            {serviceInfo?.description}
                                                        </p>
                                                        <p className="text-sm mb-1">
                                                            <strong>Average Time:</strong>{' '}
                                                            {serviceInfo?.avgTime}
                                                        </p>
                                                        <p className="text-sm mb-1">
                                                            <strong>Criteria:</strong>{' '}
                                                            {serviceInfo?.criteria.join(', ')}
                                                        </p>
                                                        {showTrends && renderTrendChart(service)}
                                                    </Card.Content>
                                                </Card>
                                            );
                                        })}
                                </div>
                            </div>
                            <div className="px-6 py-3 border-t border-healthcare-border dark:border-gray-700">
                                <button
                                    className="inline-flex items-center px-4 py-2 healthcare-button-primary text-sm font-medium rounded-md hover:bg-blue-700"
                                    onClick={() => setShowTrends(!showTrends)}
                                >
                                    <Icon
                                        icon="heroicons:chart-bar"
                                        className="w-5 h-5 mr-2"
                                    />
                                    Toggle Trends
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Trends Modal */}
            <TrendsModal
                isOpen={trendsModalOpen}
                onClose={() => setTrendsModalOpen(false)}
                data={selectedTrendData}
                units={demoData}
            />
        </RTDCPageLayout>
    );
};

export default AncillaryServices;
