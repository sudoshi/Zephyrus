#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const args = process.argv.slice(2);
const selfTest = args.includes("--self-test");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--self-test",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

function fail(message) {
    process.stderr.write(
        `Nightingale identity/source candidate violation: ${message}\n`,
    );
    process.exit(1);
}

if (unknownOptions.length > 0)
    fail(`unknown option(s): ${unknownOptions.join(", ")}`);
if (positional.length > 1)
    fail("expected at most one repository-root argument");

const repoRoot = path.resolve(positional[0] ?? ".");
const files = {
    foundation: "docs/nightingale/api-contract/nightingale-foundation.v0.json",
    identityCandidate: "docs/nightingale/identity/candidates/v0/candidate.json",
    identityFixtures: "docs/nightingale/identity/candidates/v0/fixtures.json",
    sourceCandidate:
        "docs/nightingale/source-candidates/current-inpatient/v0/candidate.json",
    sourceFixtures:
        "docs/nightingale/source-candidates/current-inpatient/v0/fixtures.json",
    config: "config/nightingale.php",
    identityEnum: "app/Nightingale/Identity/NightingaleIdentityState.php",
    sourceEnum: "app/Nightingale/Inpatient/NightingaleInpatientSourceState.php",
};

function read(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath)) fail(`missing ${relativePath}`);
    return fs.readFileSync(absolutePath, "utf8");
}

