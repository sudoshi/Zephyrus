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
        `Nightingale encounter-access candidate violation: ${message}\n`,
    );
    process.exit(1);
}

if (unknownOptions.length > 0)
    fail(`unknown option(s): ${unknownOptions.join(", ")}`);
if (positional.length > 1)
    fail("expected at most one repository-root argument");

const repoRoot = path.resolve(positional[0] ?? ".");
const candidateDirectory = path.join(
    repoRoot,
    "docs/nightingale/api-contract/candidates/encounter-access/v0",
);
const candidatePath = path.join(candidateDirectory, "candidate.json");
const fixturesPath = path.join(candidateDirectory, "fixtures.json");
const foundationPath = path.join(
    repoRoot,
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
);

const CANDIDATE_ID = "nightingale.encounter-access.v0-candidate";
const POLICY_VERSION = "nightingale-encounter-access-policy.v0-candidate";
const ROUTE_NAMESPACE = "/api/nightingale/v1";
const CANDIDATE_PATH = "/inpatient-contexts";
const OPERATION_ID = "listNightingaleInpatientContexts";
const HANDLE_PATTERN = /^ntg_enc_[a-z2-7]{50}$/;
const REQUEST_PATTERN = /^ntg_req_[a-z2-7]{50}$/;
const ISO_INSTANT_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

const METADATA_FIELDS = [
    "request_id",
    "generated_at",
    "policy_version",
    "authorization_evaluated_at",
    "completeness",
];

const EXCLUDED_LEGACY_FIELDS = [
    "encounter_uuid",
    "grant_uuid",
    "relationship",
    "scopes",
    "valid_from",
    "expires_at",
    "version",
    "principal_uuid",
    "identity_link_uuid",
    "source_encounter_id",
    "source_encounter_ref",
    "source_system_key",
];

const ELIGIBILITY_GATES = [
    "approved-candidate-operation-in-active-contract",
    "product-operation-facility-cohort-default-off-gates",
    "patient-realm-principal-session-and-assurance",
    "verified-unmerged-identity-link-owned-by-principal",
    "self-relationship-only",
    "approved-inpatient-purpose-of-use",
    "active-nonrevoked-effective-grant",
    "current-source-encounter-linkage-and-status",
    "nightingale-owned-random-handle-mapping",
    "single-eligible-context-in-initial-release",
    "authorization-recheck-before-serialization",
    "durable-request-and-disclosure-audit",
    "patient-safe-no-store-response",
];

const APPROVAL_GATES = [
    "named-product-contract-backend-identity-privacy-security-accessibility-support-and-release-owners",
    "route-and-compatibility-adr",
    "identity-proofing-session-recovery-and-representative-decision",
    "facility-unit-cohort-and-inpatient-source-definition",
    "opaque-handle-generation-storage-rotation-and-collision-design",
    "authorization-and-nondisclosure-implementation-tests",
    "canonical-backend-ios-android-fixture-parity",
    "threat-model-and-privacy-security-review",
    "patient-advisor-accessibility-language-and-support-review",
    "default-off-nonproduction-integration-and-rollback",
];

const REQUIRED_HEADERS = {
    "Cache-Control": "private, no-store, max-age=0",
    Pragma: "no-cache",
    "X-Robots-Tag": "noindex, nofollow, noarchive",
    Vary: "Authorization",
    "Content-Type": "application/json",
};

const TEMPLATE_STATUS = {
    success_one: 200,
    success_empty: 200,
    not_found: 404,
    authentication_required: 401,
    access_unavailable: 403,
    account_state_requires_review: 409,
    rate_limited: 429,
    temporarily_unavailable: 503,
};

const FAILURE_CODES = Object.fromEntries(
    Object.entries(TEMPLATE_STATUS).filter(
        ([templateName]) => !templateName.startsWith("success_"),
    ),
);

