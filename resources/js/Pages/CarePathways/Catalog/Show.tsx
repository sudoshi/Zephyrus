import { Head, Link } from "@inertiajs/react";
import { ArrowLeft } from "lucide-react";
import { useState } from "react";
import DashboardLayout from "@/Components/Dashboard/DashboardLayout";
import PageContentLayout from "@/Components/Common/PageContentLayout";
import { EvidenceClaims } from "@/Components/CarePathways/EvidenceClaims";
import { GovernanceBanner } from "@/Components/CarePathways/GovernanceBanner";
import { GovernanceLedger } from "@/Components/CarePathways/GovernanceLedger";
import { PathwayAuthoring } from "@/Components/CarePathways/PathwayAuthoring";
import { PathwaySections } from "@/Components/CarePathways/PathwaySections";
import {
    DrgChips,
    EvidenceBadge,
    GovernanceStatus,
} from "@/Components/CarePathways/StatusAtoms";
import { Section } from "@/Components/system";
import { Surface } from "@/Components/ui/Surface";
import { useCatalogVersion } from "@/hooks/carePathways/useCatalog";
import {
    versionEnvelopeSchema,
    type VersionEnvelope,
} from "@/lib/carePathways/catalogSchemas";

interface ShowProps {
    initialVersion: unknown;
}

export default function Show({ initialVersion }: ShowProps) {
    // Server-rendered props pass through the same Zod gate as live fetches.
    const [seed] = useState<VersionEnvelope>(() =>
        versionEnvelopeSchema.parse(initialVersion),
    );
    const query = useCatalogVersion(seed.data.version.version_uuid, seed);
    const envelope = query.data ?? seed;
    const { pathway, version, drg_candidates, evidence, governance, sections, authoring, changes } =
        envelope.data;

    return (
        <DashboardLayout>
            <Head title={`${pathway.name} — Care Pathway`} />
            <PageContentLayout
                title={pathway.name}
                subtitle={`${pathway.mdc ?? "MDC —"} · ${pathway.care_type ?? "—"} · ${pathway.source_service_line ?? "—"}`}
                headerContent={
                    <Link
                        href="/care-pathways/catalog"
                        className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-surface-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-surface-hover-dark"
                    >
                        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                        Catalog
                    </Link>
                }
            >
                <div className="space-y-4">
                    <GovernanceBanner
                        warning={envelope.meta.clinical_approval_warning}
                        state="inactive"
                        signoffCount={
                            governance.approvals.filter(
                                (approval) => approval.decision === "approved",
                            ).length
                        }
                    />

                    <Surface className="p-4">
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                            <div className="flex items-baseline gap-1.5">
                                <span className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {version.source_rank ?? "—"}
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    volume rank
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5">
                                <span className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {version.semantic_version}
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    version
                                </span>
                            </div>
                            <div className="flex items-baseline gap-1.5">
                                <span className="text-lg font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {evidence.claim_count}
                                </span>
                                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    evidence claims
                                </span>
                            </div>
                            <EvidenceBadge status={evidence.status} />
                            <GovernanceStatus
                                approval={governance.institutional_approval_status}
                                activation={governance.activation_status}
                            />
                        </div>
                        <p className="mt-3 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {evidence.release_disposition}
                            {evidence.confidence &&
                                ` · verification confidence: ${evidence.confidence}`}
                            {evidence.source_currency !== "current" &&
                                " · linked to at least one non-current source"}
                        </p>
                    </Surface>

                    <Section
                        title="DRG candidates"
                        summary="Grouper output is a candidate signal — clinician confirmation is always required"
                        icon="heroicons:hashtag"
                    >
                        <Surface className="overflow-hidden">
                            {drg_candidates.map((drg) => (
                                <div
                                    key={drg.ms_drg}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-healthcare-border px-4 py-2.5 last:border-b-0 dark:border-healthcare-border-dark"
                                >
                                    <DrgChips drgs={[drg]} max={1} />
                                    <span className="min-w-0 flex-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {drg.title}
                                    </span>
                                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {drg.mdc && `MDC ${drg.mdc}`}
                                        {drg.type_label && ` · ${drg.type_label}`}
                                        {` · ${drg.mapping_role.replaceAll("_", " ")}`}
                                    </span>
                                    {drg.ambiguity_note && (
                                        <p className="w-full text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {drg.ambiguity_note}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </Surface>
                    </Section>

                    <PathwaySections sections={sections} />

                    <PathwayAuthoring authoring={authoring} />

                    <EvidenceClaims
                        versionUuid={version.version_uuid}
                        claimCount={evidence.claim_count}
                    />

                    <GovernanceLedger
                        governance={governance}
                        version={version}
                        changes={changes}
                    />
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
