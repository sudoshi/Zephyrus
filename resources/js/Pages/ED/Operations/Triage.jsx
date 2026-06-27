import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Common/MetricsCard';

// Tone -> healthcare status token map. Status is NEVER conveyed by color alone;
// every badge pairs the tone with an icon + text label.
const TONE = {
    critical: {
        text: 'text-healthcare-critical dark:text-healthcare-critical-dark',
        bg: 'bg-healthcare-critical/15 dark:bg-healthcare-critical-dark/20',
        icon: 'heroicons:exclamation-triangle',
    },
    warning: {
        text: 'text-healthcare-warning dark:text-healthcare-warning-dark',
        bg: 'bg-healthcare-warning/15 dark:bg-healthcare-warning-dark/20',
        icon: 'heroicons:clock',
    },
    success: {
        text: 'text-healthcare-success dark:text-healthcare-success-dark',
        bg: 'bg-healthcare-success/15 dark:bg-healthcare-success-dark/20',
        icon: 'heroicons:check-circle',
    },
    info: {
        text: 'text-healthcare-info dark:text-healthcare-info-dark',
        bg: 'bg-healthcare-info/15 dark:bg-healthcare-info-dark/20',
        icon: 'heroicons:user-plus',
    },
};

// ESI badge palette. Acuity is data-driven (a sanctioned, non-status use of the
// scale) but still pairs the number with the tier label in the cell beside it.
const ESI_BADGE = {
    1: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    2: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    3: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    4: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
    5: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
};

const formatWait = (minutes) => {
    const m = Math.max(0, Number(minutes) || 0);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return rem > 0 ? `${h}h ${rem}m` : `${h}h`;
};

const formatClock = (iso) => {
    if (!iso) return '--:--';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '--:--';
    return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
};

const EsiBadge = ({ esi, label }) => (
    <span className="inline-flex items-center gap-1.5">
        <span
            className={`inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums ${
                ESI_BADGE[esi] || ESI_BADGE[3]
            }`}
        >
            {esi}
        </span>
        {label && (
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {label}
            </span>
        )}
    </span>
);