function parseJson(relativePath) {
    try {
        const raw = read(relativePath);
        return { document: JSON.parse(raw), raw };
    } catch (error) {
        fail(`invalid JSON in ${relativePath}: ${error.message}`);
    }
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function sameMembers(actual, expected) {
    return (
        Array.isArray(actual) &&
        actual.length === expected.length &&
        [...actual]
            .sort()
            .every((value, index) => value === [...expected].sort()[index])
    );
}

function everyFalse(record) {
    return (
        isRecord(record) &&
        Object.values(record).length > 0 &&
        Object.values(record).every((value) => value === false)
    );
}

function buildCaseMap(groups) {
    return Object.fromEntries(
        groups.flatMap(([result, auditMode, caseIds]) =>
            caseIds.map((caseId) => [caseId, [result, auditMode]]),
        ),
    );
}

const IDENTITY_CASES = buildCaseMap([
    [
        "verified_self",
        "durable_evaluation",
        ["verified_self_all_prerequisites", "recovery_fresh_proof_complete"],
    ],
    [
        "unavailable",
        "best_effort_unavailable",
        [
            "product_disabled",
            "identity_disabled",
            "provider_unconfigured",
            "provider_unavailable",
            "provider_timeout",
            "evaluation_audit_unavailable",
            "clock_untrusted",
        ],
    ],
    [
        "unavailable",
        "durable_safety_failure",
        [
            "provider_response_malformed",
            "assurance_missing",
            "principal_state_inconsistent",
            "session_wrong_principal",
            "session_state_unknown",
            "session_changed_during_evaluation",
            "identity_link_missing",
            "identity_link_pending",
            "identity_link_ambiguous",
            "identity_link_merged",
            "identity_link_revoked",
            "identity_link_verification_time_missing",
            "identity_link_wrong_principal",
            "identity_link_duplicate_active",
            "identity_link_assurance_mismatch",
            "recovery_old_binding_not_cleared",
            "recovery_superseded_session_active",
            "correlation_context_malformed",
            "session_limit_policy_unresolved",
            "policy_version_mismatch",
            "identity_changed_before_handoff",
        ],
    ],
    [
        "denied",
        "best_effort_denial",
        [
            "authentication_evidence_missing",
            "authentication_evidence_invalid",
            "authentication_evidence_expired",
            "issuer_mismatch",
            "audience_mismatch",
            "client_mismatch",
            "nonce_mismatch",
            "authentication_method_unsupported",
            "assurance_below_policy",
            "step_up_incomplete",
            "principal_missing",
            "wrong_identity_realm",
            "staff_principal_rejected",
            "representative_principal_held",
            "principal_pending",
            "principal_inactive",
            "principal_locked",
            "principal_suspended",
            "principal_closed",
            "session_missing",
            "session_expired",
            "session_idle_expired",
            "session_revoked",
            "recovery_evidence_incomplete",
            "legal_representative_held",
            "guardian_held",
            "caregiver_held",
            "proxy_held",
            "other_relationship_held",
        ],
    ],
    [
        "denied",
        "durable_safety_failure",
        [
            "replay_detected",
            "session_wrong_realm",
            "session_family_reuse_detected",
            "session_risk_hold",
        ],
    ],
    ["denied", "durable_evaluation", ["recovery_requested"]],
]);

const SOURCE_CASES = buildCaseMap([
    [
        "confirmed_current",
        "durable_evaluation",
        ["confirmed_current_all_prerequisites"],
    ],
    [
        "confirmed_closed",
        "durable_evaluation",
        ["confirmed_closed_all_prerequisites"],
    ],
    [
        "unavailable",
        "best_effort_unavailable",
        [
            "product_disabled",
            "source_capability_disabled",
            "source_adapter_unconfigured",
            "source_dependency_unavailable",
            "source_timeout",
            "database_unavailable",
        ],
    ],
    [
        "unavailable",
        "durable_evaluation",
        [
            "no_inpatient_record",
            "facility_out_of_scope",
            "unit_out_of_scope",
            "patient_class_out_of_scope",
        ],
    ],
    [
        "unavailable",
        "durable_safety_failure",
        [
            "source_response_malformed",
            "source_policy_unconfigured",
            "freshness_policy_unapproved",
            "source_observed_at_missing",
            "source_observed_at_stale",
            "clock_untrusted",
            "patient_link_missing",
            "patient_link_unverified",
            "cohort_policy_unavailable",
            "required_field_missing",
        ],
    ],
    ["unavailable", "best_effort_safety_failure", ["source_audit_unavailable"]],
    [
        "inconsistent",
        "durable_safety_failure",
        [
            "multiple_current_records",
            "duplicate_source_link",
            "ambiguous_patient_match",
            "status_missing",
            "status_unknown",
            "record_soft_deleted",
            "active_with_discharge_time",
            "admitted_at_missing",
            "admitted_at_future",
            "discharged_at_missing",
            "discharged_at_future",
            "discharged_before_admitted",
            "lifecycle_inconsistent",
            "source_version_missing",
            "source_changed_during_evaluation",
            "transfer_unresolved",
            "merge_or_correction_pending",
            "record_retracted",
            "policy_version_mismatch",
        ],
    ],
]);

const EXPECTED_IDENTITY_STATES = ["unavailable", "denied", "verified_self"];
const EXPECTED_SOURCE_STATES = [
    "unavailable",
    "inconsistent",
    "confirmed_closed",
    "confirmed_current",
];
const FORBIDDEN_LITERAL_PATTERNS = [
    /(?:postgres(?:ql)?|https?):\/\/\S+/i,
    /\b(?:password|secret|access_token|refresh_token|bearer_token)\b\s*[:=]/i,
    /\b(?:\d{1,3}\.){3}\d{1,3}\b/,
    /@(?:[a-z0-9-]+\.)+[a-z]{2,}/i,
    /\bpatient_ref\b/i,
    /\bencounter_id\b/i,
    /\bsource_encounter_id\b/i,
];

function enumValues(raw) {
    return [...raw.matchAll(/case\s+\w+\s*=\s*'([^']+)';/g)].map(
        (match) => match[1],
    );
}

