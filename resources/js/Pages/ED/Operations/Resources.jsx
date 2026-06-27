import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

// Status token name -> the paired text + chip classes (color is never the only
// signal; every status is rendered alongside a label and an icon).
const STATUS_STYLES = {
    critical: {
        chip: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
        bar: 'bg-healthcare-critical dark:bg-healthcare-critical-dark',
        icon: 'heroicons:exclamation-triangle',
        label: 'Critical',
    },
    warning: {
        chip: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
        bar: 'bg-healthcare-warning dark:bg-healthcare-warning-dark',
        icon: 'heroicons:exclamation-circle',
        label: 'Tight',
    },
    success: {
        chip: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
        bar: 'bg-healthcare-success dark:bg-healthcare-success-dark',
        icon: 'heroicons:check-circle',
        label: 'Healthy',
    },
};

const CATEGORY_META = {
    Rooms: { icon: 'heroicons:building-office-2', label: 'Rooms & Beds' },
    Staffing: { icon: 'heroicons:user-group', label: 'Staffing' },
    Equipment: { icon: 'heroicons:cube', label: 'Equipment' },
};

const statusStyle = (status) => STATUS_STYLES[status] ?? STATUS_STYLES.success;

const StatusBadge = ({ status }) => {
    const s = statusStyle(status);
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${s.chip}`}
        >
            <Icon icon={s.icon} className="h-3.5 w-3.5" />
            {s.label}
        </span>
    );
};

const UtilizationBar = ({ value, status }) => {
    const s = statusStyle(status);
    const width = Math.min(100, Math.max(0, value));
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-24 overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${s.bar}`}
                    style={{ width: `${width}%` }}
                />
            </div>
            <span className="w-10 text-right text-sm font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {value}%
            </span>
        </div>
    );
};

const EmptyState = () => (
    <div className="flex flex-col items-center justify-center rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark py-12 text-center shadow-sm transition-colors duration-300">
        <div className="mb-4 rounded-full bg-healthcare-info/10 dark:bg-healthcare-info-dark/10 p-4">
            <Icon
                icon="heroicons:cube-transparent"
                className="h-8 w-8 text-healthcare-info dark:text-healthcare-info-dark"
            />
        </div>
        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            No resource data available
        </h3>
        <p className="mt-2 max-w-md text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Resource inventory will appear here once the Emergency Department has
            active census activity.
        </p>
    </div>
);

