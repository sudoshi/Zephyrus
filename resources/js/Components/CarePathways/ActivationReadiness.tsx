import { CheckCircle2, XCircle } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import type { SummaryEnvelope } from "@/lib/carePathways/catalogSchemas";

interface ActivationReadinessProps {
    readiness: SummaryEnvelope["data"]["release_readiness"];
    release: SummaryEnvelope["data"]["release"];
    catalog: SummaryEnvelope["data"]["catalog"];
}

interface ReadinessItem {
    label: string;
    met: boolean;
    detail: string;
}

function ReadinessRow({ item }: { item: ReadinessItem }) {
    const Icon = item.met ? CheckCircle2 : XCircle;

    return (
        <li className="flex items-start gap-2.5">
            <Icon
                className={`mt-0.5 h-4 w-4 shrink-0 ${
                    item.met
                        ? "text-healthcare-success dark:text-healthcare-success-dark"
                        : "text-healthcare-warning dark:text-healthcare-warning-dark"
                }`}
                aria-hidden="true"
            />
            <div className="min-w-0">
                <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {item.label}
                    <span className="sr-only">
                        {item.met ? " — met" : " — not met"}
                    </span>
                </p>
                <p className="text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {item.detail}
                </p>
            </div>
        </li>
    );
}

// The honest answer to "why can't this serve patients yet". Derived entirely
// from the release_readiness envelope — the server, not the UI, owns truth.
export function ActivationReadiness({
    readiness,
    release,
    catalog,
}: ActivationReadinessProps) {
    const items: readonly ReadinessItem[] = [
        {
            label: "Institutional clinical sign-off",
            met: readiness.clinical_signoff_complete,
            detail: `${release.clinical_signoff_count} of ${release.pathway_count} pathways carry institutional SME sign-off.`,
        },
        {
            label: "All versions institutionally approved",
            met: catalog.institutionally_approved_versions === release.pathway_count,
            detail: `${catalog.institutionally_approved_versions} of ${release.pathway_count} versions approved by institutional review.`,
        },
        {
            label: "All versions activated",
            met: catalog.active_versions === release.pathway_count,
            detail: `${catalog.active_versions} of ${release.pathway_count} versions active; activation requires prior approval.`,
        },
        {
            label: "Release may serve the approved catalog",
            met: readiness.may_serve_approved_catalog,
            detail: readiness.may_serve_approved_catalog
                ? "The release satisfies every activation gate."
                : "Blocked until every gate above passes and the release state is set to active.",
        },
        {
            label: "Patient projection released",
            met: readiness.patient_projection_released,
            detail: "Patient- and caregiver-facing projections require separate editorial approval.",
        },
        {
            label: "Eddy retrieval released",
            met: readiness.eddy_retrieval_released,
            detail: "Eddy may not cite the catalog until reference serving is separately approved.",
        },
    ];

    return (
        <Section
            title="Why this catalog stays inactive"
            icon="heroicons:lock-closed"
        >
            <Surface className="p-4">
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {items.map((item) => (
                        <ReadinessRow key={item.label} item={item} />
                    ))}
                </ul>
            </Surface>
        </Section>
    );
}
