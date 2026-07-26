#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const args = process.argv.slice(2);
const selfTest = args.includes("--self-test");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--self-test",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

if (unknownOptions.length > 0) {
    process.stderr.write(
        `Unknown Nightingale Today verifier option(s): ${unknownOptions.join(", ")}\n`,
    );
    process.exit(2);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const candidateDirectory = path.join(
    repoRoot,
    "docs/nightingale/api-contract/candidates/today/v0",
);
const candidatePath = path.join(candidateDirectory, "candidate.json");
const fixturesPath = path.join(candidateDirectory, "fixtures.json");
const foundationPath = path.join(
    repoRoot,
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
);
const sourceLedgerPath = path.join(
    repoRoot,
    "docs/nightingale/migration/candidates/v0/source-classification.json",
);
const finalLedgerPath = path.join(
    repoRoot,
    "docs/nightingale/migration/candidates/v0/journey-preference-presentation-release-source-classification.json",
);

const CANDIDATE_ID = "nightingale.today-projection.v0-candidate";
const POLICY_VERSION = "nightingale-today-policy.v0-candidate";
const CONTENT_POLICY_VERSION = "nightingale-patient-language.v0-candidate";
const ROUTE_NAMESPACE = "/api/nightingale/v1";
const CANDIDATE_PATH = "/inpatient-contexts/{encounter_handle}/today";
const OPERATION_ID = "getNightingaleTodayProjection";
const NEGATIVE_SELF_TEST_COUNT = 24;
const FIXED_TIME = "2026-07-26T15:00:00Z";
const HANDLE_PATTERN = /^ntg_enc_[a-z2-7]{50}$/;
const REQUEST_PATTERN = /^ntg_req_[a-z2-7]{50}$/;
const REVISION_PATTERN = /^ntg_tdyrev_[a-z2-7]{50}$/;
const ITEM_HANDLE_PATTERN = /^ntg_tdyitem_[a-z2-7]{50}$/;
const ISO_INSTANT_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const LOCALE_PATTERN = /^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-\d{3})?$/;

const SOURCE_PATHS = [
    "docs/hummingbird/api-contract/hummingbird-patient.v1.yaml",
    "app/Http/Controllers/Api/Patient/EncounterProjectionController.php",
    "app/Services/Patient/Projection/PatientProjectionDisclosureService.php",
    "app/Services/Patient/Projection/PatientProjectionContentGuard.php",
    "app/Policies/Patient/PatientEncounterProjectionPolicy.php",
    "app/Models/Patient/PatientEncounterProjection.php",
    "database/migrations/2026_07_19_000200_create_patient_experience_projection_kernel.php",
    "tests/Feature/Patient/PatientProjectionApiTest.php",
    "hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIModels.swift",
    "hummingbird/iosPatientApp/HummingbirdPatient/Features/Today/PatientTodayView.swift",
    "hummingbird/iosPatientApp/HummingbirdPatient/App/PatientAppViewModel.swift",
    "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiModels.kt",
    "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientSessionCoordinator.kt",
    "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientExperienceScreen.kt",
];

const SECTION_NAMES = [
    "headline",
    "summary",
    "schedule",
    "next_steps",
    "care_location",
    "discharge_outlook",
    "questions",
    "notices",
];
const OPTIONAL_STATES = ["released", "released-empty", "not-available"];
const FIELD_CONTEXT_FIELDS = [
    "release",
    "freshness",
    "uncertainty",
    "language",
    "correction",
    "offline",
];
const SCHEDULE_STATUSES = [
    "requested",
    "planned",
    "confirmed",
    "in-progress",
    "completed",
    "delayed",
    "canceled",
];
const TIMING_CONFIDENCE = ["confirmed", "estimated", "unknown"];
const LOCATION_STATUSES = ["current", "updating", "unknown"];

const AUTHORIZATION_GATES = [
    "approved-operation-in-active-contract",
    "product-operation-facility-cohort-default-off-gates",
    "approved-nightingale-realm-principal-session-and-assurance",
    "verified-unmerged-self-identity-link",
    "opaque-context-handle-owned-by-principal",
    "authoritative-current-inpatient-context",
    "operation-specific-server-side-today-capability",
    "effective-governed-release-policy",
    "effective-patient-language-release",
    "field-level-freshness-and-uncertainty-decision",
    "field-level-localization-and-correction-decision",
    "authorization-and-context-recheck-before-serialization",
    "durable-request-and-field-disclosure-audit",
    "atomic-no-store-patient-safe-response",
];

const APPROVAL_GATES = [
    "named-product-contract-backend-identity-source-clinical-content-language-privacy-security-accessibility-support-operations-and-release-owners",
    "approved-identity-session-recovery-and-self-link-design",
    "approved-current-inpatient-source-cohort-lifecycle-freshness-and-linkage-design",
    "approved-field-source-release-freshness-uncertainty-correction-translation-and-offline-matrix",
    "approved-patient-state-and-timing-vocabulary",
    "approved-operation-specific-authorization-and-nondisclosure-tests",
    "approved-content-generation-review-release-correction-and-retraction-workflow",
    "canonical-backend-ios-android-fixture-parity",
    "threat-model-and-privacy-security-review",
    "patient-advisor-accessibility-language-and-support-review",
    "default-off-nonproduction-integration-and-rollback",
];

const RESPONSE_STATUS = {
    success_full_current: 200,
    success_minimal: 200,
    success_schedule_empty: 200,
    success_optional_unavailable: 200,
    success_approved_stale: 200,
    success_correction: 200,
    success_approved_translation: 200,
    success_approved_source_language: 200,
    success_delayed_high_uncertainty: 200,
    success_discharge_unknown_timing: 200,
    success_location_updating: 200,
    success_distinct_field_contexts: 200,
    not_found: 404,
    authentication_required: 401,
    access_unavailable: 403,
    account_state_requires_review: 409,
    rate_limited: 429,
    temporarily_unavailable: 503,
};

