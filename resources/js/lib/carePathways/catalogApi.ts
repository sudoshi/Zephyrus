import axios from "axios";
import {
    claimsEnvelopeSchema,
    pathwaysEnvelopeSchema,
    summaryEnvelopeSchema,
    versionEnvelopeSchema,
    type ClaimsEnvelope,
    type PathwaysEnvelope,
    type SummaryEnvelope,
    type VersionEnvelope,
} from "./catalogSchemas";

const BASE = "/api/care-pathways/v1";

export interface PathwayQuery {
    q?: string;
    drg?: string;
    mdc?: string;
    service_line?: string;
    evidence_state?: "verified" | "limitations";
    disposition?: "signoff" | "specialist_review" | "redesign";
    institutional_approval_status?:
        | "not_reviewed"
        | "in_review"
        | "approved"
        | "rejected"
        | "withdrawn";
    activation_status?: "inactive" | "active" | "withdrawn";
    page?: number;
    per_page?: number;
}

export async function fetchSummary(): Promise<SummaryEnvelope> {
    const { data } = await axios.get<unknown>(`${BASE}/summary`);
    return summaryEnvelopeSchema.parse(data);
}

export async function fetchPathways(
    query: PathwayQuery,
): Promise<PathwaysEnvelope> {
    const params = Object.fromEntries(
        Object.entries(query).filter(
            ([, value]) => value !== undefined && value !== "",
        ),
    );
    const { data } = await axios.get<unknown>(`${BASE}/pathways`, { params });
    return pathwaysEnvelopeSchema.parse(data);
}

export async function fetchVersion(
    versionUuid: string,
): Promise<VersionEnvelope> {
    const { data } = await axios.get<unknown>(`${BASE}/versions/${versionUuid}`);
    return versionEnvelopeSchema.parse(data);
}

export async function fetchClaims(
    versionUuid: string,
    page = 1,
    perPage = 50,
): Promise<ClaimsEnvelope> {
    const { data } = await axios.get<unknown>(
        `${BASE}/versions/${versionUuid}/claims`,
        { params: { page, per_page: perPage } },
    );
    return claimsEnvelopeSchema.parse(data);
}
