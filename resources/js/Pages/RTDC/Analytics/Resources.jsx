import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

const STATUS_STYLES = {
    critical: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    success: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
};

const STATUS_LABELS = {
    critical: 'Critical',
    warning: 'At risk',
    success: 'Balanced',
};

const PRIORITY_STYLES = {
    stat: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    critical: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    urgent: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    high: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    routine: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
};

function StatusBadge({ status }) {
    const style = STATUS_STYLES[status] || STATUS_STYLES.success;
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${style}`}>
            {STATUS_LABELS[status] || 'Balanced'}
        </span>
    );
}

function PriorityBadge({ priority }) {
    const key = (priority || 'routine').toLowerCase();
    const style = PRIORITY_STYLES[key] || PRIORITY_STYLES.routine;
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${style}`}>
            {key}
        </span>
    );
}

function EmptyState({ message }) {
    return (
        <div className="flex items-center justify-center py-12 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {message}
        </div>
    );
}

export default function Resources() {
    const { props } = usePage();
    const kpis = props.kpis ?? null;
    const staffingByUnit = props.staffingByUnit ?? [];
    const gaps = props.gaps ?? [];
    const openRequests = props.openRequests ?? [];

    // Required vs present headcount per unit. Worst-coverage units lead the
    // payload; cap the bar chart to the 12 most stretched so it stays legible.
    const chartUnits = useMemo(() => staffingByUnit.slice(0, 12), [staffingByUnit]);

    const staffingChartData = useMemo(
        () => ({
            labels: chartUnits.map((u) => u.unit),
            datasets: [
                {
                    label: 'Required',
                    data: chartUnits.map((u) => u.required),
                    backgroundColor: 'var(--info)',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.7,
                },
                {
                    label: 'Present',
                    data: chartUnits.map((u) => u.present),
                    backgroundColor: 'var(--success)',
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.7,
                },
            ],
        }),
        [chartUnits]
    );

    const chartOptions = useMemo(
        () => ({
            plugins: { legend: { display: true, position: 'top' } },
        }),
        []
    );

    const coverage = kpis ? kpis.staffCoverage : 0;
    const coverageTrend = coverage >= 95 ? 'up' : coverage >= 85 ? 'neutral' : 'down';

    return (
        <RTDCPageLayout
            title="Resource Analytics"
            subtitle="Staffing ratios, coverage gaps, and bed allocation across the house"
        >
            {!kpis ? (
                <EmptyState message="No resource data available." />
            ) : (
                <>
                    <MetricsCardGroup cols={4}>
                        <MetricsCard
                            title="Staff Coverage"
                            value={coverage}
                            formatter={(v) => `${v}%`}
                            trend={coverageTrend}
                            icon="heroicons:user-group"
                            description={`${kpis.staffPresent} present of ${kpis.staffRequired} required`}
                            comparison={null}
                        />
                        <MetricsCard
                            title="Units Short-Staffed"
                            value={kpis.unitsShort}
                            trend={kpis.unitsShort > 0 ? 'down' : 'neutral'}
                            icon="heroicons:exclamation-triangle"
                            description={`of ${kpis.unitsTracked} units tracked`}
                            comparison={null}
                        />
                        <MetricsCard
                            title="RN Ratio Breaches"
                            value={kpis.ratioBreaches}
                            trend={kpis.ratioBreaches > 0 ? 'down' : 'neutral'}
                            icon="heroicons:scale"
                            description="Units over their patient-per-RN target"
                            comparison={null}
                        />
                        <MetricsCard
                            title="Beds Available"
                            value={kpis.bedsAvailable}
                            trend="neutral"
                            icon="heroicons:home-modern"
                            description={`${kpis.bedOccupancy}% house occupancy`}
                            comparison={null}
                        />
                    </MetricsCardGroup>

                    <Card>
                        <Card.Header>
                            <Card.Title>Staffing by Unit — Required vs Present</Card.Title>
                            <Card.Description>
                                Day-shift headcount for the {chartUnits.length} most stretched units
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {chartUnits.length === 0 ? (
                                <EmptyState message="No staffing plans for the current shift." />
                            ) : (
                                <div className="h-80">
                                    <BarChart data={staffingChartData} options={chartOptions} />
                                </div>
                            )}
                        </Card.Content>
                    </Card>

                    <Card>
                        <Card.Header>
                            <Card.Title>Resource Gaps</Card.Title>
                            <Card.Description>
                                Units with a staffing shortfall, RN-ratio breach, or no open beds
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {gaps.length === 0 ? (
                                <EmptyState message="No resource gaps — every unit is within target." />
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            <th className="px-3 py-2">Unit</th>
                                            <th className="px-3 py-2">Type</th>
                                            <th className="px-3 py-2 text-right tabular-nums">Present / Req</th>
                                            <th className="px-3 py-2 text-right tabular-nums">Coverage</th>
                                            <th className="px-3 py-2 text-right tabular-nums">RN Ratio</th>
                                            <th className="px-3 py-2 text-right tabular-nums">Beds Open</th>
                                            <th className="px-3 py-2">Driver</th>
                                            <th className="px-3 py-2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {gaps.map((g) => (
                                            <tr
                                                key={g.unitId}
                                                className="border-t border-healthcare-border dark:border-healthcare-border-dark"
                                            >
                                                <td className="px-3 py-2">
                                                    <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {g.unit}
                                                    </span>
                                                    <span className="ml-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {g.unitName}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {g.type}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {g.present} / {g.required}
                                                </td>
                                                <td
                                                    className={`px-3 py-2 text-right tabular-nums font-medium ${
                                                        g.coverage < 85
                                                            ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
                                                            : g.coverage < 95
                                                              ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                                              : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                                    }`}
                                                >
                                                    {g.coverage}%
                                                </td>
                                                <td
                                                    className={`px-3 py-2 text-right tabular-nums ${
                                                        g.ratioBreach
                                                            ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                                            : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                    }`}
                                                >
                                                    {g.actualRatio > 0 ? `${g.actualRatio} / ${g.ratioTarget}` : '—'}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {g.bedsAvailable}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {g.driver}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <StatusBadge status={g.status} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Card.Content>
                    </Card>

                    <Card>
                        <Card.Header>
                            <Card.Title>Open Staffing Requests</Card.Title>
                            <Card.Description>
                                Outstanding fill-gap requests awaiting sourcing
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {openRequests.length === 0 ? (
                                <EmptyState message="No open staffing requests." />
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            <th className="px-3 py-2">Unit</th>
                                            <th className="px-3 py-2">Role</th>
                                            <th className="px-3 py-2 text-right tabular-nums">Headcount</th>
                                            <th className="px-3 py-2">Shift</th>
                                            <th className="px-3 py-2">Needed By</th>
                                            <th className="px-3 py-2">Owner</th>
                                            <th className="px-3 py-2">Priority</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {openRequests.map((r) => (
                                            <tr
                                                key={r.id}
                                                className="border-t border-healthcare-border dark:border-healthcare-border-dark"
                                            >
                                                <td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {r.unit}
                                                </td>
                                                <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {r.role}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {r.headcount}
                                                </td>
                                                <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {r.shift}
                                                    {r.shiftDate ? ` · ${r.shiftDate}` : ''}
                                                </td>
                                                <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {r.neededBy || '—'}
                                                </td>
                                                <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {r.owner}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <PriorityBadge priority={r.priority} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </Card.Content>
                    </Card>
                </>
            )}
        </RTDCPageLayout>
    );
}