const groupedCases = {
    success_full_current: ["current_full"],
    success_minimal: ["current_minimal"],
    success_schedule_empty: ["schedule_released_empty"],
    success_optional_unavailable: ["optional_sections_not_available"],
    success_approved_stale: ["approved_stale_with_notice"],
    success_correction: ["corrected_replacement"],
    success_approved_translation: ["approved_translation"],
    success_approved_source_language: ["approved_source_language"],
    success_delayed_high_uncertainty: ["delayed_schedule_high_uncertainty"],
    success_discharge_unknown_timing: ["discharge_outlook_unknown_timing"],
    success_location_updating: ["care_location_updating"],
    success_distinct_field_contexts: ["distinct_field_contexts"],
    not_found: [
        "product_disabled",
        "operation_disabled",
        "facility_disabled",
        "wrong_principal_handle",
        "unknown_handle",
        "malformed_handle",
        "current_context_closed",
        "current_context_changed",
        "today_capability_denied",
        "no_released_projection",
        "draft_projection",
        "future_release",
        "retracted_projection",
        "correction_without_replacement",
        "clinical_approval_missing",
    ],
    authentication_required: [
        "authentication_missing",
        "session_missing",
        "session_expired",
        "session_revoked",
    ],
    access_unavailable: [
        "cohort_ineligible",
        "wrong_identity_realm",
        "principal_inactive",
        "identity_link_missing",
        "identity_link_revoked",
        "representative_relationship_held",
        "sensitive_encounter_held",
    ],
    account_state_requires_review: ["handle_mapping_inconsistent"],
    rate_limited: ["rate_limited"],
    temporarily_unavailable: [
        "source_unavailable",
        "database_unavailable",
        "conflicting_active_releases",
        "policy_version_mismatch",
        "patient_language_approval_missing",
        "translation_approval_missing",
        "locale_mismatch",
        "freshness_decision_missing",
        "unapproved_stale_content",
        "unknown_freshness",
        "mandatory_headline_missing",
        "mandatory_summary_missing",
        "ungoverned_field",
        "aggregate_context_only",
        "internal_identifier_present",
        "staff_only_content_present",
        "invalid_schedule_status",
        "invalid_timing_confidence",
        "invalid_item_handle",
        "duplicate_item_handle",
        "invalid_section_state",
        "released_empty_with_items",
        "not_available_with_content",
        "inconsistent_timestamps",
        "correction_actor_exposed",
        "request_audit_unavailable",
        "disclosure_audit_unavailable",
        "response_serialization_failure",
    ],
};

const CASE_TEMPLATE = Object.fromEntries(
    Object.entries(groupedCases).flatMap(([template, caseIds]) =>
        caseIds.map((caseId) => [caseId, template]),
    ),
);

const CASE_AUDIT = Object.fromEntries(
    Object.entries(CASE_TEMPLATE).map(([caseId, template]) => [
        caseId,
        RESPONSE_STATUS[template] === 200
            ? "durable-evaluation-and-disclosure"
            : [
                    "source_unavailable",
                    "database_unavailable",
                    "handle_mapping_inconsistent",
                    ...groupedCases.temporarily_unavailable,
                ].includes(caseId)
              ? "best-effort-safety-failure"
              : "best-effort-indistinguishable-denial",
    ]),
);

const REQUIRED_HEADERS = {
    "Cache-Control": "private, no-store, max-age=0",
    Pragma: "no-cache",
    "X-Robots-Tag": "noindex, nofollow, noarchive",
    Vary: "Authorization, Accept-Language",
    "Content-Type": "application/json",
};

function parseJson(filePath) {
    try {
        const raw = fs.readFileSync(filePath, "utf8");
        return { document: JSON.parse(raw), raw };
    } catch (error) {
        process.stderr.write(`Unable to parse ${filePath}: ${error.message}\n`);
        process.exit(1);
    }
}