function inspectFixtureSet(
    candidate,
    fixtures,
    expectedCases,
    expectedStates,
    stateField,
    violations,
    label,
) {
    const assert = (condition, message) => {
        if (!condition) violations.push(`${label}: ${message}`);
    };
    const expectedIds = Object.keys(expectedCases);

    assert(fixtures.synthetic_only === true, "fixtures must be synthetic-only");
    assert(
        fixtures.production_replay_permitted === false,
        "production replay must remain prohibited",
    );
    assert(
        fixtures.contains_credentials === false,
        "fixtures must declare no credentials",
    );
    assert(
        fixtures.contains_source_identifiers === false,
        "fixtures must declare no source identifiers",
    );
    assert(
        candidate.candidate_id === fixtures.candidate_id,
        "candidate and fixture identifiers differ",
    );
    assert(
        sameMembers(candidate.required_fixture_case_ids, expectedIds),
        "candidate required fixture set changed",
    );
    assert(
        Array.isArray(fixtures.cases) &&
            fixtures.cases.length === expectedIds.length,
        `fixture count must remain ${expectedIds.length}`,
    );

    const actualIds = fixtures.cases?.map((entry) => entry.case_id) ?? [];
    assert(
        new Set(actualIds).size === actualIds.length,
        "fixture case identifiers must be unique",
    );
    assert(
        sameMembers(actualIds, expectedIds),
        "fixture case identifier set changed",
    );

    for (const fixtureCase of fixtures.cases ?? []) {
        const expected = expectedCases[fixtureCase.case_id];
        assert(
            expected !== undefined,
            `unexpected case ${fixtureCase.case_id}`,
        );
        if (!expected) continue;
        assert(
            fixtureCase.expected_result === expected[0],
            `${fixtureCase.case_id} must yield ${expected[0]}`,
        );
        assert(
            fixtureCase.audit_mode === expected[1],
            `${fixtureCase.case_id} must use ${expected[1]}`,
        );
        assert(
            Array.isArray(fixtureCase.preconditions) &&
                fixtureCase.preconditions.length > 0 &&
                fixtureCase.preconditions.every(
                    (value) => typeof value === "string" && value.length > 0,
                ),
            `${fixtureCase.case_id} needs explicit preconditions`,
        );
    }

    assert(
        sameMembers(
            Object.keys(fixtures.result_templates ?? {}),
            expectedStates,
        ),
        "result template state set changed",
    );
    for (const state of expectedStates) {
        const template = fixtures.result_templates?.[state];
        assert(template?.[stateField] === state, `${state} template drifted`);
        assert(
            template?.authorizes_patient_access === false,
            `${state} must never authorize patient access`,
        );
        assert(
            template?.permits_governed_evaluation ===
                (state === "verified_self" || state === "confirmed_current"),
            `${state} governed-evaluation disposition drifted`,
        );
    }
}

