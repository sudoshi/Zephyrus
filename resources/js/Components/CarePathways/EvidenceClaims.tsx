import { AlertTriangle, ChevronLeft, ChevronRight, ExternalLink } from "lucide-react";
import { useState } from "react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import { useCatalogClaims } from "@/hooks/carePathways/useCatalog";
import type { EvidenceClaim } from "@/lib/carePathways/catalogSchemas";

function ClaimSources({ claim }: { claim: EvidenceClaim }) {
    if (claim.sources.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 space-y-1">
            {claim.sources.map((source) => (
                <li
                    key={source.source_uuid}
                    className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                >
                    {source.pmid ? (
                        <a
                            href={`https://pubmed.ncbi.nlm.nih.gov/${source.pmid}/`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-medium text-healthcare-primary hover:underline dark:text-healthcare-primary-dark"
                        >
                            PMID <span className="tabular-nums">{source.pmid}</span>
                            <ExternalLink className="h-3 w-3" aria-hidden="true" />
                        </a>
                    ) : (
                        <span className="font-medium">Source</span>
                    )}
                    <span className="min-w-0 flex-1 truncate" title={source.title ?? undefined}>
                        {source.title ?? "Untitled source"}
                    </span>
                    {source.evidence_grade && (
                        <span className="rounded-full border border-healthcare-border px-1.5 dark:border-healthcare-border-dark">
                            Grade {source.evidence_grade}
                        </span>
                    )}
                    {source.current_status !== "current" && (
                        <span className="inline-flex items-center gap-1 text-healthcare-warning dark:text-healthcare-warning-dark">
                            <AlertTriangle className="h-3 w-3" aria-hidden="true" />
                            {source.current_status}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

export function EvidenceClaims({
    versionUuid,
    claimCount,
}: {
    versionUuid: string;
    claimCount: number;
}) {
    const [page, setPage] = useState(1);
    const claims = useCatalogClaims(versionUuid, page);
    const pagination = claims.data?.meta.pagination;

    return (
        <Section
            title="Evidence claims"
            summary={`${claimCount} claims extracted from source text, independently reviewed`}
            icon="heroicons:document-magnifying-glass"
        >
            <Surface className="p-4" aria-busy={claims.isFetching}>
                {claims.isError ? (
                    <p
                        className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                        role="alert"
                    >
                        Evidence claims could not be loaded. Try again — no
                        catalog state was changed.
                    </p>
                ) : (
                    <div
                        className={`space-y-4 transition-opacity ${claims.isFetching ? "opacity-60" : ""}`}
                    >
                        {(claims.data?.data ?? []).map((claim) => (
                            <div
                                key={claim.claim_uuid}
                                className="border-b border-healthcare-border pb-3 last:border-b-0 last:pb-0 dark:border-healthcare-border-dark"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                                        {claim.source_field.replaceAll("_", " ")}
                                    </span>
                                    {claim.claim_type && (
                                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {claim.claim_type.replaceAll("_", " ")}
                                        </span>
                                    )}
                                    {claim.verification_date && (
                                        <span className="ml-auto text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            verified {claim.verification_date}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1.5 max-w-prose text-sm leading-6 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {claim.claim_excerpt}
                                </p>
                                <ClaimSources claim={claim} />
                            </div>
                        ))}
                    </div>
                )}

                {pagination && pagination.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
                        <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Page {pagination.page} of {pagination.last_page} ·{" "}
                            {pagination.total} claims
                        </p>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setPage(page - 1)}
                                disabled={claims.isFetching || page <= 1}
                                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                            >
                                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                                Previous
                            </button>
                            <button
                                type="button"
                                onClick={() => setPage(page + 1)}
                                disabled={
                                    claims.isFetching ||
                                    page >= pagination.last_page
                                }
                                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                            >
                                Next
                                <ChevronRight className="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                )}
            </Surface>
        </Section>
    );
}