function sha256(filePath) {
    return crypto
        .createHash("sha256")
        .update(fs.readFileSync(filePath))
        .digest("hex");
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

function sameKeys(actual, expected) {
    return (
        sortedKeys(actual).length === expected.length &&
        sortedKeys(actual).every(
            (value, index) => value === [...expected].sort()[index],
        )
    );
}

function sameRecord(actual, expected) {
    return JSON.stringify(actual) === JSON.stringify(expected);
}

function clone(value) {
    return structuredClone(value);
}

function collectGovernedFields(value, result = []) {
    if (Array.isArray(value)) {
        for (const item of value) collectGovernedFields(item, result);
        return result;
    }

    if (!isRecord(value)) return result;

    if (sameKeys(value, ["value", "context"]) && isRecord(value.context)) {
        result.push(value);
        return result;
    }

    for (const child of Object.values(value)) {
        collectGovernedFields(child, result);
    }

    return result;
}

function inspect(
    candidate,
    fixtures,
    foundation,
    sourceLedger,
    finalLedger,
    candidateRaw,
    fixtureRaw,
) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(
        sameKeys(candidate, [
            "artifact_kind",
            "candidate_id",
            "status",
            "purpose",
            "operation",
            "activation",
            "request",
            "audience",
            "response_contract",
            "field_context",
            "content_rules",
            "authorization_gates",
            "failure_codes",
            "audit",
            "evidence",
            "required_fixture_case_ids",
            "approval_gates",
        ]),
        "candidate root fields changed",
    );
    assert(
        candidate.artifact_kind === "nightingale-nonrunnable-api-candidate",
        "candidate kind changed",
    );
    assert(
        candidate.candidate_id === CANDIDATE_ID,
        "candidate identity changed",
    );
    assert(candidate.status === "held-no-operation", "candidate is not held");

    assert(
        sameKeys(candidate.operation, [
            "method",
            "route_namespace",
            "path",
            "operation_id",
            "openapi_inclusion",
            "route_registration_permitted",
            "client_generation_permitted",
            "network_client_permitted",
            "legacy_alias_permitted",
        ]),
        "candidate operation fields changed",
    );
    assert(candidate.operation?.method === "GET", "candidate method changed");
    assert(
        candidate.operation?.route_namespace === ROUTE_NAMESPACE,
        "candidate route namespace changed",
    );
    assert(
        candidate.operation?.path === CANDIDATE_PATH,
        "candidate path changed",
    );
    assert(
        candidate.operation?.operation_id === OPERATION_ID,
        "candidate operation id changed",
    );
    for (const field of [
        "openapi_inclusion",
        "route_registration_permitted",
        "client_generation_permitted",
        "network_client_permitted",
        "legacy_alias_permitted",
    ]) {
        assert(
            candidate.operation?.[field] === false,
            `candidate operation permission ${field} became enabled`,
        );
    }

    const activation = candidate.activation;
    assert(isRecord(activation), "activation record is missing");
    assert(
        Object.keys(activation ?? {}).length === 11,
        "activation field set changed",
    );
    for (const [field, value] of Object.entries(activation ?? {})) {
        assert(value === false, `activation ${field} became enabled`);
    }

    assert(
        candidate.request?.path_parameter === "encounter_handle",
        "request handle name changed",
    );
    assert(
        candidate.request?.encounter_handle_pattern ===
            "^ntg_enc_[a-z2-7]{50}$",
        "request handle pattern changed",
    );
    assert(
        sameMembers(candidate.request?.query_parameters, []),
        "query parameters were introduced",
    );
    for (const field of [
        "request_body_permitted",
        "source_identifier_input_permitted",
        "legacy_identifier_input_permitted",
        "durable_client_storage_permitted",
    ]) {
        assert(
            candidate.request?.[field] === false,
            `request permission ${field} became enabled`,
        );
    }

    assert(
        sameMembers(candidate.audience?.permitted_relationships, ["self"]),
        "candidate audience is not self-only",
    );
    assert(
        candidate.audience?.representative_access === "held",
        "representative access is not held",
    );
    assert(
        candidate.audience?.sensitive_encounter_access === "held",
        "sensitive encounter access is not held",
    );

    const responseContract = candidate.response_contract;
    assert(
        sameMembers(responseContract?.envelope_fields, [
            "data",
            "meta",
            "links",
        ]),
        "response envelope fields changed",
    );
    assert(
        sameMembers(responseContract?.data_fields, [
            "kind",
            "content_revision",
            "sections",
            "revision_notice",
        ]),
        "response data fields changed",
    );
    assert(responseContract?.kind === "today", "response kind changed");
    assert(
        responseContract?.content_revision_pattern ===
            "^ntg_tdyrev_[a-z2-7]{50}$",
        "content revision pattern changed",
    );
    assert(
        responseContract?.schedule_item_handle_pattern ===
            "^ntg_tdyitem_[a-z2-7]{50}$",
        "schedule item handle pattern changed",
    );
    assert(
        sameMembers(responseContract?.section_names, SECTION_NAMES),
        "Today section set changed",
    );
    assert(
        sameMembers(responseContract?.mandatory_released_sections, [
            "headline",
            "summary",
        ]),
        "mandatory released sections changed",
    );
    assert(
        sameMembers(responseContract?.optional_section_states, OPTIONAL_STATES),
        "section states changed",
    );
    assert(
        sameMembers(responseContract?.governed_value_fields, [
            "value",
            "context",
        ]),
        "governed-value fields changed",
    );
    assert(
        sameMembers(
            responseContract?.field_context_fields,
            FIELD_CONTEXT_FIELDS,
        ),
        "field context fields changed",
    );
    for (const field of [
        "root_aggregate_provenance_permitted",
        "root_aggregate_freshness_permitted",
        "root_aggregate_uncertainty_permitted",
        "patient_visible_value_without_field_context_permitted",
        "released_empty_means_no_care_planned",
        "not_available_means_no_care_planned",
        "durable_client_cache_permitted",
    ]) {
        assert(
            responseContract?.[field] === false,
            `response safety decision ${field} weakened`,
        );
    }
    assert(
        responseContract?.cache_control === "private, no-store, max-age=0",
        "cache-control decision changed",
    );

    const fieldContext = candidate.field_context;
    assert(
        sameMembers(fieldContext?.release_fields, [
            "state",
            "released_at",
            "content_policy_version",
        ]),
        "release context fields changed",
    );
    assert(
        sameMembers(fieldContext?.release_states, ["released"]),
        "release states changed",
    );
    assert(
        sameMembers(fieldContext?.freshness_fields, [
            "status",
            "observed_at",
            "patient_notice",
        ]),
        "freshness context fields changed",
    );
    assert(
        sameMembers(fieldContext?.freshness_states, [
            "current",
            "approved-stale",
        ]),
        "freshness states changed",
    );
    assert(
        fieldContext?.approved_stale_requires_patient_notice === true,
        "approved stale notice is no longer required",
    );
    assert(
        sameMembers(fieldContext?.uncertainty_fields, [
            "level",
            "explanation",
            "can_change",
        ]),
        "uncertainty context fields changed",
    );
    assert(
        sameMembers(fieldContext?.uncertainty_levels, [
            "low",
            "medium",
            "high",
            "unknown",
        ]),
        "uncertainty levels changed",
    );
    assert(
        sameMembers(fieldContext?.language_fields, [
            "locale",
            "release_state",
            "plain_language_review",
        ]),
        "language context fields changed",
    );
    assert(
        sameMembers(fieldContext?.language_release_states, [
            "approved-source-language",
            "approved-translation",
        ]),
        "language release states changed",
    );
    assert(
        sameMembers(fieldContext?.plain_language_review_states, ["approved"]),
        "plain-language review states changed",
    );
    assert(
        sameMembers(fieldContext?.correction_fields, [
            "state",
            "patient_notice",
        ]),
        "correction context fields changed",
    );
    assert(
        sameMembers(fieldContext?.correction_states, ["original", "corrected"]),
        "correction states changed",
    );
    assert(
        fieldContext?.corrected_requires_patient_notice === true,
        "corrected field notice is no longer required",
    );
    assert(
        sameMembers(fieldContext?.offline_fields, [
            "availability",
            "durable_storage_permitted",
        ]),
        "offline context fields changed",
    );
    assert(
        sameMembers(fieldContext?.offline_availability, ["online-only"]),
        "offline availability changed",
    );
    assert(
        fieldContext?.offline_durable_storage_permitted === false,
        "offline durable storage became permitted",
    );

    assert(
        sameMembers(
            candidate.content_rules?.schedule_statuses,
            SCHEDULE_STATUSES,
        ),
        "schedule vocabulary changed",
    );
    assert(
        sameMembers(
            candidate.content_rules?.timing_confidence,
            TIMING_CONFIDENCE,
        ),
        "timing-confidence vocabulary changed",
    );
    assert(
        sameMembers(
            candidate.content_rules?.care_location_statuses,
            LOCATION_STATUSES,
        ),
        "care-location vocabulary changed",
    );
    assert(
        sameMembers(candidate.content_rules?.correction_notice_kinds, [
            "correction",
        ]),
        "correction notice kinds changed",
    );
    for (const field of [
        "exact_time_or_eta_claim_permitted",
        "raw_source_text_permitted",
        "staff_only_text_permitted",
        "internal_identifier_permitted",
    ]) {
        assert(
            candidate.content_rules?.[field] === false,
            `content prohibition ${field} weakened`,
        );
    }
    for (const field of [
        "field_level_locale_required",
        "field_level_release_required",
        "field_level_freshness_required",
        "field_level_uncertainty_required",
        "field_level_correction_required",
        "field_level_offline_rule_required",
    ]) {
        assert(
            candidate.content_rules?.[field] === true,
            `field governance requirement ${field} weakened`,
        );
    }

    assert(
        sameMembers(candidate.authorization_gates, AUTHORIZATION_GATES),
        "authorization gates changed",
    );
    assert(
        sameRecord(candidate.failure_codes, {
            not_found: 404,
            authentication_required: 401,
            access_unavailable: 403,
            account_state_requires_review: 409,
            rate_limited: 429,
            temporarily_unavailable: 503,
        }),
        "failure code mapping changed",
    );
    assert(
        sameRecord(candidate.audit, {
            request_event: "nightingale.today.evaluated",
            disclosure_event: "nightingale.today.fields_disclosed",
            success_requires_durable_request_audit: true,
            success_requires_durable_field_disclosure_audit: true,
            raw_patient_value_recording_permitted: false,
            raw_handle_recording_permitted: false,
            source_identifier_recording_permitted: false,
            staff_actor_recording_permitted: false,
            free_text_recording_permitted: false,
        }),
        "audit contract changed",
    );
    assert(
        sameMembers(candidate.approval_gates, APPROVAL_GATES),
        "approval gates changed",
    );

    assert(
        sameKeys(candidate.evidence, [
            "product_universe_source_count",
            "product_universe_inventory_digest",
            "classification_ids",
            "direct_sources",
        ]),
        "candidate evidence fields changed",
    );
    assert(
        sourceLedger.classification_id ===
            "nightingale-source-classification.v1",
        "source classification ledger identity changed",
    );
    assert(
        finalLedger.classification_id ===
            "nightingale-journey-preference-presentation-release-source-classification.v1",
        "final source classification ledger identity changed",
    );
    assert(
        finalLedger.product_universe?.source_count === 255,
        "product universe source count changed",
    );
    assert(
        finalLedger.product_universe?.inventory_digest ===
            "d6f680b73278786f8004826029e6a9413f921db4ce03df8873bde4c23c62d99c",
        "product universe digest changed",
    );
    assert(
        candidate.evidence?.product_universe_source_count === 255,
        "candidate evidence source count changed",
    );
    assert(
        candidate.evidence?.product_universe_inventory_digest ===
            finalLedger.product_universe?.inventory_digest,
        "candidate evidence digest does not match the classified universe",
    );
    assert(
        sameMembers(candidate.evidence?.classification_ids, [
            sourceLedger.classification_id,
            finalLedger.classification_id,
        ]),
        "candidate classification evidence changed",
    );
    const directSources = candidate.evidence?.direct_sources;
    assert(Array.isArray(directSources), "direct source evidence is missing");
    assert(
        sameMembers(
            (directSources ?? []).map((source) => source.path),
            SOURCE_PATHS,
        ),
        "direct source evidence path set changed",
    );
    for (const source of directSources ?? []) {
        assert(
            sameKeys(source, ["path", "sha256"]),
            `direct source evidence fields changed: ${source.path}`,
        );
        const absolutePath = path.join(repoRoot, source.path ?? "");
        assert(
            fs.existsSync(absolutePath),
            `direct source is missing: ${source.path}`,
        );
        if (fs.existsSync(absolutePath)) {
            assert(
                source.sha256 === sha256(absolutePath),
                `direct source checksum drift: ${source.path}`,
            );
        }
    }

    assert(
        foundation.info?.version === "0.0.0-governance",
        "foundation contract version changed",
    );
    assert(
        sameKeys(foundation.paths, []),
        "foundation contract gained an API path",
    );
    assert(
        foundation["x-nightingale-activation"]?.routes_registered === false,
        "foundation routes became registered",
    );
    assert(
        foundation["x-nightingale-activation"]?.route_registration_permitted ===
            false,
        "foundation route registration became permitted",
    );
    assert(
        foundation["x-nightingale-activation"]?.network_clients_permitted ===
            false,
        "foundation network clients became permitted",
    );
    assert(
        foundation["x-nightingale-activation"]?.patient_disclosure_enabled ===
            false,
        "foundation disclosure became enabled",
    );
    assert(
        !JSON.stringify(foundation).includes(CANDIDATE_PATH),
        "held Today path leaked into the runnable foundation",
    );

    assert(
        sameKeys(fixtures, [
            "fixture_schema",
            "candidate_id",
            "synthetic_only",
            "production_replay_permitted",
            "contains_real_patient_data",
            "request_template",
            "response_templates",
            "cases",
        ]),
        "fixture root fields changed",
    );
    assert(
        fixtures.fixture_schema ===
            "nightingale.today-projection.candidate-fixtures.v0",
        "fixture schema changed",
    );
    assert(
        fixtures.candidate_id === CANDIDATE_ID,
        "fixture candidate id changed",
    );
    assert(fixtures.synthetic_only === true, "fixtures are not synthetic-only");
    assert(
        fixtures.production_replay_permitted === false,
        "production fixture replay became permitted",
    );
    assert(
        fixtures.contains_real_patient_data === false,
        "fixtures claim to contain real patient data",
    );

    const requestTemplate = fixtures.request_template;
    assert(
        sameKeys(requestTemplate, [
            "method",
            "route_namespace",
            "path",
            "path_parameters",
            "query_parameters",
            "body",
            "authentication_context",
        ]),
        "request template fields changed",
    );
    assert(
        requestTemplate?.method === "GET" &&
            requestTemplate?.route_namespace === ROUTE_NAMESPACE &&
            requestTemplate?.path === CANDIDATE_PATH,
        "request template operation changed",
    );
    assert(
        sameKeys(requestTemplate?.path_parameters, ["encounter_handle"]) &&
            HANDLE_PATTERN.test(
                requestTemplate?.path_parameters?.encounter_handle ?? "",
            ),
        "request template handle is missing or invalid",
    );
    assert(
        sameKeys(requestTemplate?.query_parameters, []) &&
            requestTemplate?.body === null,
        "request template gained query parameters or a body",
    );
    assert(
        requestTemplate?.authentication_context ===
            "approved-nightingale-session-required",
        "request template authentication context changed",
    );

    const templates = fixtures.response_templates;
    assert(
        sameMembers(Object.keys(templates ?? {}), Object.keys(RESPONSE_STATUS)),
        "response template set changed",
    );
    for (const [templateName, expectedStatus] of Object.entries(
        RESPONSE_STATUS,
    )) {
        const template = templates?.[templateName];
        assert(
            isRecord(template),
            `response template missing: ${templateName}`,
        );
        assert(
            template?.status === expectedStatus,
            `response status changed: ${templateName}`,
        );
        assert(
            sameRecord(template?.headers, REQUIRED_HEADERS),
            `response headers changed: ${templateName}`,
        );
        assert(
            sameMembers(Object.keys(template?.body ?? {}), [
                ...(expectedStatus === 200
                    ? ["data", "meta", "links"]
                    : ["data", "error", "meta", "links"]),
            ]),
            `response envelope changed: ${templateName}`,
        );
        assert(
            sameKeys(template?.body?.links, []),
            `response links changed: ${templateName}`,
        );
        validateMeta(
            template?.body?.meta,
            expectedStatus,
            assert,
            templateName,
        );

        if (expectedStatus === 200) {
            validateSuccessData(template?.body?.data, assert, templateName);
            validateVariantTemplate(template?.body?.data, assert, templateName);
        } else {
            assert(
                template?.body?.data === null,
                `error template data is not null: ${templateName}`,
            );
            assert(
                isRecord(template?.body?.error) &&
                    sameKeys(template.body.error, ["code", "message"]) &&
                    template.body.error.code === templateName &&
                    typeof template.body.error.message === "string" &&
                    template.body.error.message.length > 0,
                `error template changed: ${templateName}`,
            );
        }
    }

    const fixtureCases = fixtures.cases;
    assert(Array.isArray(fixtureCases), "fixture cases are missing");
    const caseIds = (fixtureCases ?? []).map((fixture) => fixture.case_id);
    assert(
        new Set(caseIds).size === caseIds.length,
        "fixture case ids are not unique",
    );
    assert(
        sameMembers(caseIds, Object.keys(CASE_TEMPLATE)),
        "fixture case coverage changed",
    );
    assert(
        sameMembers(
            candidate.required_fixture_case_ids,
            Object.keys(CASE_TEMPLATE),
        ),
        "candidate required fixture coverage changed",
    );
    for (const fixture of fixtureCases ?? []) {
        assert(
            fixture.expected_template === CASE_TEMPLATE[fixture.case_id],
            `fixture outcome changed: ${fixture.case_id}`,
        );
        assert(
            fixture.audit_mode === CASE_AUDIT[fixture.case_id],
            `fixture audit mode changed: ${fixture.case_id}`,
        );
        assert(
            typeof fixture.category === "string" && fixture.category.length > 0,
            `fixture category missing: ${fixture.case_id}`,
        );
        assert(
            Array.isArray(fixture.preconditions) &&
                fixture.preconditions.length > 0 &&
                fixture.preconditions.every(
                    (value) => typeof value === "string" && value.length > 0,
                ),
            `fixture preconditions missing: ${fixture.case_id}`,
        );
    }

    for (const forbidden of [
        "/api/patient/v1",
        "/api/mobile/v1",
        '"encounter_uuid"',
        '"grant_uuid"',
        '"patient_id"',
        '"principal_uuid"',
        '"source_encounter_id"',
        '"source_system_key"',
        "medical_record_number",
        "access_token",
        "refresh_token",
    ]) {
        assert(
            !fixtureRaw.includes(forbidden),
            `fixtures contain forbidden legacy or credential token: ${forbidden}`,
        );
    }
    const forbiddenEnvironmentTokens = [
        ["Acumenus", "321"].join(""),
        ["pgsql", "acumenus", "net"].join("."),
        ["zephyrus", "acumenus", "net"].join("."),
    ];
    for (const forbidden of forbiddenEnvironmentTokens) {
        assert(
            !candidateRaw.includes(forbidden) &&
                !fixtureRaw.includes(forbidden),
            `candidate artifacts contain forbidden environment or credential token: ${forbidden}`,
        );
    }

    return violations;
}

