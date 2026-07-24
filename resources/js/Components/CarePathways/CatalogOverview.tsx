import { AlertTriangle, CheckCircle2 } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { MetricGrid, Section } from "@/Components/system";
import { metric } from "@/Components/system/metric";
import type { SummaryEnvelope } from "@/lib/carePathways/catalogSchemas";

interface CatalogOverviewProps {
    summary: SummaryEnvelope["data"];
    meta: SummaryEnvelope["meta"];
}

interface PartitionSegment {
    label: string;
    value: number;
    barClass: string;
    textClass: string;
}

function PartitionBar({
    segments,
    total,
}: {
    segments: readonly PartitionSegment[];
    total: number;
}) {
    return (
        <div>
            <div
                className="flex h-2 w-full overflow-hidden rounded-full bg-healthcare-background dark:bg-healthcare-background-dark"
                role="presentation"
            >
                {segments.map((segment) => (
                    <div
                        key={segment.label}
                        className={segment.barClass}
                        style={{
                            width: `${total > 0 ? (segment.value / total) * 100 : 0}%`,
                        }}
                    />
                ))}
            </div>
            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                {segments.map((segment) => (
                    <div
                        key={segment.label}
                        className="flex items-baseline gap-1.5"
                    >
                        <dd
                            className={`text-lg font-semibold tabular-nums ${segment.textClass}`}
                        >
                            {segment.value}
                        </dd>
                        <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {segment.label}
                        </dt>
                    </div>
                ))}
                <div className="ml-auto flex items-baseline gap-1.5">
                    <dd className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {total}
                    </dd>
                    <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        total
                    </dt>
                </div>
            </dl>
        </div>
    );
}

export function CatalogOverview({ summary, meta }: CatalogOverviewProps) {
    const { release, catalog, review_queues: queues, controls } = summary;

    const controlsClean =
        controls.failed === 0 && controls.residual_unknowns === 0;
    const ControlsIcon = controlsClean ? CheckCircle2 : AlertTriangle;

    const metrics = [
        metric({
            key: "pathways",
            label: "Pathways",
            value: catalog.definitions,
            definition:
                "Distinct governed pathway definitions adopted in this release.",
        }),
        metric({
            key: "drg-codes",
            label: "MS-DRG codes",
            value: catalog.drg_codebook_entries,
            definition:
                "Unique MS-DRG codebook entries pinned to this grouper version.",
        }),
        metric({
            key: "drg-associations",
            label: "DRG associations",
            value: catalog.drg_mappings,
            definition:
                "Pathway-to-DRG candidate mappings; several pathways share DRGs.",
        }),
        metric({
            key: "claims",
            label: "Evidence claims",
            value: catalog.evidence_claims,
            definition:
                "Clinical claims extracted from source text and independently reviewed.",
        }),
        metric({
            key: "sources",
            label: "Sources",
            value: catalog.sources,
            caption: `${catalog.noncurrent_sources} non-current`,
            definition:
                "Bibliographic sources in the release index; currency tracked per source.",
        }),
        metric({
            key: "signoffs",
            label: "Clinical sign-offs",
            value: release.clinical_signoff_count,
            status: "warning",
            caption: `of ${release.pathway_count} pathways`,
            definition:
                "Institutional SME sign-offs recorded. Zero — the release stays inactive.",
        }),
    ];

    return (
        <div className="space-y-4">
            <Section
                title={`MS-DRG v${release.grouper_version} verification package`}
                summary={`Grouper window ${release.grouper_effective_period.start ?? "—"} → ${release.grouper_effective_period.end ?? "—"} · source cutoff ${meta.source_cutoff_date ?? "—"}`}
                icon="heroicons:archive-box"
            >
                <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {release.dataset_key}
                </p>
                <MetricGrid metrics={metrics} detailed={false} />
            </Section>

            <div className="grid gap-4 lg:grid-cols-2">
                <Section
                    title="Evidence verification"
                    icon="heroicons:document-magnifying-glass"
                >
                    <Surface className="p-4">
                        <PartitionBar
                            total={release.pathway_count}
                            segments={[
                                {
                                    label: "verified",
                                    value: queues.evidence_verified,
                                    barClass:
                                        "bg-healthcare-success dark:bg-healthcare-success-dark",
                                    textClass:
                                        "text-healthcare-success dark:text-healthcare-success-dark",
                                },
                                {
                                    label: "with limitations",
                                    value: queues.evidence_limitations,
                                    barClass:
                                        "bg-healthcare-warning dark:bg-healthcare-warning-dark",
                                    textClass:
                                        "text-healthcare-warning dark:text-healthcare-warning-dark",
                                },
                            ]}
                        />
                        <p className="mt-3 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Automated independent review only — verification is
                            not institutional clinical approval.
                        </p>
                    </Surface>
                </Section>

                <Section
                    title="Release disposition"
                    icon="heroicons:clipboard-document-check"
                >
                    <Surface className="p-4">
                        <PartitionBar
                            total={release.pathway_count}
                            segments={[
                                {
                                    label: "sign-off queue",
                                    value: queues.institutional_signoff,
                                    barClass:
                                        "bg-healthcare-info dark:bg-healthcare-info-dark",
                                    textClass:
                                        "text-healthcare-info dark:text-healthcare-info-dark",
                                },
                                {
                                    label: "specialist review",
                                    value: queues.specialist_review,
                                    barClass:
                                        "bg-healthcare-warning dark:bg-healthcare-warning-dark",
                                    textClass:
                                        "text-healthcare-warning dark:text-healthcare-warning-dark",
                                },
                                {
                                    label: "redesign",
                                    value: queues.redesign,
                                    barClass:
                                        "bg-healthcare-text-secondary dark:bg-healthcare-text-secondary-dark",
                                    textClass:
                                        "text-healthcare-text-primary dark:text-healthcare-text-primary-dark",
                                },
                            ]}
                        />
                        <p className="mt-3 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Every pathway carries exactly one disposition; none
                            is clinically signed off in this release.
                        </p>
                    </Surface>
                </Section>
            </div>

            <Section
                title="Release controls"
                icon="heroicons:shield-check"
                actions={
                    <span
                        className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                            controlsClean
                                ? "text-healthcare-success dark:text-healthcare-success-dark"
                                : "text-healthcare-warning dark:text-healthcare-warning-dark"
                        }`}
                    >
                        <ControlsIcon className="h-4 w-4" aria-hidden="true" />
                        {controlsClean
                            ? "All controls reconciled"
                            : "Controls need attention"}
                    </span>
                }
            >
                <Surface className="p-4">
                    <dl className="flex flex-wrap gap-x-8 gap-y-2">
                        {Object.entries(controls.by_status).map(
                            ([status, count]) => (
                                <div
                                    key={status}
                                    className="flex items-baseline gap-1.5"
                                >
                                    <dd className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {count}
                                    </dd>
                                    <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {status.replaceAll("_", " ")}
                                    </dt>
                                </div>
                            ),
                        )}
                        <div className="flex items-baseline gap-1.5">
                            <dd className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {controls.residual_unknowns}
                            </dd>
                            <dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                residual unknowns
                            </dt>
                        </div>
                    </dl>
                </Surface>
            </Section>
        </div>
    );
}
