import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import {
    serviceCategories,
    services,
    generateDemoData,
} from '@/mock-data/rtdc';
import { trendUtils } from '@/mock-data/rtdc-trends';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import TrendsModal from '@/Components/RTDC/TrendsModal';
import { formatDurationMinutes } from '@/lib/duration';

// Ancillary Services rebuilt on the gold-standard design system: the summary
// KPIs are a single MetricGrid of KpiTiles (status dot + value + caption); the
// service matrix/table and drill-down detail live in Panels under Section
// headers. Per-unit ancillary data is server-computed and deterministic
// (`unitServices` prop), falling back to the local generator only when absent —
// no fabricated trends or sparklines are introduced.

const getStatusClasses = (value) => {
    if (value > 120) return 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark';
    if (value > 90) return 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark';
    if (value > 60) return 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark';
    return 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark';
};

const formatMinutes = (value) => {
    if (value === null || value === undefined || value === '') return formatDurationMinutes(null);
    const minutes = Number(value);

    return formatDurationMinutes(Number.isFinite(minutes) ? minutes : null);
};
const formatConfiguredAverage = (value) => {
    const minutes = Number.parseFloat(value);

    return Number.isFinite(minutes) ? formatDurationMinutes(minutes) : value;
};

const OWNED_DRILL_SERVICES = ['lab', 'pharmacy'];

const ownedDrillHref = (serviceInfo, service) => {
    if (serviceInfo?.category !== 'imaging' && !OWNED_DRILL_SERVICES.includes(serviceInfo?.id)) {
        return null;
    }

    // Only server-owned units can satisfy the worklist's exists:prod.units
    // validation. The legacy client fallback is intentionally non-drillable.
    return service?.drillHref ?? null;
};

// Human-readable owner for the drill affordance. The server owns the href; this
// only labels the handoff so the accessible name/title matches the destination.
const drillOwnerLabel = (serviceInfo) => {
    if (serviceInfo?.id === 'lab') return 'Laboratory Flow Board';
    if (serviceInfo?.id === 'pharmacy') return 'Medication Flow Board';

    return 'Radiology worklist';
};

