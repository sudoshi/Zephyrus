import { Head, Link, usePage } from "@inertiajs/react";
import axios from "axios";
import {
    ArrowLeft,
    ArrowRight,
    BookOpen,
    Bot,
    Check,
    CircleAlert,
    ClipboardCheck,
    HeartPulse,
    History,
    LockKeyhole,
    MessageCircle,
    Minus,
    RefreshCcw,
    ShieldCheck,
    Smartphone,
    Stethoscope,
    Users,
} from "lucide-react";
import { useMemo, useState, type ReactNode } from "react";
import DashboardLayout from "@/Components/Dashboard/DashboardLayout";
import PageContentLayout from "@/Components/Common/PageContentLayout";
import { Surface } from "@/Components/ui/Surface";
import type { PageProps } from "@/types";

type StepState = "complete" | "current" | "upcoming";

interface ScenarioStep {
    key: string;
    label: string;
    summary: string;
    index: number;
    state: StepState;
}

interface CareTeamProjection {
    visible: boolean;
    assignment: {
        status: string;
        pathway: string;
        version: string;
        current_stage: string;
        confidence: string;
        requires_confirmation: boolean;
        matched: string[];
        conflicts: string[];
        decision_record: string;
    };
    milestones: Array<{ label: string; owner: string; state: string }>;
    next_decisions: string[];
}

interface RoundsProjection {
    visible: boolean;
    queue_status: string;
    pathway_badge: {
        stage: string;
        variance: string | null;
        patient_question_count: number;
    };
    role_inputs: Array<{ role: string; state: string; summary: string }>;
    open_question: string | null;
}

interface StaffProjection {
    visible: boolean;
    for_you: { priority: string; title: string; detail: string };
    patient_context: Record<string, string | number | boolean>;
    notification: { message: string };
}

interface PatientProjection {
    visible: boolean;
    headline: string;
    why_here: string;
    today: Array<{ label: string; state: string }>;
    goals: Array<{ author: string; text: string }>;
    question: { text: string; status: string };
    urgent_help: string;
}

interface EddyProjection {
    visible: boolean;
    mode: string;
    prompt: string;
    answer: string;
    citations: Array<{ label: string; reference: string; scope: string }>;
    guardrails: Record<string, boolean>;
}

interface GovernanceProjection {
    release_state: string;
    controls: { failed: number; residual_unknowns: number };
    activation_blockers: string[];
    separation: Record<string, string>;
}

interface DemoScenario {
    meta: {
        title: string;
        current_step: number;
        max_step: number;
        synthetic: boolean;
        read_only: boolean;
        clinical_use: boolean;
        warning: string;
    };
    steps: ScenarioStep[];
    catalog: Record<string, string | number | boolean>;
    subject: {
        display_name: string;
        synthetic_label: string;
        context_ref: string;
        location: string;
        encounter_day: string;
        working_problem: string;
        service: string;
        privacy: string;
    };
    care_team: CareTeamProjection;
    virtual_rounds: RoundsProjection;
    hummingbird_staff: StaffProjection;
    hummingbird_patient: PatientProjection;
    eddy: EddyProjection;
    governance: GovernanceProjection;
    timeline: Array<{
        step: number;
        time: string;
        actor: string;
        event: string;
    }>;
}

interface DemoProps {
    initialScenario: DemoScenario;
}

export function scenarioFromApiEnvelope(payload: unknown): DemoScenario {
    if (
        typeof payload !== "object" ||
        payload === null ||
        !("data" in payload) ||
        typeof payload.data !== "object" ||
        payload.data === null ||
        !("meta" in payload.data) ||
        typeof payload.data.meta !== "object" ||
        payload.data.meta === null ||
        !("current_step" in payload.data.meta) ||
        !("steps" in payload.data) ||
        !Array.isArray(payload.data.steps)
    ) {
        throw new Error("Invalid care pathway demo response");
    }

    return payload.data as DemoScenario;
}

