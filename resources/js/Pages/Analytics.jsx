import React, { useEffect, useMemo, useState } from 'react';
import PropTypes from 'prop-types';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    AlertCircle,
    ArrowRightCircle,
    BarChart3,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Database,
    Gauge,
    GitBranch,
    LineChart,
    ListChecks,
    PlayCircle,
    RefreshCcw,
    Search,
    Shield,
    Target,
    Timer,
    TrendingUp,
    UserCheck,
    Workflow,
    XCircle,
} from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { formatDurationForUnit } from '@/lib/duration';

const sectionDefinitions = {
    hub: {
        label: 'Intelligence Hub',
        href: '/analytics',
        icon: BarChart3,
        status: 'active',
        headline: 'Hospital-wide signal fusion',
        summary: 'Capacity, flow, surgical throughput, transport, ED, and improvement work consolidated into one operating picture.',
        cadence: '45 sec live refresh plus daily retrospectives',
        owner: 'Operations command team',
        horizon: 'Now / shift / 7 day',
        outputs: [
            'System strain score with capacity, flow, outcome, and forecast drivers.',
            'Cross-domain exception queue ranked by bed-hours, delay minutes, safety risk, and owner.',
            'One click drill-through to command center, RTDC huddles, surgical analytics, transport, and PDSA work.',
        ],
        decisions: [
            'Which constraint needs action before the next bed meeting?',
            'Which improvement work is measurably changing today\'s flow?',
            'Which metric changed because of demand, capacity, process reliability, or data quality?',
        ],
    },
    live: {
        label: 'Live Signals',
        href: '/analytics/live',
        icon: Activity,
        status: 'active',
        headline: 'Real-time operations watch',
        summary: 'Current-state telemetry for demand, staffed capacity, patient movement, OR progression, transport load, and active barriers.',
        cadence: 'Event stream plus 45 sec polling',
        owner: 'House supervisor / command center',
        horizon: 'Now to next 8 hours',
        outputs: [
            'Live capacity map with staffed beds, blocked beds, pending admits, ED boarders, and discharge readiness.',
            'Escalation triggers for admit-to-bed latency, transport SLA risk, PACU holds, and discharge blockers.',
            'Huddle-ready queue of unresolved barriers grouped by owner and time at risk.',
        ],
        decisions: [
            'Where should the next bed, nurse, transporter, or discharge resource go?',
            'Which exception should be escalated now rather than discussed retrospectively?',
            'Which huddle plan changed the live signal in the last hour?',
        ],
    },
    retrospective: {
        label: 'Retrospective Review',
        href: '/analytics/retrospective',
        icon: TrendingUp,
        status: 'planned',
        headline: 'Performance review and variation study',
        summary: 'Service-line, unit, provider, location, shift, weekday, seasonality, and cohort analysis across operational metrics.',
        cadence: 'Daily close plus weekly/monthly reviews',
        owner: 'Service-line operations',
        horizon: '7 day / 30 day / 13 week / fiscal year',
        outputs: [
            'Control charts, run charts, percentile bands, cohort comparisons, and target attainment.',
            'Variance attribution by demand mix, staffing, location, room, service, discharge timing, and upstream queues.',
            'Executive-ready scorecards with drill-through to event-level evidence.',
        ],
        decisions: [
            'Which variation is signal rather than noise?',
            'Which service, unit, shift, or workflow has the biggest stable gap to target?',
            'Which improvement should be stopped, scaled, or redesigned?',
        ],
    },
    predictive: {
        label: 'Predictive Planning',
        href: '/analytics/predictive',
        icon: LineChart,
        status: 'planned',
        headline: 'Forecast and early-warning engine',
        summary: 'Demand, discharge, occupancy, staffing, transport, OR, and ancillary forecasts with confidence, back-tests, and action thresholds.',
        cadence: 'Hourly refresh with daily model review',
        owner: 'Capacity management / analytics',
        horizon: '4 hour / 24 hour / 7 day',
        outputs: [
            'Admission, discharge, bed need, occupancy, staffing, and transport forecasts with uncertainty bands.',
            'Surge and bottleneck probabilities tied to concrete mitigation playbooks.',
            'Back-tested reliability, drift flags, and model version evidence before forecasts reach operators.',
        ],
        decisions: [
            'Which constraint will bind before the next shift change?',
            'Which plan has the best expected bed-hour, wait-time, and staffing impact?',
            'Which forecast is too unreliable for operational action today?',
        ],
    },
    'process-intelligence': {
        label: 'Process Intelligence',
        href: '/analytics/process-intelligence',
        icon: Workflow,
        status: 'planned',
        headline: 'Process mining and bottleneck causality',
        summary: 'Event-log analysis for ED-to-inpatient, discharge, bed placement, surgical flow, transport, and ancillary turnaround.',
        cadence: 'Daily event-log build with live exception overlays',
        owner: 'Process improvement team',
        horizon: 'Event to cohort',
        outputs: [
            'Happy-path, variant, rework, handoff, wait-state, and bottleneck views from canonical operational events.',
            'Cascade analysis showing how one delayed process affects downstream beds, OR starts, transport, and diversion.',
            'Root-cause worklists that link evidence to PDSA cycles and owner assignments.',
        ],
        decisions: [
            'Which process step should be redesigned first?',
            'Which variation is acceptable clinical tailoring versus avoidable operational waste?',
            'Which root cause explains the largest downstream delay footprint?',
        ],
    },
    opportunities: {
        label: 'Opportunity Portfolio',
        href: '/analytics/opportunities',
        icon: AlertCircle,
        status: 'planned',
        headline: 'Actionable improvement portfolio',
        summary: 'Prioritized opportunity management that converts analytic findings into owned experiments, playbooks, and measured impact.',
        cadence: 'Live intake plus weekly governance',
        owner: 'Improvement governance council',
        horizon: 'Idea to sustained control',
        outputs: [
            'Opportunity scoring by patient impact, bed-hours, avoidable delay, financial value, feasibility, equity, and confidence.',
            'Suggested interventions mapped to RTDC plans, staffing changes, block governance, discharge redesign, and transport workflows.',
            'PDSA impact tracking with baseline, countermeasure, adoption, balancing metrics, and sustainment checks.',
        ],
        decisions: [
            'Which opportunity earns scarce leadership attention this week?',
            'Which intervention is safe to standardize?',
            'Which improvement created an unintended harm or balancing-measure tradeoff?',
        ],
    },
    workbench: {
        label: 'Scenario Workbench',
        href: '/analytics/workbench',
        icon: Search,
        status: 'planned',
        headline: 'What-if planning and constraint simulation',
        summary: 'Scenario modeling for staffing, capacity, discharge acceleration, elective volume, transport resources, and surge response.',
        cadence: 'On demand plus scheduled planning cycles',
        owner: 'Operations planning',
        horizon: 'Shift to quarter',
        outputs: [
            'Scenario comparison for beds opened, staff added, block changes, early discharge targets, and diversion mitigation.',
            'Constraint-aware recommendations with capacity, staffing, transport, OR, and safety assumptions made explicit.',
            'Simulation audit trail so approved plans can be revisited against actual outcomes.',
        ],
        decisions: [
            'Which plan produces the most throughput with acceptable operational risk?',
            'Which assumption is most sensitive and needs confirmation before action?',
            'Which capital, staffing, or service-line decision is supported by the forecast history?',
        ],
    },
    'data-quality': {
        label: 'Data Quality',
        href: '/analytics/data-quality',
        icon: Shield,
        status: 'active',
        headline: 'Trust, lineage, and model governance',
        summary: 'Freshness, completeness, reconciliation, lineage, PHI boundaries, access controls, and model performance monitoring.',
        cadence: 'Continuous checks plus release gates',
        owner: 'Analytics governance',
        horizon: 'Now to audit history',
        outputs: [
            'Source freshness and completeness checks for census, ED, OR, transport, predictions, operational events, and improvement records.',
            'Metric definitions with lineage from source table to transformation to visual surface.',
            'Forecast/model cards with intended use, validation, drift, bias review, owner, and rollback status.',
        ],
        decisions: [
            'Which metric should be withheld from decisions because source quality is degraded?',
            'Which model needs retraining, downgrade, or retirement?',
            'Which user role should see PHI, de-identified evidence, or aggregate-only views?',
        ],
    },
};

