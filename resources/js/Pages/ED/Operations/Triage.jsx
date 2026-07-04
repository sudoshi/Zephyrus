import React from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { StudyLink } from '@/Components/Common/StudyLink';
import { Section, MetricGrid, Panel, EmptyState, metric } from '@/Components/system';

// ED Triage rebuilt on the gold-standard design system: the KPI wall is one
// MetricGrid of KpiTiles, the triage board and the acuity/longest-wait sidebars
// live in Panels under Section headers. All values are server-computed from the
// live `prod` schema (TriageService over seeded ed_visits); the page renders
// empty states rather than fabricating data.

// ESI badge palette. Acuity is data-driven (a sanctioned, non-status use of the
// scale) but still pairs the number with the tier label in the cell beside it.
const ESI_BADGE = {
    1: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    2: 'bg-healthcare-critical/15 text-healthcare-critical dark:text-healthcare-critical-dark',
    3: 'bg-healthcare-warning/15 text-healthcare-warning dark:text-healthcare-warning-dark',
    4: 'bg-healthcare-info/15 text-healthcare-info dark:text-healthcare-info-dark',
    5: 'bg-healthcare-success/15 text-healthcare-success dark:text-healthcare-success-dark',
};

// Status tone -> icon. Status is NEVER conveyed by color alone; every badge
// pairs the tone with an icon + text label.
const TONE_ICON = {
    critical: 'heroicons:exclamation-triangle',
    warning: 'heroicons:clock',
    success: 'heroicons:check-circle',
    info: 'heroicons:user-plus',
};

const TONE_TEXT = {
    critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
    success: 'text-healthcare-success dark:text-healthcare-success-dark',
    info: 'text-healthcare-info dark:text-healthcare-info-dark',
};

const TONE_BG = {
    critical: 'bg-healthcare-critical/15 dark:bg-healthcare-critical-dark/20',
    warning: 'bg-healthcare-warning/15 dark:bg-healthcare-warning-dark/20',
    success: 'bg-healthcare-success/15 dark:bg-healthcare-success-dark/20',
    info: 'bg-healthcare-info/15 dark:bg-healthcare-info-dark/20',
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
    const icon = TONE_ICON[tone] || TONE_ICON.info;
    const text = TONE_TEXT[tone] || TONE_TEXT.info;
    const bg = TONE_BG[tone] || TONE_BG.info;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${bg} ${text}`}
        >
            <Icon icon={icon} className="h-3.5 w-3.5" aria-hidden="true" />
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

    const kpiMetrics = [
        metric({
            key: 'in-queue', label: 'In Queue', value: Number(k.inQueue ?? 0), status: 'info',
            caption: 'Patients currently in the ED',
            definition: 'Patients currently present in the Emergency Department.',
        }),
        metric({
            key: 'high-acuity', label: 'High Acuity (ESI 1-2)', value: Number(k.highAcuity ?? 0),
            status: (k.highAcuity ?? 0) > 0 ? 'critical' : 'success', goodWhenDown: true,
            caption: 'Emergent / resuscitation',
            definition: 'Patients triaged at ESI 1 or 2 — emergent / resuscitation acuity.',
        }),
        metric({
            key: 'longest-wait', label: 'Longest Wait', value: Number(k.longestWaitMinutes ?? 0),
            display: formatWait(k.longestWaitMinutes), goodWhenDown: true,
            status: (k.longestWaitMinutes ?? 0) > 60 ? 'warning' : 'success',
            caption: `${k.overTarget ?? 0} over target`,
            definition: 'Current wait of the patient who has waited longest.',
        }),
        metric({
            key: 'door-to-triage', label: 'Median Door-to-Triage', value: Number(k.medianDoorToTriage ?? 0),
            display: `${k.medianDoorToTriage ?? 0}m`, target: 10, targetDisplay: '10m', goodWhenDown: true,
            status: (k.medianDoorToTriage ?? 0) <= 10 ? 'success' : 'warning',
            caption: 'Target: 10m',
            definition: 'Median time from arrival to triage completion. Target 10 minutes.',
        }),
    ];

    return (
        <DashboardLayout>
            <Head title="Triage - Emergency" />
            <PageContentLayout
                title="ED Triage"
                subtitle="Live triage queue, acuity mix, and patient prioritization"
                headerContent={<StudyLink href="/ed/analytics/wait-time" label="View trends" />}
            >
                <div className="flex flex-col gap-5">
                    <Section title="Triage overview" icon="heroicons:user-group"
                             summary={`${k.inQueue ?? 0} in queue · ${k.highAcuity ?? 0} high acuity`}>
                        <MetricGrid metrics={kpiMetrics} />
                    </Section>

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <Section title="Triage Queue" icon="heroicons:clipboard-document-list"
                                 summary="Ordered by acuity, then longest wait"
                                 className="lg:col-span-2">
                            <Panel className="p-0">
                                {queue.length === 0 ? (
                                    <EmptyState
                                        message="Triage queue is clear — no patients are currently waiting in the Emergency Department."
                                        icon="heroicons:check-badge"
                                    />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                            <thead>
                                                <tr>
                                                    {['Patient', 'ESI', 'Chief Complaint', 'Room', 'Arrived', 'Wait', 'Status'].map((h, i) => (
                                                        <th key={h} className={`px-4 py-3 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${i === 5 ? 'text-right' : 'text-left'}`}>{h}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                                {queue.map((p) => (
                                                    <tr
                                                        key={p.id}
                                                        className="transition-colors duration-150 hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark"
                                                    >
                                                        <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                            <div className="font-medium">{p.patientName}</div>
                                                            <div className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                                {p.patientRef}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 text-sm">
                                                            <EsiBadge esi={p.esi} label={p.esiLabel} />
                                                        </td>
                                                        <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{p.chiefComplaint}</td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            {p.triageRoom}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            {formatClock(p.arrivedAt)}
                                                        </td>
                                                        <td className="px-4 py-3 text-right text-sm">
                                                            <span
                                                                className={`tabular-nums font-medium ${
                                                                    p.overTarget
                                                                        ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                                                                        : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                                                }`}
                                                            >
                                                                {formatWait(p.waitMinutes)}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-sm">
                                                            <StatusBadge status={p.status} tone={p.statusTone} />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </Panel>
                        </Section>

                        <div className="flex flex-col gap-5">
                            <Section title="Acuity Mix" icon="heroicons:chart-bar"
                                     summary="Active queue by ESI level">
                                <Panel className="p-4">
                                    {esiBreakdown.length === 0 ? (
                                        <EmptyState message="No acuity data available." icon="heroicons:chart-bar" />
                                    ) : (
                                        <EsiDistribution breakdown={esiBreakdown} />
                                    )}
                                </Panel>
                            </Section>

                            <Section title="Longest Waits" icon="heroicons:clock"
                                     summary="Watch-list, by current wait">
                                <Panel className="p-4">
                                    {longestWaits.length === 0 ? (
                                        <EmptyState message="No patients waiting." icon="heroicons:clock" />
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
                                </Panel>
                            </Section>
                        </div>
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
