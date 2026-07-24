import { z } from "zod";

// Zod mirrors of the care-pathway governance envelopes produced by
// App\Services\CarePathways\CatalogGovernanceReadService. Every API boundary
// in the Catalog Explorer parses through these — no unvalidated shapes.

const metaSchema = z.object({
    schema: z.string(),
    catalog_release_uuid: z.string(),
    dataset_key: z.string(),
    grouper_version: z.string(),
    source_cutoff_date: z.string().nullable(),
    as_of: z.string(),
    clinical_approval_warning: z.string(),
    patient_serving: z.boolean(),
    hummingbird_serving: z.boolean(),
    eddy_serving: z.boolean(),
    pagination: z
        .object({
            page: z.number(),
            per_page: z.number(),
            total: z.number(),
            last_page: z.number(),
        })
        .optional(),
});

const releaseSchema = z.object({
    catalog_release_uuid: z.string(),
    dataset_key: z.string(),
    grouper_version: z.string(),
    grouper_effective_period: z.object({
        start: z.string().nullable(),
        end: z.string().nullable(),
    }),
    state: z.string(),
    clinical_signoff_complete: z.boolean(),
    clinical_signoff_count: z.number(),
    pathway_count: z.number(),
    adopted_at: z.string().nullable(),
    activated_at: z.string().nullable(),
    withdrawn_at: z.string().nullable(),
});

export const summaryEnvelopeSchema = z.object({
    data: z.object({
        release: releaseSchema,
        catalog: z.object({
            definitions: z.number(),
            versions: z.number(),
            institutionally_approved_versions: z.number(),
            active_versions: z.number(),
            sections: z.number(),
            approved_sections: z.number(),
            patient_or_caregiver_sections: z.number(),
            drg_codebook_entries: z.number(),
            drg_mappings: z.number(),
            evidence_claims: z.number(),
            sources: z.number(),
            current_sources: z.number(),
            noncurrent_sources: z.number(),
            changes: z.number(),
        }),
        review_queues: z.object({
            evidence_verified: z.number(),
            evidence_limitations: z.number(),
            institutional_signoff: z.number(),
            specialist_review: z.number(),
            redesign: z.number(),
            recorded_reviews: z.number(),
            recorded_approvals: z.number(),
        }),
        controls: z.object({
            by_status: z.record(z.string(), z.number()),
            failed: z.number(),
            residual_unknowns: z.number(),
            service_line_mappings: z.record(z.string(), z.number()),
        }),
        serving_flags: z.record(z.string(), z.boolean()),
        release_readiness: z.object({
            clinical_signoff_complete: z.boolean(),
            may_serve_approved_catalog: z.boolean(),
            patient_projection_released: z.boolean(),
            eddy_retrieval_released: z.boolean(),
        }),
        authorization: z.record(z.string(), z.boolean()).optional(),
    }),
    meta: metaSchema,
});

const drgCandidateSchema = z.object({
    ms_drg: z.string(),
    title: z.string(),
    mdc: z.string().nullable(),
    type_code: z.string().nullable(),
    type_label: z.string().nullable(),
    mapping_role: z.string(),
    ambiguity_note: z.string().nullable(),
    requires_clinician_confirmation: z.boolean(),
});

const pathwayIdentitySchema = z.object({
    uuid: z.string(),
    key: z.string(),
    name: z.string(),
    mdc: z.string().nullable(),
    care_type: z.string().nullable(),
    source_service_line: z.string().nullable(),
    service_line_code: z.string().nullable(),
    lifecycle_state: z.string(),
});

const versionIdentitySchema = z.object({
    version_uuid: z.string(),
    semantic_version: z.string(),
    source_rank: z.number().nullable(),
    content_digest: z.string(),
    effective_period: z.object({
        start: z.string().nullable(),
        end: z.string().nullable(),
    }),
    source_cutoff_date: z.string().nullable(),
    exact_version: z.boolean(),
});

const evidenceSchema = z.object({
    status: z.string(),
    confidence: z.string().nullable(),
    source_specificity: z.string().nullable(),
    release_disposition: z.string(),
    claim_count: z.number(),
    source_currency: z.string(),
});

const pathwayRowGovernanceSchema = z.object({
    clinical_signoff_status: z.string(),
    institutional_approval_status: z.string(),
    activation_status: z.string(),
    section_count: z.number(),
    approved_section_count: z.number(),
    review_count: z.number(),
    approval_count: z.number(),
});

export const pathwayRowSchema = z.object({
    pathway: pathwayIdentitySchema,
    version: versionIdentitySchema,
    drg_candidates: z.array(drgCandidateSchema),
    evidence: evidenceSchema,
    governance: pathwayRowGovernanceSchema,
});

export const pathwaysEnvelopeSchema = z.object({
    data: z.array(pathwayRowSchema),
    meta: metaSchema,
});

