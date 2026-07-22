export type PatientCommunicationRoutingKind =
    "release" | "reassign" | "reroute";

export interface PatientCommunicationRoutingReason {
    code: string;
    label: string;
}

export interface PatientCommunicationReassignCandidate {
    membership_uuid: string;
    label: string;
    membership_role: "responder" | "triage" | "supervisor";
}

export interface PatientCommunicationRerouteCandidate {
    pool_uuid: string;
    label: string;
    scope_type: "unit" | "facility" | "enterprise";
    unit: { id: number; label: string } | null;
}

export interface PatientCommunicationRouteCandidates {
    work_item_uuid: string;
    work_item_version: number;
    thread_version: number;
    actions: {
        can_release: boolean;
        can_reassign: boolean;
        can_reroute: boolean;
    };
    reason_options: Record<
        PatientCommunicationRoutingKind,
        PatientCommunicationRoutingReason[]
    >;
    reassign_candidates: PatientCommunicationReassignCandidate[];
    reroute_candidates: PatientCommunicationRerouteCandidate[];
}

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const REASON_CODES: Record<PatientCommunicationRoutingKind, Set<string>> = {
    release: new Set([
        "return_to_team",
        "shift_handoff",
        "responder_unavailable",
        "incorrect_assignment",
    ]),
    reassign: new Set([
        "supervisor_assignment",
        "shift_handoff",
        "coverage_change",
        "workload_balance",
    ]),
    reroute: new Set([
        "wrong_team",
        "unit_transfer",
        "service_change",
        "specialty_needed",
    ]),
};

