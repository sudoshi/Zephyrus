import { describe, expect, it } from "vitest";
import { scenarioFromApiEnvelope } from "@/Pages/CarePathways/Demo";

describe("care pathway demo API envelope", () => {
    it("extracts a valid scenario", () => {
        const scenario = {
            meta: { current_step: 2 },
            steps: [{ index: 2 }],
        };

        expect(scenarioFromApiEnvelope({ data: scenario })).toBe(scenario);
    });

    it("rejects malformed payloads instead of replacing the active scenario", () => {
        expect(() => scenarioFromApiEnvelope(undefined)).toThrow(
            "Invalid care pathway demo response",
        );
        expect(() =>
            scenarioFromApiEnvelope("PHP warning before JSON"),
        ).toThrow("Invalid care pathway demo response");
        expect(() => scenarioFromApiEnvelope({ data: {} })).toThrow(
            "Invalid care pathway demo response",
        );
    });
});