const CASE_TEMPLATE = {
    eligible_self_single: "success_one",
    no_candidate_grants: "success_empty",
    revoked_grant_omitted: "success_empty",
    suspended_grant_omitted: "success_empty",
    pending_grant_omitted: "success_empty",
    closed_grant_omitted: "success_empty",
    expired_status_grant_omitted: "success_empty",
    revoked_timestamp_omitted: "success_empty",
    future_validity_omitted: "success_empty",
    expired_window_omitted: "success_empty",
    wrong_principal_omitted: "success_empty",
    purpose_mismatch_omitted: "success_empty",
    representative_relationship_held: "success_empty",
    source_encounter_closed_omitted: "success_empty",
    product_disabled: "not_found",
    operation_disabled: "not_found",
    authentication_missing: "authentication_required",
    wrong_identity_realm: "access_unavailable",
    principal_inactive: "access_unavailable",
    principal_locked: "access_unavailable",
    session_missing: "authentication_required",
    session_expired: "authentication_required",
    session_revoked: "authentication_required",
    identity_link_missing: "account_state_requires_review",
    identity_link_revoked: "account_state_requires_review",
    identity_link_merged: "account_state_requires_review",
    identity_principal_mismatch: "account_state_requires_review",
    unknown_relationship: "temporarily_unavailable",
    unknown_grant_status: "temporarily_unavailable",
    unknown_purpose_of_use: "temporarily_unavailable",
    malformed_nightingale_handle: "temporarily_unavailable",
    nightingale_handle_collision: "temporarily_unavailable",
    source_link_missing: "account_state_requires_review",
    source_system_unavailable: "temporarily_unavailable",
    database_unavailable: "temporarily_unavailable",
    request_audit_unavailable: "temporarily_unavailable",
    disclosure_audit_unavailable: "temporarily_unavailable",
    multiple_eligible_contexts: "account_state_requires_review",
    grant_changed_before_serialization: "account_state_requires_review",
    policy_version_mismatch: "temporarily_unavailable",
    malformed_scope_registry: "temporarily_unavailable",
    rate_limited: "rate_limited",
};

const CASE_AUDIT = {
    eligible_self_single: "durable_evaluation_and_disclosure",
    no_candidate_grants: "durable_evaluation",
    revoked_grant_omitted: "durable_evaluation",
    suspended_grant_omitted: "durable_evaluation",
    pending_grant_omitted: "durable_evaluation",
    closed_grant_omitted: "durable_evaluation",
    expired_status_grant_omitted: "durable_evaluation",
    revoked_timestamp_omitted: "durable_evaluation",
    future_validity_omitted: "durable_evaluation",
    expired_window_omitted: "durable_evaluation",
    wrong_principal_omitted: "durable_evaluation",
    purpose_mismatch_omitted: "durable_evaluation",
    representative_relationship_held: "durable_evaluation",
    source_encounter_closed_omitted: "durable_evaluation",
    product_disabled: "best_effort_denial",
    operation_disabled: "best_effort_denial",
    authentication_missing: "best_effort_denial",
    wrong_identity_realm: "best_effort_denial",
    principal_inactive: "best_effort_denial",
    principal_locked: "best_effort_denial",
    session_missing: "best_effort_denial",
    session_expired: "best_effort_denial",
    session_revoked: "best_effort_denial",
    identity_link_missing: "durable_safety_failure",
    identity_link_revoked: "durable_safety_failure",
    identity_link_merged: "durable_safety_failure",
    identity_principal_mismatch: "durable_safety_failure",
    unknown_relationship: "durable_safety_failure",
    unknown_grant_status: "durable_safety_failure",
    unknown_purpose_of_use: "durable_safety_failure",
    malformed_nightingale_handle: "durable_safety_failure",
    nightingale_handle_collision: "durable_safety_failure",
    source_link_missing: "durable_safety_failure",
    source_system_unavailable: "best_effort_safety_failure",
    database_unavailable: "best_effort_safety_failure",
    request_audit_unavailable: "best_effort_safety_failure",
    disclosure_audit_unavailable: "best_effort_safety_failure",
    multiple_eligible_contexts: "durable_safety_failure",
    grant_changed_before_serialization: "durable_safety_failure",
    policy_version_mismatch: "durable_safety_failure",
    malformed_scope_registry: "durable_safety_failure",
    rate_limited: "best_effort_denial",
};