type SurfaceKey =
    | "care-team"
    | "rounds"
    | "hummingbird-staff"
    | "hummingbird-patient"
    | "eddy"
    | "governance";

const surfaces: Array<{
    key: SurfaceKey;
    label: string;
    icon: typeof Users;
}> = [
    { key: "care-team", label: "Care Team", icon: Users },
    { key: "rounds", label: "Virtual Rounds", icon: Stethoscope },
    { key: "hummingbird-staff", label: "Hummingbird Staff", icon: Smartphone },
    {
        key: "hummingbird-patient",
        label: "Hummingbird Patient",
        icon: HeartPulse,
    },
    { key: "eddy", label: "Eddy", icon: Bot },
    { key: "governance", label: "Governance", icon: ShieldCheck },
];

const initialSurface = (): SurfaceKey => {
    if (typeof window === "undefined") return "care-team";
    const value = new URLSearchParams(window.location.search).get("surface");
    return surfaces.some((surface) => surface.key === value)
        ? (value as SurfaceKey)
        : "care-team";
};

const titleCase = (value: string) =>
    value
        .replaceAll("_", " ")
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

function StatusPill({ value }: { value: string }) {
    const positive = [
        "complete",
        "completed",
        "submitted",
        "resolved",
        "done",
        "active",
        "answered_in_person",
    ].includes(value);
    const attention = [
        "due",
        "today",
        "needs_action",
        "needs_help",
        "action_due",
        "ready_with_barrier",
        "sent_to_care_team",
    ].includes(value);
    const Icon = positive ? Check : attention ? CircleAlert : Minus;
    const tone = positive
        ? "border-healthcare-success/40 text-healthcare-success dark:border-healthcare-success-dark/40 dark:text-healthcare-success-dark"
        : attention
          ? "border-healthcare-warning/40 text-healthcare-warning dark:border-healthcare-warning-dark/40 dark:text-healthcare-warning-dark"
          : "border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark";

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium uppercase tracking-wide ${tone}`}
        >
            <Icon className="h-3 w-3" aria-hidden="true" />
            {titleCase(value)}
        </span>
    );
}

function DemoPanel({
    title,
    icon,
    children,
}: {
    title: string;
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <Surface>
            <header className="flex items-center gap-3 border-b border-healthcare-border px-5 py-3.5 dark:border-healthcare-border-dark">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-healthcare-background text-healthcare-primary dark:bg-healthcare-background-dark dark:text-healthcare-primary-dark">
                    {icon}
                </span>
                <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {title}
                </h2>
            </header>
            <div className="p-5">{children}</div>
        </Surface>
    );
}

function LockedSurface({
    label,
    unlockStep,
}: {
    label: string;
    unlockStep: string;
}) {
    return (
        <div className="grid min-h-72 place-items-center text-center">
            <div className="max-w-md">
                <LockKeyhole
                    className="mx-auto mb-4 h-10 w-10 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                    aria-hidden="true"
                />
                <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {label} is not released yet
                </h3>
                <p className="mt-2 text-sm leading-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Advance to {unlockStep}. The simulation reveals each
                    projection only after its release boundary is reached.
                </p>
            </div>
        </div>
    );
}

function CareTeamPanel({ data }: { data: CareTeamProjection }) {
    return (
        <div className="grid gap-5 xl:grid-cols-[1.25fr_1fr]">
            <div className="space-y-5">
                <Surface className="p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                                Current pathway stage
                            </p>
                            <h3 className="mt-2 text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {data.assignment.current_stage}
                            </h3>
                            <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {data.assignment.pathway} ·{" "}
                                {data.assignment.version}
                            </p>
                        </div>
                        <StatusPill value={data.assignment.status} />
                    </div>
                    <p className="mt-5 border-t border-healthcare-border pt-4 text-sm leading-6 text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                        {data.assignment.decision_record}
                    </p>
                </Surface>

                <div>
                    <h3 className="mb-3 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Milestone ownership
                    </h3>
                    <div className="space-y-2">
                        {data.milestones.map((milestone) => (
                            <div
                                key={milestone.label}
                                className="flex items-center justify-between gap-4 rounded-lg border border-healthcare-border px-4 py-3 dark:border-healthcare-border-dark"
                            >
                                <div>
                                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {milestone.label}
                                    </p>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Owner: {milestone.owner}
                                    </p>
                                </div>
                                <StatusPill value={milestone.state} />
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="space-y-5">
                <div className="rounded-lg border border-healthcare-border p-4 dark:border-healthcare-border-dark">
                    <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Next decisions
                    </h3>
                    <ul className="mt-3 space-y-3">
                        {data.next_decisions.map((decision) => (
                            <li
                                key={decision}
                                className="flex gap-3 text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                            >
                                <CircleAlert
                                    className="mt-1 h-4 w-4 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                                    aria-hidden="true"
                                />
                                {decision}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="rounded-lg border border-healthcare-border p-4 dark:border-healthcare-border-dark">
                    <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Assignment evidence
                    </h3>
                    <dl className="mt-3 space-y-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Confidence
                            </dt>
                            <dd className="text-right font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {titleCase(data.assignment.confidence)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Confirmation
                            </dt>
                            <dd className="text-right font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {data.assignment.requires_confirmation
                                    ? "Required"
                                    : "Recorded in demo"}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Matched signals
                            </dt>
                            <dd className="mt-2 flex flex-wrap gap-2">
                                {data.assignment.matched.length ? (
                                    data.assignment.matched.map((item) => (
                                        <StatusPill key={item} value={item} />
                                    ))
                                ) : (
                                    <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        Not evaluated
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    );
}

function RoundsPanel({ data }: { data: RoundsProjection }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Virtual Rounds"
                unlockStep="Coordinate rounds"
            />
        );

    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            4D pathway badge
                        </p>
                        <h3 className="mt-2 text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {data.pathway_badge.stage}
                        </h3>
                    </div>
                    <StatusPill value={data.queue_status} />
                </div>
                <div className="mt-5 grid grid-cols-2 gap-3">
                    <div className="rounded-lg bg-healthcare-background p-3 dark:bg-healthcare-background-dark">
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Variance
                        </p>
                        <p className="mt-1 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {data.pathway_badge.variance ?? "None"}
                        </p>
                    </div>
                    <div className="rounded-lg bg-healthcare-background p-3 dark:bg-healthcare-background-dark">
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Questions
                        </p>
                        <p className="mt-1 text-sm font-medium tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {data.pathway_badge.patient_question_count}
                        </p>
                    </div>
                </div>
                {data.open_question && (
                    <blockquote className="mt-4 rounded-lg bg-healthcare-background p-4 text-sm italic text-healthcare-text-primary dark:bg-healthcare-background-dark dark:text-healthcare-text-primary-dark">
                        “{data.open_question}”
                    </blockquote>
                )}
            </div>
            <div className="space-y-3">
                {data.role_inputs.map((input) => (
                    <div
                        key={input.role}
                        className="rounded-lg border border-healthcare-border p-4 dark:border-healthcare-border-dark"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {input.role}
                            </h3>
                            <StatusPill value={input.state} />
                        </div>
                        <p className="mt-2 text-sm leading-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {input.summary}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function PhoneFrame({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-[2rem] border-8 border-healthcare-border bg-healthcare-surface p-4 shadow-md dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="mx-auto mb-5 h-1.5 w-20 rounded-full bg-healthcare-border dark:bg-healthcare-border-dark" />
            {children}
        </div>
    );
}

function StaffPanel({ data }: { data: StaffProjection }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Hummingbird Staff"
                unlockStep="Coordinate rounds"
            />
        );

    return (
        <div className="mx-auto grid max-w-4xl gap-5 lg:grid-cols-[320px_1fr]">
            <PhoneFrame>
                <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                    For You
                </p>
                <div className="mt-4 rounded-xl bg-healthcare-background p-4 dark:bg-healthcare-background-dark">
                    <div className="flex items-center justify-between gap-2">
                        <MessageCircle
                            className="h-5 w-5 text-healthcare-warning dark:text-healthcare-warning-dark"
                            aria-hidden="true"
                        />
                        <StatusPill value={data.for_you.priority} />
                    </div>
                    <h3 className="mt-4 font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {data.for_you.title}
                    </h3>
                    <p className="mt-2 text-sm leading-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {data.for_you.detail}
                    </p>
                </div>
            </PhoneFrame>
            <div className="space-y-4">
                <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                    <h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Role-shaped patient context
                    </h3>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        {Object.entries(data.patient_context).map(
                            ([key, value]) => (
                                <div
                                    key={key}
                                    className="rounded-lg bg-healthcare-background p-3 dark:bg-healthcare-background-dark"
                                >
                                    <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {titleCase(key)}
                                    </dt>
                                    <dd className="mt-1 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {String(value)}
                                    </dd>
                                </div>
                            ),
                        )}
                    </dl>
                </div>
                <div className="flex items-start gap-2 rounded-lg border border-healthcare-border p-4 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark">
                    <ShieldCheck
                        className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-success dark:text-healthcare-success-dark"
                        aria-hidden="true"
                    />
                    <span>
                        Push is a generic doorbell only: “
                        {data.notification.message}” No PHI is included.
                    </span>
                </div>
            </div>
        </div>
    );
}

function PatientPanel({ data }: { data: PatientProjection }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Hummingbird Patient"
                unlockStep="Patient awareness"
            />
        );

    return (
        <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[360px_1fr]">
            <PhoneFrame>
                <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                    {data.headline}
                </p>
                <p className="mt-3 text-lg font-semibold leading-7 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {data.why_here}
                </p>
                <div className="mt-5 space-y-3">
                    {data.today.map((item) => (
                        <div
                            key={item.label}
                            className="rounded-xl border border-healthcare-border p-3 dark:border-healthcare-border-dark"
                        >
                            <p className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {item.label}
                            </p>
                            <div className="mt-2">
                                <StatusPill value={item.state} />
                            </div>
                        </div>
                    ))}
                </div>
            </PhoneFrame>
            <div className="space-y-4">
                <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                    <h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Goals remain attributable
                    </h3>
                    <div className="mt-4 space-y-3">
                        {data.goals.map((goal) => (
                            <div
                                key={goal.text}
                                className="rounded-lg bg-healthcare-background p-4 dark:bg-healthcare-background-dark"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {titleCase(goal.author)}
                                </p>
                                <p className="mt-2 text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {goal.text}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                    <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                        Question to the care team
                    </p>
                    <p className="mt-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {data.question.text}
                    </p>
                    <div className="mt-3">
                        <StatusPill value={data.question.status} />
                    </div>
                </div>
                <p className="flex items-start gap-2 rounded-lg border border-healthcare-border p-4 text-xs leading-5 text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                    <CircleAlert
                        className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                        aria-hidden="true"
                    />
                    {data.urgent_help}
                </p>
            </div>
        </div>
    );
}

function EddyPanel({ data }: { data: EddyProjection }) {
    if (!data.visible)
        return <LockedSurface label="Eddy" unlockStep="Coordinate rounds" />;

    return (
        <div className="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
            <Surface className="p-5">
                <div className="flex items-center gap-3">
                    <Bot
                        className="h-6 w-6 text-healthcare-primary dark:text-healthcare-primary-dark"
                        aria-hidden="true"
                    />
                    <div>
                        <p className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {titleCase(data.mode)}
                        </p>
                        <h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Local-only pathway draft
                        </h3>
                    </div>
                </div>
                <p className="mt-5 rounded-lg bg-healthcare-background p-4 text-sm leading-6 text-healthcare-text-secondary dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark">
                    {data.prompt}
                </p>
                <p className="mt-4 text-sm leading-7 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {data.answer}
                </p>
                <div className="mt-5 space-y-3">
                    {data.citations.map((citation) => (
                        <div
                            key={citation.reference}
                            className="rounded-lg border border-healthcare-border p-3 dark:border-healthcare-border-dark"
                        >
                            <p className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {citation.label}
                            </p>
                            <p className="mt-1 text-xs tabular-nums text-healthcare-primary dark:text-healthcare-primary-dark">
                                {citation.reference}
                            </p>
                            <p className="mt-2 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {citation.scope}
                            </p>
                        </div>
                    ))}
                </div>
            </Surface>
            <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                <h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Enforced guardrails
                </h3>
                <div className="mt-4 space-y-3">
                    {Object.entries(data.guardrails).map(([key, enabled]) => (
                        <div
                            key={key}
                            className="flex items-center justify-between gap-4 rounded-lg bg-healthcare-background px-3 py-2.5 dark:bg-healthcare-background-dark"
                        >
                            <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {titleCase(key)}
                            </span>
                            <span
                                className={
                                    enabled
                                        ? "text-healthcare-success dark:text-healthcare-success-dark"
                                        : "text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                }
                            >
                                {enabled ? (
                                    <Check
                                        className="h-5 w-5"
                                        aria-label="Enabled"
                                    />
                                ) : (
                                    "No"
                                )}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function GovernancePanel({
    data,
    catalog,
}: {
    data: GovernanceProjection;
    catalog: DemoScenario["catalog"];
}) {
    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Real catalog release
                        </p>
                        <h3 className="mt-2 text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            MS-DRG v{String(catalog.grouper_version)}
                        </h3>
                    </div>
                    <StatusPill value={data.release_state} />
                </div>
                <dl className="mt-5 grid grid-cols-2 gap-3">
                    {(
                        [
                            ["Pathways", catalog.pathways],
                            ["Evidence verified", catalog.evidence_verified],
                            ["Limitations", catalog.evidence_limitations],
                            [
                                "Clinical signoffs",
                                catalog.clinical_signoff_count,
                            ],
                            ["Failed controls", data.controls.failed],
                            [
                                "Residual unknowns",
                                data.controls.residual_unknowns,
                            ],
                        ] as Array<[string, string | number | boolean]>
                    ).map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-lg bg-healthcare-background p-3 dark:bg-healthcare-background-dark"
                        >
                            <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {label}
                            </dt>
                            <dd className="mt-1 text-xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {String(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
            <div className="rounded-lg border border-healthcare-border p-5 dark:border-healthcare-border-dark">
                <h3 className="flex items-center gap-2 font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    <CircleAlert
                        className="h-4 w-4 text-healthcare-warning dark:text-healthcare-warning-dark"
                        aria-hidden="true"
                    />
                    Why production is still inactive
                </h3>
                <ul className="mt-4 space-y-3">
                    {data.activation_blockers.map((blocker) => (
                        <li
                            key={blocker}
                            className="flex gap-3 text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                        >
                            <CircleAlert
                                className="mt-1 h-4 w-4 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                                aria-hidden="true"
                            />
                            {blocker}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="grid gap-3 md:grid-cols-3 lg:col-span-2">
                {Object.entries(data.separation).map(([key, value]) => (
                    <div
                        key={key}
                        className="rounded-lg border border-healthcare-border p-4 dark:border-healthcare-border-dark"
                    >
                        <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {titleCase(key)}
                        </p>
                        <p className="mt-2 text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {value}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Demo({ initialScenario }: DemoProps) {
    const [scenario, setScenario] = useState(initialScenario);
    const [surface, setSurface] = useState<SurfaceKey>(initialSurface);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const catalogEnabled =
        usePage<PageProps>().props.features?.care_pathways_catalog === true;

    const currentStep = scenario.meta.current_step;
    const activeStep = scenario.steps[currentStep];
    const progress = useMemo(
        () => Math.round(((currentStep + 1) / scenario.steps.length) * 100),
        [currentStep, scenario.steps.length],
    );

    const loadStep = async (step: number) => {
        setLoading(true);
        setError(null);
        try {
            const response = await axios.get<unknown>(
                "/api/care-pathways/v1/demo/scenario",
                {
                    params: { step },
                },
            );
            setScenario(scenarioFromApiEnvelope(response.data));
        } catch {
            setError(
                "The demo step could not be loaded. No clinical or demo state was changed.",
            );
        } finally {
            setLoading(false);
        }
    };

    const renderSurface = () => {
        switch (surface) {
            case "care-team":
                return <CareTeamPanel data={scenario.care_team} />;
            case "rounds":
                return <RoundsPanel data={scenario.virtual_rounds} />;
            case "hummingbird-staff":
                return <StaffPanel data={scenario.hummingbird_staff} />;
            case "hummingbird-patient":
                return <PatientPanel data={scenario.hummingbird_patient} />;
            case "eddy":
                return <EddyPanel data={scenario.eddy} />;
            case "governance":
                return (
                    <GovernancePanel
                        data={scenario.governance}
                        catalog={scenario.catalog}
                    />
                );
        }
    };

    return (
        <DashboardLayout>
            <Head title="Care Pathway Journey Demo" />
            <PageContentLayout
                title={scenario.meta.title}
                subtitle="One fictional patient, six governed steps, and every intended audience projection — without activating the clinical catalog"
                headerContent={
                    catalogEnabled ? (
                        <Link
                            href="/care-pathways/catalog"
                            className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-surface-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-surface-hover-dark"
                        >
                            <BookOpen className="h-4 w-4" aria-hidden="true" />
                            Browse the 250-pathway catalog
                        </Link>
                    ) : undefined
                }
            >
                <div className="space-y-4">
                    <Surface className="p-4" role="status">
                        <div className="flex items-start gap-3">
                            <CircleAlert
                                className="mt-0.5 h-5 w-5 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    Synthetic simulation — not clinical care
                                </p>
                                <p className="mt-1 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {scenario.meta.warning}
                                </p>
                            </div>
                        </div>
                    </Surface>

                    <Surface className="p-4">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {(
                                [
                                    ["Patient", scenario.subject.display_name],
                                    ["Location", scenario.subject.location],
                                    [
                                        "Journey",
                                        scenario.subject.encounter_day,
                                    ],
                                    ["Progress", `${progress}%`],
                                ] as Array<[string, string]>
                            ).map(([label, value]) => (
                                <div key={label}>
                                    <p className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {label}
                                    </p>
                                    <p className="mt-1 text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Surface>

                    <Surface className="p-5">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                            {scenario.steps.map((step) => (
                                <button
                                    key={step.key}
                                    type="button"
                                    onClick={() => loadStep(step.index)}
                                    disabled={loading}
                                    className={`rounded-lg border p-4 text-left transition ${
                                        step.state === "current"
                                            ? "border-healthcare-primary bg-healthcare-background dark:border-healthcare-primary-dark dark:bg-healthcare-background-dark"
                                            : step.state === "complete"
                                              ? "border-healthcare-success/40 dark:border-healthcare-success-dark/40"
                                              : "border-healthcare-border hover:border-healthcare-text-secondary dark:border-healthcare-border-dark dark:hover:border-healthcare-text-secondary-dark"
                                    }`}
                                    aria-current={
                                        step.state === "current"
                                            ? "step"
                                            : undefined
                                    }
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Step{" "}
                                            <span className="tabular-nums">
                                                {step.index + 1}
                                            </span>
                                        </span>
                                        {step.state === "complete" ? (
                                            <Check
                                                className="h-4 w-4 text-healthcare-success dark:text-healthcare-success-dark"
                                                aria-label="Complete"
                                            />
                                        ) : (
                                            <span className="grid h-5 w-5 place-items-center rounded-full border border-current text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {step.index + 1}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-3 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {step.label}
                                    </p>
                                    <p className="mt-2 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {step.summary}
                                    </p>
                                </button>
                            ))}
                        </div>
                        <div className="mt-5 flex flex-col gap-3 border-t border-healthcare-border pt-5 dark:border-healthcare-border-dark sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                                    Now demonstrating
                                </p>
                                <p className="mt-1 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {activeStep.label}: {activeStep.summary}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => loadStep(0)}
                                    disabled={loading || currentStep === 0}
                                    className="inline-flex items-center gap-2 rounded-lg border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                                >
                                    <RefreshCcw
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Reset
                                </button>
                                <button
                                    type="button"
                                    onClick={() => loadStep(currentStep - 1)}
                                    disabled={loading || currentStep === 0}
                                    className="inline-flex items-center gap-2 rounded-lg border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                                >
                                    <ArrowLeft
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Back
                                </button>
                                <button
                                    type="button"
                                    onClick={() => loadStep(currentStep + 1)}
                                    disabled={
                                        loading ||
                                        currentStep === scenario.meta.max_step
                                    }
                                    className="inline-flex items-center gap-2 rounded-lg bg-healthcare-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-healthcare-primary-hover disabled:opacity-40 dark:bg-healthcare-primary-dark dark:hover:bg-healthcare-primary-hover-dark"
                                >
                                    Advance
                                    <ArrowRight
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </div>
                        {error && (
                            <p
                                className="mt-4 flex items-start gap-2 rounded-lg border border-healthcare-critical/40 p-3 text-sm text-healthcare-critical dark:border-healthcare-critical-dark/40 dark:text-healthcare-critical-dark"
                                role="alert"
                            >
                                <CircleAlert
                                    className="mt-0.5 h-4 w-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {error}
                            </p>
                        )}
                    </Surface>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {(
                            [
                                [
                                    "Real catalog",
                                    String(scenario.catalog.state),
                                    "250 research pathways remain governed",
                                ],
                                [
                                    "Demo overlay",
                                    "Simulation only",
                                    "No database writes or clinical activation",
                                ],
                                [
                                    "Selected pilot",
                                    "Heart Failure",
                                    "Rank 6 · evidence-verified cohort",
                                ],
                                [
                                    "Safety boundary",
                                    "Fail closed",
                                    "Patient and Eddy production flags stay off",
                                ],
                            ] as Array<[string, string, string]>
                        ).map(([label, value, note]) => (
                            <Surface key={label} className="p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {label}
                                </p>
                                <p className="mt-2 text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {titleCase(value)}
                                </p>
                                <p className="mt-2 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {note}
                                </p>
                            </Surface>
                        ))}
                    </div>

                    <Surface className="p-2">
                        <div
                            className="flex min-w-max gap-1 overflow-x-auto"
                            role="tablist"
                            aria-label="Demo surfaces"
                        >
                            {surfaces.map(({ key, label, icon: Icon }) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={surface === key}
                                    onClick={() => setSurface(key)}
                                    className={`inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium ${
                                        surface === key
                                            ? "bg-healthcare-primary text-white dark:bg-healthcare-primary-dark"
                                            : "text-healthcare-text-secondary hover:bg-healthcare-surface-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
                                    }`}
                                >
                                    <Icon
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </Surface>

                    <DemoPanel
                        title={
                            surfaces.find((item) => item.key === surface)
                                ?.label ?? "Demo surface"
                        }
                        icon={
                            surface === "governance" ? (
                                <ShieldCheck className="h-5 w-5" />
                            ) : (
                                <ClipboardCheck className="h-5 w-5" />
                            )
                        }
                    >
                        {renderSurface()}
                    </DemoPanel>

                    <DemoPanel
                        title="Synthetic audit timeline"
                        icon={<History className="h-5 w-5" />}
                    >
                        <ol className="grid gap-3 lg:grid-cols-3">
                            {scenario.timeline.map((event) => (
                                <li
                                    key={`${event.step}-${event.time}`}
                                    className="rounded-lg border border-healthcare-border p-4 dark:border-healthcare-border-dark"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-healthcare-primary dark:text-healthcare-primary-dark">
                                            {event.actor}
                                        </span>
                                        <time className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {event.time}
                                        </time>
                                    </div>
                                    <p className="mt-3 text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {event.event}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </DemoPanel>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