export default function Resources({
    summary = null,
    resources = [],
    capacityChart = null,
    generatedAt = null,
}) {
    const hasData = resources.length > 0;

    // Group resource rows by category for the table sections.
    const grouped = useMemo(() => {
        const order = ['Rooms', 'Staffing', 'Equipment'];
        const buckets = resources.reduce((acc, row) => {
            (acc[row.category] = acc[row.category] || []).push(row);
            return acc;
        }, {});
        return order
            .filter((cat) => buckets[cat]?.length)
            .map((cat) => ({ category: cat, rows: buckets[cat] }));
    }, [resources]);

    const chartData = useMemo(() => {
        if (!capacityChart) return null;
        return {
            labels: capacityChart.labels,
            datasets: [
                {
                    label: 'Capacity',
                    data: capacityChart.capacity,
                    backgroundColor: 'rgb(var(--color-healthcare-info))',
                    borderRadius: 4,
                    maxBarThickness: 28,
                },
                {
                    label: 'Current Demand',
                    data: capacityChart.demand,
                    backgroundColor: 'rgb(var(--color-healthcare-primary))',
                    borderRadius: 4,
                    maxBarThickness: 28,
                },
            ],
        };
    }, [capacityChart]);

    const chartOptions = useMemo(
        () => ({
            plugins: { legend: { display: true, position: 'top' } },
        }),
        []
    );

    const generatedLabel = useMemo(() => {
        if (!generatedAt) return null;
        try {
            return new Date(generatedAt).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return null;
        }
    }, [generatedAt]);

    return (
        <DashboardLayout>
            <Head title="Resource Management - Emergency" />
            <PageContentLayout
                title="Resource Management"
                subtitle="ED bed, staffing, and equipment capacity against live demand"
                headerContent={
                    generatedLabel && (
                        <div className="flex items-center gap-1.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            <Icon icon="heroicons:clock" className="h-4 w-4" />
                            <span className="tabular-nums">Updated {generatedLabel}</span>
                        </div>
                    )
                }
            >
                {!hasData || !summary ? (
                    <EmptyState />
                ) : (
                    <div className="space-y-6">
                        {/* KPI tiles */}
                        <MetricsCardGroup cols={4}>
                            <MetricsCard
                                title="Bed Occupancy"
                                value={`${summary.occupancy.value}%`}
                                icon="heroicons:building-office-2"
                                trend={summary.occupancy.status === 'success' ? 'up' : 'down'}
                                trendValue={summary.occupancy.value}
                                trendFormatter={(v) => `${v}%`}
                                description={`${summary.occupancy.occupied} of ${summary.occupancy.total} staffed beds`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Available Beds"
                                value={summary.availableBeds.value.toString()}
                                icon="heroicons:home-modern"
                                trend={summary.availableBeds.status === 'success' ? 'up' : 'down'}
                                trendValue={summary.availableBeds.cleaning}
                                trendFormatter={(v) => `${v} cleaning`}
                                description={`${summary.availableBeds.blocked} blocked`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Nurse Coverage"
                                value={`${summary.nurseCoverage.value}%`}
                                icon="heroicons:user-group"
                                trend={summary.nurseCoverage.status === 'success' ? 'up' : 'down'}
                                trendValue={summary.nurseCoverage.present}
                                trendFormatter={(v) => `${v} present`}
                                description={`${summary.nurseCoverage.required} required`}
                                comparison={null}
                            />
                            <MetricsCard
                                title="Equipment In Use"
                                value={`${summary.equipmentUtilization.value}%`}
                                icon="heroicons:cube"
                                trend={summary.equipmentUtilization.status === 'success' ? 'up' : 'down'}
                                trendValue={summary.equipmentUtilization.inUse}
                                trendFormatter={(v) => `${v} active`}
                                description={`${summary.equipmentUtilization.total} units total`}
                                comparison={null}
                            />
                        </MetricsCardGroup>

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            {/* Resource inventory table */}
                            <Card className="lg:col-span-2">
                                <Card.Header>
                                    <Card.Title>
                                        <div className="flex items-center space-x-2">
                                            <Icon icon="heroicons:squares-2x2" className="h-5 w-5" />
                                            <span>Resource Inventory</span>
                                        </div>
                                    </Card.Title>
                                    <Card.Description>
                                        Capacity, current use, and availability by resource class
                                    </Card.Description>
                                </Card.Header>
                                <Card.Content className="p-0">
                                    <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        <thead>
                                            <tr className="text-left text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                <th className="px-4 py-3">Resource</th>
                                                <th className="px-4 py-3 text-right">In Use</th>
                                                <th className="px-4 py-3 text-right">Available</th>
                                                <th className="px-4 py-3">Utilization</th>
                                                <th className="px-4 py-3">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {grouped.map(({ category, rows }) => (
                                                <React.Fragment key={category}>
                                                    <tr className="bg-healthcare-background dark:bg-healthcare-background-dark">
                                                        <td
                                                            colSpan={5}
                                                            className="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                        >
                                                            <span className="inline-flex items-center gap-1.5">
                                                                <Icon
                                                                    icon={CATEGORY_META[category]?.icon ?? 'heroicons:cube'}
                                                                    className="h-4 w-4"
                                                                />
                                                                {CATEGORY_META[category]?.label ?? category}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    {rows.map((row) => (
                                                        <tr
                                                            key={row.id}
                                                            className="transition-colors duration-200 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                                                        >
                                                            <td className="px-4 py-3">
                                                                <div className="flex items-center gap-2.5">
                                                                    <Icon
                                                                        icon={row.icon}
                                                                        className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                                    />
                                                                    <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                        {row.name}
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                {row.inUse}
                                                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                    {' / '}
                                                                    {row.total}
                                                                </span>
                                                            </td>
                                                            <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                                {row.available}
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <UtilizationBar
                                                                    value={row.utilization}
                                                                    status={row.status}
                                                                />
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <StatusBadge status={row.status} />
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </React.Fragment>
                                            ))}
                                        </tbody>
                                    </table>
                                </Card.Content>
                            </Card>

                            {/* Capacity vs demand chart */}
                            <Card>
                                <Card.Header>
                                    <Card.Title>
                                        <div className="flex items-center space-x-2">
                                            <Icon icon="heroicons:chart-bar" className="h-5 w-5" />
                                            <span>Capacity vs Demand</span>
                                        </div>
                                    </Card.Title>
                                    <Card.Description>
                                        Available capacity against current demand by class
                                    </Card.Description>
                                </Card.Header>
                                <Card.Content>
                                    {chartData ? (
                                        <div className="h-72">
                                            <BarChart data={chartData} options={chartOptions} />
                                        </div>
                                    ) : (
                                        <p className="py-12 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            No capacity data available.
                                        </p>
                                    )}
                                    {summary.boarding && (
                                        <div className="mt-4 flex items-center justify-between rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark p-3">
                                            <div className="flex items-center gap-2">
                                                <Icon
                                                    icon="heroicons:arrow-right-on-rectangle"
                                                    className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                />
                                                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    Boarding (awaiting beds)
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {summary.boarding.value}
                                                </span>
                                                <StatusBadge status={summary.boarding.status} />
                                            </div>
                                        </div>
                                    )}
                                </Card.Content>
                            </Card>
                        </div>
                    </div>
                )}
            </PageContentLayout>
        </DashboardLayout>
    );
}