const AncillaryServices = ({ unitServices = null }) => {
    const [viewMode, setViewMode] = useState('table'); // 'table' or 'matrix'
    const [selectedUnit, setSelectedUnit] = useState(null);
    const [expandedService, setExpandedService] = useState(null);
    const [showTrends, setShowTrends] = useState(false);
    const [trendsModalOpen, setTrendsModalOpen] = useState(false);
    const [selectedTrendData, setSelectedTrendData] = useState(null);
    // Server-computed, deterministic per-unit ancillary data (falls back to the
    // local generator if the prop is ever absent).
    const [demoData] = useState(() =>
        unitServices && unitServices.length > 0 ? unitServices : generateDemoData()
    );

    // Calculate summary metrics
    const calculateMetrics = () => {
        let totalRequests = 0;
        let criticalDelays = 0;
        let crossDepartmentImpact = 0;
        let totalWait = 0;

        if (!demoData || !Array.isArray(demoData)) {
            return {
                totalRequests: 0,
                criticalDelays: 0,
                resourceUtilization: 0,
                crossDepartmentImpact: 0,
            };
        }

        const unitsWithDelay = new Set();

        // Safely iterate through units and their services
        demoData.forEach((unit) => {
            if (!unit || !unit.services) return;
            Object.entries(unit.services || {}).forEach(([serviceId, service]) => {
                if (service) {
                    totalRequests++;
                    totalWait += service.value ?? 0;
                    const serviceInfo = services.find((s) => s.id === serviceId);
                    if (serviceInfo) {
                        const delayThreshold = serviceInfo.category === 'imaging' ? 120 : 90;
                        if (service.value > delayThreshold) {
                            criticalDelays++;
                            unitsWithDelay.add(unit.id);
                        }
                    }
                }
            });
        });

        crossDepartmentImpact = unitsWithDelay.size;

        // Mean wait time across active requests, expressed as a 0-100 utilization
        // proxy (a 180-min wait reads as 100% utilization of the service window).
        const resourceUtilization =
            totalRequests > 0 ? Math.min(Math.round((totalWait / totalRequests / 180) * 100), 100) : 0;

        return {
            totalRequests,
            criticalDelays,
            resourceUtilization,
            crossDepartmentImpact,
        };
    };

    const metrics = calculateMetrics();

    // Gold-standard KPI wall: real, server-derived counts. Status follows the
    // page's own thresholds; no sparklines (no honest per-metric series exists).
    const kpiMetrics = [
        metric({
            key: 'critical-delays',
            label: 'Critical delays',
            value: metrics.criticalDelays,
            status: metrics.criticalDelays > 5 ? 'critical' : metrics.criticalDelays > 0 ? 'warning' : 'success',
            goodWhenDown: true,
            caption: 'Services over their category delay threshold',
            definition: `Service entries whose current wait exceeds the category threshold (${formatMinutes(120)} imaging, ${formatMinutes(90)} otherwise).`,
        }),
        metric({
            key: 'active-requests',
            label: 'Active requests',
            value: metrics.totalRequests,
            status: 'info',
            caption: 'Current active ancillary service requests',
            definition: 'Total in-progress ancillary service requests across all monitored units.',
        }),
        metric({
            key: 'resource-utilization',
            label: 'Resource utilization',
            value: metrics.resourceUtilization,
            unit: '%',
            status: metrics.resourceUtilization > 90 ? 'critical' : metrics.resourceUtilization > 75 ? 'warning' : 'success',
            target: 75,
            goodWhenDown: true,
            caption: 'Mean wait as a share of the service window',
            definition: `Average request wait time across services expressed against a ${formatMinutes(180)} service window.`,
        }),
        metric({
            key: 'cross-dept-impact',
            label: 'Cross-dept impact',
            value: metrics.crossDepartmentImpact,
            status: metrics.crossDepartmentImpact > 3 ? 'warning' : 'success',
            goodWhenDown: true,
            caption: `Of ${demoData?.length ?? 0} units experiencing delays`,
            definition: 'Number of distinct units with at least one service over its delay threshold.',
        }),
    ];

    // Render trend chart
    const renderTrendChart = (data) => {
        if (!data?.trend) return null;
        return (
            <TrendChart
                data={data.trend}
                series={[{ dataKey: 'value', name: 'Wait Time', color: '#3B82F6' }]}
                xAxis={{ dataKey: 'time' }}
                yAxis={{ domain: ['auto', 'auto'], formatter: formatMinutes }}
                height={200}
            />
        );
    };

    // Render Matrix View
    const renderMatrixView = () => (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {demoData.map((unit) => (
                <Panel
                    key={unit.id}
                    className="group p-0 cursor-pointer"
                    onClick={() => setSelectedUnit(unit)}
                >
                    <div className="bg-healthcare-background dark:bg-healthcare-background-dark border-b border-healthcare-border dark:border-healthcare-border-dark px-4 py-3">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark group-hover:opacity-80 dark:group-hover:opacity-80 transition-colors">
                                {unit.name}
                            </h3>
                            <Icon
                                icon="heroicons:chevron-right"
                                className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark group-hover:opacity-80 dark:group-hover:opacity-80 transition-transform group-hover:translate-x-1"
                            />
                        </div>
                    </div>
                    <div className="p-4">
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
                                        <h4 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {category.name}
                                        </h4>
                                        <div className="grid gap-2">
                                            {categoryServices.map(([serviceId, service]) => {
                                                const serviceInfo = services.find(
                                                    (s) => s.id === serviceId
                                                );
                                                const drillHref = ownedDrillHref(serviceInfo, service);
                                                const drillOwner = drillOwnerLabel(serviceInfo);
                                                return (
                                                    <div
                                                        key={serviceId}
                                                        className={`flex items-center p-2.5 rounded-lg ${getStatusClasses(
                                                            service.value
                                                        )} transition-all duration-200 hover:scale-[1.02] cursor-pointer relative group/service`}
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            if (drillHref) {
                                                                router.visit(drillHref);

                                                                return;
                                                            }
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
                                                            <span className="whitespace-nowrap text-sm font-semibold tabular-nums">
                                                                {formatMinutes(service.value)}
                                                            </span>
                                                            {drillHref && (
                                                                <a
                                                                    href={drillHref}
                                                                    aria-label={`Open ${serviceInfo?.name ?? serviceId} ${drillOwner} for ${unit.name}`}
                                                                    title={`Open ${drillOwner}`}
                                                                    className="rounded p-1 hover:bg-black/10 focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:hover:bg-white/10"
                                                                >
                                                                    <Icon icon="heroicons:arrow-top-right-on-square" className="h-4 w-4" />
                                                                </a>
                                                            )}
                                                        </div>

                                                        {/* Tooltip */}
                                                        <div className="absolute invisible group-hover/service:visible opacity-0 group-hover/service:opacity-100 transition-all duration-200 z-10 bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-gray-900 dark:bg-gray-700 text-white text-xs rounded shadow-lg whitespace-nowrap">
                                                            Avg Time: {formatConfiguredAverage(serviceInfo?.avgTime)}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </Panel>
            ))}
        </div>
    );

    // Render Table View
    const renderTableView = () => (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                    <tr>
                        <th className="px-4 py-3 text-left text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark w-32">
                            Unit
                        </th>
                        {Object.entries(serviceCategories).map(([catKey, category]) => (
                            <th
                                key={catKey}
                                colSpan={category.services.length}
                                className="px-4 py-3 text-center text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-l border-healthcare-border dark:border-healthcare-border-dark"
                            >
                                {category.name}
                            </th>
                        ))}
                    </tr>
                    <tr>
                        <th className="px-4 py-3 bg-healthcare-background dark:bg-healthcare-background-dark"></th>
                        {Object.values(serviceCategories).flatMap((category) =>
                            category.services.map((serviceId) => {
                                const serviceInfo = services.find((s) => s.id === serviceId);
                                return (
                                    <th
                                        key={serviceId}
                                        className="px-4 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark bg-healthcare-background dark:bg-healthcare-background-dark border-l border-healthcare-border dark:border-healthcare-border-dark"
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
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark">
                    {demoData.map((unit) => (
<tr
    key={unit.id}
    className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark cursor-pointer transition-colors"
    onClick={() => setSelectedUnit(unit)}
>
                            <td className="px-4 py-3 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark whitespace-nowrap">
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
                                            className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark border-l border-healthcare-border dark:border-healthcare-border-dark"
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
                                                        className={`flex items-center gap-2 whitespace-nowrap px-3 py-1.5 rounded-full text-sm font-medium transition-all duration-200 hover:scale-105 ${getStatusClasses(
                                                            service.value
                                                        )}`}
                                                    >
                                                        <span>{formatMinutes(service.value)}</span>
                                                    </div>

                                                    {expandedService === serviceId && (
                                                        <div className="absolute z-10 w-64 p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-xl border mt-2">
                                                            <h4 className="text-md font-semibold mb-2">
                                                                {serviceInfo?.name}
                                                            </h4>
                                                            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-2">
                                                                {serviceInfo?.description}
                                                            </p>
                                                            <p className="text-sm mb-1">
                                                                <strong>Current Wait:</strong>{' '}
                                                                {formatMinutes(service.value)}
                                                            </p>
                                                            <p className="text-sm mb-1">
                                                                <strong>Average Time:</strong>{' '}
                                                                {formatConfiguredAverage(serviceInfo?.avgTime)}
                                                            </p>
                                                            <p className="text-sm mb-2">
                                                                <strong>Criteria:</strong>{' '}
                                                                {serviceInfo?.criteria.join(', ')}
                                                            </p>
                                                            {ownedDrillHref(serviceInfo, service) && (
                                                                <a
                                                                    href={ownedDrillHref(serviceInfo, service)}
                                                                    className="inline-flex items-center gap-1 text-sm font-medium text-healthcare-primary hover:underline"
                                                                >
                                                                    Open {drillOwnerLabel(serviceInfo)}
                                                                    <Icon icon="heroicons:arrow-top-right-on-square" className="h-4 w-4" />
                                                                </a>
                                                            )}
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
                                                                    yAxis={{ domain: ['auto', 'auto'], formatter: formatMinutes }}
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
            <div className="flex flex-col gap-5">
                {/* Summary Metrics */}
                <Section
                    title="Service load"
                    icon="heroicons:squares-plus"
                    summary={`${metrics.totalRequests} active requests across ${demoData?.length ?? 0} units`}
                >
                    <MetricGrid metrics={kpiMetrics} />
                </Section>

                {/* Service detail matrix / table */}
                <Section
                    title="Service details"
                    icon="heroicons:table-cells"
                    summary={viewMode === 'table' ? 'Wait time by unit and service category' : 'Per-unit service status matrix'}
                    actions={
                        <div className="flex space-x-2">
                            <button
                                className={`inline-flex items-center px-3 py-1.5 ${
                                    viewMode === 'table'
                                        ? 'healthcare-button-primary'
                                        : 'healthcare-button-secondary'
                                } text-sm font-medium rounded-l-md`}
                                onClick={() => setViewMode('table')}
                            >
                                <Icon icon="heroicons:table-cells" className="w-4 h-4 mr-1.5" />
                                Table
                            </button>
                            <button
                                className={`inline-flex items-center px-3 py-1.5 ${
                                    viewMode === 'matrix'
                                        ? 'healthcare-button-primary'
                                        : 'healthcare-button-secondary'
                                } text-sm font-medium rounded-r-md -ml-px`}
                                onClick={() => setViewMode('matrix')}
                            >
                                <Icon icon="heroicons:squares-2x2" className="w-4 h-4 mr-1.5" />
                                Matrix
                            </button>
                            <button
                                className="ml-2 inline-flex items-center px-3 py-1.5 healthcare-button healthcare-button-primary text-sm font-medium rounded-md"
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
                                <Icon icon="heroicons:chart-bar" className="w-4 h-4 mr-1.5" />
                                Show Trends
                            </button>
                        </div>
                    }
                >
                    {viewMode === 'table' ? (
                        <Panel className="p-0">{renderTableView()}</Panel>
                    ) : (
                        renderMatrixView()
                    )}
                </Section>
            </div>

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
                            <div className="px-6 py-4 border-b border-healthcare-border dark:border-healthcare-border-dark flex justify-between items-center">
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
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    {Object.entries(selectedUnit.services)
                                        .filter(([_, service]) => service)
                                        .map(([serviceId, service]) => {
                                            const serviceInfo = services.find(
                                                (s) => s.id === serviceId
                                            );
                                            return (
                                                <Panel key={serviceId} className="p-4">
                                                    <h4 className="text-md font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3">
                                                        {serviceInfo?.name}
                                                    </h4>
                                                    <div className="mb-4">
                                                        <span
                                                            className={`inline-flex whitespace-nowrap px-2 py-1 rounded-full text-xs font-semibold ${getStatusClasses(
                                                                service.value
                                                            )}`}
                                                        >
                                                            Current Wait: {formatMinutes(service.value)}
                                                        </span>
                                                    </div>
                                                    <p className="text-sm mb-2">
                                                        {serviceInfo?.description}
                                                    </p>
                                                    <p className="text-sm mb-1">
                                                        <strong>Average Time:</strong>{' '}
                                                        {formatConfiguredAverage(serviceInfo?.avgTime)}
                                                    </p>
                                                    <p className="text-sm mb-1">
                                                        <strong>Criteria:</strong>{' '}
                                                        {serviceInfo?.criteria.join(', ')}
                                                    </p>
                                                    {ownedDrillHref(serviceInfo, service) && (
                                                        <a
                                                            href={ownedDrillHref(serviceInfo, service)}
                                                            className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-healthcare-primary hover:underline"
                                                        >
                                                            Open {drillOwnerLabel(serviceInfo)}
                                                            <Icon icon="heroicons:arrow-top-right-on-square" className="h-4 w-4" />
                                                        </a>
                                                    )}
                                                    {showTrends && renderTrendChart(service)}
                                                </Panel>
                                            );
                                        })}
                                </div>
                            </div>
                            <div className="px-6 py-3 border-t border-healthcare-border dark:border-healthcare-border-dark">
                                <button
                                    className="inline-flex items-center px-4 py-2 healthcare-button-primary text-sm font-medium rounded-md hover:bg-healthcare-primary-hover"
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
