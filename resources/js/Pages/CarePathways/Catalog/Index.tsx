import { Head, Link, usePage } from "@inertiajs/react";
import { HeartPulse } from "lucide-react";
import { useCallback, useState } from "react";
import DashboardLayout from "@/Components/Dashboard/DashboardLayout";
import PageContentLayout from "@/Components/Common/PageContentLayout";
import { ActivationReadiness } from "@/Components/CarePathways/ActivationReadiness";
import { CatalogOverview } from "@/Components/CarePathways/CatalogOverview";
import { GovernanceBanner } from "@/Components/CarePathways/GovernanceBanner";
import { PathwayFilters } from "@/Components/CarePathways/PathwayFilters";
import { PathwayTable } from "@/Components/CarePathways/PathwayTable";
import {
    useCatalogPathways,
    useCatalogSummary,
} from "@/hooks/carePathways/useCatalog";
import type { PathwayQuery } from "@/lib/carePathways/catalogApi";
import type { PageProps } from "@/types";
import {
    pathwaysEnvelopeSchema,
    summaryEnvelopeSchema,
    type PathwaysEnvelope,
    type SummaryEnvelope,
} from "@/lib/carePathways/catalogSchemas";

interface IndexProps {
    initialSummary: unknown;
    initialPathways: unknown;
}

export default function Index({ initialSummary, initialPathways }: IndexProps) {
    // Server-rendered props pass through the same Zod gate as live fetches.
    const [seedSummary] = useState<SummaryEnvelope>(() =>
        summaryEnvelopeSchema.parse(initialSummary),
    );
    const [seedPathways] = useState<PathwaysEnvelope>(() =>
        pathwaysEnvelopeSchema.parse(initialPathways),
    );

    const [query, setQuery] = useState<PathwayQuery>({ page: 1, per_page: 25 });
    const summary = useCatalogSummary(seedSummary);
    const pathways = useCatalogPathways(query, seedPathways);

    const onPage = useCallback(
        (page: number) => setQuery((current) => ({ ...current, page })),
        [],
    );

    const demoEnabled =
        usePage<PageProps>().props.features?.care_pathways_demo === true;

    const summaryData = summary.data ?? seedSummary;

    return (
        <DashboardLayout>
            <Head title="Care Pathways Catalog" />
            <PageContentLayout
                title="Care Pathways Catalog"
                subtitle="Governed DRG catalog — 250 pathways under evidence verification and institutional review"
                headerContent={
                    demoEnabled ? (
                        <Link
                            href="/care-pathways/demo"
                            className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-surface-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-surface-hover-dark"
                        >
                            <HeartPulse className="h-4 w-4" aria-hidden="true" />
                            Pathway Journey Demo
                        </Link>
                    ) : undefined
                }
            >
                <div className="space-y-4">
                    <GovernanceBanner
                        warning={summaryData.meta.clinical_approval_warning}
                        state={summaryData.data.release.state}
                        signoffCount={
                            summaryData.data.release.clinical_signoff_count
                        }
                    />

                    <CatalogOverview
                        summary={summaryData.data}
                        meta={summaryData.meta}
                    />

                    <ActivationReadiness
                        readiness={summaryData.data.release_readiness}
                        release={summaryData.data.release}
                        catalog={summaryData.data.catalog}
                    />

                    <PathwayFilters value={query} onChange={setQuery} />

                    {pathways.isError ? (
                        <p
                            className="rounded-md border border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark"
                            role="alert"
                        >
                            The pathway list could not be loaded. Adjust the
                            filters or try again — no catalog state was
                            changed.
                        </p>
                    ) : (
                        <PathwayTable
                            rows={pathways.data?.data ?? []}
                            pagination={pathways.data?.meta.pagination}
                            onPage={onPage}
                            isLoading={pathways.isFetching}
                        />
                    )}
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
