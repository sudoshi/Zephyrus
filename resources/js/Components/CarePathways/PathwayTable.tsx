import { Link } from "@inertiajs/react";
import { ChevronLeft, ChevronRight, SearchX } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";
import { Section } from "@/Components/system";
import type { PathwayRow } from "@/lib/carePathways/catalogSchemas";
import {
    DrgChips,
    EvidenceBadge,
    GovernanceStatus,
} from "@/Components/CarePathways/StatusAtoms";

interface Pagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

interface PathwayTableProps {
    rows: readonly PathwayRow[];
    pagination?: Pagination;
    onPage: (page: number) => void;
    isLoading: boolean;
}

const headerCell =
    "px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark";

const bodyCell = "px-3 py-2.5 align-top text-sm";

export function PathwayTable({
    rows,
    pagination,
    onPage,
    isLoading,
}: PathwayTableProps) {
    return (
        <Section
            title="Pathways"
            icon="heroicons:map"
            actions={
                pagination && (
                    <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {pagination.total} pathway
                        {pagination.total === 1 ? "" : "s"}
                    </span>
                )
            }
        >
            <Surface className="p-4">
            <div
                className={`overflow-x-auto transition-opacity ${isLoading ? "opacity-60" : ""}`}
                aria-busy={isLoading}
            >
                <table className="w-full min-w-[900px] border-collapse">
                    <thead>
                        <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                            <th scope="col" className={headerCell}>
                                Rank
                            </th>
                            <th scope="col" className={headerCell}>
                                Pathway
                            </th>
                            <th scope="col" className={headerCell}>
                                MDC
                            </th>
                            <th scope="col" className={headerCell}>
                                Service line
                            </th>
                            <th scope="col" className={headerCell}>
                                DRGs
                            </th>
                            <th scope="col" className={headerCell}>
                                Evidence
                            </th>
                            <th scope="col" className={headerCell}>
                                Governance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.version.version_uuid}
                                className="border-b border-healthcare-border last:border-b-0 hover:bg-healthcare-surface-hover dark:border-healthcare-border-dark dark:hover:bg-healthcare-surface-hover-dark"
                            >
                                <td
                                    className={`${bodyCell} tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}
                                >
                                    {row.version.source_rank ?? "—"}
                                </td>
                                <td className={bodyCell}>
                                    <Link
                                        href={`/care-pathways/catalog/${row.version.version_uuid}`}
                                        className="font-medium text-healthcare-primary hover:underline dark:text-healthcare-primary-dark"
                                    >
                                        {row.pathway.name}
                                    </Link>
                                    <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {row.pathway.care_type ?? "—"}
                                        {" · "}
                                        <span className="tabular-nums">
                                            {row.evidence.claim_count}
                                        </span>{" "}
                                        claims
                                        {" · "}
                                        <span className="tabular-nums">
                                            {row.governance.section_count}
                                        </span>{" "}
                                        sections
                                    </p>
                                </td>
                                <td
                                    className={`${bodyCell} max-w-52 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}
                                >
                                    {row.pathway.mdc ?? "—"}
                                </td>
                                <td
                                    className={`${bodyCell} text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark`}
                                >
                                    {row.pathway.source_service_line ?? "—"}
                                </td>
                                <td className={bodyCell}>
                                    <DrgChips drgs={row.drg_candidates} />
                                </td>
                                <td className={bodyCell}>
                                    <EvidenceBadge
                                        status={row.evidence.status}
                                    />
                                </td>
                                <td className={bodyCell}>
                                    <GovernanceStatus
                                        approval={
                                            row.governance
                                                .institutional_approval_status
                                        }
                                        activation={
                                            row.governance.activation_status
                                        }
                                    />
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && !isLoading && (
                            <tr>
                                <td colSpan={7} className="px-3 py-10">
                                    <div className="flex flex-col items-center gap-2 text-center">
                                        <SearchX
                                            className="h-6 w-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                            aria-hidden="true"
                                        />
                                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            No pathways match the current
                                            filters.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {pagination && pagination.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
                    <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Page {pagination.page} of {pagination.last_page}
                    </p>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => onPage(pagination.page - 1)}
                            disabled={isLoading || pagination.page <= 1}
                            className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                        >
                            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                            Previous
                        </button>
                        <button
                            type="button"
                            onClick={() => onPage(pagination.page + 1)}
                            disabled={
                                isLoading ||
                                pagination.page >= pagination.last_page
                            }
                            className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm font-medium text-healthcare-text-primary disabled:opacity-40 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                        >
                            Next
                            <ChevronRight
                                className="h-4 w-4"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                </div>
            )}
            </Surface>
        </Section>
    );
}