const primarySections = [
    sectionDefinitions.hub,
    sectionDefinitions.live,
    sectionDefinitions.retrospective,
    sectionDefinitions.predictive,
    sectionDefinitions['process-intelligence'],
    sectionDefinitions.opportunities,
    sectionDefinitions.workbench,
    sectionDefinitions['data-quality'],
];

const sectionApiPaths = {
    hub: '/api/analytics/overview',
    live: '/api/analytics/live',
    retrospective: '/api/analytics/retrospective',
    predictive: '/api/analytics/predictive',
    'process-intelligence': '/api/analytics/process-intelligence',
    opportunities: '/api/analytics/opportunities',
    workbench: '/api/analytics/workbench',
    'data-quality': '/api/analytics/data-quality',
};

const fallbackIntelligenceMetrics = [
    {
        label: 'Live Signal Coverage',
        value: '7',
        unit: 'domains',
        status: 'success',
        detail: 'RTDC, ED, OR, transport, outcomes, PDSA, data trust',
    },
    {
        label: 'Decision Horizons',
        value: '4',
        unit: 'bands',
        status: 'info',
        detail: 'Now, shift, 24 hour, strategic retrospective',
    },
    {
        label: 'Action Pathways',
        value: '6',
        unit: 'loops',
        status: 'warning',
        detail: 'Huddle, bed placement, discharge, OR, transport, PDSA',
    },
    {
        label: 'Legacy Surgical Pages',
        value: '5',
        unit: 'kept',
        status: 'neutral',
        detail: 'Moved under Surgical Deep Dives',
    },
];

const fallbackActionQueue = [
    {
        title: 'ED admit-to-bed delay cluster',
        owner: 'Capacity huddle',
        impact: 'High',
        status: 'critical',
        route: '/dashboard',
    },
    {
        title: 'Discharge before noon reliability',
        owner: 'RTDC unit huddles',
        impact: 'Medium',
        status: 'warning',
        route: '/rtdc/unit-huddle',
    },
    {
        title: 'Transport handoff SLA drift',
        owner: 'Transport dispatch',
        impact: 'Medium',
        status: 'warning',
        route: '/transport/analytics',
    },
    {
        title: 'OR first-case late start variance',
        owner: 'Perioperative operations',
        impact: 'Medium',
        status: 'info',
        route: '/analytics/or-utilization',
    },
];

const fallbackSourceMap = [
    {
        label: 'Command center payload',
        source: 'CommandCenterDataService',
        scope: 'capacity, flow, outcomes, forecast',
        route: '/dashboard',
    },
    {
        label: 'RTDC event loop',
        source: 'rtdc_predictions, huddles, barriers',
        scope: 'demand/capacity plans and reconciliation',
        route: '/rtdc/global-huddle',
    },
    {
        label: 'Patient flow evidence',
        source: 'ed_visits, bed_requests, operational_events',
        scope: 'admit, boarding, placement, discharge flow',
        route: '/rtdc/bed-placement',
    },
    {
        label: 'Surgical throughput',
        source: 'or_cases, case_metrics, block_utilization',
        scope: 'block, room, prime time, turnover',
        route: '/analytics/block-utilization',
    },
    {
        label: 'Transport operations',
        source: 'transport_requests, transport_events',
        scope: 'request, assignment, SLA, handoff',
        route: '/transport/analytics',
    },
    {
        label: 'Improvement work',
        source: 'pdsa_cycles and process layouts',
        scope: 'experiments, ownership, sustainment',
        route: '/improvement/pdsa',
    },
];

