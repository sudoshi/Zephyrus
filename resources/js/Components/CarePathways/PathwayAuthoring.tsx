import { CheckCircle2, Circle } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import type { VersionEnvelope } from "@/lib/carePathways/catalogSchemas";

type Authoring = VersionEnvelope["data"]["authoring"];

const asDisplayRange = (value: unknown): string | null => {
    if (
        typeof value === "object" &&
        value !== null &&
        "display" in value &&
        typeof (value as { display: unknown }).display === "string"
    ) {
        return (value as { display: string }).display;
    }
    return null;
};

function AuthoringGroup({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <Surface className="p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {title}
            </p>
            <div className="mt-2 space-y-2">{children}</div>
        </Surface>
    );
}

// Structured authoring artifacts (milestones / goals / activities / education)
// are authored AFTER adoption, during clinical review. Release 2 ships with
// none — that absence is stated, not hidden.
export function PathwayAuthoring({ authoring }: { authoring: Authoring }) {
    const counts =
        authoring.milestones.length +
        authoring.activities.length +
        authoring.goals.length +
        authoring.education.length;

    if (counts === 0) {
        return (
            <Section
                title="Structured authoring"
                icon="heroicons:pencil-square"
            >
                <Surface className="p-4">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No milestones, goals, activities, or education have been
                        authored for this version. Structured artifacts are
                        created during clinical review — after institutional
                        sign-off begins, not at adoption.
                    </p>
                </Surface>
            </Section>
        );
    }

    return (
        <Section
            title="Structured authoring"
            summary={`${counts} artifacts`}
            icon="heroicons:pencil-square"
        >
            <div className="grid gap-4 lg:grid-cols-2">
                {authoring.milestones.length > 0 && (
                    <AuthoringGroup title={`Milestones (${authoring.milestones.length})`}>
                        {[...authoring.milestones]
                            .sort(
                                (a, b) =>
                                    (a.sequence ?? Number.MAX_SAFE_INTEGER) -
                                    (b.sequence ?? Number.MAX_SAFE_INTEGER),
                            )
                            .map((milestone) => (
                                <div
                                    key={milestone.milestone_uuid}
                                    className="flex items-start justify-between gap-3 text-sm"
                                >
                                    <div>
                                        <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {milestone.title}
                                        </p>
                                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {milestone.phase ?? "—"}
                                            {asDisplayRange(
                                                milestone.expected_range,
                                            ) &&
                                                ` · ${asDisplayRange(milestone.expected_range)}`}
                                        </p>
                                    </div>
                                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {milestone.review_state}
                                    </span>
                                </div>
                            ))}
                    </AuthoringGroup>
                )}
                {authoring.goals.length > 0 && (
                    <AuthoringGroup title={`Goals (${authoring.goals.length})`}>
                        {authoring.goals.map((goal) => (
                            <div key={goal.goal_uuid} className="text-sm">
                                <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {goal.goal_text}
                                </p>
                                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {goal.author_type} · {goal.review_state}
                                </p>
                            </div>
                        ))}
                    </AuthoringGroup>
                )}
                {authoring.activities.length > 0 && (
                    <AuthoringGroup title={`Activities (${authoring.activities.length})`}>
                        {authoring.activities.map((activity) => {
                            const ExecIcon = activity.executable
                                ? CheckCircle2
                                : Circle;
                            return (
                                <div
                                    key={activity.activity_uuid}
                                    className="flex items-start justify-between gap-3 text-sm"
                                >
                                    <div>
                                        <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {activity.title}
                                        </p>
                                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {activity.activity_type}
                                            {activity.performer_role &&
                                                ` · ${activity.performer_role}`}
                                        </p>
                                    </div>
                                    <span
                                        className="inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                        title={
                                            activity.executable
                                                ? "Executable (FHIR-aligned)"
                                                : "Narrative only"
                                        }
                                    >
                                        <ExecIcon
                                            className="h-3.5 w-3.5"
                                            aria-hidden="true"
                                        />
                                        {activity.executable
                                            ? "Executable"
                                            : "Narrative"}
                                    </span>
                                </div>
                            );
                        })}
                    </AuthoringGroup>
                )}
                {authoring.education.length > 0 && (
                    <AuthoringGroup title={`Education (${authoring.education.length})`}>
                        {authoring.education.map((item) => (
                            <div key={item.education_uuid} className="text-sm">
                                <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {item.title}
                                </p>
                                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {item.audience}
                                    {item.reading_level &&
                                        ` · reading level ${item.reading_level}`}
                                    {` · ${item.review_state}`}
                                </p>
                            </div>
                        ))}
                    </AuthoringGroup>
                )}
            </div>
        </Section>
    );
}
