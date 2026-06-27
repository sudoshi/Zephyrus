import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

// ED Resource Management rebuilt on the gold-standard design system: the KPI wall
// is one MetricGrid of KpiTiles, the resource inventory table and the
// capacity-vs-demand chart live in Panels under Section headers. All values are
// server-computed from the live `prod` schema (ResourceService over seeded
// census activity); the page renders an empty state rather than fabricating data.

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

// Map a service status ('success' = healthy) to a four-color status level.
const kpiStatus = (status) => (status === 'success' ? 'success' : 'warning');

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

    const kpiMetrics = summary
        ? [
              metric({
                  key: 'bed-occupancy', label: 'Bed Occupancy', value: Number(summary.occupancy.value ?? 0), unit: '%',
                  status: kpiStatus(summary.occupancy.status), goodWhenDown: true,
                  caption: `${summary.occupancy.occupied} of ${summary.occupancy.total} staffed beds`,
                  definition: 'Share of staffed ED beds currently occupied.',
              }),
              metric({
                  key: 'available-beds', label: 'Available Beds', value: Number(summary.availableBeds.value ?? 0),
                  status: kpiStatus(summary.availableBeds.status),
                  caption: `${summary.availableBeds.cleaning} cleaning · ${summary.availableBeds.blocked} blocked`,
                  definition: 'Clean, staffed ED beds immediately available for placement.',
              }),
              metric({
                  key: 'nurse-coverage', label: 'Nurse Coverage', value: Number(summary.nurseCoverage.value ?? 0), unit: '%',
                  status: kpiStatus(summary.nurseCoverage.status),
                  caption: `${summary.nurseCoverage.present} present · ${summary.nurseCoverage.required} required`,
                  definition: 'Nurses present against the required staffing level for current census.',
              }),
              metric({
                  key: 'equipment-in-use', label: 'Equipment In Use', value: Number(summary.equipmentUtilization.value ?? 0), unit: '%',
                  status: kpiStatus(summary.equipmentUtilization.status), goodWhenDown: true,
                  caption: `${summary.equipmentUtilization.inUse} active · ${summary.equipmentUtilization.total} units total`,
                  definition: 'Share of tracked ED equipment currently in active use.',
              }),
          ]
        : [];

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
                    <Panel className="p-4">
                        <EmptyState
                            message="No resource data available. Inventory will appear here once the Emergency Department has active census activity."
                            icon="heroicons:cube-transparent"
                        />
                    </Panel>
                ) : (
                    <div className="flex flex-col gap-5">
                        <Section title="Capacity overview" icon="heroicons:squares-2x2"
                                 summary={`${summary.occupancy.value}% occupancy · ${summary.availableBeds.value} beds open`}>
                            <MetricGrid metrics={kpiMetrics} />
                        </Section>

                        <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                            <Section title="Resource Inventory" icon="heroicons:squares-2x2"
                                     summary="Capacity, current use, and availability by resource class"
                                     className="lg:col-span-2">
                                <Panel className="p-0">
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
                                </Panel>
                            </Section>

                            <Section title="Capacity vs Demand" icon="heroicons:chart-bar"
                                     summary="Available capacity against current demand by class">
                                <Panel className="p-4">
                                    {chartData ? (
                                        <div className="h-72">
                                            <BarChart data={chartData} options={chartOptions} />
                                        </div>
                                    ) : (
                                        <EmptyState message="No capacity data available." icon="heroicons:chart-bar" />
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
                                </Panel>
                            </Section>
                        </div>
                    </div>
                )}
            </PageContentLayout>
        </DashboardLayout>
    );
}
