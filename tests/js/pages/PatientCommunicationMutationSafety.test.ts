import { describe, expect, it } from "vitest";
import {
    createMutationCommand,
    isUnknownMutationOutcome,
    mutationRequest,
} from "@/Pages/PatientCommunications/mutationSafety";

describe("Patient Communication mutation safety", () => {
    it("replays an uncertain reply with the exact idempotency key, client UUID, and payload", () => {
        const command = createMutationCommand(
            "reply",
            "019f0000-0000-7000-8000-000000000001",
            "/patient-communications/threads/019f0000-0000-7000-8000-000000000001/reply",
            {
                work_item_version: 4,
                thread_version: 7,
                message: "The same patient-visible response.",
                client_message_uuid: "019f0000-0000-7000-8000-000000000002",
            },
            "Response sent.",
            () => "019f0000-0000-7000-8000-000000000003",
        );

        const firstAttempt = mutationRequest(command);
        const exactRetry = mutationRequest(command);

        expect(exactRetry).toEqual(firstAttempt);
        expect(exactRetry.headers["Idempotency-Key"]).toBe(
            "019f0000-0000-7000-8000-000000000003",
        );
        expect(exactRetry.payload.client_message_uuid).toBe(
            "019f0000-0000-7000-8000-000000000002",
        );
        expect(Object.isFrozen(command)).toBe(true);
        expect(Object.isFrozen(command.payload)).toBe(true);
    });

    it("retains only genuinely unknown outcomes for exact replay", () => {
        expect(isUnknownMutationOutcome(null)).toBe(true);
        expect(isUnknownMutationOutcome(500)).toBe(true);
        expect(isUnknownMutationOutcome(503)).toBe(true);
        expect(isUnknownMutationOutcome(409)).toBe(false);
        expect(isUnknownMutationOutcome(422)).toBe(false);
    });

    it("replays an uncertain reroute with the exact destination, versions, reason, and key", () => {
        const command = createMutationCommand(
            "reroute",
            "019f0000-0000-7000-8000-000000000001",
            "/patient-communications/threads/019f0000-0000-7000-8000-000000000001/reroute",
            {
                work_item_version: 9,
                thread_version: 11,
                target_pool_uuid: "019f0000-0000-7000-8000-000000000002",
                reason_code: "specialty_needed",
            },
            "Conversation rerouted.",
            () => "019f0000-0000-7000-8000-000000000003",
        );

        expect(mutationRequest(command)).toEqual(mutationRequest(command));
        expect(mutationRequest(command).headers["Idempotency-Key"]).toBe(
            "019f0000-0000-7000-8000-000000000003",
        );
        expect(mutationRequest(command).payload).toEqual({
            work_item_version: 9,
            thread_version: 11,
            target_pool_uuid: "019f0000-0000-7000-8000-000000000002",
            reason_code: "specialty_needed",
        });
    });
});
