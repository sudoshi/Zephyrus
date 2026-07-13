import React, { useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';
import BarChart from '@/Components/Dashboard/Charts/BarChart';
import { formatDurationMinutes } from '@/lib/duration';

// ED Treatment Board rebuilt on the gold-standard design system: the KPI wall is
// one MetricGrid of KpiTiles, the acuity-mix chart and the active treatment
// board live in Panels under Section headers. All values are server-computed
// from the live `prod` schema (TreatmentService over seeded ed_visits); the page
// renders empty states rather than fabricating data.

// Maps a status tone token from TreatmentService into a healthcare-* class set.
// Status is never communicated by color alone — every badge carries an icon + label.
const STATUS_TONES = {
    critical: {
        badge: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
        icon: 'heroicons:exclamation-triangle',
    },
    warning: {
        badge: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
        icon: 'heroicons:clock',
    },
    success: {
        badge: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
        icon: 'heroicons:check-circle',
    },
    info: {
        badge: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
        icon: 'heroicons:bolt',
    },
};

// ESI badge palette (data-driven acuity, not status) — paired with the "ESI n" label.
const esiBadgeClass = (level) => {
    if (level <= 2) {
        return 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark';
    }
    if (level === 3) {
        return 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark';
    }
    return 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark';
};

const formatElapsed = (value) => {
    if (value === null || value === undefined || value === '') return formatDurationMinutes(null);
    const minutes = Number(value);

    return formatDurationMinutes(Number.isFinite(minutes) ? Math.max(0, minutes) : null);
};

const StatusBadge = ({ status, tone }) => {
    const config = STATUS_TONES[tone] ?? STATUS_TONES.info;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${config.badge}`}
        >
            <Icon icon={config.icon} className="h-3.5 w-3.5" aria-hidden="true" />
            {status}
        </span>
    );
};

const ReadinessChip = ({ axis, countLabel }) => {
    if (!axis) return <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">Unavailable</span>;

    const stale = axis.freshness?.status !== 'fresh';
    const state = stale ? 'unknown' : axis.state;
    const config = {
        blocked: { icon: 'heroicons:exclamation-triangle', label: 'Blocked', classes: 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark' },
        pending: { icon: 'heroicons:clock', label: 'Pending', classes: 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark' },
        ready: { icon: 'heroicons:check-circle', label: 'Ready', classes: 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark' },
        unknown: { icon: 'heroicons:question-mark-circle', label: 'Unknown', classes: 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark' },
    }[state] ?? { icon: 'heroicons:question-mark-circle', label: 'Unknown', classes: 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark' };
    const age = axis.oldestAgeMinutes === null ? 'age unavailable' : `${axis.oldestAgeMinutes} min oldest`;
    const content = <><Icon icon={config.icon} className="h-3.5 w-3.5" aria-hidden="true" /><span>{axis.pendingCount} {countLabel} · {age}</span></>;
    const className = `inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium ${config.classes}`;
    const label = `Open ${axis.label}: ${config.label}. ${axis.pendingCount} pending, ${age}.`;

    return axis.drillHref ? <Link href={axis.drillHref} aria-label={label} className={className}>{content}</Link> : <span aria-label={label} className={className}>{content}</span>;
};

const Treatment = ({ kpis = {}, board = [], acuityMix = [], meta = {} }) => {
    const rows = Array.isArray(board) ? board : [];
    const hasPatients = rows.length > 0;

    // Disposition mix straight off the board rows — the overview line answers
    // "where is everyone in the pipeline" without scanning the table.
    const statusCounts = rows.reduce((acc, r) => {
        acc[r.status] = (acc[r.status] ?? 0) + 1;
        return acc;
    }, {});
    const mixSummary = [
        `${statusCounts['In Treatment'] ?? 0} in treatment`,
        `${statusCounts['Boarding'] ?? 0} boarding`,
        `${statusCounts['Transfer Pending'] ?? 0} transfer pending`,
        `${statusCounts['Discharge Ready'] ?? 0} discharge ready`,
    ].join(' · ');

    const inTreatment = kpis.inTreatment ?? { value: 0, trend: 'flat', context: '' };
    const awaitingDisposition = kpis.awaitingDisposition ?? { value: 0, trend: 'flat', context: '' };
    const boarding = kpis.boarding ?? { value: 0, trend: 'flat', context: '' };
    const medianTreatment = kpis.medianTreatmentTime ?? { value: 0, trend: 'flat', context: '' };

    const kpiMetrics = [
        metric({
            key: 'in-treatment', label: 'In Treatment', value: Number(inTreatment.value ?? 0),
            status: 'info', caption: inTreatment.context || undefined,
            definition: 'Patients currently being seen by a provider in a treatment room.',
        }),
        metric({
            key: 'awaiting-disposition', label: 'Awaiting Disposition', value: Number(awaitingDisposition.value ?? 0),
            status: (awaitingDisposition.value ?? 0) > 0 ? 'warning' : 'success', goodWhenDown: true,
            caption: awaitingDisposition.context || undefined,
            definition: 'Patients seen by a provider but not yet dispositioned (admit / discharge / transfer).',
        }),
        metric({
            key: 'boarding', label: 'Boarding', value: Number(boarding.value ?? 0),
            status: (boarding.value ?? 0) > 0 ? 'critical' : 'success', goodWhenDown: true,
            caption: boarding.context || undefined,
            definition: 'Admitted patients held in the ED awaiting an inpatient bed.',
        }),
        metric({
            key: 'median-treatment', label: 'Median Treatment Time', value: Number(medianTreatment.value ?? 0),
            display: formatElapsed(medianTreatment.value), goodWhenDown: true, status: 'neutral',
            caption: medianTreatment.context || undefined,
            definition: 'Median time patients have spent in active treatment.',
        }),
    ];

    const acuityChartData = useMemo(
        () => ({
            labels: (acuityMix ?? []).map((bucket) => bucket.esi),
            datasets: [
                {
                    label: 'Patients in treatment',
                    data: (acuityMix ?? []).map((bucket) => bucket.count),
                    backgroundColor: 'var(--info)',
                    borderRadius: 4,
                    barPercentage: 0.6,
                    categoryPercentage: 0.7,
                },
            ],
        }),
        [acuityMix]
    );

    const acuityChartOptions = useMemo(
        () => ({
            plugins: { legend: { display: false } },
            scales: { y: { ticks: { precision: 0 } } },
        }),
        []
    );

    return (
        <DashboardLayout>
            <Head title="ED Treatment - ZephyrusOR" />
            <PageContentLayout
                title="ED Treatment Board"
                subtitle="Patients in active treatment — disposition tracking and care-team assignment"
            >
                <div className="flex flex-col gap-5">
                    <Section title="Treatment overview" icon="heroicons:user-group"
                             summary={mixSummary}>
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <Section title="Treatment Cohort by Acuity" icon="heroicons:chart-bar"
                             summary="ESI distribution of patients currently in treatment">
                        <Panel className="p-4">
                            {hasPatients ? (
                                <div className="h-56">
                                    <BarChart data={acuityChartData} options={acuityChartOptions} />
                                </div>
                            ) : (
                                <EmptyState message="No active treatment cohort to chart." icon="heroicons:chart-bar" />
                            )}
                        </Panel>
                    </Section>

                    <Section title="Active Treatment Board" icon="heroicons:clipboard-document-list"
                             summary={hasPatients
                                 ? `${rows.length} patient${rows.length === 1 ? '' : 's'} with a provider, not yet departed`
                                 : 'Live patients with a provider, not yet departed'}>
                        <Panel className="p-0">
                            {hasPatients ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        <thead>
                                            <tr>
                                                {[
                                                    ['Room', 'left'],
                                                    ['Chief Complaint', 'left'],
                                                    ['ESI', 'left'],
                                                    ['In Treatment', 'right'],
                                                    ['Total LOS', 'right'],
                                                    ['Since Dispo', 'right'],
                                                    ['Status', 'left'],
                                                    ['Care Team', 'left'],
                                                    ['Pending Orders', 'left'],
                                                    ['Imaging', 'left'],
                                                    ['Lab', 'left'],
                                                ].map(([h, align]) => (
                                                    <th key={h} className={`px-4 py-3 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-${align}`}>
                                                        {h}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {rows.map((patient) => (
                                                <tr
                                                    key={patient.id}
                                                    className="transition-colors duration-200 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                                                >
                                                    <td className="px-4 py-3 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {patient.room}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {patient.chiefComplaint}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${esiBadgeClass(
                                                                patient.esiLevel
                                                            )}`}
                                                        >
                                                            ESI {patient.esiLevel}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {formatElapsed(patient.treatmentMinutes)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {formatElapsed(patient.losMinutes)}
                                                    </td>
                                                    <td
                                                        className={`px-4 py-3 text-right text-sm tabular-nums ${
                                                            (patient.dispositionMinutes ?? 0) > 60
                                                                ? 'font-medium text-healthcare-warning dark:text-healthcare-warning-dark'
                                                                : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                                                        }`}
                                                    >
                                                        {patient.dispositionMinutes != null
                                                            ? formatElapsed(patient.dispositionMinutes)
                                                            : '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge
                                                            status={patient.status}
                                                            tone={patient.statusTone}
                                                        />
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        <div className="flex flex-col">
                                                            <span>{patient.provider}</span>
                                                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                {patient.nurse}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {patient.pendingOrders && patient.pendingOrders.length > 0 ? (
                                                            <div className="flex flex-wrap gap-1.5">
                                                                {patient.pendingOrders.map((order) => (
                                                                    <span
                                                                        key={`${patient.id}-${order}`}
                                                                        className="inline-flex items-center rounded-md bg-healthcare-background px-2 py-0.5 text-xs text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark"
                                                                    >
                                                                        {order}
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                                None
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <ReadinessChip axis={patient.imaging} countLabel="imaging" />
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <ReadinessChip axis={patient.lab} countLabel="pending" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState
                                    message="No patients in active treatment — every patient seen by a provider has been dispositioned and departed."
                                    icon="heroicons:check-badge"
                                />
                            )}
                        </Panel>
                    </Section>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Treatment;
