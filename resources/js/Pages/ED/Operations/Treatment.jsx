import React, { useMemo } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';
import BarChart from '@/Components/Dashboard/Charts/BarChart';

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

const formatElapsed = (minutes) => {
    const mins = Math.max(0, Number(minutes) || 0);
    const hours = Math.floor(mins / 60);
    const rem = mins % 60;
    return hours > 0 ? `${hours}h ${rem}m` : `${rem}m`;
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

const EmptyState = () => (
    <div className="flex flex-col items-center justify-center py-12 text-center">
        <div className="mb-4 rounded-full bg-healthcare-info/10 p-4">
            <Icon
                icon="heroicons:check-badge"
                className="h-8 w-8 text-healthcare-info dark:text-healthcare-info-dark"
                aria-hidden="true"
            />
        </div>
        <h3 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            No patients in active treatment
        </h3>
        <p className="mt-2 max-w-md text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Every patient who has been seen by a provider has been dispositioned and
            departed. New treatment rooms will populate here automatically.
        </p>
    </div>
);

const Treatment = ({ kpis = {}, board = [], acuityMix = [], meta = {} }) => {
    const rows = Array.isArray(board) ? board : [];
    const hasPatients = rows.length > 0;

    const inTreatment = kpis.inTreatment ?? { value: 0, trend: 'flat', context: '' };
    const awaitingDisposition = kpis.awaitingDisposition ?? { value: 0, trend: 'flat', context: '' };
    const boarding = kpis.boarding ?? { value: 0, trend: 'flat', context: '' };
    const medianTreatment = kpis.medianTreatmentTime ?? { value: 0, trend: 'flat', context: '' };

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
                <div className="space-y-6">
                    {/* KPI tiles */}
                    <MetricsCardGroup cols={4}>
                        <MetricsCard
                            title="In Treatment"
                            value={String(inTreatment.value)}
                            trend={inTreatment.trend}
                            icon="heroicons:user-group"
                            description={inTreatment.context}
                            comparison={null}
                        />
                        <MetricsCard
                            title="Awaiting Disposition"
                            value={String(awaitingDisposition.value)}
                            trend={awaitingDisposition.trend}
                            icon="heroicons:clipboard-document-check"
                            description={awaitingDisposition.context}
                            comparison={null}
                        />
                        <MetricsCard
                            title="Boarding"
                            value={String(boarding.value)}
                            trend={boarding.trend}
                            icon="heroicons:building-office-2"
                            description={boarding.context}
                            comparison={null}
                        />
                        <MetricsCard
                            title="Median Treatment Time"
                            value={formatElapsed(medianTreatment.value)}
                            trend={medianTreatment.trend}
                            icon="heroicons:clock"
                            description={medianTreatment.context}
                            comparison={null}
                        />
                    </MetricsCardGroup>

                    {/* Acuity mix chart */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center gap-2">
                                    <Icon icon="heroicons:chart-bar" className="h-5 w-5" aria-hidden="true" />
                                    <span>Treatment Cohort by Acuity</span>
                                </div>
                            </Card.Title>
                            <Card.Description>
                                ESI distribution of patients currently in treatment
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {hasPatients ? (
                                <div className="h-56">
                                    <BarChart data={acuityChartData} options={acuityChartOptions} />
                                </div>
                            ) : (
                                <p className="py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    No active treatment cohort to chart.
                                </p>
                            )}
                        </Card.Content>
                    </Card>

                    {/* Treatment board table */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center gap-2">
                                    <Icon
                                        icon="heroicons:clipboard-document-list"
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                    <span>Active Treatment Board</span>
                                </div>
                            </Card.Title>
                            <Card.Description>
                                {hasPatients
                                    ? `${rows.length} patient${rows.length === 1 ? '' : 's'} with a provider, not yet departed`
                                    : 'Live patients with a provider, not yet departed'}
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {hasPatients ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        <thead>
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Room
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Chief Complaint
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    ESI
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    In Treatment
                                                </th>
                                                <th className="px-4 py-3 text-right text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Total LOS
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Status
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Care Team
                                                </th>
                                                <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    Pending Orders
                                                </th>
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
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState />
                            )}
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Treatment;
