import { describe, expect, it } from "vitest";
import claimsFixture from "./__fixtures__/claims-page1.json";
import pathwaysFixture from "./__fixtures__/pathways-page1.json";
import summaryFixture from "./__fixtures__/summary.json";
import versionFixture from "./__fixtures__/version-detail.json";
import {
    claimsEnvelopeSchema,
    pathwaysEnvelopeSchema,
    summaryEnvelopeSchema,
    versionEnvelopeSchema,
} from "@/lib/carePathways/catalogSchemas";

// Fixtures are real envelopes captured from CatalogGovernanceReadService
// against the adopted v43.1 release (250 pathways). If the PHP service's
// shape changes, these parses fail before any page breaks silently.
describe("care pathway governance envelope schemas", () => {
    it("parses the summary envelope", () => {
        const parsed = summaryEnvelopeSchema.parse(summaryFixture);
        expect(parsed.data.release.state).toBe("inactive");
        expect(parsed.data.release.clinical_signoff_count).toBe(0);
        expect(parsed.data.catalog.definitions).toBe(250);
        expect(parsed.meta.patient_serving).toBe(false);
        expect(parsed.meta.clinical_approval_warning).toContain(
            "not institutional clinical approval",
        );
    });

    it("parses the pathways index envelope", () => {
        const parsed = pathwaysEnvelopeSchema.parse(pathwaysFixture);
        expect(parsed.data.length).toBe(25);
        expect(parsed.meta.pagination?.total).toBe(250);
        expect(parsed.data[0].version.source_rank).toBe(1);
        expect(parsed.data[0].drg_candidates.length).toBeGreaterThan(0);
        expect(
            parsed.data[0].drg_candidates[0].requires_clinician_confirmation,
        ).toBe(true);
    });

    it("parses the version detail envelope", () => {
        const parsed = versionEnvelopeSchema.parse(versionFixture);
        expect(parsed.data.sections.length).toBeGreaterThan(0);
        expect(parsed.data.governance.activation_status).toBe("inactive");
        expect(parsed.data.version.content_digest).toMatch(/^[0-9a-f]{64}$/);
    });

    it("parses the claims envelope", () => {
        const parsed = claimsEnvelopeSchema.parse(claimsFixture);
        expect(parsed.data.length).toBeGreaterThan(0);
        expect(parsed.data[0].claim_excerpt.length).toBeGreaterThan(0);
    });
});
