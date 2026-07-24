import { keepPreviousData, useQuery } from "@tanstack/react-query";
import {
    fetchClaims,
    fetchPathways,
    fetchSummary,
    fetchVersion,
    type PathwayQuery,
} from "@/lib/carePathways/catalogApi";
import type {
    ClaimsEnvelope,
    PathwaysEnvelope,
    SummaryEnvelope,
    VersionEnvelope,
} from "@/lib/carePathways/catalogSchemas";

const STALE_MS = 5 * 60 * 1000;

const isDefaultQuery = (query: PathwayQuery): boolean =>
    (query.page ?? 1) === 1 &&
    Object.entries(query).every(
        ([key, value]) =>
            key === "page" || key === "per_page" || value === undefined || value === "",
    );

export function useCatalogSummary(initialData?: SummaryEnvelope) {
    return useQuery({
        queryKey: ["care-pathways", "summary"],
        queryFn: fetchSummary,
        initialData,
        staleTime: STALE_MS,
    });
}

export function useCatalogPathways(
    query: PathwayQuery,
    initialData?: PathwaysEnvelope,
) {
    return useQuery({
        queryKey: ["care-pathways", "pathways", query],
        queryFn: () => fetchPathways(query),
        initialData:
            initialData && isDefaultQuery(query) ? initialData : undefined,
        staleTime: STALE_MS,
        placeholderData: keepPreviousData,
    });
}

export function useCatalogVersion(
    versionUuid: string,
    initialData?: VersionEnvelope,
) {
    return useQuery({
        queryKey: ["care-pathways", "version", versionUuid],
        queryFn: () => fetchVersion(versionUuid),
        initialData,
        staleTime: STALE_MS,
    });
}

export function useCatalogClaims(versionUuid: string, page: number) {
    return useQuery<ClaimsEnvelope>({
        queryKey: ["care-pathways", "claims", versionUuid, page],
        queryFn: () => fetchClaims(versionUuid, page),
        staleTime: STALE_MS,
        placeholderData: keepPreviousData,
    });
}