function parseJson(filePath) {
    if (!fs.existsSync(filePath)) fail(`missing ${filePath}`);
    try {
        const raw = fs.readFileSync(filePath, "utf8");
        return {
            document: JSON.parse(raw),
            raw,
        };
    } catch (error) {
        fail(`invalid JSON in ${filePath}: ${error.message}`);
    }
}

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function sortedKeys(value) {
    return isRecord(value) ? Object.keys(value).sort() : [];
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

function sameRecord(actual, expected) {
    return (
        isRecord(actual) &&
        JSON.stringify(Object.fromEntries(Object.entries(actual).sort())) ===
            JSON.stringify(Object.fromEntries(Object.entries(expected).sort()))
    );
}

function inspect(candidate, fixtures, foundation, candidateRaw, fixtureRaw) {
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
    assert(
        foundation?.["x-nightingale-contract"]?.route_namespace ===
            ROUTE_NAMESPACE &&
            foundation?.["x-nightingale-contract"]?.route_namespace_reserved ===
                true,
        "foundation route namespace decision changed",
    );
    assert(
        foundation?.["x-nightingale-activation"]
            ?.route_registration_permitted === false &&
            foundation?.["x-nightingale-activation"]?.routes_registered ===
                false,
        "foundation route activation changed",
    );
    assert(
        candidate.artifact_kind === "nightingale-nonrunnable-api-candidate",
        "candidate kind changed",
    );
    assert(
        candidate.candidate_id === CANDIDATE_ID,
        "candidate identity changed",
    );
    assert(
        candidate.status === "held-no-operation",
        "candidate status is not held-no-operation",
    );

    const operation = candidate.operation;
    assert(
        sameMembers(sortedKeys(operation), [
            "method",
            "path",
            "operation_id",
            "openapi_inclusion",
            "route_namespace_reserved",
            "route_registration_permitted",
            "client_generation_permitted",
            "network_client_permitted",
        ]),
        "candidate operation fields changed",
    );
    assert(operation?.method === "GET", "candidate method must remain GET");
    assert(
        operation?.path === CANDIDATE_PATH,
        "candidate path decision changed",
    );
    assert(
        operation?.operation_id === OPERATION_ID,
        "candidate operation id decision changed",
    );
    for (const field of [
        "openapi_inclusion",
        "route_registration_permitted",
        "client_generation_permitted",
        "network_client_permitted",
    ]) {
        assert(operation?.[field] === false, `${field} must remain false`);
    }

    const activation = candidate.activation;
    assert(
        sameMembers(sortedKeys(activation), [
            "product",
            "operation",
            "identity",
            "disclosure",
            "nonproduction_integration",
            "production",
        ]),
        "candidate activation fields changed",
    );
    for (const field of [
        "product",
        "operation",
        "identity",
        "disclosure",
        "nonproduction_integration",
        "production",
    ]) {
        assert(
            activation?.[field] === false,
            `activation.${field} must remain false`,
        );
    }
    assert(
        operation?.route_namespace_reserved === true,
        "candidate route namespace is not reserved",
    );

    assert(
        sameMembers(candidate.audience?.permitted_relationships, ["self"]),
        "only self relationship may be a candidate",
    );
    assert(
        candidate.audience?.representative_access === "held",
        "representative access is not held",
    );
    assert(
        candidate.audience?.maximum_eligible_inpatient_contexts === 1,
        "initial candidate must support at most one eligible context",
    );

    const response = candidate.response;
    assert(response?.success_status === 200, "success status changed");
    assert(
        response?.maximum_encounters === 1,
        "response maximum must remain one encounter",
    );
    assert(
        sameMembers(response?.entry_fields, ["encounter_handle"]),
        "candidate response entry must contain only encounter_handle",
    );
    assert(
        response?.encounter_handle_pattern === "^ntg_enc_[a-z2-7]{50}$",
        "encounter handle pattern changed",
    );
    assert(
        sameMembers(response?.metadata_fields, METADATA_FIELDS),
        "candidate metadata fields changed",
    );
    assert(
        sameMembers(response?.excluded_legacy_fields, EXCLUDED_LEGACY_FIELDS),
        "legacy-field exclusion list changed",
    );
    assert(
        response?.cache_control === REQUIRED_HEADERS["Cache-Control"],
        "candidate cache-control decision changed",
    );
    assert(
        response?.state_vocabulary_applies === false,
        "irrelevant state vocabulary was attached",
    );
    assert(
        response?.durable_client_storage_permitted === false,
        "durable client storage was permitted",
    );
    assert(
        sameMembers(candidate.eligibility_gates, ELIGIBILITY_GATES),
        "eligibility gate list changed",
    );
    assert(
        sameRecord(candidate.failure_codes, FAILURE_CODES),
        "candidate failure-code mapping changed",
    );
    assert(
        sameRecord(candidate.audit, {
            request_event: "nightingale.encounter_access.evaluated",
            disclosure_event: "nightingale.encounter_handle.disclosed",
            success_and_empty_result_require_durable_audit: true,
            raw_handle_recording_permitted: false,
            source_identifier_recording_permitted: false,
            free_text_recording_permitted: false,
        }),
        "candidate audit contract changed",
    );
    assert(
        sameMembers(candidate.approval_gates, APPROVAL_GATES),
        "approval gate list changed",
    );

    assert(
        fixtures.fixture_schema ===
            "nightingale.encounter-access.candidate-fixtures.v0",
        "fixture schema changed",
    );
    assert(
        fixtures.candidate_id === CANDIDATE_ID,
        "fixture candidate identity changed",
    );
    assert(fixtures.synthetic_only === true, "fixtures are not synthetic-only");
    assert(
        fixtures.production_replay_permitted === false,
        "production fixture replay was permitted",
    );

    const templates = fixtures.response_templates;
    assert(
        sameMembers(sortedKeys(templates), Object.keys(TEMPLATE_STATUS)),
        "response template set changed",
    );

    for (const [templateName, expectedStatus] of Object.entries(
        TEMPLATE_STATUS,
    )) {
        const template = templates?.[templateName];
        assert(isRecord(template), `missing response template ${templateName}`);
        if (!isRecord(template)) continue;

        assert(
            template.status === expectedStatus,
            `${templateName} status changed`,
        );
        assert(
            sameRecord(template.headers, REQUIRED_HEADERS),
            `${templateName} headers changed`,
        );
        assert(
            sameMembers(
                sortedKeys(template.body),
                expectedStatus === 200
                    ? ["data", "meta", "links"]
                    : ["data", "error", "meta", "links"],
            ),
            `${templateName} body keys changed`,
        );
        assert(
            isRecord(template.body?.links) &&
                Object.keys(template.body.links).length === 0,
            `${templateName} links must be empty`,
        );

        const meta = template.body?.meta;
        assert(
            sameMembers(sortedKeys(meta), response?.metadata_fields ?? []),
            `${templateName} metadata keys changed`,
        );
        assert(
            REQUEST_PATTERN.test(meta?.request_id ?? ""),
            `${templateName} request id is not synthetic opaque data`,
        );
        assert(
            meta?.policy_version === POLICY_VERSION,
            `${templateName} policy version changed`,
        );
        assert(
            typeof meta?.generated_at === "string" &&
                ISO_INSTANT_PATTERN.test(meta.generated_at) &&
                !Number.isNaN(Date.parse(meta.generated_at)),
            `${templateName} generated_at is invalid`,
        );
        assert(
            typeof meta?.authorization_evaluated_at === "string" &&
                ISO_INSTANT_PATTERN.test(meta.authorization_evaluated_at) &&
                !Number.isNaN(Date.parse(meta.authorization_evaluated_at)),
            `${templateName} authorization_evaluated_at is invalid`,
        );
        assert(
            meta?.generated_at === meta?.authorization_evaluated_at,
            `${templateName} synthetic evaluation timestamps diverged`,
        );

        if (expectedStatus === 200) {
            assert(
                meta?.completeness === "complete",
                `${templateName} must be complete`,
            );
            assert(
                sameMembers(sortedKeys(template.body?.data), ["encounters"]),
                `${templateName} data keys changed`,
            );
            const encounters = template.body?.data?.encounters;
            assert(
                Array.isArray(encounters),
                `${templateName} encounters must be an array`,
            );
            assert(
                (encounters?.length ?? 2) <= 1,
                `${templateName} disclosed more than one encounter`,
            );
            for (const encounter of encounters ?? []) {
                assert(
                    sameMembers(sortedKeys(encounter), ["encounter_handle"]),
                    `${templateName} encounter fields changed`,
                );
                assert(
                    HANDLE_PATTERN.test(encounter.encounter_handle ?? ""),
                    `${templateName} encounter handle is malformed`,
                );
            }
        } else {
            assert(
                template.body?.data === null,
                `${templateName} error data must be null`,
            );
            assert(
                meta?.completeness === "withheld",
                `${templateName} error must be withheld`,
            );
            assert(
                sameMembers(sortedKeys(template.body?.error), [
                    "code",
                    "message",
                ]),
                `${templateName} error keys changed`,
            );
            assert(
                template.body?.error?.code === templateName,
                `${templateName} error code changed`,
            );
            assert(
                typeof template.body?.error?.message === "string" &&
                    template.body.error.message.length >= 20 &&
                    template.body.error.message.length <= 180,
                `${templateName} error message is not bounded patient language`,
            );
        }

        const serializedBody = JSON.stringify(template.body);
        for (const forbiddenField of EXCLUDED_LEGACY_FIELDS) {
            assert(
                !serializedBody.includes(`"${forbiddenField}"`),
                `${templateName} exposes forbidden legacy field ${forbiddenField}`,
            );
        }
        assert(
            !serializedBody.includes("state_vocabulary_version"),
            `${templateName} includes an irrelevant vocabulary version`,
        );
    }

    const cases = fixtures.cases;
    assert(Array.isArray(cases), "fixture cases must be an array");
    const caseIds = (cases ?? []).map((fixture) => fixture.case_id);
    assert(
        new Set(caseIds).size === caseIds.length,
        "fixture case ids are not unique",
    );
    assert(
        sameMembers(
            candidate.required_fixture_case_ids,
            Object.keys(CASE_TEMPLATE),
        ),
        "candidate required fixture set changed",
    );
    assert(
        sameMembers(caseIds, Object.keys(CASE_TEMPLATE)),
        "fixture case coverage changed",
    );

    for (const fixture of cases ?? []) {
        const caseId = fixture.case_id;
        assert(
            fixture.expected_template === CASE_TEMPLATE[caseId],
            `${caseId} expected template changed`,
        );
        assert(
            fixture.audit_mode === CASE_AUDIT[caseId],
            `${caseId} audit mode changed`,
        );
        assert(
            typeof fixture.category === "string" && fixture.category.length > 0,
            `${caseId} category is missing`,
        );
        assert(
            Array.isArray(fixture.preconditions) &&
                fixture.preconditions.length > 0 &&
                fixture.preconditions.every(
                    (precondition) =>
                        typeof precondition === "string" &&
                        precondition.length >= 12,
                ),
            `${caseId} preconditions are incomplete`,
        );
    }

    for (const forbidden of [
        "Hummingbird",
        "/api/patient",
        "/api/mobile",
        "pgsql.acumenus.net",
        "zephyrus.acumenus.net",
        "password",
        "access_token",
        "refresh_token",
        "source_encounter_id",
        "source_system_key",
    ]) {
        assert(
            !fixtureRaw.includes(forbidden),
            `fixtures contain forbidden production or legacy token: ${forbidden}`,
        );
    }
    for (const forbidden of [
        "pgsql.acumenus.net",
        "zephyrus.acumenus.net",
        "password",
        "smudoshi",
    ]) {
        assert(
            !candidateRaw.includes(forbidden),
            `candidate contains forbidden production or credential token: ${forbidden}`,
        );
    }

    return violations;
}

function cloned(value) {
    return JSON.parse(JSON.stringify(value));
}

function runNegativeSelfTests(candidate, fixtures, foundation) {
    const cases = [
        {
            name: "candidate path drift",
            expected: "candidate path decision changed",
            mutate(candidateCopy) {
                candidateCopy.operation.path = "/encounters";
            },
        },
        {
            name: "operation activation",
            expected: "openapi_inclusion must remain false",
            mutate(candidateCopy) {
                candidateCopy.operation.openapi_inclusion = true;
            },
        },
        {
            name: "second encounter disclosure",
            expected: "success_one disclosed more than one encounter",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_one.body.data.encounters.push(
                    cloned(
                        fixturesCopy.response_templates.success_one.body.data
                            .encounters[0],
                    ),
                );
            },
        },
        {
            name: "legacy field disclosure",
            expected: "success_one encounter fields changed",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_one.body.data.encounters[0].grant_uuid =
                    "00000000-0000-4000-8000-000000000001";
            },
        },
        {
            name: "fixture outcome weakening",
            expected: "identity_link_revoked expected template changed",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.cases.find(
                    (fixture) => fixture.case_id === "identity_link_revoked",
                ).expected_template = "success_empty";
            },
        },
        {
            name: "production replay",
            expected: "production fixture replay was permitted",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.production_replay_permitted = true;
            },
        },
    ];

    for (const testCase of cases) {
        const candidateCopy = cloned(candidate);
        const fixturesCopy = cloned(fixtures);
        const foundationCopy = cloned(foundation);
        testCase.mutate(candidateCopy, fixturesCopy, foundationCopy);
        const violations = inspect(
            candidateCopy,
            fixturesCopy,
            foundationCopy,
            JSON.stringify(candidateCopy),
            JSON.stringify(fixturesCopy),
        );
        if (!violations.includes(testCase.expected)) {
            fail(
                `negative self-test "${testCase.name}" did not produce expected rejection: ${testCase.expected}`,
            );
        }
    }
}

const { document: candidate, raw: candidateRaw } = parseJson(candidatePath);
const { document: fixtures, raw: fixtureRaw } = parseJson(fixturesPath);
const { document: foundation } = parseJson(foundationPath);

const violations = inspect(
    candidate,
    fixtures,
    foundation,
    candidateRaw,
    fixtureRaw,
);
if (violations.length > 0) fail(violations.join("; "));
if (selfTest) runNegativeSelfTests(candidate, fixtures, foundation);

process.stdout.write(
    `Nightingale encounter-access candidate fixtures verified${selfTest ? " with negative self-tests" : ""}\n`,
);