const deepDiveLinks = [
    { label: 'Block Utilization', href: '/analytics/block-utilization', icon: BarChart3 },
    { label: 'OR Utilization', href: '/analytics/or-utilization', icon: Gauge },
    { label: 'Primetime Utilization', href: '/analytics/primetime-utilization', icon: Clock },
    { label: 'Room Running', href: '/analytics/room-running', icon: Activity },
    { label: 'Turnover Times', href: '/analytics/turnover-times', icon: Timer },
];

const implementationPhases = [
    {
        phase: 'Foundation',
        status: 'active',
        scope: 'Navigation, hub shell, source map, data-quality contract, preserved surgical deep dives.',
    },
    {
        phase: 'Unified Metrics',
        status: 'planned',
        scope: 'Canonical metric registry, live API payloads, metric definitions, lineage, and freshness checks.',
    },
    {
        phase: 'Prediction',
        status: 'planned',
        scope: 'Forecast service, back-testing, confidence bands, alert thresholds, and model-card governance.',
    },
    {
        phase: 'Improvement Loop',
        status: 'planned',
        scope: 'Opportunity scoring, PDSA linkage, intervention library, and outcome/balancing metrics.',
    },
];

const toneClasses = {
    critical: 'border-healthcare-critical/30 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
    warning: 'border-healthcare-warning/30 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
    success: 'border-healthcare-success/30 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
    info: 'border-healthcare-info/30 bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark',
    active: 'border-healthcare-success/30 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
    planned: 'border-healthcare-warning/30 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
    neutral: 'border-healthcare-border bg-healthcare-surface-secondary text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-secondary-dark',
};

