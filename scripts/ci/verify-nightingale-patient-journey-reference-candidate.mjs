#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import { execFileSync } from "node:child_process";

const args = process.argv.slice(2);
const selfTest = args.includes("--self-test");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--self-test",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

if (unknownOptions.length > 0 || positional.length > 1) {
    process.stderr.write(
        "Usage: verify-nightingale-patient-journey-reference-candidate.mjs [repository-root] [--self-test]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const candidateDirectory = path.join(
    repoRoot,
    "docs/nightingale/api-contract/candidates/patient-journeys/v0",
);
const candidatePath = path.join(candidateDirectory, "candidate.json");
const fixturesPath = path.join(candidateDirectory, "fixtures.json");
const foundationPath = path.join(
    repoRoot,
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
);

const CANDIDATE_ID = "nightingale.patient-journey-reference.v0-candidate";
const POLICY_VERSION = "nightingale-patient-journey-reference.v0-candidate";
const REVIEWED_SOURCE_COMMIT = "a825db89ec1efaf4b2c55a26e04d41161445b001";
const STATUS = "synthetic_reference_only_not_approved_for_implementation";
const FIXED_TIME = "2026-07-27T17:00:00Z";
const NEGATIVE_SELF_TEST_COUNT = 23;

const SOURCE_PATHS = [
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
    "docs/nightingale/api-contract/candidates/encounter-access/v0/candidate.json",
    "docs/nightingale/api-contract/candidates/encounter-access/v0/fixtures.json",
    "docs/nightingale/api-contract/candidates/today/v0/candidate.json",
    "docs/nightingale/api-contract/candidates/today/v0/fixtures.json",
    "docs/nightingale/identity/candidates/v0/candidate.json",
    "docs/nightingale/identity/candidates/v0/fixtures.json",
    "docs/nightingale/source-candidates/current-inpatient/v0/candidate.json",
    "docs/nightingale/source-candidates/current-inpatient/v0/fixtures.json",
    "docs/nightingale/migration/candidates/v0/communication-notification-source-classification.json",
    "docs/nightingale/migration/candidates/v0/journey-preference-presentation-release-source-classification.json",
    "docs/hummingbird/ZEPHYRUS-HUMMINGBIRD-FUNCTIONAL-PARITY-AND-PATIENT-EXPERIENCE-PLAN-2026-07-19.md",
];

const FAMILY_CASES = {
    admission: ["adult_admission_context_established"],
    "unit-transfer": ["unit_transfer_old_location_withdrawn"],
    procedure: ["procedure_plan_released_without_outcome_inference"],
    "ancillary-test-and-result": [
        "ancillary_test_delay_released",
        "ancillary_result_not_released",
        "ancillary_result_separately_released",
    ],
    "pre-round-question": [
        "pre_round_question_server_accepted",
        "pre_round_response_separately_released",
    ],
    "shift-handoff": ["shift_handoff_preserves_thread_accountability"],
    "discharge-estimate": ["discharge_estimate_corrected"],
    "discharge-with-open-thread": ["discharge_closes_open_thread"],
    "identity-correction": ["identity_correction_revokes_old_context"],
    "representative-lifecycle": [
        "representative_invited_without_access",
        "representative_scoped_candidate",
        "representative_scope_expired",
        "representative_scope_revoked",
    ],
    "language-and-interpreter": ["limited_english_interpreter_support"],
    accommodations: [
        "visual_accommodation_equivalence",
        "hearing_accommodation_equivalence",
        "motor_accommodation_equivalence",
        "cognitive_accommodation_equivalence",
        "low_literacy_teach_back_equivalence",
    ],
    "sensitive-data": ["sensitive_data_fails_closed"],
    "source-outage-and-staleness": [
        "source_outage_sections_not_available",
        "stale_projection_policy_evaluated_per_field",
    ],
    "content-retraction-and-correction": [
        "incorrect_content_retracted",
        "incorrect_content_corrected_replacement",
    ],
};

const GLOBAL_APPROVAL_GATES = [
    "named-product-and-contract-owner",
    "approved-self-identity-session-and-recovery-design",
    "approved-current-inpatient-source-and-lifecycle-design",
    "approved-operation-specific-authorization-and-nondisclosure-matrix",
    "approved-field-source-release-freshness-uncertainty-correction-language-and-offline-matrix",
    "approved-clinical-content-and-patient-language",
    "approved-communication-routing-replay-urgency-and-patient-visible-state-model",
    "approved-representative-consent-scope-expiry-revocation-and-sensitive-service-policy",
    "approved-accessibility-language-interpreter-and-accommodation-design",
    "approved-privacy-security-threat-and-clinical-hazard-controls",
    "approved-audit-monitoring-support-incident-rollback-and-kill-switch-plan",
    "canonical-backend-ios-android-fixture-parity",
    "default-off-nonproduction-integration-approval",
];

const CONSTRAINT_KEYS = [
    "implementation_permitted",
    "executable_contract_path_permitted",
    "route_registration_permitted",
    "controller_or_provider_binding_permitted",
    "client_generation_permitted",
    "native_networking_permitted",
    "identity_provider_selection_permitted",
    "source_adapter_selection_permitted",
    "database_query_permitted",
    "production_data_use_permitted",
    "production_mutation_permitted",
    "patient_or_representative_creation_permitted",
    "clinical_content_release_permitted",
    "communication_mutation_permitted",
    "notification_delivery_permitted",
    "offline_phi_storage_permitted",
    "migration_execution_permitted",
    "deployment_permitted",
    "pilot_activation_permitted",
];

const REQUIRED_FINDINGS = [
    "generated_artifacts_match_builder",
    "every_fixture_is_synthetic_and_contains_no_phi",
    "every_case_is_held_and_non_runnable",
    "every_case_requires_all_global_approval_gates",
    "every_disclosed_value_requires_separate_release",
    "every_response_is_atomic_and_no_store",
    "unavailable_and_released_empty_are_distinct",
    "source_fact_does_not_equal_patient_release",
    "server_acceptance_does_not_equal_staff_delivery_or_review",
    "notification_delivery_is_not_available",
    "representative_access_remains_held",
    "sensitive_service_requests_fail_closed_without_existence_disclosure",
    "identity_correction_revokes_old_handles_and_requires_reproof",
    "source_outage_never_presents_last_known_value_as_current",
    "stale_display_requires_field_specific_approval_and_notice",
    "correction_replaces_and_retraction_withholds",
    "accessibility_changes_never_remove_information_or_actions",
    "no_offline_phi_or_queued_mutation_is_permitted",
    "production_data_was_not_used",
];

const SHARED_NONDISCLOSURE = [
    "no-source-system-identifier",
    "no-patient-principal-grant-encounter-or-staff-identifier",
    "no-unreleased-clinical-value",
    "no-withheld-resource-existence-or-denial-reason",
    "no-internal-routing-pool-or-staffing-detail",
    "no-approval-actor-or-internal-correction-target",
];

const SHARED_AUDIT = [
    "journey-evaluation-with-policy-and-case-id",
    "authorization-and-current-context-outcome",
    "disclosed-field-keys-without-values",
    "communication-state-transition-without-message-body",
    "withhold-correction-retraction-or-outage-reason-code-without-clinical-content",
];

const ALLOWED_SURFACES = new Set([
    "today",
    "my-path",
    "care-team",
    "communication",
]);
const ALLOWED_ACTORS = new Set(["self", "representative-candidate"]);
const ALLOWED_NOTIFICATION_STATES = new Set([
    "not-available",
    "held-until-provider-and-payload-approval",
]);
const FORBIDDEN_KEYS = new Set([
    "patient_id",
    "patient_uuid",
    "patient_ref",
    "principal_id",
    "principal_uuid",
    "principal_ref",
    "encounter_id",
    "encounter_uuid",
    "encounter_ref",
    "grant_id",
    "grant_uuid",
    "grant_ref",
    "staff_id",
    "staff_uuid",
    "staff_ref",
    "source_id",
    "source_uuid",
    "source_ref",
    "message_body",
    "clinical_value",
]);

function fail(message) {
    throw new Error(
        `Nightingale patient-journey reference violation: ${message}`,
    );
}

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function assertExactArray(actual, expected, label) {
    if (
        !Array.isArray(actual) ||
        actual.length !== expected.length ||
        actual.some((value, index) => value !== expected[index])
    ) {
        fail(`${label} must be exact and ordered`);
    }
}

function collectKeysAndStrings(value, keys = [], strings = []) {
    if (Array.isArray(value)) {
        for (const child of value) collectKeysAndStrings(child, keys, strings);
        return { keys, strings };
    }
    if (value === null || typeof value !== "object") {
        if (typeof value === "string") strings.push(value);
        return { keys, strings };
    }
    for (const [key, child] of Object.entries(value)) {
        keys.push(key);
        collectKeysAndStrings(child, keys, strings);
    }
    return { keys, strings };
}

function validate(candidate, fixtures, foundation, verifySources = true) {
    if (candidate.candidate_id !== CANDIDATE_ID) fail("candidate_id drift");
    if (candidate.policy_version !== POLICY_VERSION)
        fail("policy_version drift");
    if (candidate.status !== STATUS) fail("candidate status must remain held");
    if (candidate.reviewed_source_commit !== REVIEWED_SOURCE_COMMIT) {
        fail("reviewed source commit drift");
    }
    if (candidate.reviewed_at !== "2026-07-27") fail("review date drift");

    if (
        foundation.paths === null ||
        typeof foundation.paths !== "object" ||
        Array.isArray(foundation.paths) ||
        Object.keys(foundation.paths).length !== 0
    ) {
        fail("executable foundation contract must retain zero paths");
    }
    const activation = foundation["x-nightingale-activation"];
    const activationBooleans = [
        "routes_registered",
        "route_registration_permitted",
        "network_clients_permitted",
        "identity_enabled",
        "inpatient_source_enabled",
        "production_source_query_permitted",
        "patient_disclosure_enabled",
        "patient_mutation_enabled",
        "production_enabled",
    ];
    if (
        activation === null ||
        typeof activation !== "object" ||
        activation.default !== "disabled" ||
        activationBooleans.some((key) => activation[key] !== false)
    ) {
        fail("every executable foundation activation must remain false");
    }

    const executable = candidate.executable_boundary;
    if (
        executable.foundation_contract_path_count !== 0 ||
        executable.candidate_operation_count !== 0 ||
        executable.runtime_binding_count !== 0 ||
        executable.native_client_operation_count !== 0 ||
        executable.route_namespace_reserved_only !== "/api/nightingale/v1"
    ) {
        fail("executable boundary drift");
    }

    assertExactArray(
        Object.keys(candidate.global_constraints),
        CONSTRAINT_KEYS,
        "global constraint keys",
    );
    for (const [key, value] of Object.entries(candidate.global_constraints)) {
        if (value !== false) fail(`global constraint ${key} must remain false`);
    }
    assertExactArray(
        candidate.approval_gates,
        GLOBAL_APPROVAL_GATES,
        "global approval gates",
    );

    if (candidate.family_count !== 15 || candidate.case_count !== 27) {
        fail("expected exactly 15 families and 27 cases");
    }
    if (fixtures.case_count !== 27 || fixtures.cases.length !== 27) {
        fail("fixture count must remain 27");
    }
    if (
        fixtures.candidate_id !== CANDIDATE_ID ||
        fixtures.policy_version !== POLICY_VERSION ||
        fixtures.fixed_clock !== FIXED_TIME ||
        fixtures.fixture_class !== "synthetic-no-phi"
    ) {
        fail("fixture metadata drift");
    }

    const expectedFamilyIds = Object.keys(FAMILY_CASES);
    assertExactArray(
        candidate.families.map(({ family_id }) => family_id),
        expectedFamilyIds,
        "family ids",
    );
    candidate.families.forEach((family) => {
        assertExactArray(
            family.required_case_ids,
            FAMILY_CASES[family.family_id],
            `family ${family.family_id} case ids`,
        );
    });

    const expectedCaseIds = Object.values(FAMILY_CASES).flat();
    assertExactArray(
        candidate.required_case_ids,
        expectedCaseIds,
        "candidate required case ids",
    );
    assertExactArray(
        fixtures.cases.map(({ case_id }) => case_id),
        expectedCaseIds,
        "fixture case ids",
    );
    if (new Set(candidate.required_case_ids).size !== expectedCaseIds.length) {
        fail("case ids must be unique");
    }

    const familyByCase = new Map();
    for (const [familyId, caseIds] of Object.entries(FAMILY_CASES)) {
        for (const caseId of caseIds) familyByCase.set(caseId, familyId);
    }

    for (const fixture of fixtures.cases) {
        if (fixture.family_id !== familyByCase.get(fixture.case_id)) {
            fail(`fixture ${fixture.case_id} family drift`);
        }
        if (
            fixture.fixture_class !== "synthetic-no-phi" ||
            fixture.execution_status !== "held-not-runnable"
        ) {
            fail(`fixture ${fixture.case_id} must remain synthetic and held`);
        }
        if (!ALLOWED_ACTORS.has(fixture.actor)) {
            fail(`fixture ${fixture.case_id} actor is not allowed`);
        }
        if (
            typeof fixture.event !== "string" ||
            fixture.event.length === 0 ||
            !Array.isArray(fixture.preconditions) ||
            fixture.preconditions.length === 0
        ) {
            fail(`fixture ${fixture.case_id} lacks event or preconditions`);
        }
        assertExactArray(
            fixture.required_approval_gates.slice(
                0,
                GLOBAL_APPROVAL_GATES.length,
            ),
            GLOBAL_APPROVAL_GATES,
            `fixture ${fixture.case_id} global approval prefix`,
        );
        if (
            fixture.required_approval_gates.length <=
            GLOBAL_APPROVAL_GATES.length
        ) {
            fail(
                `fixture ${fixture.case_id} needs a scenario-specific approval`,
            );
        }

        const expected = fixture.expected;
        if (
            expected.disclosure.values_must_be_separately_released !== true ||
            expected.disclosure.atomic_response_required !== true ||
            expected.disclosure.no_store_required !== true
        ) {
            fail(`fixture ${fixture.case_id} weakened disclosure controls`);
        }
        if (
            !Array.isArray(expected.disclosure.surfaces) ||
            expected.disclosure.surfaces.some(
                (surface) => !ALLOWED_SURFACES.has(surface),
            )
        ) {
            fail(`fixture ${fixture.case_id} has an invalid surface`);
        }
        if (
            typeof expected.disclosure.patient_message_key !== "string" ||
            !/^journey\.[a-z0-9_.]+$/.test(
                expected.disclosure.patient_message_key,
            )
        ) {
            fail(`fixture ${fixture.case_id} must use a governed message key`);
        }
        assertExactArray(
            expected.nondisclosure,
            SHARED_NONDISCLOSURE,
            `fixture ${fixture.case_id} nondisclosure rules`,
        );
        assertExactArray(
            expected.audit,
            SHARED_AUDIT,
            `fixture ${fixture.case_id} audit rules`,
        );
        if (
            expected.offline.phi_cache_permitted !== false ||
            expected.offline.stale_value_may_be_presented_as_current !==
                false ||
            expected.offline.queued_mutation_permitted !== false ||
            expected.offline
                .last_known_value_requires_separate_approved_policy !== true
        ) {
            fail(`fixture ${fixture.case_id} weakened offline controls`);
        }
        if (
            expected.accessibility
                .essential_information_in_imagery_permitted !== false ||
            expected.accessibility.color_only_state_permitted !== false ||
            expected.accessibility.patient_action_loss_permitted !== false
        ) {
            fail(`fixture ${fixture.case_id} weakened accessibility controls`);
        }
        if (
            expected.rollback.kill_switch_required !== true ||
            expected.rollback.release_withdrawal_required !== true ||
            expected.rollback
                .cached_handle_and_volatile_draft_purge_required !== true
        ) {
            fail(`fixture ${fixture.case_id} weakened rollback controls`);
        }
        if (
            !ALLOWED_NOTIFICATION_STATES.has(
                expected.communication.notification_state,
            )
        ) {
            fail(`fixture ${fixture.case_id} implies notification delivery`);
        }
        if (
            /(delivered-to|read-by|reviewed-by)/.test(
                expected.communication.accepted_state_meaning,
            )
        ) {
            fail(`fixture ${fixture.case_id} overstates server acceptance`);
        }
        if (
            !Array.isArray(expected.prohibited_inferences) ||
            expected.prohibited_inferences.length === 0
        ) {
            fail(`fixture ${fixture.case_id} needs prohibited inferences`);
        }
    }

    const caseById = new Map(
        fixtures.cases.map((fixture) => [fixture.case_id, fixture]),
    );
    for (const caseId of FAMILY_CASES["representative-lifecycle"]) {
        const fixture = caseById.get(caseId);
        if (
            fixture.expected.disclosure.mode !== "generic-withhold" ||
            fixture.expected.disclosure.surfaces.length !== 0
        ) {
            fail("representative scenarios must remain fully held");
        }
    }
    const sensitive = caseById.get("sensitive_data_fails_closed");
    if (
        sensitive.expected.disclosure.mode !== "generic-withhold" ||
        sensitive.expected.disclosure.surfaces.length !== 0 ||
        !sensitive.expected.prohibited_inferences.includes(
            "sensitive-service-exists",
        )
    ) {
        fail(
            "sensitive-data scenario must fail closed without existence disclosure",
        );
    }
    if (
        !caseById
            .get("identity_correction_revokes_old_context")
            .expected.state_transitions.includes(
                "new-access-requires-approved-reproof-and-authoritative-linkage",
            )
    ) {
        fail("identity correction must require reproof");
    }
    if (
        !caseById
            .get("source_outage_sections_not_available")
            .expected.state_transitions.includes(
                "last-known-value-not-presented-as-current",
            )
    ) {
        fail("source outage must not present last known data as current");
    }
    if (
        !caseById
            .get("incorrect_content_retracted")
            .expected.state_transitions.includes(
                "withdrawn-value-removed-atomically-from-all-surfaces",
            )
    ) {
        fail("retraction must withdraw content atomically");
    }

    assertExactArray(
        Object.keys(candidate.required_findings),
        REQUIRED_FINDINGS,
        "required finding keys",
    );
    for (const [key, value] of Object.entries(candidate.required_findings)) {
        if (value !== true) fail(`required finding ${key} must remain true`);
    }

    const { keys, strings } = collectKeysAndStrings(fixtures);
    for (const key of keys) {
        if (FORBIDDEN_KEYS.has(key)) fail(`forbidden fixture key ${key}`);
    }
    const fixtureText = JSON.stringify(fixtures);
    if (
        /\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i.test(
            fixtureText,
        ) ||
        /\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/.test(fixtureText) ||
        /\/api\/(?:patient|hummingbird|nightingale)\//.test(fixtureText)
    ) {
        fail("fixtures contain a concrete identifier, email, or API path");
    }
    if (strings.some((value) => value.startsWith("http://"))) {
        fail("fixtures contain an insecure URL");
    }

    assertExactArray(
        candidate.evidence.sources.map(({ path: sourcePath }) => sourcePath),
        SOURCE_PATHS,
        "evidence source paths",
    );
    if (candidate.evidence.source_count !== SOURCE_PATHS.length) {
        fail("evidence source count drift");
    }
    if (verifySources) {
        for (const source of candidate.evidence.sources) {
            const absolutePath = path.join(repoRoot, source.path);
            if (!fs.existsSync(absolutePath)) {
                fail(`missing evidence source ${source.path}`);
            }
            const actual = sha256(fs.readFileSync(absolutePath));
            if (source.sha256 !== actual) {
                fail(`evidence source digest changed: ${source.path}`);
            }
        }
    }
    const sourceInventoryDigest = sha256(
        candidate.evidence.sources
            .map((source) => `${source.path}\0${source.sha256}`)
            .sort()
            .join("\n"),
    );
    if (candidate.evidence.source_inventory_digest !== sourceInventoryDigest) {
        fail("source inventory digest drift");
    }
    const fixturesSerialized = `${JSON.stringify(fixtures, null, 4)}\n`;
    if (candidate.evidence.fixtures_sha256 !== sha256(fixturesSerialized)) {
        fail("fixture digest drift");
    }
}

function clone(value) {
    return structuredClone(value);
}

function verifyBuilderOutput(candidate, fixtures) {
    const builderPath = path.join(
        repoRoot,
        "scripts/ci/build-nightingale-patient-journey-reference-candidate.mjs",
    );
    const generated = JSON.parse(
        execFileSync(process.execPath, [builderPath, repoRoot], {
            encoding: "utf8",
        }),
    );
    if (
        JSON.stringify(generated.candidate) !== JSON.stringify(candidate) ||
        JSON.stringify(generated.fixtures) !== JSON.stringify(fixtures)
    ) {
        fail(
            "checked-in candidate or fixtures do not match the deterministic builder",
        );
    }
}

function runSelfTests(candidate, fixtures, foundation) {
    const mutations = [
        [
            "foundation path added",
            (c, f, foundationCopy) => {
                foundationCopy.paths["/forbidden"] = { get: {} };
            },
        ],
        [
            "constraint enabled",
            (c) => {
                c.global_constraints.route_registration_permitted = true;
            },
        ],
        [
            "required finding softened",
            (c) => {
                c.required_findings.generated_artifacts_match_builder = false;
            },
        ],
        [
            "family removed",
            (c) => {
                c.families.pop();
            },
        ],
        [
            "case removed",
            (c, f) => {
                f.cases.pop();
                f.case_count -= 1;
            },
        ],
        [
            "case duplicated",
            (c, f) => {
                f.cases[1].case_id = f.cases[0].case_id;
            },
        ],
        [
            "phi identifier key introduced",
            (c, f) => {
                f.cases[0].patient_uuid = "synthetic";
            },
        ],
        [
            "concrete UUID introduced",
            (c, f) => {
                f.cases[0].preconditions.push(
                    "123e4567-e89b-42d3-a456-426614174000",
                );
            },
        ],
        [
            "runtime status enabled",
            (c, f) => {
                f.cases[0].execution_status = "runnable";
            },
        ],
        [
            "global approval removed",
            (c, f) => {
                f.cases[0].required_approval_gates.shift();
            },
        ],
        [
            "separate release disabled",
            (c, f) => {
                f.cases[0].expected.disclosure.values_must_be_separately_released = false;
            },
        ],
        [
            "no-store disabled",
            (c, f) => {
                f.cases[0].expected.disclosure.no_store_required = false;
            },
        ],
        [
            "offline PHI enabled",
            (c, f) => {
                f.cases[0].expected.offline.phi_cache_permitted = true;
            },
        ],
        [
            "queued mutation enabled",
            (c, f) => {
                f.cases[0].expected.offline.queued_mutation_permitted = true;
            },
        ],
        [
            "representative disclosure enabled",
            (c, f) => {
                const fixture = f.cases.find(
                    ({ case_id }) =>
                        case_id === "representative_scoped_candidate",
                );
                fixture.expected.disclosure.surfaces = ["today"];
                fixture.expected.disclosure.mode = "released-fields-only";
            },
        ],
        [
            "sensitive existence disclosure enabled",
            (c, f) => {
                const fixture = f.cases.find(
                    ({ case_id }) => case_id === "sensitive_data_fails_closed",
                );
                fixture.expected.prohibited_inferences = ["resource-type"];
            },
        ],
        [
            "notification delivery implied",
            (c, f) => {
                f.cases[0].expected.communication.notification_state =
                    "delivered";
            },
        ],
        [
            "server acceptance overstated",
            (c, f) => {
                f.cases[0].expected.communication.accepted_state_meaning =
                    "delivered-to-care-team";
            },
        ],
        [
            "evidence source digest changed",
            (c) => {
                c.evidence.sources[0].sha256 = "0".repeat(64);
            },
        ],
        [
            "fixture digest changed",
            (c) => {
                c.evidence.fixtures_sha256 = "0".repeat(64);
            },
        ],
        [
            "patient message key replaced with prose",
            (c, f) => {
                f.cases[0].expected.disclosure.patient_message_key =
                    "Please review this information.";
            },
        ],
        [
            "stale value presented as current",
            (c, f) => {
                f.cases[0].expected.offline.stale_value_may_be_presented_as_current = true;
            },
        ],
        [
            "accessibility action loss enabled",
            (c, f) => {
                f.cases[0].expected.accessibility.patient_action_loss_permitted = true;
            },
        ],
    ];
    if (mutations.length !== NEGATIVE_SELF_TEST_COUNT) {
        fail("negative self-test count drift");
    }

    for (const [label, mutate] of mutations) {
        const candidateCopy = clone(candidate);
        const fixturesCopy = clone(fixtures);
        const foundationCopy = clone(foundation);
        mutate(candidateCopy, fixturesCopy, foundationCopy);
        let rejected = false;
        try {
            validate(candidateCopy, fixturesCopy, foundationCopy, false);
        } catch {
            rejected = true;
        }
        if (!rejected) fail(`negative self-test was not rejected: ${label}`);
    }
}

try {
    const candidate = JSON.parse(fs.readFileSync(candidatePath, "utf8"));
    const fixtures = JSON.parse(fs.readFileSync(fixturesPath, "utf8"));
    const foundation = JSON.parse(fs.readFileSync(foundationPath, "utf8"));
    validate(candidate, fixtures, foundation);
    verifyBuilderOutput(candidate, fixtures);
    if (selfTest) runSelfTests(candidate, fixtures, foundation);
    process.stdout.write(
        `Nightingale patient-journey reference candidate verified: 15 families, 27 synthetic cases${selfTest ? `, ${NEGATIVE_SELF_TEST_COUNT} negative self-tests` : ""}.\n`,
    );
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
}
