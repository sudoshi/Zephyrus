import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import RTDCPageLayout from '@/Components/RTDC/RTDCPageLayout';
import { Section, MetricGrid, Panel, EmptyState, metric, STATUS_VAR } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

// Resource Analytics rebuilt on the gold-standard design system: the KPI wall is
// one MetricGrid of KpiTiles; the staffing chart and the gaps/requests tables
// live in Panels under Section headers, with status cells coloured via
// STATUS_VAR. All values are server-computed live props (ResourceAnalyticsService);
// the page renders empty states rather than fabricating data.

// Gap status → the four-color status vocabulary. The payload uses
// critical/warning/success; default balanced units to success.
const GAP_STATUS = {
    critical: { level: 'critical', label: 'Critical' },
    warning: { level: 'warning', label: 'At risk' },
    success: { level: 'success', label: 'Balanced' },
};

// Request priority → status level. STAT/critical escalate to critical, urgent/
// high to warning, routine to info.
const PRIORITY_STATUS = {
    stat: 'critical',
    critical: 'critical',
    urgent: 'warning',
    high: 'warning',
    routine: 'info',
};

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

    if (!kpis) {
        return (
            <RTDCPageLayout
                title="Resource Analytics"
                subtitle="Staffing ratios, coverage gaps, and bed allocation across the house"
            >
                <Panel className="p-4">
                    <EmptyState message="No resource data available." icon="heroicons:user-group" />
                </Panel>
            </RTDCPageLayout>
        );
    }

    const coverage = kpis.staffCoverage ?? 0;
    const coverageStatus = coverage >= 95 ? 'success' : coverage >= 85 ? 'warning' : 'critical';
    const unitsShortStatus = kpis.unitsShort > 0 ? 'warning' : 'success';
    const ratioStatus = kpis.ratioBreaches > 0 ? 'critical' : 'success';

    const kpiMetrics = [
        metric({
            key: 'staff-coverage', label: 'Staff Coverage', value: Number(coverage), unit: '%',
            status: coverageStatus, target: 95,
            caption: `${kpis.staffPresent} present of ${kpis.staffRequired} required`,
            definition: 'Present staff as a share of required headcount across tracked units.',
        }),
        metric({
            key: 'units-short', label: 'Units Short-Staffed', value: Number(kpis.unitsShort ?? 0),
            status: unitsShortStatus, goodWhenDown: true, target: 0,
            caption: `of ${kpis.unitsTracked} units tracked`,
            definition: 'Units below their required headcount for the current shift.',
        }),
        metric({
            key: 'ratio-breaches', label: 'RN Ratio Breaches', value: Number(kpis.ratioBreaches ?? 0),
            status: ratioStatus, goodWhenDown: true, target: 0,
            caption: 'Units over their patient-per-RN target',
            definition: 'Units exceeding their target patient-to-RN ratio.',
        }),
        metric({
            key: 'beds-available', label: 'Beds Available', value: Number(kpis.bedsAvailable ?? 0),
            status: 'info', caption: `${kpis.bedOccupancy}% house occupancy`,
            definition: 'Clean, staffed beds immediately assignable house-wide.',
        }),
    ];

    return (
        <RTDCPageLayout
            title="Resource Analytics"
            subtitle="Staffing ratios, coverage gaps, and bed allocation across the house"
        >
            <div className="flex flex-col gap-5">
                <Section
                    title="Staffing & capacity"
                    icon="heroicons:user-group"
                    summary={`${coverage}% coverage · ${kpis.unitsShort} of ${kpis.unitsTracked} units short`}
                >
                    <MetricGrid metrics={kpiMetrics} />
                </Section>

                <Section
                    title="Staffing by Unit — Required vs Present"
                    icon="heroicons:chart-bar"
                    summary={`Day-shift headcount for the ${chartUnits.length} most stretched units`}
                >
                    <Panel className="p-4">
                        {chartUnits.length === 0 ? (
                            <EmptyState message="No staffing plans for the current shift." icon="heroicons:chart-bar" />
                        ) : (
                            <div className="h-80">
                                <BarChart data={staffingChartData} options={chartOptions} />
                            </div>
                        )}
                    </Panel>
                </Section>

                <Section
                    title="Resource Gaps"
                    icon="heroicons:exclamation-triangle"
                    summary="Units with a staffing shortfall, RN-ratio breach, or no open beds"
                >
                    <Panel className="p-4">
                        {gaps.length === 0 ? (
                            <EmptyState message="No resource gaps — every unit is within target." icon="heroicons:check-circle" />
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        <th className="px-3 py-2">Unit</th>
                                        <th className="px-3 py-2">Type</th>
                                        <th className="px-3 py-2 text-right tabular-nums">Present / Sched / Req</th>
                                        <th className="px-3 py-2 text-right tabular-nums">Coverage</th>
                                        <th className="px-3 py-2 text-right tabular-nums">RN Ratio</th>
                                        <th className="px-3 py-2 text-right tabular-nums">Beds Open</th>
                                        <th className="px-3 py-2 text-right tabular-nums">Turnover</th>
                                        <th className="px-3 py-2 text-right tabular-nums">Blocked</th>
                                        <th className="px-3 py-2">Driver</th>
                                        <th className="px-3 py-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {gaps.map((g) => {
                                        const gap = GAP_STATUS[g.status] || GAP_STATUS.success;
                                        return (
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
                                                <td
                                                    className="px-3 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                                                    title={g.minimumSafe != null ? `Minimum safe: ${g.minimumSafe}` : undefined}
                                                >
                                                    <span
                                                        style={{
                                                            color:
                                                                g.minimumSafe != null && g.present < g.minimumSafe
                                                                    ? STATUS_VAR.critical
                                                                    : undefined,
                                                        }}
                                                    >
                                                        {g.present}
                                                    </span>
                                                    {' / '}
                                                    {g.scheduled ?? '—'} / {g.required}
                                                </td>
                                                <td
                                                    className="px-3 py-2 text-right tabular-nums font-medium"
                                                    style={{
                                                        color:
                                                            g.coverage < 85
                                                                ? STATUS_VAR.critical
                                                                : g.coverage < 95
                                                                  ? STATUS_VAR.warning
                                                                  : undefined,
                                                    }}
                                                >
                                                    {g.coverage}%
                                                </td>
                                                <td
                                                    className="px-3 py-2 text-right tabular-nums"
                                                    style={{ color: g.ratioBreach ? STATUS_VAR.warning : undefined }}
                                                >
                                                    {g.actualRatio > 0 ? `${g.actualRatio} / ${g.ratioTarget}` : '—'}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {g.bedsAvailable}
                                                </td>
                                                <td className={`px-3 py-2 text-right tabular-nums ${(g.bedsTurnover ?? 0) > 0 ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}`}>
                                                    {g.bedsTurnover ?? '—'}
                                                </td>
                                                <td className={`px-3 py-2 text-right tabular-nums ${(g.bedsBlocked ?? 0) > 0 ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}`}>
                                                    {g.bedsBlocked ?? '—'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {g.driver}
                                                </td>
                                                <td
                                                    className="px-3 py-2 text-xs font-semibold"
                                                    style={{ color: STATUS_VAR[gap.level] }}
                                                >
                                                    {gap.label}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </Section>

                <Section
                    title="Open Staffing Requests"
                    icon="heroicons:clipboard-document-list"
                    summary="Outstanding fill-gap requests awaiting sourcing"
                >
                    <Panel className="p-4">
                        {openRequests.length === 0 ? (
                            <EmptyState message="No open staffing requests." icon="heroicons:clipboard-document-list" />
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
                                    {openRequests.map((r) => {
                                        const priorityKey = (r.priority || 'routine').toLowerCase();
                                        const priorityLevel = PRIORITY_STATUS[priorityKey] || 'info';
                                        return (
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
                                                <td
                                                    className="px-3 py-2 text-xs font-semibold capitalize"
                                                    style={{ color: STATUS_VAR[priorityLevel] }}
                                                >
                                                    {priorityKey}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </Section>
            </div>
        </RTDCPageLayout>
    );
}
