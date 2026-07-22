import {
    parsePatientCommunicationRouteCandidates,
    type PatientCommunicationRouteCandidates,
} from "@/Pages/PatientCommunications/routingPolicy";
import { describe, expect, it } from "vitest";

const workItemUuid = "019f0000-0000-7000-8000-000000000001";

function projection(): PatientCommunicationRouteCandidates {
    return {
        work_item_uuid: workItemUuid,
        work_item_version: 4,
        thread_version: 7,
        actions: {
            can_release: true,
            can_reassign: true,
            can_reroute: true,
        },
        reason_options: {
            release: [{ code: "shift_handoff", label: "Shift handoff" }],
            reassign: [{ code: "coverage_change", label: "Coverage change" }],
            reroute: [{ code: "specialty_needed", label: "Specialty needed" }],
        },
        reassign_candidates: [
            {
                membership_uuid: "019f0000-0000-7000-8000-000000000002",
                label: "Care-team responder",
                membership_role: "responder",
            },
        ],
        reroute_candidates: [
            {
                pool_uuid: "019f0000-0000-7000-8000-000000000003",
                label: "Cardiology Care Team",
                scope_type: "facility",
                unit: null,
            },
        ],
    };
}

describe("patient communication routing policy parser", () => {
    it("accepts a bounded, opaque, versioned authorization projection", () => {
        expect(
            parsePatientCommunicationRouteCandidates(
                projection(),
                workItemUuid,
            ),
        ).toEqual(projection());
    });

    it("fails closed on an unknown reason or an oversized candidate list", () => {
        const unknownReason = projection() as unknown as Record<
            string,
            unknown
        >;
        (
            unknownReason.reason_options as PatientCommunicationRouteCandidates["reason_options"]
        ).reroute[0].code = "arbitrary_destination";
        expect(
            parsePatientCommunicationRouteCandidates(
                unknownReason,
                workItemUuid,
            ),
        ).toBeNull();

        const oversized = projection();
        oversized.reassign_candidates = Array.from({ length: 51 }, (_, i) => ({
            membership_uuid: `019f0000-0000-7000-8${String(i).padStart(3, "0")}-000000000002`,
            label: `Responder ${i + 1}`,
            membership_role: "responder" as const,
        }));
        expect(
            parsePatientCommunicationRouteCandidates(oversized, workItemUuid),
        ).toBeNull();
    });

    it("fails closed on the wrong resource or any raw internal identifier", () => {
        expect(
            parsePatientCommunicationRouteCandidates(
                projection(),
                "019f0000-0000-7000-8000-000000000099",
            ),
        ).toBeNull();

        const leakedIdentifier =
            projection() as PatientCommunicationRouteCandidates & {
                assigned_user_id: number;
            };
        leakedIdentifier.assigned_user_id = 42;
        expect(
            parsePatientCommunicationRouteCandidates(
                leakedIdentifier,
                workItemUuid,
            ),
        ).toBeNull();
    });
});