const reviewSchema = z.object({
    review_uuid: z.string(),
    reviewer_role: z.string(),
    review_scope: z.string(),
    decision: z.string(),
    reason: z.string(),
    issues: z.unknown(),
    reviewed_at: z.string().nullable(),
});

const approvalSchema = z.object({
    approval_uuid: z.string(),
    approval_type: z.string(),
    decision: z.string(),
    conditions: z.string().nullable(),
    effective_period: z.object({
        start: z.string().nullable(),
        end: z.string().nullable(),
    }),
    decided_at: z.string().nullable(),
});

const sectionSchema = z.object({
    section_uuid: z.string(),
    section_code: z.string(),
    audience: z.string(),
    language_code: z.string(),
    source_text: z.string(),
    approved_text: z.string().nullable(),
    content_mode: z.string(),
    review_state: z.string(),
    source_digest: z.string(),
    approved_digest: z.string().nullable(),
});

const milestoneSchema = z.object({
    milestone_uuid: z.string(),
    stable_key: z.string(),
    title: z.string(),
    phase: z.string().nullable(),
    sequence: z.number().nullable(),
    predecessor_keys: z.unknown(),
    expected_range: z.unknown(),
    applicability_ref: z.string().nullable(),
    completion_evidence_ref: z.string().nullable(),
    review_state: z.string(),
});

const goalSchema = z.object({
    goal_uuid: z.string(),
    stable_key: z.string(),
    goal_code: z.string().nullable(),
    goal_text: z.string(),
    author_type: z.string(),
    target: z.unknown(),
    patient_visible_explanation: z.string().nullable(),
    review_state: z.string(),
});

const activitySchema = z.object({
    activity_uuid: z.string(),
    stable_key: z.string(),
    activity_type: z.string(),
    title: z.string(),
    performer_role: z.string().nullable(),
    timing: z.unknown(),
    preconditions: z.unknown(),
    executable: z.boolean(),
    fhir_canonical_ref: z.string().nullable(),
    review_state: z.string(),
});

const educationSchema = z.object({
    education_uuid: z.string(),
    stable_key: z.string(),
    audience: z.string(),
    language_code: z.string(),
    reading_level: z.string().nullable(),
    title: z.string(),
    approved_content: z.string().nullable(),
    teach_back_prompt: z.string().nullable(),
    required_reviewer_role: z.string().nullable(),
    content_digest: z.string().nullable(),
    review_state: z.string(),
});

export const versionEnvelopeSchema = z.object({
    data: z.object({
        pathway: pathwayIdentitySchema,
        version: versionIdentitySchema.extend({
            source_digest: z.string(),
            unresolved_flags: z.unknown(),
        }),
        drg_candidates: z.array(drgCandidateSchema),
        evidence: evidenceSchema,
        governance: z.object({
            clinical_signoff_status: z.string(),
            institutional_approval_status: z.string(),
            activation_status: z.string(),
            reviews: z.array(reviewSchema),
            approvals: z.array(approvalSchema),
        }),
        sections: z.array(sectionSchema),
        authoring: z.object({
            milestones: z.array(milestoneSchema),
            activities: z.array(activitySchema),
            goals: z.array(goalSchema),
            education: z.array(educationSchema),
        }),
        changes: z.array(z.record(z.string(), z.unknown())),
    }),
    meta: metaSchema,
});

const claimSchema = z.object({
    claim_uuid: z.string(),
    source_rank: z.number(),
    source_field: z.string(),
    claim_type: z.string().nullable(),
    claim_excerpt: z.string(),
    automated_review: z.object({
        pass_1: z.unknown(),
        pass_2: z.unknown(),
    }),
    clinical_adjudication: z.unknown(),
    verification_date: z.string().nullable(),
    claim_digest: z.string(),
    sources: z.array(
        z.object({
            source_uuid: z.string(),
            pmid: z.string().nullable(),
            title: z.string().nullable(),
            current_status: z.string(),
            content_digest: z.string(),
            evidence_grade: z.string().nullable(),
            applicability_note: z.string().nullable(),
        }),
    ),
});

export const claimsEnvelopeSchema = z.object({
    data: z.array(claimSchema),
    meta: metaSchema,
});

export type SummaryEnvelope = z.infer<typeof summaryEnvelopeSchema>;
export type PathwaysEnvelope = z.infer<typeof pathwaysEnvelopeSchema>;
export type PathwayRow = z.infer<typeof pathwayRowSchema>;
export type DrgCandidate = z.infer<typeof drgCandidateSchema>;
export type PathwaySection = z.infer<typeof sectionSchema>;
export type VersionEnvelope = z.infer<typeof versionEnvelopeSchema>;
export type ClaimsEnvelope = z.infer<typeof claimsEnvelopeSchema>;
export type EvidenceClaim = z.infer<typeof claimSchema>;