function validateMeta(meta, status, assert, templateName) {
    assert(
        sameKeys(meta, [
            "request_id",
            "generated_at",
            "policy_version",
            "authorization_evaluated_at",
            "inpatient_context_evaluated_at",
            "locale",
            "completeness",
        ]),
        `metadata fields changed: ${templateName}`,
    );
    assert(
        REQUEST_PATTERN.test(meta?.request_id ?? ""),
        `request id invalid: ${templateName}`,
    );
    for (const field of [
        "generated_at",
        "authorization_evaluated_at",
        "inpatient_context_evaluated_at",
    ]) {
        assert(
            ISO_INSTANT_PATTERN.test(meta?.[field] ?? ""),
            `metadata timestamp invalid (${field}): ${templateName}`,
        );
        assert(
            meta?.[field] === FIXED_TIME,
            `metadata timestamp drifted (${field}): ${templateName}`,
        );
    }
    assert(
        meta?.policy_version === POLICY_VERSION,
        `policy version changed: ${templateName}`,
    );
    assert(
        LOCALE_PATTERN.test(meta?.locale ?? ""),
        `response locale invalid: ${templateName}`,
    );
    assert(
        meta?.completeness ===
            (status === 200
                ? "governed-evaluation-complete"
                : status === 503
                  ? "evaluation-incomplete"
                  : "withheld"),
        `response completeness changed: ${templateName}`,
    );
}

