import { Head } from "@inertiajs/react";
import axios from "axios";
import {
    ArrowLeft,
    ArrowRight,
    Bot,
    Check,
    CircleAlert,
    ClipboardCheck,
    HeartPulse,
    History,
    LockKeyhole,
    MessageCircle,
    RefreshCcw,
    ShieldCheck,
    Smartphone,
    Stethoscope,
    Users,
} from "lucide-react";
import { useMemo, useState, type ReactNode } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";

type StepState = "complete" | "current" | "upcoming";

interface ScenarioStep {
    key: string;
    label: string;
    summary: string;
    index: number;
    state: StepState;
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
    care_team: any;
    virtual_rounds: any;
    hummingbird_staff: any;
    hummingbird_patient: any;
    eddy: any;
    governance: any;
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
    ].includes(value);
    const attention = [
        "due",
        "needs_action",
        "needs_help",
        "action_due",
        "ready_with_barrier",
    ].includes(value);

    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wide ${
                positive
                    ? "border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                    : attention
                      ? "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100"
                      : "border-slate-300 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
            }`}
        >
            {titleCase(value)}
        </span>
    );
}

function Panel({
    title,
    icon,
    children,
}: {
    title: string;
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
            <header className="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <span className="grid h-9 w-9 place-items-center rounded-xl bg-cyan-50 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-200">
                    {icon}
                </span>
                <h2 className="text-base font-semibold text-slate-950 dark:text-white">
                    {title}
                </h2>
            </header>
            <div className="p-5">{children}</div>
        </section>
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
                <LockKeyhole className="mx-auto mb-4 h-10 w-10 text-slate-400" />
                <h3 className="text-lg font-semibold text-slate-900 dark:text-white">
                    {label} is not released yet
                </h3>
                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                    Advance to {unlockStep}. The simulation reveals each
                    projection only after its release boundary is reached.
                </p>
            </div>
        </div>
    );
}

function CareTeamPanel({ data }: { data: DemoScenario["care_team"] }) {
    return (
        <div className="grid gap-5 xl:grid-cols-[1.25fr_1fr]">
            <div className="space-y-5">
                <div className="rounded-xl bg-slate-950 p-5 text-white dark:bg-slate-900">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">
                                Current pathway stage
                            </p>
                            <h3 className="mt-2 text-2xl font-semibold">
                                {data.assignment.current_stage}
                            </h3>
                            <p className="mt-2 text-sm text-slate-300">
                                {data.assignment.pathway} ·{" "}
                                {data.assignment.version}
                            </p>
                        </div>
                        <StatusPill value={data.assignment.status} />
                    </div>
                    <p className="mt-5 border-t border-slate-700 pt-4 text-sm leading-6 text-slate-200">
                        {data.assignment.decision_record}
                    </p>
                </div>

                <div>
                    <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">
                        Milestone ownership
                    </h3>
                    <div className="space-y-2">
                        {data.milestones.map((milestone: any) => (
                            <div
                                key={milestone.label}
                                className="flex items-center justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-800"
                            >
                                <div>
                                    <p className="text-sm font-medium text-slate-900 dark:text-white">
                                        {milestone.label}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
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
                <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <h3 className="text-sm font-semibold text-slate-900 dark:text-white">
                        Next decisions
                    </h3>
                    <ul className="mt-3 space-y-3">
                        {data.next_decisions.map((decision: string) => (
                            <li
                                key={decision}
                                className="flex gap-3 text-sm leading-6 text-slate-700 dark:text-slate-200"
                            >
                                <CircleAlert className="mt-1 h-4 w-4 shrink-0 text-amber-500" />
                                {decision}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                    <h3 className="text-sm font-semibold text-slate-900 dark:text-white">
                        Assignment evidence
                    </h3>
                    <dl className="mt-3 space-y-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-slate-500">Confidence</dt>
                            <dd className="text-right font-medium text-slate-900 dark:text-white">
                                {titleCase(data.assignment.confidence)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-slate-500">Confirmation</dt>
                            <dd className="text-right font-medium text-slate-900 dark:text-white">
                                {data.assignment.requires_confirmation
                                    ? "Required"
                                    : "Recorded in demo"}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Matched signals</dt>
                            <dd className="mt-2 flex flex-wrap gap-2">
                                {data.assignment.matched.length ? (
                                    data.assignment.matched.map(
                                        (item: string) => (
                                            <StatusPill
                                                key={item}
                                                value={item}
                                            />
                                        ),
                                    )
                                ) : (
                                    <span className="text-slate-500">
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

function RoundsPanel({ data }: { data: DemoScenario["virtual_rounds"] }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Virtual Rounds"
                unlockStep="Coordinate rounds"
            />
        );

    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            4D pathway badge
                        </p>
                        <h3 className="mt-2 text-xl font-semibold text-slate-900 dark:text-white">
                            {data.pathway_badge.stage}
                        </h3>
                    </div>
                    <StatusPill value={data.queue_status} />
                </div>
                <div className="mt-5 grid grid-cols-2 gap-3">
                    <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                        <p className="text-xs text-slate-500">Variance</p>
                        <p className="mt-1 text-sm font-medium text-slate-900 dark:text-white">
                            {data.pathway_badge.variance ?? "None"}
                        </p>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                        <p className="text-xs text-slate-500">Questions</p>
                        <p className="mt-1 text-sm font-medium text-slate-900 dark:text-white">
                            {data.pathway_badge.patient_question_count}
                        </p>
                    </div>
                </div>
                {data.open_question && (
                    <blockquote className="mt-4 rounded-lg border-l-4 border-cyan-500 bg-cyan-50 p-4 text-sm text-cyan-950 dark:bg-cyan-950/40 dark:text-cyan-100">
                        “{data.open_question}”
                    </blockquote>
                )}
            </div>
            <div className="space-y-3">
                {data.role_inputs.map((input: any) => (
                    <div
                        key={input.role}
                        className="rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="font-semibold text-slate-900 dark:text-white">
                                {input.role}
                            </h3>
                            <StatusPill value={input.state} />
                        </div>
                        <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                            {input.summary}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function StaffPanel({ data }: { data: DemoScenario["hummingbird_staff"] }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Hummingbird Staff"
                unlockStep="Coordinate rounds"
            />
        );

    return (
        <div className="mx-auto grid max-w-4xl gap-5 lg:grid-cols-[320px_1fr]">
            <div className="rounded-[2rem] border-8 border-slate-900 bg-slate-950 p-4 text-white shadow-xl">
                <div className="mx-auto mb-5 h-1.5 w-20 rounded-full bg-slate-700" />
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">
                    For You
                </p>
                <div className="mt-4 rounded-2xl bg-slate-900 p-4">
                    <div className="flex items-center justify-between gap-2">
                        <MessageCircle className="h-5 w-5 text-amber-300" />
                        <StatusPill value={data.for_you.priority} />
                    </div>
                    <h3 className="mt-4 font-semibold">{data.for_you.title}</h3>
                    <p className="mt-2 text-sm leading-6 text-slate-300">
                        {data.for_you.detail}
                    </p>
                </div>
            </div>
            <div className="space-y-4">
                <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                    <h3 className="font-semibold text-slate-900 dark:text-white">
                        Role-shaped patient context
                    </h3>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        {Object.entries(data.patient_context).map(
                            ([key, value]) => (
                                <div
                                    key={key}
                                    className="rounded-lg bg-slate-50 p-3 dark:bg-slate-900"
                                >
                                    <dt className="text-xs text-slate-500">
                                        {titleCase(key)}
                                    </dt>
                                    <dd className="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                        {String(value)}
                                    </dd>
                                </div>
                            ),
                        )}
                    </dl>
                </div>
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                    Push is a generic doorbell only: “
                    {data.notification.message}” No PHI is included.
                </div>
            </div>
        </div>
    );
}

function PatientPanel({ data }: { data: DemoScenario["hummingbird_patient"] }) {
    if (!data.visible)
        return (
            <LockedSurface
                label="Hummingbird Patient"
                unlockStep="Patient awareness"
            />
        );

    return (
        <div className="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[360px_1fr]">
            <div className="rounded-[2rem] border-8 border-slate-900 bg-white p-5 shadow-xl dark:bg-slate-950">
                <div className="mx-auto mb-5 h-1.5 w-20 rounded-full bg-slate-300 dark:bg-slate-700" />
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700 dark:text-cyan-300">
                    {data.headline}
                </p>
                <p className="mt-3 text-lg font-semibold leading-7 text-slate-950 dark:text-white">
                    {data.why_here}
                </p>
                <div className="mt-5 space-y-3">
                    {data.today.map((item: any) => (
                        <div
                            key={item.label}
                            className="rounded-xl border border-slate-200 p-3 dark:border-slate-800"
                        >
                            <p className="text-sm text-slate-900 dark:text-white">
                                {item.label}
                            </p>
                            <div className="mt-2">
                                <StatusPill value={item.state} />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            <div className="space-y-4">
                <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                    <h3 className="font-semibold text-slate-900 dark:text-white">
                        Goals remain attributable
                    </h3>
                    <div className="mt-4 space-y-3">
                        {data.goals.map((goal: any) => (
                            <div
                                key={goal.text}
                                className="rounded-lg bg-slate-50 p-4 dark:bg-slate-900"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {titleCase(goal.author)}
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-800 dark:text-slate-100">
                                    {goal.text}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="rounded-xl border border-cyan-200 bg-cyan-50 p-5 dark:border-cyan-900 dark:bg-cyan-950/40">
                    <p className="text-xs font-semibold uppercase tracking-wide text-cyan-800 dark:text-cyan-200">
                        Question to the care team
                    </p>
                    <p className="mt-2 text-sm font-medium text-cyan-950 dark:text-cyan-50">
                        {data.question.text}
                    </p>
                    <div className="mt-3">
                        <StatusPill value={data.question.status} />
                    </div>
                </div>
                <p className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    {data.urgent_help}
                </p>
            </div>
        </div>
    );
}

function EddyPanel({ data }: { data: DemoScenario["eddy"] }) {
    if (!data.visible)
        return <LockedSurface label="Eddy" unlockStep="Coordinate rounds" />;

    return (
        <div className="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
            <div className="rounded-xl bg-slate-950 p-5 text-white dark:bg-black">
                <div className="flex items-center gap-3">
                    <Bot className="h-6 w-6 text-cyan-300" />
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-400">
                            {titleCase(data.mode)}
                        </p>
                        <h3 className="font-semibold">
                            Local-only pathway draft
                        </h3>
                    </div>
                </div>
                <p className="mt-5 rounded-lg bg-slate-900 p-4 text-sm leading-6 text-slate-300">
                    {data.prompt}
                </p>
                <p className="mt-4 text-sm leading-7 text-white">
                    {data.answer}
                </p>
                <div className="mt-5 space-y-3">
                    {data.citations.map((citation: any) => (
                        <div
                            key={citation.reference}
                            className="rounded-lg border border-slate-700 p-3"
                        >
                            <p className="text-sm font-semibold">
                                {citation.label}
                            </p>
                            <p className="mt-1 text-xs text-cyan-300">
                                {citation.reference}
                            </p>
                            <p className="mt-2 text-xs leading-5 text-slate-400">
                                {citation.scope}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
            <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-white">
                    Enforced guardrails
                </h3>
                <div className="mt-4 space-y-3">
                    {Object.entries(data.guardrails).map(([key, enabled]) => (
                        <div
                            key={key}
                            className="flex items-center justify-between gap-4 rounded-lg bg-slate-50 px-3 py-2.5 dark:bg-slate-900"
                        >
                            <span className="text-sm text-slate-700 dark:text-slate-200">
                                {titleCase(key)}
                            </span>
                            <span
                                className={
                                    enabled
                                        ? "text-emerald-600"
                                        : "text-slate-500"
                                }
                            >
                                {enabled ? <Check className="h-5 w-5" /> : "No"}
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
    data: DemoScenario["governance"];
    catalog: DemoScenario["catalog"];
}) {
    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-slate-500">
                            Real catalog release
                        </p>
                        <h3 className="mt-2 text-xl font-semibold text-slate-950 dark:text-white">
                            MS-DRG v{String(catalog.grouper_version)}
                        </h3>
                    </div>
                    <StatusPill value={data.release_state} />
                </div>
                <dl className="mt-5 grid grid-cols-2 gap-3">
                    {[
                        ["Pathways", catalog.pathways],
                        ["Evidence verified", catalog.evidence_verified],
                        ["Limitations", catalog.evidence_limitations],
                        ["Clinical signoffs", catalog.clinical_signoff_count],
                        ["Failed controls", data.controls.failed],
                        ["Residual unknowns", data.controls.residual_unknowns],
                    ].map(([label, value]) => (
                        <div
                            key={String(label)}
                            className="rounded-lg bg-slate-50 p-3 dark:bg-slate-900"
                        >
                            <dt className="text-xs text-slate-500">
                                {String(label)}
                            </dt>
                            <dd className="mt-1 text-xl font-semibold text-slate-950 dark:text-white">
                                {String(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
                <h3 className="font-semibold text-amber-950 dark:text-amber-100">
                    Why production is still inactive
                </h3>
                <ul className="mt-4 space-y-3">
                    {data.activation_blockers.map((blocker: string) => (
                        <li
                            key={blocker}
                            className="flex gap-3 text-sm leading-6 text-amber-950 dark:text-amber-100"
                        >
                            <CircleAlert className="mt-1 h-4 w-4 shrink-0" />
                            {blocker}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="lg:col-span-2 grid gap-3 md:grid-cols-3">
                {Object.entries(data.separation).map(([key, value]) => (
                    <div
                        key={key}
                        className="rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                    >
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {titleCase(key)}
                        </p>
                        <p className="mt-2 text-sm leading-6 text-slate-700 dark:text-slate-200">
                            {String(value)}
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
        <AuthenticatedLayout>
            <Head title="Care Pathway Journey Demo" />
            <div className="min-h-full bg-slate-50 px-4 py-6 dark:bg-slate-900/40 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1500px] space-y-6">
                    <div
                        className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        role="status"
                    >
                        <CircleAlert className="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p className="text-sm font-semibold">
                                Synthetic simulation — not clinical care
                            </p>
                            <p className="mt-1 text-xs leading-5">
                                {scenario.meta.warning}
                            </p>
                        </div>
                    </div>

                    <header className="rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-6 text-white shadow-xl sm:p-8">
                        <div className="flex flex-col justify-between gap-6 xl:flex-row xl:items-end">
                            <div>
                                <div className="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-cyan-300">
                                    <HeartPulse className="h-4 w-4" /> Care
                                    Pathway Journey Demo
                                </div>
                                <h1 className="mt-4 max-w-4xl text-3xl font-semibold tracking-tight sm:text-4xl">
                                    {scenario.meta.title}
                                </h1>
                                <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                                    One fictional patient, six governed steps,
                                    and every intended audience
                                    projection—without activating the clinical
                                    catalog.
                                </p>
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:min-w-[620px]">
                                {[
                                    ["Patient", scenario.subject.display_name],
                                    ["Location", scenario.subject.location],
                                    ["Journey", scenario.subject.encounter_day],
                                    ["Progress", `${progress}%`],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="rounded-xl border border-white/10 bg-white/5 p-3"
                                    >
                                        <p className="text-xs uppercase tracking-wide text-slate-400">
                                            {label}
                                        </p>
                                        <p className="mt-1 text-sm font-semibold text-white">
                                            {value}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </header>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                            {scenario.steps.map((step) => (
                                <button
                                    key={step.key}
                                    type="button"
                                    onClick={() => loadStep(step.index)}
                                    disabled={loading}
                                    className={`rounded-xl border p-4 text-left transition ${step.state === "current" ? "border-cyan-500 bg-cyan-50 ring-2 ring-cyan-100 dark:bg-cyan-950/40 dark:ring-cyan-950" : step.state === "complete" ? "border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/30" : "border-slate-200 hover:border-slate-400 dark:border-slate-800 dark:hover:border-slate-600"}`}
                                    aria-current={
                                        step.state === "current"
                                            ? "step"
                                            : undefined
                                    }
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Step {step.index + 1}
                                        </span>
                                        {step.state === "complete" ? (
                                            <Check className="h-4 w-4 text-emerald-600" />
                                        ) : (
                                            <span className="grid h-5 w-5 place-items-center rounded-full border border-current text-xs">
                                                {step.index + 1}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-3 text-sm font-semibold text-slate-950 dark:text-white">
                                        {step.label}
                                    </p>
                                    <p className="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                        {step.summary}
                                    </p>
                                </button>
                            ))}
                        </div>
                        <div className="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-5 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">
                                    Now demonstrating
                                </p>
                                <p className="mt-1 text-sm font-medium text-slate-950 dark:text-white">
                                    {activeStep.label}: {activeStep.summary}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => loadStep(0)}
                                    disabled={loading || currentStep === 0}
                                    className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
                                >
                                    <RefreshCcw className="h-4 w-4" /> Reset
                                </button>
                                <button
                                    type="button"
                                    onClick={() => loadStep(currentStep - 1)}
                                    disabled={loading || currentStep === 0}
                                    className="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 disabled:opacity-40 dark:border-slate-700 dark:text-slate-200"
                                >
                                    <ArrowLeft className="h-4 w-4" /> Back
                                </button>
                                <button
                                    type="button"
                                    onClick={() => loadStep(currentStep + 1)}
                                    disabled={
                                        loading ||
                                        currentStep === scenario.meta.max_step
                                    }
                                    className="inline-flex items-center gap-2 rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-800 disabled:opacity-40"
                                >
                                    Advance <ArrowRight className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        {error && (
                            <p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
                                {error}
                            </p>
                        )}
                    </section>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {[
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
                        ].map(([label, value, note]) => (
                            <div
                                key={label}
                                className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {label}
                                </p>
                                <p className="mt-2 text-lg font-semibold text-slate-950 dark:text-white">
                                    {titleCase(value)}
                                </p>
                                <p className="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                    {note}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div
                            className="flex min-w-max gap-1"
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
                                    className={`inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium ${surface === key ? "bg-slate-950 text-white dark:bg-cyan-700" : "text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900"}`}
                                >
                                    <Icon className="h-4 w-4" />
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <Panel
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
                    </Panel>

                    <Panel
                        title="Synthetic audit timeline"
                        icon={<History className="h-5 w-5" />}
                    >
                        <ol className="grid gap-3 lg:grid-cols-3">
                            {scenario.timeline.map((event) => (
                                <li
                                    key={`${event.step}-${event.time}`}
                                    className="rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-xs font-semibold uppercase tracking-wide text-cyan-700 dark:text-cyan-300">
                                            {event.actor}
                                        </span>
                                        <time className="font-mono text-xs text-slate-500">
                                            {event.time}
                                        </time>
                                    </div>
                                    <p className="mt-3 text-sm leading-6 text-slate-700 dark:text-slate-200">
                                        {event.event}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </Panel>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