const StatusBadge = ({ status, tone }) => {
    const t = TONE[tone] || TONE.info;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${t.bg} ${t.text}`}
        >
            <Icon icon={t.icon} className="h-3.5 w-3.5" aria-hidden="true" />
            {status}
        </span>
    );
};

// Horizontal ESI distribution bar — categorical, data-driven heights, paired
// with explicit counts so it reads without relying on color.
const EsiDistribution = ({ breakdown }) => {
    const max = Math.max(1, ...breakdown.map((b) => b.count));
    return (
        <div className="space-y-3">
            {breakdown.map((b) => (
                <div key={b.esi} className="flex items-center gap-3">
                    <div className="w-28 shrink-0">
                        <EsiBadge esi={b.esi} label={b.label} />
                    </div>
                    <div className="flex-1">
                        <div className="h-2.5 w-full overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                            <div
                                className={`h-full rounded-full ${
                                    b.esi <= 2
                                        ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                                        : b.esi === 3
                                          ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                                          : 'bg-healthcare-info dark:bg-healthcare-info-dark'
                                }`}
                                style={{ width: `${(b.count / max) * 100}%` }}
                            />
                        </div>
                    </div>
                    <span className="w-8 shrink-0 text-right text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {b.count}
                    </span>
                </div>
            ))}
        </div>
    );
};

const EmptyQueue = () => (
    <div className="flex flex-col items-center justify-center py-12 text-center">
        <div className="mb-3 rounded-full bg-healthcare-success/15 dark:bg-healthcare-success-dark/20 p-3">
            <Icon
                icon="heroicons:check-badge"
                className="h-7 w-7 text-healthcare-success dark:text-healthcare-success-dark"
                aria-hidden="true"
            />
        </div>
        <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Triage queue is clear
        </h3>
        <p className="mt-1 max-w-sm text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No patients are currently waiting in the Emergency Department.
        </p>
    </div>
);

const Th = ({ children, className = '' }) => (
    <th
        className={`px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${className}`}
    >
        {children}
    </th>
);

const Td = ({ children, className = '' }) => (
    <td
        className={`px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark ${className}`}
    >
        {children}
    </td>
);

export default function Triage({
    kpis = {},
    esiBreakdown = [],
    longestWaits = [],
    queue = [],
}) {
    const k = {
        inQueue: 0,
        awaitingTriage: 0,
        awaitingProvider: 0,
        highAcuity: 0,
        longestWaitMinutes: 0,
        medianDoorToTriage: 0,
        overTarget: 0,
        ...kpis,
    };

    return (
        <DashboardLayout>
            <Head title="Triage - Emergency" />
            <PageContentLayout
                title="ED Triage"
                subtitle="Live triage queue, acuity mix, and patient prioritization"
            >
                {/* KPI tiles */}
                <MetricsCardGroup cols={4}>
                    <MetricsCard
                        title="In Queue"
                        value={k.inQueue.toString()}
                        icon="heroicons:user-group"
                        comparison=""
                        description="Patients currently in the ED"
                    />
                    <MetricsCard
                        title="High Acuity (ESI 1-2)"
                        value={k.highAcuity.toString()}
                        trend={k.highAcuity > 0 ? 'down' : 'up'}
                        icon="heroicons:exclamation-triangle"
                        comparison=""
                        description="Emergent / resuscitation"
                    />
                    <MetricsCard
                        title="Longest Wait"
                        value={formatWait(k.longestWaitMinutes)}
                        trend={k.longestWaitMinutes > 60 ? 'down' : 'up'}
                        icon="heroicons:clock"
                        comparison=""
                        description={`${k.overTarget} over target`}
                    />
                    <MetricsCard
                        title="Median Door-to-Triage"
                        value={`${k.medianDoorToTriage}m`}
                        trend={k.medianDoorToTriage <= 10 ? 'up' : 'down'}
                        icon="heroicons:bolt"
                        comparison=""
                        description="Target: 10m"
                    />
                </MetricsCardGroup>

                <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Triage board */}
                    <Card className="lg:col-span-2">
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center gap-2">
                                    <Icon icon="heroicons:clipboard-document-list" className="h-5 w-5" />
                                    <span>Triage Queue</span>
                                </div>
                            </Card.Title>
                            <Card.Description>
                                Ordered by acuity, then longest wait
                            </Card.Description>
                        </Card.Header>
                        <Card.Content>
                            {queue.length === 0 ? (
                                <EmptyQueue />
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        <thead>
                                            <tr>
                                                <Th>Patient</Th>
                                                <Th>ESI</Th>
                                                <Th>Chief Complaint</Th>
                                                <Th>Room</Th>
                                                <Th>Arrived</Th>
                                                <Th className="text-right">Wait</Th>
                                                <Th>Status</Th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            {queue.map((p) => (
                                                <tr
                                                    key={p.id}
                                                    className="transition-colors duration-150 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                                                >
                                                    <Td>
                                                        <div className="font-medium">{p.patientName}</div>
                                                        <div className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            {p.patientRef}
                                                        </div>
                                                    </Td>
                                                    <Td>
                                                        <EsiBadge esi={p.esi} label={p.esiLabel} />
                                                    </Td>
                                                    <Td>{p.chiefComplaint}</Td>
                                                    <Td className="whitespace-nowrap text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {p.triageRoom}
                                                    </Td>
                                                    <Td className="whitespace-nowrap tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                        {formatClock(p.arrivedAt)}
                                                    </Td>
                                                    <Td className="text-right">
                                                        <span
                                                            className={`tabular-nums font-medium ${
                                                                p.overTarget
                                                                    ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                                                    : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                                            }`}
                                                        >
                                                            {formatWait(p.waitMinutes)}
                                                        </span>
                                                    </Td>
                                                    <Td>
                                                        <StatusBadge status={p.status} tone={p.statusTone} />
                                                    </Td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Card.Content>
                    </Card>

                    {/* Sidebar: ESI mix + longest waits */}
                    <div className="space-y-6">
                        <Card>
                            <Card.Header>
                                <Card.Title>
                                    <div className="flex items-center gap-2">
                                        <Icon icon="heroicons:chart-bar" className="h-5 w-5" />
                                        <span>Acuity Mix</span>
                                    </div>
                                </Card.Title>
                                <Card.Description>Active queue by ESI level</Card.Description>
                            </Card.Header>
                            <Card.Content>
                                {esiBreakdown.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        No acuity data available.
                                    </p>
                                ) : (
                                    <EsiDistribution breakdown={esiBreakdown} />
                                )}
                            </Card.Content>
                        </Card>

                        <Card>
                            <Card.Header>
                                <Card.Title>
                                    <div className="flex items-center gap-2">
                                        <Icon icon="heroicons:clock" className="h-5 w-5" />
                                        <span>Longest Waits</span>
                                    </div>
                                </Card.Title>
                                <Card.Description>Watch-list, by current wait</Card.Description>
                            </Card.Header>
                            <Card.Content>
                                {longestWaits.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        No patients waiting.
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {longestWaits.map((p) => (
                                            <li
                                                key={p.id}
                                                className="flex items-center justify-between gap-3 rounded-md p-2 transition-colors duration-150 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                                            >
                                                <div className="flex min-w-0 items-center gap-2.5">
                                                    <EsiBadge esi={p.esi} />
                                                    <div className="min-w-0">
                                                        <div className="truncate text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            {p.patientName}
                                                        </div>
                                                        <div className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            {p.chiefComplaint}
                                                        </div>
                                                    </div>
                                                </div>
                                                <span
                                                    className={`shrink-0 text-sm font-semibold tabular-nums ${
                                                        p.overTarget
                                                            ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                                            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                                    }`}
                                                >
                                                    {formatWait(p.waitMinutes)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card.Content>
                        </Card>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