function validateField(field, assert, location, responseLocale) {
    assert(
        sameKeys(field, ["value", "context"]),
        `governed value fields changed: ${location}`,
    );
    const context = field?.context;
    assert(
        sameKeys(context, FIELD_CONTEXT_FIELDS),
        `field context missing or aggregate-only: ${location}`,
    );

    assert(
        sameKeys(context?.release, [
            "state",
            "released_at",
            "content_policy_version",
        ]),
        `release context changed: ${location}`,
    );
    assert(
        context?.release?.state === "released",
        `field is not released: ${location}`,
    );
    assert(
        ISO_INSTANT_PATTERN.test(context?.release?.released_at ?? ""),
        `release timestamp invalid: ${location}`,
    );
    assert(
        context?.release?.content_policy_version === CONTENT_POLICY_VERSION,
        `content policy version changed: ${location}`,
    );

    assert(
        sameKeys(context?.freshness, [
            "status",
            "observed_at",
            "patient_notice",
        ]),
        `freshness context changed: ${location}`,
    );
    assert(
        ["current", "approved-stale"].includes(
            context?.freshness?.status ?? "",
        ),
        `freshness state invalid: ${location}`,
    );
    assert(
        ISO_INSTANT_PATTERN.test(context?.freshness?.observed_at ?? ""),
        `observed timestamp invalid: ${location}`,
    );
    if (context?.freshness?.status === "approved-stale") {
        assert(
            typeof context.freshness.patient_notice === "string" &&
                context.freshness.patient_notice.length > 0,
            `approved stale field lacks patient notice: ${location}`,
        );
    }

    assert(
        sameKeys(context?.uncertainty, ["level", "explanation", "can_change"]),
        `uncertainty context changed: ${location}`,
    );
    assert(
        ["low", "medium", "high", "unknown"].includes(
            context?.uncertainty?.level ?? "",
        ),
        `uncertainty level invalid: ${location}`,
    );
    assert(
        typeof context?.uncertainty?.explanation === "string" &&
            context.uncertainty.explanation.length > 0,
        `uncertainty explanation missing: ${location}`,
    );
    assert(
        typeof context?.uncertainty?.can_change === "boolean",
        `uncertainty can_change is not boolean: ${location}`,
    );

    assert(
        sameKeys(context?.language, [
            "locale",
            "release_state",
            "plain_language_review",
        ]),
        `language context changed: ${location}`,
    );
    assert(
        context?.language?.locale === responseLocale &&
            LOCALE_PATTERN.test(context?.language?.locale ?? ""),
        `field locale does not match response locale: ${location}`,
    );
    assert(
        ["approved-source-language", "approved-translation"].includes(
            context?.language?.release_state ?? "",
        ),
        `language release state invalid: ${location}`,
    );
    assert(
        context?.language?.plain_language_review === "approved",
        `plain-language review is not approved: ${location}`,
    );

    assert(
        sameKeys(context?.correction, ["state", "patient_notice"]),
        `correction context changed: ${location}`,
    );
    assert(
        ["original", "corrected"].includes(context?.correction?.state ?? ""),
        `correction state invalid: ${location}`,
    );
    if (context?.correction?.state === "corrected") {
        assert(
            typeof context.correction.patient_notice === "string" &&
                context.correction.patient_notice.length > 0,
            `corrected field lacks patient notice: ${location}`,
        );
    }

    assert(
        sameKeys(context?.offline, [
            "availability",
            "durable_storage_permitted",
        ]),
        `offline context changed: ${location}`,
    );
    assert(
        context?.offline?.availability === "online-only" &&
            context?.offline?.durable_storage_permitted === false,
        `offline storage decision weakened: ${location}`,
    );

    const observed = Date.parse(context?.freshness?.observed_at ?? "");
    const released = Date.parse(context?.release?.released_at ?? "");
    assert(
        Number.isFinite(observed) &&
            Number.isFinite(released) &&
            observed <= released &&
            released <= Date.parse(FIXED_TIME),
        `field timestamps are inconsistent: ${location}`,
    );
}