function inspect(input) {
    const {
        foundation,
        identityCandidate,
        identityFixtures,
        sourceCandidate,
        sourceFixtures,
        configRaw,
        identityEnumRaw,
        sourceEnumRaw,
        allCandidateRaw,
    } = input;
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(
        foundation?.["x-nightingale-contract"]?.governance_status ===
            "foundation-no-operations",
        "foundation governance status changed",
    );
    assert(
        isRecord(foundation?.paths) &&
            Object.keys(foundation.paths).length === 0,
        "foundation paths must remain empty",
    );
    const foundationActivation = foundation?.["x-nightingale-activation"];
    assert(
        foundationActivation?.routes_registered === false &&
            foundationActivation?.route_registration_permitted === false &&
            foundationActivation?.network_clients_permitted === false &&
            foundationActivation?.identity_enabled === false &&
            foundationActivation?.inpatient_source_enabled === false &&
            foundationActivation?.production_source_query_permitted === false &&
            foundationActivation?.patient_disclosure_enabled === false &&
            foundationActivation?.production_enabled === false,
        "foundation activation must remain fail-closed",
    );

    const configPatterns = [
        /'status'\s*=>\s*'foundation'/,
        /'routes_registered'\s*=>\s*false/,
        /'network_clients_permitted'\s*=>\s*false/,
        /'identity'\s*=>\s*\[[\s\S]*?'enabled'\s*=>\s*false[\s\S]*?'provider'\s*=>\s*null/,
        /'inpatient_context'\s*=>\s*\[[\s\S]*?'enabled'\s*=>\s*false[\s\S]*?'source_adapter'\s*=>\s*null[\s\S]*?'production_query_permitted'\s*=>\s*false/,
        /'patient_disclosure_enabled'\s*=>\s*false/,
        /'patient_mutation_enabled'\s*=>\s*false/,
        /'production_enabled'\s*=>\s*false/,
    ];
    for (const pattern of configPatterns)
        assert(pattern.test(configRaw), `config lost invariant ${pattern}`);

    assert(
        sameMembers(enumValues(identityEnumRaw), EXPECTED_IDENTITY_STATES),
        "PHP identity enum and candidate state vocabulary differ",
    );
    assert(
        sameMembers(enumValues(sourceEnumRaw), EXPECTED_SOURCE_STATES),
        "PHP source enum and candidate state vocabulary differ",
    );

    assert(
        identityCandidate.artifact_kind ===
            "nightingale-nonrunnable-identity-candidate" &&
            identityCandidate.candidate_id ===
                "nightingale.identity-session-recovery.v0-candidate" &&
            identityCandidate.status === "held-no-provider-no-credential",
        "identity candidate identity or held status changed",
    );
    const identityRuntime = identityCandidate.runtime;
    for (const field of [
        "route",
        "operation_id",
        "provider",
        "authentication_method",
        "credential_format",
        "refresh_credential",
        "enrollment_channel",
        "recovery_channel",
    ])
        assert(
            identityRuntime?.[field] === null,
            `identity runtime ${field} must remain null`,
        );
    assert(
        identityRuntime?.service_binding_permitted === false &&
            identityRuntime?.client_implementation_permitted === false,
        "identity runtime binding/client must remain prohibited",
    );
    assert(
        everyFalse(identityCandidate.activation),
        "all identity activation gates must remain false",
    );
    assert(
        identityCandidate.audience?.initial_relationship === "self" &&
            identityCandidate.audience?.representative_access === "held" &&
            identityCandidate.audience?.staff_realm_accepted === false &&
            identityCandidate.audience?.legacy_patient_realm_accepted === false,
        "identity audience boundary changed",
    );
    assert(
        sameMembers(
            Object.keys(identityCandidate.identity_states ?? {}),
            EXPECTED_IDENTITY_STATES,
        ),
        "identity candidate state set changed",
    );
    for (const state of EXPECTED_IDENTITY_STATES) {
        const semantics = identityCandidate.identity_states?.[state];
        assert(
            semantics?.authorizes_patient_access === false,
            `identity ${state} must not authorize patient access`,
        );
        assert(
            semantics?.permits_governed_evaluation ===
                (state === "verified_self"),
            `identity ${state} governed-evaluation disposition drifted`,
        );
    }
    assert(
        identityCandidate.protected_state?.access_credential_persistence ===
            "prohibited" &&
            identityCandidate.protected_state?.durable_device_identifier ===
                "prohibited" &&
            identityCandidate.protected_state
                ?.backup_or_cross_product_migration === "prohibited",
        "identity protected-state prohibition changed",
    );

    assert(
        sourceCandidate.artifact_kind ===
            "nightingale-nonrunnable-inpatient-source-candidate" &&
            sourceCandidate.candidate_id ===
                "nightingale.current-inpatient-source.v0-candidate" &&
            sourceCandidate.status === "held-no-adapter-no-query",
        "source candidate identity or held status changed",
    );
    const sourceRuntime = sourceCandidate.runtime;
    for (const field of [
        "route",
        "operation_id",
        "source_adapter",
        "query_contract",
        "database_connection",
    ])
        assert(
            sourceRuntime?.[field] === null,
            `source runtime ${field} must remain null`,
        );
    assert(
        sourceRuntime?.service_binding_permitted === false &&
            sourceRuntime?.query_implementation_permitted === false &&
            sourceRuntime?.production_query_permitted === false,
        "source binding or query must remain prohibited",
    );
    assert(
        everyFalse(sourceCandidate.activation),
        "all source activation gates must remain false",
    );
    assert(
        sourceCandidate.freshness_policy?.policy_version === null &&
            sourceCandidate.freshness_policy?.evaluation_clock === null &&
            sourceCandidate.freshness_policy?.maximum_source_age_seconds ===
                null &&
            sourceCandidate.freshness_policy?.thresholds_approved === false &&
            sourceCandidate.freshness_policy
                ?.hard_coded_thresholds_permitted === false,
        "source freshness policy must remain held without guessed thresholds",
    );
    assert(
        sameMembers(
            Object.keys(sourceCandidate.source_states ?? {}),
            EXPECTED_SOURCE_STATES,
        ),
        "source candidate state set changed",
    );
    for (const state of EXPECTED_SOURCE_STATES) {
        const semantics = sourceCandidate.source_states?.[state];
        assert(
            semantics?.authorizes_patient_access === false,
            `source ${state} must not authorize patient access`,
        );
        assert(
            semantics?.permits_governed_evaluation ===
                (state === "confirmed_current"),
            `source ${state} governed-evaluation disposition drifted`,
        );
    }

    inspectFixtureSet(
        identityCandidate,
        identityFixtures,
        IDENTITY_CASES,
        EXPECTED_IDENTITY_STATES,
        "identity_state",
        violations,
        "identity",
    );
    inspectFixtureSet(
        sourceCandidate,
        sourceFixtures,
        SOURCE_CASES,
        EXPECTED_SOURCE_STATES,
        "source_state",
        violations,
        "source",
    );

    for (const pattern of FORBIDDEN_LITERAL_PATTERNS)
        assert(
            !pattern.test(allCandidateRaw),
            `candidate artifacts contain forbidden literal ${pattern}`,
        );

    return violations;
}

const foundation = parseJson(files.foundation);
const identityCandidate = parseJson(files.identityCandidate);
const identityFixtures = parseJson(files.identityFixtures);
const sourceCandidate = parseJson(files.sourceCandidate);
const sourceFixtures = parseJson(files.sourceFixtures);
const baseInput = {
    foundation: foundation.document,
    identityCandidate: identityCandidate.document,
    identityFixtures: identityFixtures.document,
    sourceCandidate: sourceCandidate.document,
    sourceFixtures: sourceFixtures.document,
    configRaw: read(files.config),
    identityEnumRaw: read(files.identityEnum),
    sourceEnumRaw: read(files.sourceEnum),
    allCandidateRaw: [
        identityCandidate.raw,
        identityFixtures.raw,
        sourceCandidate.raw,
        sourceFixtures.raw,
    ].join("\n"),
};

const violations = inspect(baseInput);
if (violations.length > 0) fail(violations.join("; "));

if (selfTest) {
    const mutations = [
        {
            name: "identity provider selection",
            mutate(input) {
                input.identityCandidate.runtime.provider = "legacy-local";
            },
        },
        {
            name: "representative activation",
            mutate(input) {
                input.identityCandidate.activation.representatives = true;
            },
        },
        {
            name: "identity precondition grants access",
            mutate(input) {
                input.identityCandidate.identity_states.verified_self.authorizes_patient_access = true;
            },
        },
        {
            name: "weakened identity fixture",
            mutate(input) {
                input.identityFixtures.cases.find(
                    (entry) => entry.case_id === "session_wrong_principal",
                ).expected_result = "verified_self";
            },
        },
        {
            name: "production fixture replay",
            mutate(input) {
                input.identityFixtures.production_replay_permitted = true;
            },
        },
        {
            name: "source query activation",
            mutate(input) {
                input.sourceCandidate.runtime.production_query_permitted = true;
            },
        },
        {
            name: "stale source called current",
            mutate(input) {
                input.sourceFixtures.cases.find(
                    (entry) => entry.case_id === "source_observed_at_stale",
                ).expected_result = "confirmed_current";
            },
        },
        {
            name: "runtime operation added",
            mutate(input) {
                input.foundation.paths["/inpatient-contexts"] = { get: {} };
            },
        },
        {
            name: "configured source adapter",
            mutate(input) {
                input.configRaw = input.configRaw.replace(
                    "'source_adapter' => null",
                    "'source_adapter' => 'legacy'",
                );
            },
        },
    ];

    for (const mutation of mutations) {
        const input = {
            ...baseInput,
            foundation: clone(baseInput.foundation),
            identityCandidate: clone(baseInput.identityCandidate),
            identityFixtures: clone(baseInput.identityFixtures),
            sourceCandidate: clone(baseInput.sourceCandidate),
            sourceFixtures: clone(baseInput.sourceFixtures),
        };
        mutation.mutate(input);
        if (inspect(input).length === 0)
            fail(`self-test failed to reject ${mutation.name}`);
    }
}

process.stdout.write(
    `Nightingale identity/source candidate verified (${Object.keys(IDENTITY_CASES).length} identity cases, ${Object.keys(SOURCE_CASES).length} source cases${selfTest ? ", negative self-tests passed" : ""}).\n`,
);