function record(value: unknown): Record<string, unknown> | null {
    return value !== null && typeof value === "object" && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function hasExactKeys(
    value: Record<string, unknown> | null,
    expected: readonly string[],
): boolean {
    if (value === null) return false;
    const actual = Object.keys(value).sort();
    const allowed = [...expected].sort();

    return (
        actual.length === allowed.length &&
        actual.every((key, index) => key === allowed[index])
    );
}

function boundedLabel(value: unknown): string | null {
    if (typeof value !== "string") return null;
    const label = value.trim();
    if (
        label.length === 0 ||
        label.length > 120 ||
        /[\u0000-\u001f\u007f]/u.test(label)
    )
        return null;

    return label;
}

function canonicalUuid(value: unknown): string | null {
    return typeof value === "string" && UUID_PATTERN.test(value) ? value : null;
}

function positiveVersion(value: unknown): number | null {
    return typeof value === "number" && Number.isSafeInteger(value) && value > 0
        ? value
        : null;
}

function parseReasons(
    value: unknown,
    kind: PatientCommunicationRoutingKind,
): PatientCommunicationRoutingReason[] | null {
    if (!Array.isArray(value) || value.length > 12) return null;
    const seen = new Set<string>();
    const parsed: PatientCommunicationRoutingReason[] = [];

    for (const candidate of value) {
        const item = record(candidate);
        if (!hasExactKeys(item, ["code", "label"])) return null;
        const code = item?.code;
        const label = boundedLabel(item?.label);
        if (
            typeof code !== "string" ||
            !REASON_CODES[kind].has(code) ||
            seen.has(code) ||
            label === null
        )
            return null;
        seen.add(code);
        parsed.push({ code, label });
    }

    return parsed;
}

function parseReassignCandidates(
    value: unknown,
): PatientCommunicationReassignCandidate[] | null {
    if (!Array.isArray(value) || value.length > 50) return null;
    const seen = new Set<string>();
    const parsed: PatientCommunicationReassignCandidate[] = [];

    for (const candidate of value) {
        const item = record(candidate);
        if (
            !hasExactKeys(item, ["membership_uuid", "label", "membership_role"])
        )
            return null;
        const membershipUuid = canonicalUuid(item?.membership_uuid);
        const label = boundedLabel(item?.label);
        const membershipRole = item?.membership_role;
        if (
            membershipUuid === null ||
            seen.has(membershipUuid) ||
            label === null ||
            !["responder", "triage", "supervisor"].includes(
                String(membershipRole),
            )
        )
            return null;
        seen.add(membershipUuid);
        parsed.push({
            membership_uuid: membershipUuid,
            label,
            membership_role:
                membershipRole as PatientCommunicationReassignCandidate["membership_role"],
        });
    }

    return parsed;
}

function parseRerouteCandidates(
    value: unknown,
): PatientCommunicationRerouteCandidate[] | null {
    if (!Array.isArray(value) || value.length > 50) return null;
    const seen = new Set<string>();
    const parsed: PatientCommunicationRerouteCandidate[] = [];

    for (const candidate of value) {
        const item = record(candidate);
        if (!hasExactKeys(item, ["pool_uuid", "label", "scope_type", "unit"]))
            return null;
        const poolUuid = canonicalUuid(item?.pool_uuid);
        const label = boundedLabel(item?.label);
        const scopeType = item?.scope_type;
        const unitRecord = item?.unit === null ? null : record(item?.unit);
        if (unitRecord !== null && !hasExactKeys(unitRecord, ["id", "label"]))
            return null;
        const unit =
            unitRecord === null
                ? null
                : {
                      id:
                          typeof unitRecord.id === "number" &&
                          Number.isSafeInteger(unitRecord.id) &&
                          unitRecord.id > 0
                              ? unitRecord.id
                              : 0,
                      label: boundedLabel(unitRecord.label) ?? "",
                  };
        if (
            poolUuid === null ||
            seen.has(poolUuid) ||
            label === null ||
            !["unit", "facility", "enterprise"].includes(String(scopeType)) ||
            (item?.unit !== null &&
                (unit === null || unit.id === 0 || unit.label.length === 0)) ||
            (scopeType === "unit" && unit === null) ||
            (scopeType !== "unit" && unit !== null)
        )
            return null;
        seen.add(poolUuid);
        parsed.push({
            pool_uuid: poolUuid,
            label,
            scope_type:
                scopeType as PatientCommunicationRerouteCandidate["scope_type"],
            unit,
        });
    }

    return parsed;
}

/**
 * Treat routing discovery as an untrusted authorization projection. Any drift,
 * over-broad list, unknown reason, raw identifier, or stale resource tuple
 * fails closed before a routing control is rendered.
 */
export function parsePatientCommunicationRouteCandidates(
    value: unknown,
    expectedWorkItemUuid: string,
): PatientCommunicationRouteCandidates | null {
    const root = record(value);
    const workItemUuid = canonicalUuid(root?.work_item_uuid);
    const workItemVersion = positiveVersion(root?.work_item_version);
    const threadVersion = positiveVersion(root?.thread_version);
    const actions = record(root?.actions);
    const reasonOptions = record(root?.reason_options);
    const release = parseReasons(reasonOptions?.release, "release");
    const reassign = parseReasons(reasonOptions?.reassign, "reassign");
    const reroute = parseReasons(reasonOptions?.reroute, "reroute");
    const reassignCandidates = parseReassignCandidates(
        root?.reassign_candidates,
    );
    const rerouteCandidates = parseRerouteCandidates(root?.reroute_candidates);

    if (
        !hasExactKeys(root, [
            "work_item_uuid",
            "work_item_version",
            "thread_version",
            "actions",
            "reason_options",
            "reassign_candidates",
            "reroute_candidates",
        ]) ||
        !hasExactKeys(actions, [
            "can_release",
            "can_reassign",
            "can_reroute",
        ]) ||
        !hasExactKeys(reasonOptions, ["release", "reassign", "reroute"]) ||
        workItemUuid !== expectedWorkItemUuid ||
        workItemVersion === null ||
        threadVersion === null ||
        typeof actions?.can_release !== "boolean" ||
        typeof actions?.can_reassign !== "boolean" ||
        typeof actions?.can_reroute !== "boolean" ||
        release === null ||
        reassign === null ||
        reroute === null ||
        reassignCandidates === null ||
        rerouteCandidates === null ||
        (actions.can_release && release.length === 0) ||
        (actions.can_reassign &&
            (reassign.length === 0 || reassignCandidates.length === 0)) ||
        (actions.can_reroute &&
            (reroute.length === 0 || rerouteCandidates.length === 0)) ||
        (!actions.can_reassign && reassignCandidates.length !== 0) ||
        (!actions.can_reroute && rerouteCandidates.length !== 0)
    )
        return null;

    return {
        work_item_uuid: workItemUuid,
        work_item_version: workItemVersion,
        thread_version: threadVersion,
        actions: {
            can_release: actions.can_release,
            can_reassign: actions.can_reassign,
            can_reroute: actions.can_reroute,
        },
        reason_options: { release, reassign, reroute },
        reassign_candidates: reassignCandidates,
        reroute_candidates: rerouteCandidates,
    };
}