function StatusPill({ status, children }) {
    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${toneClasses[status] || toneClasses.neutral}`}>
            {children}
        </span>
    );
}

function MetricTile({ metric }) {
    const durationDisplay = formatDurationForUnit(Number(metric.value), metric.unit);

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {metric.label}
                    </p>
                    <div className="mt-2 flex items-baseline gap-2">
                        <span className="text-3xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {durationDisplay ?? String(metric.value)}
                        </span>
                        {durationDisplay === null && (
                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {metric.unit}
                            </span>
                        )}
                    </div>
                </div>
                <StatusPill status={metric.status}>{metric.status}</StatusPill>
            </div>
            <p className="mt-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {metric.detail}
            </p>
            {metric.sourceTrust && (
                <p className="mt-2 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Source trust {metric.sourceTrust.score}% · {metric.lineageSummary}
                </p>
            )}
        </div>
    );
}

function SectionNav({ activeKey }) {
    return (
        <div className="flex gap-2 overflow-x-auto pb-1">
            {primarySections.map((item) => {
                const Icon = item.icon;
                const active = Object.keys(sectionDefinitions).find((key) => sectionDefinitions[key] === item) === activeKey;
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`inline-flex min-h-[40px] shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors ${
                            active
                                ? 'border-healthcare-primary bg-healthcare-primary text-white dark:border-healthcare-primary-dark dark:bg-healthcare-primary-dark'
                                : 'border-healthcare-border bg-healthcare-surface text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark'
                        }`}
                    >
                        <Icon className="h-4 w-4" />
                        {item.label}
                    </Link>
                );
            })}
        </div>
    );
}

function EngineStatusBanner({ isLoading, apiError, generatedAtIso }) {
    const status = apiError ? 'warning' : 'success';
    const label = apiError ? 'Fallback Shell' : 'Live API';
    const detail = apiError
        ? 'Live analytics payload could not be loaded; showing the design fallback.'
        : generatedAtIso
            ? `Generated ${new Date(generatedAtIso).toLocaleString()}`
            : 'Connected to the operations analytics engine.';

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-healthcare-border bg-healthcare-surface px-4 py-3 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <RefreshCcw className={`h-4 w-4 text-healthcare-primary dark:text-healthcare-primary-dark ${isLoading ? 'animate-spin' : ''}`} />
                <span className="font-medium">{isLoading ? 'Refreshing analytics engine' : label}</span>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <span>{detail}</span>
                <StatusPill status={isLoading ? 'info' : status}>{isLoading ? 'loading' : status}</StatusPill>
            </div>
        </div>
    );
}

function DetailPanel({ section, sources }) {
    const Icon = section.icon;
    const sourceAnchors = sources.length > 0 ? sources : fallbackSourceMap;

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex flex-col gap-4 border-b border-healthcare-border p-5 dark:border-healthcare-border-dark lg:flex-row lg:items-start lg:justify-between">
                <div className="flex items-start gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-healthcare-primary/10 text-healthcare-primary dark:bg-healthcare-primary-dark/20 dark:text-healthcare-primary-dark">
                        <Icon className="h-5 w-5" />
                    </div>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {section.headline}
                            </h2>
                            <StatusPill status={section.status}>{section.status}</StatusPill>
                        </div>
                        <p className="mt-2 max-w-4xl text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {section.summary}
                        </p>
                    </div>
                </div>
                <div className="grid min-w-[260px] grid-cols-1 gap-2 text-sm sm:grid-cols-3 lg:grid-cols-1">
                    <MetaLine label="Cadence" value={section.cadence} />
                    <MetaLine label="Owner" value={section.owner} />
                    <MetaLine label="Horizon" value={section.horizon} />
                </div>
            </div>

            <div className="grid gap-5 p-5 xl:grid-cols-3">
                <InsightList title="Decision Questions" icon={Target} items={section.decisions} />
                <InsightList title="Engine Outputs" icon={ListChecks} items={section.outputs} />
                <div>
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <Database className="h-4 w-4 text-healthcare-teal dark:text-healthcare-teal-dark" />
                        Source Anchors
                    </h3>
                    <div className="mt-3 space-y-2">
                        {sourceAnchors.slice(0, 4).map((source) => (
                            <Link
                                key={source.label}
                                href={source.route}
                                className="block rounded-lg border border-healthcare-border p-3 text-sm transition-colors hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {source.label}
                                    </span>
                                    <ArrowRightCircle className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                                </div>
                                <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {source.scope}
                                </p>
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

function MetaLine({ label, value }) {
    return (
        <div className="rounded-lg bg-healthcare-background px-3 py-2 dark:bg-healthcare-background-dark">
            <div className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {label}
            </div>
            <div className="mt-1 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {value}
            </div>
        </div>
    );
}

function InsightList({ title, icon: Icon, items }) {
    return (
        <div>
            <h3 className="flex items-center gap-2 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <Icon className="h-4 w-4 text-healthcare-primary dark:text-healthcare-primary-dark" />
                {title}
            </h3>
            <ul className="mt-3 space-y-2">
                {items.map((item) => (
                    <li key={item} className="rounded-lg border border-healthcare-border px-3 py-2 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                        {item}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ActionQueue({ items }) {
    const queue = items.length > 0 ? items : fallbackActionQueue;

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    <AlertCircle className="h-4 w-4 text-healthcare-warning dark:text-healthcare-warning-dark" />
                    Action Queue
                </h2>
                <Link href="/improvement/pdsa" className="text-sm font-medium text-healthcare-primary hover:underline dark:text-healthcare-primary-dark">
                    PDSA
                </Link>
            </div>
            <div className="mt-4 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                {queue.map((item) => (
                    <Link key={item.title} href={item.route} className="flex items-start justify-between gap-3 py-3">
                        <div>
                            <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {item.title}
                            </p>
                            <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {item.owner}
                            </p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1">
                            <StatusPill status={item.status}>{item.impact}</StatusPill>
                            <ArrowRightCircle className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function DataQualityAgentPanel({ agent }) {
    if (!agent) {
        return null;
    }

    const findings = agent.findings?.length ? agent.findings : [];

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <Shield className="h-4 w-4 text-healthcare-primary dark:text-healthcare-primary-dark" />
                        {agent.label}
                    </h2>
                    <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {agent.mode} · LLM {agent.llmEnabled ? 'on' : 'off'} · {agent.summary?.checksEvaluated ?? 0} checks
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill status={agent.status}>{agent.status}</StatusPill>
                    <StatusPill status={agent.summary?.critical > 0 ? 'critical' : agent.summary?.warning > 0 ? 'warning' : 'success'}>
                        {agent.summary?.issuesOpen ?? 0} open
                    </StatusPill>
                </div>
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="space-y-2">
                    {findings.slice(0, 5).map((finding) => (
                        <div key={finding.key} className="rounded-lg border border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {finding.label}
                                    </p>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {finding.detail}
                                    </p>
                                </div>
                                <StatusPill status={finding.status}>{finding.status}</StatusPill>
                            </div>
                            <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {finding.recommendedAction}
                            </p>
                        </div>
                    ))}
                    {findings.length === 0 && (
                        <div className="rounded-lg border border-healthcare-border px-3 py-3 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                            No open findings.
                        </div>
                    )}
                </div>
                <div className="space-y-2">
                    {(agent.rules ?? []).map((rule) => (
                        <div key={rule.key} className="rounded-lg bg-healthcare-background px-3 py-2 dark:bg-healthcare-background-dark">
                            <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {rule.label}
                            </p>
                            <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {rule.scope}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function SimulationWorkbenchPanel({ simulation }) {
    const [pendingScenario, setPendingScenario] = useState(null);
    const [promotedScenarioIds, setPromotedScenarioIds] = useState(() => new Set());

    if (!simulation?.scenarios?.length) {
        return null;
    }

    const baseline = simulation.baseline ?? {};
    const summary = simulation.summary ?? {};

    const promoteScenario = async (scenario) => {
        setPendingScenario(scenario.scenarioId);
        try {
            await axios.post(`/api/ops/simulation-scenarios/${scenario.scenarioId}/promote`);
            setPromotedScenarioIds((current) => new Set([...current, scenario.scenarioId]));
        } finally {
            setPendingScenario(null);
        }
    };

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <Gauge className="h-4 w-4 text-healthcare-info dark:text-healthcare-info-dark" />
                        Simulation Workbench
                    </h2>
                    <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Run {simulation.run?.simulationRunUuid} · snapshot {simulation.run?.baselineSnapshotId}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill status="info">{summary.scenarioCount ?? simulation.scenarios.length} scenarios</StatusPill>
                    <StatusPill status={(summary.bestRiskScore ?? 100) >= 70 ? 'critical' : (summary.bestRiskScore ?? 100) >= 45 ? 'warning' : 'success'}>
                        best risk {summary.bestRiskScore ?? baseline.risk_score ?? 0}
                    </StatusPill>
                </div>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-4">
                {[
                    ['Current net beds', baseline.current_net_beds, 'beds'],
                    ['ED boarders', baseline.ed_boarders, 'patients'],
                    ['Dirty/blocked beds', baseline.dirty_or_blocked_beds, 'beds'],
                    ['PACU holds', baseline.pacu_holds, 'holds'],
                ].map(([label, value, unit]) => (
                    <div key={label} className="rounded-lg bg-healthcare-background px-3 py-2 dark:bg-healthcare-background-dark">
                        <div className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {label}
                        </div>
                        <div className="mt-1 text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {value ?? 0} <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{unit}</span>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4 overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
                <div className="min-w-[860px]">
                    <div className="grid grid-cols-[1.4fr_120px_120px_1fr_130px] bg-healthcare-background px-3 py-2 text-xs font-semibold uppercase text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                        <span>Scenario</span>
                        <span>Net Beds</span>
                        <span>Risk</span>
                        <span>Interventions</span>
                        <span className="text-right">Action</span>
                    </div>
                    {simulation.scenarios.map((scenario) => {
                        const isPromotable = scenario.key !== 'no_action' && !scenario.promotedAtIso;
                        const isPending = pendingScenario === scenario.scenarioId;

                        return (
                            <div key={scenario.scenarioUuid} className="grid grid-cols-[1.4fr_120px_120px_1fr_130px] items-center gap-3 border-t border-healthcare-border px-3 py-3 text-sm dark:border-healthcare-border-dark">
                                <div>
                                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {scenario.title}
                                    </p>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {scenario.assumption}
                                    </p>
                                </div>
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {scenario.netBedForecast}
                                </span>
                                <span>
                                    <StatusPill status={scenario.riskScore >= 70 ? 'critical' : scenario.riskScore >= 45 ? 'warning' : 'success'}>
                                        {scenario.riskScore}
                                    </StatusPill>
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {scenario.interventions?.length ? scenario.interventions.join(', ') : 'Baseline comparison'}
                                </span>
                                <div className="flex justify-end">
                                    {scenario.promotedAtIso || promotedScenarioIds.has(scenario.scenarioId) ? (
                                        <StatusPill status="success">promoted</StatusPill>
                                    ) : (
                                        <button
                                            type="button"
                                            disabled={!isPromotable || isPending}
                                            onClick={() => promoteScenario(scenario)}
                                            className="inline-flex min-h-[34px] items-center gap-2 rounded-lg border border-healthcare-border px-3 py-2 text-xs font-medium text-healthcare-text-primary transition-colors hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                                        >
                                            <ClipboardCheck className="h-4 w-4" />
                                            Promote
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function GraphRecommendationsPanel({ recommendations, summary, onLifecycleChange }) {
    const [pendingOperation, setPendingOperation] = useState(null);

    if (!recommendations?.length) {
        return null;
    }

    const runLifecycleRequest = async (operationKey, request) => {
        setPendingOperation(operationKey);
        try {
            await request();
            onLifecycleChange?.();
        } finally {
            setPendingOperation(null);
        }
    };

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <Workflow className="h-4 w-4 text-healthcare-primary dark:text-healthcare-primary-dark" />
                        Graph-Backed Recommendations
                    </h2>
                    <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {summary?.total ?? recommendations.length} draft recommendations · {summary?.pendingApprovals ?? 0} pending approvals
                    </p>
                </div>
                <StatusPill status={(summary?.critical ?? 0) > 0 ? 'critical' : (summary?.high ?? 0) > 0 ? 'warning' : 'info'}>
                    approval gated
                </StatusPill>
            </div>

            <div className="mt-4 grid gap-3 xl:grid-cols-2">
                {recommendations.slice(0, 6).map((recommendation) => {
                    const action = recommendation.actions?.[0] ?? null;
                    const pendingApproval = action?.approvals?.find((approval) => approval.status === 'pending') ?? null;
                    const owner = action?.ownerName ?? action?.payload?.owner ?? 'Operations command team';
                    const isBusy = pendingOperation?.startsWith(`${recommendation.recommendationUuid}:`);

                    return (
                        <div key={recommendation.recommendationUuid} className="rounded-lg border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {recommendation.title}
                                    </p>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {recommendation.rationale}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusPill status={recommendation.riskLevel === 'critical' ? 'critical' : 'warning'}>
                                        {recommendation.riskLevel}
                                    </StatusPill>
                                    <StatusPill status={recommendation.status === 'completed' ? 'success' : recommendation.status === 'draft' ? 'info' : 'warning'}>
                                        {recommendation.status}
                                    </StatusPill>
                                </div>
                            </div>
                            <div className="mt-3 grid gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark sm:grid-cols-2">
                                <span>Confidence {Math.round((recommendation.confidence ?? 0) * 100)}%</span>
                                <span>{recommendation.evidence?.graph_nodes?.length ?? 0} graph nodes</span>
                                <span>{owner}</span>
                                <span>{pendingApproval?.status ?? action?.status ?? 'queued'} approval</span>
                            </div>

                            {action && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {pendingApproval && (
                                        <>
                                            <button
                                                type="button"
                                                disabled={isBusy}
                                                onClick={() => runLifecycleRequest(
                                                    `${recommendation.recommendationUuid}:approve`,
                                                    () => axios.post(`/api/ops/approvals/${pendingApproval.approvalId}/decision`, { decision: 'approved' }),
                                                )}
                                                className="inline-flex min-h-[34px] items-center gap-2 rounded-lg bg-healthcare-success px-3 py-2 text-xs font-medium text-white transition-colors hover:bg-healthcare-success/90 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <CheckCircle2 className="h-4 w-4" />
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                disabled={isBusy}
                                                onClick={() => runLifecycleRequest(
                                                    `${recommendation.recommendationUuid}:reject`,
                                                    () => axios.post(`/api/ops/approvals/${pendingApproval.approvalId}/decision`, { decision: 'rejected', reason: 'Rejected from Operations Intelligence review.' }),
                                                )}
                                                className="inline-flex min-h-[34px] items-center gap-2 rounded-lg border border-healthcare-critical px-3 py-2 text-xs font-medium text-healthcare-critical transition-colors hover:bg-healthcare-critical/10 disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-critical-dark dark:text-healthcare-critical-dark"
                                            >
                                                <XCircle className="h-4 w-4" />
                                                Reject
                                            </button>
                                        </>
                                    )}
                                    {action.status === 'approved' && (
                                        <button
                                            type="button"
                                            disabled={isBusy}
                                            onClick={() => runLifecycleRequest(
                                                `${recommendation.recommendationUuid}:assign`,
                                                () => axios.post(`/api/ops/actions/${action.actionId}/assign`, { owner_name: owner }),
                                            )}
                                            className="inline-flex min-h-[34px] items-center gap-2 rounded-lg border border-healthcare-border px-3 py-2 text-xs font-medium text-healthcare-text-primary transition-colors hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                                        >
                                            <UserCheck className="h-4 w-4" />
                                            Assign
                                        </button>
                                    )}
                                    {action.status === 'assigned' && (
                                        <button
                                            type="button"
                                            disabled={isBusy}
                                            onClick={() => runLifecycleRequest(
                                                `${recommendation.recommendationUuid}:start`,
                                                () => axios.post(`/api/ops/actions/${action.actionId}/start`),
                                            )}
                                            className="inline-flex min-h-[34px] items-center gap-2 rounded-lg border border-healthcare-border px-3 py-2 text-xs font-medium text-healthcare-text-primary transition-colors hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                                        >
                                            <PlayCircle className="h-4 w-4" />
                                            Start
                                        </button>
                                    )}
                                    {action.status === 'executing' && (
                                        <button
                                            type="button"
                                            disabled={isBusy}
                                            onClick={() => runLifecycleRequest(
                                                `${recommendation.recommendationUuid}:complete`,
                                                () => axios.post(`/api/ops/actions/${action.actionId}/complete`, { note: 'Completed from Operations Intelligence review.' }),
                                            )}
                                            className="inline-flex min-h-[34px] items-center gap-2 rounded-lg bg-healthcare-primary px-3 py-2 text-xs font-medium text-white transition-colors hover:bg-healthcare-primary-hover disabled:cursor-not-allowed disabled:opacity-60 dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary"
                                        >
                                            <ClipboardCheck className="h-4 w-4" />
                                            Complete
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function ImpactAttributionPanel({ impact }) {
    if (!impact) {
        return null;
    }

    const cards = impact.cards ?? [];
    const interventions = impact.interventions ?? [];

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        <TrendingUp className="h-4 w-4 text-healthcare-success dark:text-healthcare-success-dark" />
                        Intervention Impact
                    </h2>
                    <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {impact.summary?.confidenceLanguage ?? 'Before/after attribution will appear when completed actions are available.'}
                    </p>
                </div>
                <StatusPill status={impact.summary?.confidenceLevel === 'high' ? 'success' : impact.summary?.confidenceLevel === 'medium' ? 'warning' : 'neutral'}>
                    {impact.summary?.confidenceLevel ?? 'insufficient'}
                </StatusPill>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-4">
                {cards.map((card) => (
                    <div key={card.label} className="rounded-lg bg-healthcare-background px-3 py-2 dark:bg-healthcare-background-dark">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {card.label}
                                </div>
                                <div className="mt-1 text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {card.value} <span className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{card.unit}</span>
                                </div>
                            </div>
                            <StatusPill status={card.status}>{card.status}</StatusPill>
                        </div>
                        <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {card.detail}
                        </p>
                    </div>
                ))}
            </div>

            <div className="mt-4 overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
                <div className="min-w-[920px]">
                    <div className="grid grid-cols-[1.4fr_130px_130px_150px_1fr] bg-healthcare-background px-3 py-2 text-xs font-semibold uppercase text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                        <span>Intervention</span>
                        <span>Outcome</span>
                        <span>Delta</span>
                        <span>Confidence</span>
                        <span>Window</span>
                    </div>
                    {interventions.map((intervention) => {
                        const primaryMetric = intervention.metrics?.find((metric) => metric.isPrimary) ?? intervention.metrics?.[0];
                        const delta = primaryMetric?.deltaValue ?? 0;

                        return (
                            <div key={intervention.interventionUuid} className="grid grid-cols-[1.4fr_130px_130px_150px_1fr] items-center gap-3 border-t border-healthcare-border px-3 py-3 text-sm dark:border-healthcare-border-dark">
                                <div>
                                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {intervention.title}
                                    </p>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {intervention.ownerName ?? 'Operations'} · {intervention.pdsaTitle ?? intervention.type}
                                    </p>
                                </div>
                                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {primaryMetric?.label ?? 'Outcome'}
                                </span>
                                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {delta > 0 ? '+' : ''}{delta} {primaryMetric?.unit ?? ''}
                                </span>
                                <span>
                                    <StatusPill status={intervention.confidenceLevel === 'high' ? 'success' : intervention.confidenceLevel === 'medium' ? 'warning' : 'neutral'}>
                                        {intervention.confidenceLevel}
                                    </StatusPill>
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {intervention.attribution?.executiveSummary ?? intervention.confidenceLanguage}
                                </span>
                            </div>
                        );
                    })}
                    {interventions.length === 0 && (
                        <div className="border-t border-healthcare-border px-3 py-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                            No intervention attribution records yet.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function PhasePlan() {
    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <GitBranch className="h-4 w-4 text-healthcare-purple dark:text-healthcare-purple-dark" />
                Build Sequence
            </h2>
            <div className="mt-4 space-y-3">
                {implementationPhases.map((phase) => (
                    <div key={phase.phase} className="grid gap-2 rounded-lg border border-healthcare-border p-3 dark:border-healthcare-border-dark sm:grid-cols-[150px_1fr]">
                        <div className="flex items-center justify-between gap-2 sm:block">
                            <div className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {phase.phase}
                            </div>
                            <StatusPill status={phase.status}>{phase.status}</StatusPill>
                        </div>
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {phase.scope}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DeepDivePanel() {
    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <Gauge className="h-4 w-4 text-healthcare-info dark:text-healthcare-info-dark" />
                Surgical Deep Dives
            </h2>
            <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                {deepDiveLinks.map((item) => {
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className="flex min-h-[56px] items-center justify-between gap-3 rounded-lg border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary transition-colors hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                        >
                            <span className="flex items-center gap-2">
                                <Icon className="h-4 w-4 text-healthcare-primary dark:text-healthcare-primary-dark" />
                                {item.label}
                            </span>
                            <ArrowRightCircle className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}

function SourceMapPanel({ sources }) {
    const sourceRows = sources.length > 0 ? sources : fallbackSourceMap;

    return (
        <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <h2 className="flex items-center gap-2 text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <Database className="h-4 w-4 text-healthcare-teal dark:text-healthcare-teal-dark" />
                Source Map
            </h2>
            <div className="mt-4 overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
                <div className="min-w-[720px]">
                    <div className="grid grid-cols-[1fr_1.3fr_1.4fr_120px] bg-healthcare-background px-3 py-2 text-xs font-semibold uppercase text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                        <span>Signal</span>
                        <span>Current Source</span>
                        <span>Operational Scope</span>
                        <span className="text-right">Status</span>
                    </div>
                    {sourceRows.map((source) => (
                        <Link
                            key={source.label}
                            href={source.route}
                            className="grid grid-cols-[1fr_1.3fr_1.4fr_120px] items-center gap-3 border-t border-healthcare-border px-3 py-3 text-sm transition-colors hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
                        >
                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {source.label}
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {source.source}
                                {source.freshnessLabel && (
                                    <span className="mt-1 block text-xs">
                                        {source.freshnessLabel}
                                    </span>
                                )}
                            </span>
                            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {source.scope}
                            </span>
                            <span className="flex items-center justify-end">
                                {source.status && (
                                    <span className="mr-2 hidden sm:inline-flex">
                                        <StatusPill status={source.status}>{source.status}</StatusPill>
                                    </span>
                                )}
                                <ArrowRightCircle className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                            </span>
                        </Link>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function Analytics({ section = 'hub' }) {
    const activeKey = sectionDefinitions[section] ? section : 'hub';
    const activeSection = sectionDefinitions[activeKey];
    const [engineData, setEngineData] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [apiError, setApiError] = useState(null);
    const [refreshNonce, setRefreshNonce] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setIsLoading(true);
        setApiError(null);

        axios.get(sectionApiPaths[activeKey])
            .then((response) => {
                if (!cancelled) {
                    setEngineData(response.data?.data ?? null);
                }
            })
            .catch((error) => {
                if (!cancelled) {
                    setApiError(error);
                    setEngineData(null);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setIsLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [activeKey, refreshNonce]);

    const metrics = useMemo(
        () => (engineData?.metrics?.length ? engineData.metrics : fallbackIntelligenceMetrics),
        [engineData],
    );
    const queue = useMemo(
        () => (engineData?.actionQueue?.length ? engineData.actionQueue : fallbackActionQueue),
        [engineData],
    );
    const sources = useMemo(
        () => (engineData?.sourceMap?.length ? engineData.sourceMap : fallbackSourceMap),
        [engineData],
    );
    const agent = engineData?.agent ?? null;
    const recommendations = engineData?.recommendations ?? [];
    const recommendationSummary = engineData?.recommendationSummary ?? null;
    const simulation = engineData?.simulation ?? null;
    const impact = engineData?.impact ?? null;

    return (
        <DashboardLayout>
            {/* Destination-specific identity: every section is its own page in the
                navigation, so H1 and document title must confirm the destination —
                a shared generic heading breaks orientation (HFE audit §4.1). */}
            <Head title={activeKey === 'hub' ? 'Operations Intelligence' : `${activeSection.label} · Operations Intelligence`} />
            <PageContentLayout
                title={activeKey === 'hub' ? 'Operations Intelligence' : activeSection.label}
                subtitle={
                    activeKey === 'hub'
                        ? 'Real-time, retrospective, predictive, and improvement analytics for hospital operations'
                        : activeSection.summary
                }
                headerContent={
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Link
                            href="/dashboard"
                            className="inline-flex min-h-[36px] items-center gap-2 rounded-lg border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                        >
                            <Activity className="h-4 w-4" />
                            Command Center
                        </Link>
                        <Link
                            href="/improvement/pdsa"
                            className="inline-flex min-h-[36px] items-center gap-2 rounded-lg bg-healthcare-primary px-3 py-2 text-sm font-medium text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary"
                        >
                            <RefreshCcw className="h-4 w-4" />
                            PDSA
                        </Link>
                    </div>
                }
            >
                <div className="space-y-5">
                    <SectionNav activeKey={activeKey} />
                    <EngineStatusBanner
                        isLoading={isLoading}
                        apiError={apiError}
                        generatedAtIso={engineData?.generatedAtIso}
                    />

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {metrics.map((metric) => (
                            <MetricTile key={metric.label} metric={metric} />
                        ))}
                    </div>

                    {activeKey === 'data-quality' && (
                        <DataQualityAgentPanel agent={agent} />
                    )}

                    {activeKey === 'opportunities' && (
                        <GraphRecommendationsPanel
                            recommendations={recommendations}
                            summary={recommendationSummary}
                            onLifecycleChange={() => setRefreshNonce((value) => value + 1)}
                        />
                    )}

                    {activeKey === 'workbench' && (
                        <div className="space-y-5">
                            <ImpactAttributionPanel impact={impact} />
                            <SimulationWorkbenchPanel
                                simulation={simulation}
                            />
                        </div>
                    )}

                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <DetailPanel section={activeSection} sources={sources} />
                        <div className="space-y-5">
                            <ActionQueue items={queue} />
                            <PhasePlan />
                        </div>
                    </div>

                    <SourceMapPanel sources={sources} />
                    <DeepDivePanel />
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}

StatusPill.propTypes = {
    status: PropTypes.string.isRequired,
    children: PropTypes.node.isRequired,
};

MetricTile.propTypes = {
    metric: PropTypes.shape({
        label: PropTypes.string.isRequired,
        value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
        unit: PropTypes.string.isRequired,
        status: PropTypes.string.isRequired,
        detail: PropTypes.string.isRequired,
    }).isRequired,
};

SectionNav.propTypes = {
    activeKey: PropTypes.string.isRequired,
};

EngineStatusBanner.propTypes = {
    isLoading: PropTypes.bool.isRequired,
    apiError: PropTypes.object,
    generatedAtIso: PropTypes.string,
};

DetailPanel.propTypes = {
    section: PropTypes.shape({
        label: PropTypes.string.isRequired,
        icon: PropTypes.elementType.isRequired,
        status: PropTypes.string.isRequired,
        headline: PropTypes.string.isRequired,
        summary: PropTypes.string.isRequired,
        cadence: PropTypes.string.isRequired,
        owner: PropTypes.string.isRequired,
        horizon: PropTypes.string.isRequired,
        outputs: PropTypes.arrayOf(PropTypes.string).isRequired,
        decisions: PropTypes.arrayOf(PropTypes.string).isRequired,
    }).isRequired,
    sources: PropTypes.arrayOf(PropTypes.shape({
        label: PropTypes.string.isRequired,
        source: PropTypes.string.isRequired,
        scope: PropTypes.string.isRequired,
        route: PropTypes.string.isRequired,
        freshnessLabel: PropTypes.string,
        status: PropTypes.string,
    })).isRequired,
};

MetaLine.propTypes = {
    label: PropTypes.string.isRequired,
    value: PropTypes.string.isRequired,
};

InsightList.propTypes = {
    title: PropTypes.string.isRequired,
    icon: PropTypes.elementType.isRequired,
    items: PropTypes.arrayOf(PropTypes.string).isRequired,
};

ActionQueue.propTypes = {
    items: PropTypes.arrayOf(PropTypes.shape({
        title: PropTypes.string.isRequired,
        owner: PropTypes.string.isRequired,
        impact: PropTypes.string.isRequired,
        status: PropTypes.string.isRequired,
        route: PropTypes.string.isRequired,
    })).isRequired,
};

SourceMapPanel.propTypes = {
    sources: PropTypes.arrayOf(PropTypes.shape({
        label: PropTypes.string.isRequired,
        source: PropTypes.string.isRequired,
        scope: PropTypes.string.isRequired,
        route: PropTypes.string.isRequired,
        freshnessLabel: PropTypes.string,
        status: PropTypes.string,
    })).isRequired,
};

GraphRecommendationsPanel.propTypes = {
    recommendations: PropTypes.arrayOf(PropTypes.shape({
        recommendationUuid: PropTypes.string.isRequired,
        title: PropTypes.string.isRequired,
        rationale: PropTypes.string,
        confidence: PropTypes.number,
        riskLevel: PropTypes.string.isRequired,
        status: PropTypes.string.isRequired,
        evidence: PropTypes.object,
        actions: PropTypes.arrayOf(PropTypes.shape({
            actionId: PropTypes.number.isRequired,
            status: PropTypes.string.isRequired,
            ownerName: PropTypes.string,
            payload: PropTypes.object,
            approvals: PropTypes.arrayOf(PropTypes.shape({
                approvalId: PropTypes.number.isRequired,
                status: PropTypes.string.isRequired,
            })),
        })),
    })).isRequired,
    summary: PropTypes.shape({
        total: PropTypes.number,
        pendingApprovals: PropTypes.number,
        critical: PropTypes.number,
        high: PropTypes.number,
    }),
    onLifecycleChange: PropTypes.func,
};

SimulationWorkbenchPanel.propTypes = {
    simulation: PropTypes.shape({
        run: PropTypes.shape({
            simulationRunUuid: PropTypes.string,
            baselineSnapshotId: PropTypes.number,
        }),
        baseline: PropTypes.object,
        summary: PropTypes.shape({
            scenarioCount: PropTypes.number,
            bestRiskScore: PropTypes.number,
        }),
        scenarios: PropTypes.arrayOf(PropTypes.shape({
            scenarioId: PropTypes.number.isRequired,
            scenarioUuid: PropTypes.string.isRequired,
            key: PropTypes.string.isRequired,
            title: PropTypes.string.isRequired,
            assumption: PropTypes.string.isRequired,
            netBedForecast: PropTypes.number.isRequired,
            riskScore: PropTypes.number.isRequired,
            interventions: PropTypes.arrayOf(PropTypes.string),
            promotedAtIso: PropTypes.string,
        })),
    }),
};

ImpactAttributionPanel.propTypes = {
    impact: PropTypes.shape({
        summary: PropTypes.shape({
            confidenceLevel: PropTypes.string,
            confidenceLanguage: PropTypes.string,
        }),
        cards: PropTypes.arrayOf(PropTypes.shape({
            label: PropTypes.string.isRequired,
            value: PropTypes.string.isRequired,
            unit: PropTypes.string.isRequired,
            status: PropTypes.string.isRequired,
            detail: PropTypes.string.isRequired,
        })),
        interventions: PropTypes.arrayOf(PropTypes.shape({
            interventionUuid: PropTypes.string.isRequired,
            title: PropTypes.string.isRequired,
            type: PropTypes.string.isRequired,
            ownerName: PropTypes.string,
            pdsaTitle: PropTypes.string,
            confidenceLevel: PropTypes.string,
            confidenceLanguage: PropTypes.string,
            metrics: PropTypes.arrayOf(PropTypes.shape({
                label: PropTypes.string.isRequired,
                unit: PropTypes.string.isRequired,
                deltaValue: PropTypes.number,
                isPrimary: PropTypes.bool,
            })),
            attribution: PropTypes.shape({
                executiveSummary: PropTypes.string,
            }),
        })),
    }),
};

Analytics.propTypes = {
    section: PropTypes.string,
};