function validateOptionalListSection(
    section,
    assert,
    location,
    responseLocale,
    itemValidator = null,
) {
    assert(
        OPTIONAL_STATES.includes(section?.state),
        `section state invalid: ${location}`,
    );
    if (section?.state === "released") {
        assert(
            sameKeys(section, ["state", "items", "patient_notice"]),
            `released section fields changed: ${location}`,
        );
        assert(
            Array.isArray(section?.items),
            `section items missing: ${location}`,
        );
        for (const [index, item] of (section?.items ?? []).entries()) {
            if (itemValidator) {
                itemValidator(item, `${location}.items[${index}]`);
            } else {
                validateField(
                    item,
                    assert,
                    `${location}.items[${index}]`,
                    responseLocale,
                );
            }
        }
    } else if (section?.state === "released-empty") {
        assert(
            sameKeys(section, ["state", "items", "patient_notice"]),
            `released-empty section fields changed: ${location}`,
        );
        assert(
            Array.isArray(section?.items) && section.items.length === 0,
            `released-empty section contains items: ${location}`,
        );
        assert(
            typeof section?.patient_notice === "string" &&
                section.patient_notice.length > 0,
            `released-empty section lacks non-absence notice: ${location}`,
        );
    } else if (section?.state === "not-available") {
        assert(
            sameKeys(section, ["state", "patient_notice"]),
            `not-available section contains content: ${location}`,
        );
        assert(
            typeof section?.patient_notice === "string" &&
                section.patient_notice.length > 0,
            `not-available section lacks patient notice: ${location}`,
        );
    }
}

function validateSuccessData(data, assert, templateName) {
    assert(
        sameKeys(data, [
            "kind",
            "content_revision",
            "sections",
            "revision_notice",
        ]),
        `success data fields changed: ${templateName}`,
    );
    assert(data?.kind === "today", `success kind changed: ${templateName}`);
    assert(
        REVISION_PATTERN.test(data?.content_revision ?? ""),
        `content revision invalid: ${templateName}`,
    );
    for (const aggregateKey of [
        "provenance",
        "source_freshness",
        "freshness",
        "uncertainty",
    ]) {
        assert(
            !Object.hasOwn(data ?? {}, aggregateKey),
            `root aggregate ${aggregateKey} is present: ${templateName}`,
        );
    }

    const sections = data?.sections;
    assert(
        sameKeys(sections, SECTION_NAMES),
        `success section fields changed: ${templateName}`,
    );
    for (const name of ["headline", "summary"]) {
        const section = sections?.[name];
        assert(
            sameKeys(section, ["state", "field"]) &&
                section?.state === "released",
            `mandatory section is not released: ${templateName}.${name}`,
        );
        validateField(
            section?.field,
            assert,
            `${templateName}.sections.${name}.field`,
            "en-US",
        );
    }

    validateOptionalListSection(
        sections?.schedule,
        assert,
        `${templateName}.sections.schedule`,
        "en-US",
        (item, location) => {
            assert(
                sameKeys(item, [
                    "item_handle",
                    "label",
                    "detail",
                    "status",
                    "time_window",
                    "timing_confidence",
                    "preparation",
                    "can_change",
                ]),
                `schedule item fields changed: ${location}`,
            );
            assert(
                ITEM_HANDLE_PATTERN.test(item?.item_handle ?? ""),
                `schedule item handle invalid: ${location}`,
            );
            for (const fieldName of [
                "label",
                "detail",
                "status",
                "time_window",
                "timing_confidence",
                "preparation",
                "can_change",
            ]) {
                validateField(
                    item?.[fieldName],
                    assert,
                    `${location}.${fieldName}`,
                    "en-US",
                );
            }
            assert(
                SCHEDULE_STATUSES.includes(item?.status?.value),
                `schedule status invalid: ${location}`,
            );
            assert(
                TIMING_CONFIDENCE.includes(item?.timing_confidence?.value),
                `timing confidence invalid: ${location}`,
            );
            assert(
                typeof item?.can_change?.value === "boolean",
                `schedule can_change value invalid: ${location}`,
            );
        },
    );

    for (const name of ["next_steps", "questions", "notices"]) {
        validateOptionalListSection(
            sections?.[name],
            assert,
            `${templateName}.sections.${name}`,
            "en-US",
        );
    }

    const careLocation = sections?.care_location;
    assert(
        OPTIONAL_STATES.includes(careLocation?.state),
        `care-location state invalid: ${templateName}`,
    );
    if (careLocation?.state === "released") {
        assert(
            sameKeys(careLocation, ["state", "fields", "patient_notice"]),
            `care-location section fields changed: ${templateName}`,
        );
        assert(
            sameKeys(careLocation?.fields, [
                "facility_display_name",
                "unit_display_name",
                "room_display_name",
                "status",
            ]),
            `care-location governed fields changed: ${templateName}`,
        );
        for (const [name, value] of Object.entries(
            careLocation?.fields ?? {},
        )) {
            validateField(
                value,
                assert,
                `${templateName}.sections.care_location.fields.${name}`,
                "en-US",
            );
        }
        assert(
            LOCATION_STATUSES.includes(careLocation?.fields?.status?.value),
            `care-location status invalid: ${templateName}`,
        );
    } else {
        validateOptionalListSection(
            careLocation,
            assert,
            `${templateName}.sections.care_location`,
            "en-US",
        );
    }

    const discharge = sections?.discharge_outlook;
    assert(
        OPTIONAL_STATES.includes(discharge?.state),
        `discharge-outlook state invalid: ${templateName}`,
    );
    if (discharge?.state === "released") {
        assert(
            sameKeys(discharge, ["state", "fields", "patient_notice"]),
            `discharge-outlook section fields changed: ${templateName}`,
        );
        assert(
            sameKeys(discharge?.fields, [
                "estimated_range",
                "confidence",
                "readiness_topics",
                "remaining_steps",
                "can_change",
            ]),
            `discharge-outlook governed fields changed: ${templateName}`,
        );
        for (const name of ["estimated_range", "confidence", "can_change"]) {
            validateField(
                discharge?.fields?.[name],
                assert,
                `${templateName}.sections.discharge_outlook.fields.${name}`,
                "en-US",
            );
        }
        assert(
            TIMING_CONFIDENCE.includes(discharge?.fields?.confidence?.value),
            `discharge confidence invalid: ${templateName}`,
        );
        assert(
            typeof discharge?.fields?.can_change?.value === "boolean",
            `discharge can_change invalid: ${templateName}`,
        );
        for (const name of ["readiness_topics", "remaining_steps"]) {
            validateOptionalListSection(
                discharge?.fields?.[name],
                assert,
                `${templateName}.sections.discharge_outlook.fields.${name}`,
                "en-US",
            );
        }
    } else {
        validateOptionalListSection(
            discharge,
            assert,
            `${templateName}.sections.discharge_outlook`,
            "en-US",
        );
    }

    const scheduleHandles =
        sections?.schedule?.state === "released"
            ? sections.schedule.items.map((item) => item.item_handle)
            : [];
    assert(
        new Set(scheduleHandles).size === scheduleHandles.length,
        `schedule item handles are not unique: ${templateName}`,
    );

    if (data?.revision_notice !== null) {
        assert(
            sameRecord(data.revision_notice, {
                kind: "correction",
                message:
                    "Your care team updated part of today’s plan. Please use the information shown here.",
            }),
            `revision notice exposes unsupported correction detail: ${templateName}`,
        );
        const correctedFields =
            JSON.stringify(sections).match(/"state":"corrected"/g);
        assert(
            (correctedFields ?? []).length > 0,
            `revision notice has no corrected field: ${templateName}`,
        );
    }
}

function validateVariantTemplate(data, assert, templateName) {
    const sections = data?.sections;
    const governedFields = collectGovernedFields(sections);
    assert(
        governedFields.length > 0,
        `success variant has no governed fields: ${templateName}`,
    );

    const scheduleItem = sections?.schedule?.items?.[0];
    const discharge = sections?.discharge_outlook;
    const careLocation = sections?.care_location;

    switch (templateName) {
        case "success_full_current":
            assert(
                governedFields.every(
                    (item) => item.context.freshness.status === "current",
                ),
                "full-current variant contains a non-current field",
            );
            assert(
                governedFields.every(
                    (item) =>
                        item.context.language.release_state ===
                        "approved-source-language",
                ),
                "full-current variant contains a non-source-language field",
            );
            break;
        case "success_minimal":
            assert(
                [
                    "schedule",
                    "next_steps",
                    "care_location",
                    "discharge_outlook",
                    "questions",
                    "notices",
                ].every((name) => sections?.[name]?.state === "not-available"),
                "minimal variant does not hold every optional section as not available",
            );
            break;
        case "success_schedule_empty":
            assert(
                sections?.schedule?.state === "released-empty" &&
                    sections.schedule.items?.length === 0,
                "released-empty schedule variant is not empty",
            );
            break;
        case "success_optional_unavailable":
            assert(
                careLocation?.state === "not-available" &&
                    discharge?.state === "not-available",
                "optional-unavailable variant releases held location or discharge content",
            );
            break;
        case "success_approved_stale":
            assert(
                scheduleItem?.time_window?.context?.freshness?.status ===
                    "approved-stale" &&
                    scheduleItem.time_window.context.uncertainty.level ===
                        "high" &&
                    typeof scheduleItem.time_window.context.freshness
                        .patient_notice === "string" &&
                    scheduleItem.time_window.context.freshness.patient_notice
                        .length > 0,
                "approved-stale variant lacks its stale, high-uncertainty field and notice",
            );
            break;
        case "success_correction":
            assert(
                scheduleItem?.time_window?.context?.correction?.state ===
                    "corrected" && data?.revision_notice?.kind === "correction",
                "correction variant lacks its corrected replacement or revision notice",
            );
            break;
        case "success_approved_translation":
            assert(
                governedFields.every(
                    (item) =>
                        item.context.language.release_state ===
                        "approved-translation",
                ),
                "approved-translation variant contains a field without translation release",
            );
            break;
        case "success_approved_source_language":
            assert(
                governedFields.every(
                    (item) =>
                        item.context.language.release_state ===
                        "approved-source-language",
                ),
                "approved-source-language variant contains a translated or unapproved field",
            );
            break;
        case "success_delayed_high_uncertainty":
            assert(
                scheduleItem?.status?.value === "delayed" &&
                    scheduleItem.status.context.uncertainty.level === "high" &&
                    scheduleItem.time_window.context.uncertainty.level ===
                        "high",
                "delayed variant lacks delayed status with field-specific high uncertainty",
            );
            break;
        case "success_discharge_unknown_timing":
            assert(
                discharge?.state === "released" &&
                    discharge.fields?.confidence?.value === "unknown" &&
                    discharge.fields.confidence.context.uncertainty.level ===
                        "unknown" &&
                    discharge.fields.estimated_range.context.uncertainty
                        .level === "unknown" &&
                    typeof discharge.fields.estimated_range.value ===
                        "string" &&
                    discharge.fields.estimated_range.value.length > 0 &&
                    discharge.fields.can_change?.value === true,
                "unknown-discharge variant does not explicitly communicate unknown, changeable timing",
            );
            break;
        case "success_location_updating":
            assert(
                careLocation?.state === "released" &&
                    careLocation.fields?.status?.value === "updating" &&
                    careLocation.fields.status.context.uncertainty.level ===
                        "medium" &&
                    careLocation.fields.room_display_name.context.uncertainty
                        .level === "medium",
                "location-updating variant asserts an ungoverned or confirmed location",
            );
            break;
        case "success_distinct_field_contexts": {
            const freshnessStates = new Set(
                governedFields.map((item) => item.context.freshness.status),
            );
            const languageStates = new Set(
                governedFields.map(
                    (item) => item.context.language.release_state,
                ),
            );
            const correctionStates = new Set(
                governedFields.map((item) => item.context.correction.state),
            );
            assert(
                freshnessStates.has("current") &&
                    freshnessStates.has("approved-stale") &&
                    languageStates.has("approved-source-language") &&
                    languageStates.has("approved-translation") &&
                    correctionStates.has("original") &&
                    correctionStates.has("corrected"),
                "distinct-context variant does not preserve independently varied field decisions",
            );
            break;
        }
        default:
            assert(false, `unvalidated success variant: ${templateName}`);
    }
}

function runNegativeSelfTests(
    candidate,
    fixtures,
    foundation,
    sourceLedger,
    finalLedger,
) {
    const mutations = [
        {
            name: "path drift",
            expected: "candidate path changed",
            mutate(candidateCopy) {
                candidateCopy.operation.path = "/today";
            },
        },
        {
            name: "OpenAPI activation",
            expected:
                "candidate operation permission openapi_inclusion became enabled",
            mutate(candidateCopy) {
                candidateCopy.operation.openapi_inclusion = true;
            },
        },
        {
            name: "production activation",
            expected: "activation production became enabled",
            mutate(candidateCopy) {
                candidateCopy.activation.production = true;
            },
        },
        {
            name: "aggregate freshness",
            expected: "root aggregate freshness is present",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_full_current.body.data.freshness =
                    { status: "current" };
            },
        },
        {
            name: "missing field context",
            expected: "governed value fields changed",
            mutate(_candidateCopy, fixturesCopy) {
                delete fixturesCopy.response_templates.success_full_current.body
                    .data.sections.headline.field.context;
            },
        },
        {
            name: "stale without notice",
            expected: "approved stale field lacks patient notice",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_approved_stale.body.data.sections.schedule.items[0].time_window.context.freshness.patient_notice =
                    null;
            },
        },
        {
            name: "locale drift",
            expected: "field locale does not match response locale",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_full_current.body.data.sections.headline.field.context.language.locale =
                    "fr-FR";
            },
        },
        {
            name: "released empty contradiction",
            expected: "released-empty section contains items",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_schedule_empty.body.data.sections.schedule.items.push(
                    { item_handle: `ntg_tdyitem_${"b".repeat(50)}` },
                );
            },
        },
        {
            name: "production replay",
            expected: "production fixture replay became permitted",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.production_replay_permitted = true;
            },
        },
        {
            name: "foundation operation",
            expected: "foundation contract gained an API path",
            mutate(_candidateCopy, _fixturesCopy, foundationCopy) {
                foundationCopy.paths[CANDIDATE_PATH] = { get: {} };
            },
        },
        {
            name: "source checksum drift",
            expected: "direct source checksum drift",
            mutate(candidateCopy) {
                candidateCopy.evidence.direct_sources[0].sha256 = "0".repeat(
                    64,
                );
            },
        },
        {
            name: "fixture removal",
            expected: "fixture case coverage changed",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.cases.pop();
            },
        },
        {
            name: "durable cache",
            expected:
                "response safety decision durable_client_cache_permitted weakened",
            mutate(candidateCopy) {
                candidateCopy.response_contract.durable_client_cache_permitted = true;
            },
        },
        {
            name: "full-current semantic drift",
            expected: "full-current variant contains a non-current field",
            mutate(_candidateCopy, fixturesCopy) {
                const freshness =
                    fixturesCopy.response_templates.success_full_current.body
                        .data.sections.headline.field.context.freshness;
                freshness.status = "approved-stale";
                freshness.patient_notice =
                    "This field is approved for stale use in this synthetic mutation.";
            },
        },
        {
            name: "minimal optional-content drift",
            expected:
                "minimal variant does not hold every optional section as not available",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_minimal.body.data.sections.schedule =
                    clone(
                        fixturesCopy.response_templates.success_full_current
                            .body.data.sections.schedule,
                    );
            },
        },
        {
            name: "optional-unavailable semantic drift",
            expected:
                "optional-unavailable variant releases held location or discharge content",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_optional_unavailable.body.data.sections.care_location =
                    clone(
                        fixturesCopy.response_templates.success_full_current
                            .body.data.sections.care_location,
                    );
            },
        },
        {
            name: "approved-stale semantic drift",
            expected:
                "approved-stale variant lacks its stale, high-uncertainty field and notice",
            mutate(_candidateCopy, fixturesCopy) {
                const freshness =
                    fixturesCopy.response_templates.success_approved_stale.body
                        .data.sections.schedule.items[0].time_window.context
                        .freshness;
                freshness.status = "current";
                freshness.patient_notice = null;
            },
        },
        {
            name: "correction semantic drift",
            expected:
                "correction variant lacks its corrected replacement or revision notice",
            mutate(_candidateCopy, fixturesCopy) {
                const correction =
                    fixturesCopy.response_templates.success_correction.body.data
                        .sections.schedule.items[0].time_window.context
                        .correction;
                correction.state = "original";
                correction.patient_notice = null;
            },
        },
        {
            name: "translation-release semantic drift",
            expected:
                "approved-translation variant contains a field without translation release",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_approved_translation.body.data.sections.headline.field.context.language.release_state =
                    "approved-source-language";
            },
        },
        {
            name: "source-language semantic drift",
            expected:
                "approved-source-language variant contains a translated or unapproved field",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_approved_source_language.body.data.sections.headline.field.context.language.release_state =
                    "approved-translation";
            },
        },
        {
            name: "delayed-state semantic drift",
            expected:
                "delayed variant lacks delayed status with field-specific high uncertainty",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_delayed_high_uncertainty.body.data.sections.schedule.items[0].status.value =
                    "planned";
            },
        },
        {
            name: "unknown-discharge semantic drift",
            expected:
                "unknown-discharge variant does not explicitly communicate unknown, changeable timing",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_discharge_unknown_timing.body.data.sections.discharge_outlook.fields.confidence.value =
                    "estimated";
            },
        },
        {
            name: "location-updating semantic drift",
            expected:
                "location-updating variant asserts an ungoverned or confirmed location",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_location_updating.body.data.sections.care_location.fields.status.value =
                    "current";
            },
        },
        {
            name: "distinct-context semantic drift",
            expected:
                "distinct-context variant does not preserve independently varied field decisions",
            mutate(_candidateCopy, fixturesCopy) {
                fixturesCopy.response_templates.success_distinct_field_contexts.body.data.sections.schedule.items[0].preparation.context.language.release_state =
                    "approved-source-language";
            },
        },
    ];

    if (mutations.length !== NEGATIVE_SELF_TEST_COUNT) {
        throw new Error(
            `negative self-test inventory changed: expected ${NEGATIVE_SELF_TEST_COUNT}, observed ${mutations.length}`,
        );
    }

    for (const mutation of mutations) {
        const candidateCopy = clone(candidate);
        const fixturesCopy = clone(fixtures);
        const foundationCopy = clone(foundation);
        mutation.mutate(candidateCopy, fixturesCopy, foundationCopy);
        const violations = inspect(
            candidateCopy,
            fixturesCopy,
            foundationCopy,
            sourceLedger,
            finalLedger,
            JSON.stringify(candidateCopy),
            JSON.stringify(fixturesCopy),
        );
        if (
            !violations.some((violation) =>
                violation.includes(mutation.expected),
            )
        ) {
            throw new Error(
                `negative self-test "${mutation.name}" did not produce expected rejection: ${mutation.expected}\nObserved: ${violations.join("; ")}`,
            );
        }
    }
}

const { document: candidate, raw: candidateRaw } = parseJson(candidatePath);
const { document: fixtures, raw: fixtureRaw } = parseJson(fixturesPath);
const { document: foundation } = parseJson(foundationPath);
const { document: sourceLedger } = parseJson(sourceLedgerPath);
const { document: finalLedger } = parseJson(finalLedgerPath);

const violations = inspect(
    candidate,
    fixtures,
    foundation,
    sourceLedger,
    finalLedger,
    candidateRaw,
    fixtureRaw,
);

if (violations.length > 0) {
    for (const violation of violations) {
        process.stderr.write(
            `Nightingale Today candidate violation: ${violation}\n`,
        );
    }
    process.exit(1);
}

if (selfTest) {
    runNegativeSelfTests(
        candidate,
        fixtures,
        foundation,
        sourceLedger,
        finalLedger,
    );
}

process.stdout.write(
    `Nightingale Today candidate verified: ${fixtures.cases.length} synthetic cases${
        selfTest ? ` with ${NEGATIVE_SELF_TEST_COUNT} negative self-tests` : ""
    }.\n`,
);
