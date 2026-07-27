#!/usr/bin/env node

import crypto from "node:crypto";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const args = process.argv.slice(2);
const selfTest = args.includes("--self-test");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--self-test",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

if (unknownOptions.length > 0 || positional.length > 1) {
    process.stderr.write(
        "Usage: verify-nightingale-controlled-pilot-manifest.mjs [repository-root] [--self-test]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const candidateDirectory = "docs/nightingale/pilot/candidates/v0";
const candidatePath = path.join(candidateDirectory, "candidate.json");
const fixturesPath = path.join(candidateDirectory, "fixtures.json");
const builderPath =
    "scripts/ci/build-nightingale-controlled-pilot-manifest.mjs";
const foundationPath =
    "docs/nightingale/api-contract/nightingale-foundation.v0.json";
const configPath = "config/nightingale.php";

const CANDIDATE_ID = "nightingale.controlled-pilot-manifest.v0-candidate";
const POLICY_VERSION = "nightingale-controlled-pilot-manifest.v0-candidate";
const REVIEWED_SOURCE_COMMIT = "b333ebf8a618cac5c3c8a27d43ea666de32579bc";
const GENERATED_AT = "2026-07-27T20:00:00Z";
const MAXIMUM_VALIDITY_HOURS = 168;
const MAXIMUM_ACTIVE_ENROLLMENTS = 25;
const EXPECTED_CASE_COUNT = 34;
const EXPECTED_HOLD_COUNT = 33;
const EXPECTED_ELIGIBLE_COUNT = 1;

const sourcePaths = [
    "config/nightingale.php",
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
    "app/Nightingale/Activation/NightingaleActivationGate.php",
    "app/Nightingale/Activation/NightingalePilotEnrollmentState.php",
];

const requiredApprovalRoles = [
    "product_owner",
    "clinical_safety_owner",
    "privacy_security_owner",
    "patient_advisor_accessibility_owner",
    "identity_source_owner",
    "operations_support_owner",
    "release_owner",
];

const requiredAuditEventTypes = [
    "manifest_created",
    "manifest_review_requested",
    "manifest_approved",
    "go_no_go_requested",
    "pilot_started",
    "pilot_expired",
    "pilot_withdrawn",
    "kill_switch_invoked",
    "rollback_completed",
];

const requiredAuditFields = [
    "manifest_id",
    "manifest_revision",
    "event_type",
    "occurred_at",
    "actor_role",
    "actor_reference_hash",
    "outcome",
    "reason_code",
    "policy_version",
];

const constraintKeys = [
    "runtime_implementation_permitted",
    "route_registration_permitted",
    "native_networking_permitted",
    "identity_provider_selection_permitted",
    "source_adapter_selection_permitted",
    "production_query_permitted",
    "patient_or_representative_creation_permitted",
    "patient_enrollment_permitted",
    "patient_disclosure_permitted",
    "patient_mutation_permitted",
    "communication_or_notification_permitted",
    "deployment_permitted",
    "pilot_activation_permitted",
];

const forbiddenKeys = new Set([
    "patient_id",
    "patient_uuid",
    "patient_mrn",
    "patient_name",
    "principal_id",
    "principal_uuid",
    "encounter_id",
    "encounter_uuid",
    "grant_id",
    "grant_uuid",
    "message_body",
    "clinical_value",
    "access_token",
    "refresh_token",
    "password",
]);

function fail(message) {
    throw new Error(
        `Nightingale controlled-pilot manifest violation: ${message}`,
    );
}

function read(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath) || !fs.statSync(absolutePath).isFile()) {
        fail(`missing ${relativePath}`);
    }
    return fs.readFileSync(absolutePath, "utf8");
}

function parseJson(relativePath) {
    try {
        return JSON.parse(read(relativePath));
    } catch (error) {
        fail(`invalid JSON in ${relativePath}: ${error.message}`);
    }
}

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function sameArray(actual, expected) {
    return (
        Array.isArray(actual) &&
        actual.length === expected.length &&
        actual.every((value, index) => value === expected[index])
    );
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

function sameKeys(value, expected) {
    return (
        value !== null &&
        typeof value === "object" &&
        !Array.isArray(value) &&
        sameMembers(Object.keys(value), expected)
    );
}

function nonEmptyString(value) {
    return typeof value === "string" && value.trim() === value && value !== "";
}

function collectKeys(value, output = []) {
    if (Array.isArray(value)) {
        for (const item of value) collectKeys(item, output);
        return output;
    }
    if (value === null || typeof value !== "object") return output;
    for (const [key, child] of Object.entries(value)) {
        output.push(key);
        collectKeys(child, output);
    }
    return output;
}

function parseUtcTimestamp(value) {
    if (
        typeof value !== "string" ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
    ) {
        return null;
    }
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function isOpaqueHandle(value, prefix) {
    return (
        typeof value === "string" &&
        new RegExp(`^${prefix}_[a-z0-9_]{4,64}$`).test(value)
    );
}

function isLocaleTag(value) {
    if (
        typeof value !== "string" ||
        !/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|\d{3}))?$/.test(value)
    ) {
        return false;
    }
    try {
        return Intl.getCanonicalLocales(value)[0] === value;
    } catch {
        return false;
    }
}

function isTimezone(value) {
    if (
        typeof value !== "string" ||
        !/^[A-Za-z_+-]+\/[A-Za-z0-9_+-]+(?:\/[A-Za-z0-9_+-]+)?$/.test(value)
    ) {
        return false;
    }
    try {
        new Intl.DateTimeFormat("en-US", { timeZone: value }).format();
        return true;
    } catch {
        return false;
    }
}

function localMinutes(value) {
    if (
        typeof value !== "string" ||
        !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)
    ) {
        return null;
    }
    const [hours, minutes] = value.split(":").map(Number);
    return hours * 60 + minutes;
}

function validateSupportWindows(windows) {
    if (!Array.isArray(windows) || windows.length === 0) {
        return ["support_windows_missing_or_invalid"];
    }

    const reasons = [];
    const byDay = new Map();
    for (const window of windows) {
        if (
            !sameKeys(window, [
                "iso_weekday",
                "starts_at_local",
                "ends_at_local",
            ]) ||
            !Number.isInteger(window.iso_weekday) ||
            window.iso_weekday < 1 ||
            window.iso_weekday > 7
        ) {
            reasons.push("support_windows_missing_or_invalid");
            continue;
        }

        const start = localMinutes(window.starts_at_local);
        const end = localMinutes(window.ends_at_local);
        if (start === null || end === null || start >= end) {
            reasons.push("support_windows_missing_or_invalid");
            continue;
        }

        const intervals = byDay.get(window.iso_weekday) ?? [];
        intervals.push([start, end]);
        byDay.set(window.iso_weekday, intervals);
    }

    for (const intervals of byDay.values()) {
        intervals.sort((left, right) => left[0] - right[0]);
        for (let index = 1; index < intervals.length; index += 1) {
            if (intervals[index][0] < intervals[index - 1][1]) {
                reasons.push("support_windows_overlap");
            }
        }
    }

    return [...new Set(reasons)];
}

function validateManifestShape(manifest, label) {
    if (
        !sameKeys(manifest, [
            "manifest_id",
            "manifest_revision",
            "policy_version",
            "state",
            "go_no_go_review_requested",
            "runtime_activation_permitted",
            "scope",
            "support_coverage",
            "validity",
            "prerequisites",
            "approvals",
            "audit",
            "rollback",
        ])
    ) {
        fail(`${label} top-level field inventory changed`);
    }
    if (
        !sameKeys(manifest.scope, [
            "facility_handles",
            "unit_handles",
            "cohort",
            "languages",
            "exclusions",
        ])
    ) {
        fail(`${label} scope field inventory changed`);
    }
    if (
        !sameKeys(manifest.scope.cohort, [
            "cohort_handle",
            "inclusion_policy_release_id",
            "exclusion_policy_release_id",
            "maximum_active_enrollments",
            "enrollment_is_automatic",
            "unknown_or_unavailable_eligibility_result",
        ])
    ) {
        fail(`${label} cohort field inventory changed`);
    }
    if (
        !sameKeys(manifest.scope.languages, [
            "patient_locale_tags",
            "interpreter_coverage_release_id",
            "unapproved_locale_fallback_permitted",
            "unknown_language_disposition",
        ])
    ) {
        fail(`${label} language field inventory changed`);
    }
    if (
        !sameKeys(manifest.scope.exclusions, [
            "exclusion_policy_release_id",
            "deny_if_rule_result_unknown",
            "deny_if_rule_source_unavailable",
            "sensitive_service_inference_permitted",
            "exclusion_reason_disclosure_permitted",
        ])
    ) {
        fail(`${label} exclusion field inventory changed`);
    }
    if (
        !sameKeys(manifest.support_coverage, [
            "timezone",
            "weekly_windows",
            "urgent_help_content_release_id",
            "uncovered_window_disposition",
            "support_availability_may_be_inferred",
        ])
    ) {
        fail(`${label} support field inventory changed`);
    }
    if (
        !sameKeys(manifest.validity, [
            "starts_at",
            "expires_at",
            "maximum_validity_hours",
            "renewal_requires_new_manifest",
            "expiry_fails_closed",
        ])
    ) {
        fail(`${label} validity field inventory changed`);
    }
    if (
        !sameKeys(manifest.prerequisites, [
            "clinical_approval_record",
            "patient_content_release_record",
            "feature_activation_record",
            "source_connector_deployment_record",
            "identity_source_approval_record",
            "audit_sink_deployment_record",
            "rollback_plan_record",
            "kill_switch_test_record",
        ])
    ) {
        fail(`${label} prerequisite field inventory changed`);
    }
    if (!sameKeys(manifest.approvals, requiredApprovalRoles)) {
        fail(`${label} approval-role inventory changed`);
    }
    for (const role of requiredApprovalRoles) {
        if (
            !sameKeys(manifest.approvals[role], [
                "approver_subject",
                "approval_record",
                "approved_at",
            ])
        ) {
            fail(`${label} approval fields changed for ${role}`);
        }
    }
    if (
        !sameKeys(manifest.audit, [
            "append_only_required",
            "durable_before_effective_change",
            "event_schema_release_id",
            "required_event_types",
            "required_fields",
            "patient_identifiers_permitted",
            "clinical_content_permitted",
            "message_content_permitted",
            "audit_failure_disposition",
        ])
    ) {
        fail(`${label} audit field inventory changed`);
    }
    if (
        !sameKeys(manifest.rollback, [
            "kill_switch_default_state",
            "rollback_target_release_id",
            "rollback_verification_record",
            "enrollment_and_disclosure_after_expiry_permitted",
        ])
    ) {
        fail(`${label} rollback field inventory changed`);
    }

    const discoveredForbiddenKeys = collectKeys(manifest).filter((key) =>
        forbiddenKeys.has(key),
    );
    if (discoveredForbiddenKeys.length > 0) {
        fail(
            `${label} contains forbidden patient/content key(s): ${[
                ...new Set(discoveredForbiddenKeys),
            ].join(", ")}`,
        );
    }
}

function evaluateManifest(manifest, evaluationTime) {
    const reasons = [];
    const add = (reason) => {
        if (!reasons.includes(reason)) reasons.push(reason);
    };

    if (manifest.state !== "approved_inactive") {
        add("manifest_not_approved_inactive");
    }
    if (manifest.go_no_go_review_requested !== true) {
        add("go_no_go_review_not_requested");
    }
    if (manifest.runtime_activation_permitted !== false) {
        add("runtime_activation_must_remain_prohibited");
    }
    if (
        !nonEmptyString(manifest.manifest_id) ||
        !manifest.manifest_id.startsWith("nightingale.controlled-pilot.")
    ) {
        add("manifest_id_missing_or_invalid");
    }
    if (
        !Number.isInteger(manifest.manifest_revision) ||
        manifest.manifest_revision < 1
    ) {
        add("manifest_revision_missing_or_invalid");
    }
    if (manifest.policy_version !== POLICY_VERSION) {
        add("policy_version_mismatch");
    }

    const facilities = manifest.scope.facility_handles;
    if (
        !Array.isArray(facilities) ||
        facilities.length === 0 ||
        new Set(facilities).size !== facilities.length ||
        facilities.some((value) => !isOpaqueHandle(value, "ngf"))
    ) {
        add("facility_scope_missing_or_invalid");
    }
    const units = manifest.scope.unit_handles;
    if (
        !Array.isArray(units) ||
        units.length === 0 ||
        new Set(units).size !== units.length ||
        units.some((value) => !isOpaqueHandle(value, "ngu"))
    ) {
        add("unit_scope_missing_or_invalid");
    }

    const cohort = manifest.scope.cohort;
    if (!isOpaqueHandle(cohort.cohort_handle, "ngc")) {
        add("cohort_handle_missing_or_invalid");
    }
    if (
        !nonEmptyString(cohort.inclusion_policy_release_id) ||
        !nonEmptyString(cohort.exclusion_policy_release_id)
    ) {
        add("cohort_policy_release_missing");
    }
    if (
        cohort.exclusion_policy_release_id !==
        manifest.scope.exclusions.exclusion_policy_release_id
    ) {
        add("exclusion_policy_release_mismatch");
    }
    if (
        !Number.isInteger(cohort.maximum_active_enrollments) ||
        cohort.maximum_active_enrollments < 1 ||
        cohort.maximum_active_enrollments > MAXIMUM_ACTIVE_ENROLLMENTS
    ) {
        add("cohort_enrollment_limit_invalid");
    }
    if (cohort.enrollment_is_automatic !== false) {
        add("automatic_enrollment_prohibited");
    }
    if (cohort.unknown_or_unavailable_eligibility_result !== "withhold") {
        add("unknown_cohort_eligibility_must_withhold");
    }

    const languages = manifest.scope.languages;
    if (
        !Array.isArray(languages.patient_locale_tags) ||
        languages.patient_locale_tags.length === 0 ||
        new Set(languages.patient_locale_tags).size !==
            languages.patient_locale_tags.length ||
        languages.patient_locale_tags.some((value) => !isLocaleTag(value))
    ) {
        add("language_scope_missing_or_invalid");
    }
    if (!nonEmptyString(languages.interpreter_coverage_release_id)) {
        add("interpreter_coverage_release_missing");
    }
    if (languages.unapproved_locale_fallback_permitted !== false) {
        add("unapproved_language_fallback_prohibited");
    }
    if (languages.unknown_language_disposition !== "withhold") {
        add("unknown_language_must_withhold");
    }

    const exclusions = manifest.scope.exclusions;
    if (exclusions.deny_if_rule_result_unknown !== true) {
        add("exclusion_unknown_must_deny");
    }
    if (exclusions.deny_if_rule_source_unavailable !== true) {
        add("exclusion_source_outage_must_deny");
    }
    if (
        exclusions.sensitive_service_inference_permitted !== false ||
        exclusions.exclusion_reason_disclosure_permitted !== false
    ) {
        add("exclusion_nondisclosure_must_hold");
    }

    const support = manifest.support_coverage;
    if (!isTimezone(support.timezone)) {
        add("support_timezone_missing_or_invalid");
    }
    for (const reason of validateSupportWindows(support.weekly_windows)) {
        add(reason);
    }
    if (!nonEmptyString(support.urgent_help_content_release_id)) {
        add("urgent_help_content_release_missing");
    }
    if (
        support.uncovered_window_disposition !==
        "withhold_new_enrollment_and_show_released_support_guidance"
    ) {
        add("uncovered_support_window_disposition_invalid");
    }
    if (support.support_availability_may_be_inferred !== false) {
        add("support_availability_inference_prohibited");
    }

    const startsAt = parseUtcTimestamp(manifest.validity.starts_at);
    const expiresAt = parseUtcTimestamp(manifest.validity.expires_at);
    const evaluatedAt = parseUtcTimestamp(evaluationTime);
    if (startsAt === null || expiresAt === null || evaluatedAt === null) {
        add("validity_timestamp_invalid");
    } else {
        if (startsAt >= expiresAt) add("validity_window_invalid");
        if (evaluatedAt < startsAt) add("manifest_not_yet_effective");
        if (evaluatedAt >= expiresAt) add("manifest_expired");
        const durationHours = (expiresAt - startsAt) / 3_600_000;
        if (durationHours > MAXIMUM_VALIDITY_HOURS) {
            add("manifest_validity_exceeds_ceiling");
        }
    }
    if (
        manifest.validity.maximum_validity_hours !== MAXIMUM_VALIDITY_HOURS ||
        manifest.validity.renewal_requires_new_manifest !== true ||
        manifest.validity.expiry_fails_closed !== true
    ) {
        add("validity_policy_invalid");
    }

    if (
        Object.values(manifest.prerequisites).some(
            (value) => !nonEmptyString(value),
        )
    ) {
        add("prerequisite_record_missing");
    }

    for (const role of requiredApprovalRoles) {
        const approval = manifest.approvals[role];
        if (
            !nonEmptyString(approval.approver_subject) ||
            !nonEmptyString(approval.approval_record) ||
            parseUtcTimestamp(approval.approved_at) === null
        ) {
            add("named_approval_missing");
        } else if (
            startsAt !== null &&
            parseUtcTimestamp(approval.approved_at) >= startsAt
        ) {
            add("approval_not_recorded_before_validity");
        }
    }

    const audit = manifest.audit;
    if (audit.append_only_required !== true) {
        add("audit_append_only_required");
    }
    if (
        audit.durable_before_effective_change !== true ||
        !nonEmptyString(audit.event_schema_release_id) ||
        audit.audit_failure_disposition !== "withhold"
    ) {
        add("audit_durability_policy_invalid");
    }
    if (!sameArray(audit.required_event_types, requiredAuditEventTypes)) {
        add("audit_event_inventory_invalid");
    }
    if (!sameArray(audit.required_fields, requiredAuditFields)) {
        add("audit_field_inventory_invalid");
    }
    if (
        audit.patient_identifiers_permitted !== false ||
        audit.clinical_content_permitted !== false ||
        audit.message_content_permitted !== false
    ) {
        add("audit_content_must_remain_prohibited");
    }

    const rollback = manifest.rollback;
    if (rollback.kill_switch_default_state !== "engaged") {
        add("kill_switch_must_default_engaged");
    }
    if (
        !nonEmptyString(rollback.rollback_target_release_id) ||
        !nonEmptyString(rollback.rollback_verification_record)
    ) {
        add("rollback_evidence_missing");
    }
    if (rollback.enrollment_and_disclosure_after_expiry_permitted !== false) {
        add("post_expiry_activity_must_remain_prohibited");
    }

    return {
        disposition:
            reasons.length === 0
                ? "eligible_for_external_go_no_go_review_only"
                : "hold",
        reasons,
    };
}

function applyMutation(target, mutation) {
    const segments = mutation.path.split(".");
    let cursor = target;
    for (const segment of segments.slice(0, -1)) {
        if (
            cursor === null ||
            typeof cursor !== "object" ||
            !(segment in cursor)
        ) {
            fail(`mutation path does not exist: ${mutation.path}`);
        }
        cursor = cursor[segment];
    }
    const last = segments.at(-1);
    if (cursor === null || typeof cursor !== "object" || !(last in cursor)) {
        fail(`mutation path does not exist: ${mutation.path}`);
    }
    cursor[last] = clone(mutation.value);
}

function validate(
    candidate,
    fixtures,
    foundation,
    config,
    { verifyGenerated = true, verifySources = true } = {},
) {
    if (
        !sameKeys(candidate, [
            "candidate_id",
            "policy_version",
            "schema_version",
            "status",
            "generated_at",
            "reviewed_source_commit",
            "product",
            "purpose",
            "disposition_vocabulary",
            "eligibility_boundary",
            "technical_ceilings",
            "required_approval_roles",
            "required_audit_event_types",
            "required_audit_fields",
            "committed_default_manifest",
            "constraints",
            "source_evidence",
        ])
    ) {
        fail("candidate field inventory changed");
    }
    if (candidate.candidate_id !== CANDIDATE_ID) fail("candidate_id drift");
    if (candidate.policy_version !== POLICY_VERSION)
        fail("policy_version drift");
    if (candidate.schema_version !== 0) fail("schema_version must remain 0");
    if (candidate.status !== "draft_default_off_not_approved_for_pilot") {
        fail("candidate status must remain draft/default-off");
    }
    if (candidate.generated_at !== GENERATED_AT) fail("generated_at drift");
    if (candidate.reviewed_source_commit !== REVIEWED_SOURCE_COMMIT) {
        fail("reviewed source commit drift");
    }
    if (candidate.product !== "Nightingale") fail("product identity drift");
    if (
        !sameArray(candidate.disposition_vocabulary, [
            "hold",
            "eligible_for_external_go_no_go_review_only",
        ])
    ) {
        fail("disposition vocabulary changed");
    }
    if (
        JSON.stringify(candidate.eligibility_boundary) !==
        JSON.stringify({
            positive_disposition_is_activation: false,
            positive_disposition_is_enrollment_authorization: false,
            positive_disposition_is_deployment_authorization: false,
            positive_disposition_is_patient_disclosure_authorization: false,
            external_go_no_go_record_still_required: true,
            runtime_evaluator_implemented: false,
            runtime_callers: 0,
        })
    ) {
        fail("eligibility boundary changed");
    }
    if (
        JSON.stringify(candidate.technical_ceilings) !==
        JSON.stringify({
            maximum_validity_hours: MAXIMUM_VALIDITY_HOURS,
            maximum_active_enrollments: MAXIMUM_ACTIVE_ENROLLMENTS,
            automatic_enrollment_permitted: false,
        })
    ) {
        fail("technical ceilings changed");
    }
    if (
        !sameArray(candidate.required_approval_roles, requiredApprovalRoles) ||
        !sameArray(
            candidate.required_audit_event_types,
            requiredAuditEventTypes,
        ) ||
        !sameArray(candidate.required_audit_fields, requiredAuditFields)
    ) {
        fail("required governance inventories changed");
    }
    if (!sameKeys(candidate.constraints, constraintKeys)) {
        fail("constraint field inventory changed");
    }
    for (const key of constraintKeys) {
        if (candidate.constraints[key] !== false) {
            fail(`constraint must remain false: ${key}`);
        }
    }

    validateManifestShape(
        candidate.committed_default_manifest,
        "committed default manifest",
    );
    const defaultManifest = candidate.committed_default_manifest;
    if (
        defaultManifest.state !== "draft_inactive" ||
        defaultManifest.go_no_go_review_requested !== false ||
        defaultManifest.runtime_activation_permitted !== false ||
        defaultManifest.manifest_revision !== 0 ||
        defaultManifest.scope.facility_handles.length !== 0 ||
        defaultManifest.scope.unit_handles.length !== 0 ||
        defaultManifest.scope.cohort.maximum_active_enrollments !== 0 ||
        defaultManifest.scope.languages.patient_locale_tags.length !== 0 ||
        defaultManifest.support_coverage.weekly_windows.length !== 0 ||
        Object.values(defaultManifest.prerequisites).some(
            (value) => value !== null,
        ) ||
        Object.values(defaultManifest.approvals).some(
            (approval) =>
                approval.approver_subject !== null ||
                approval.approval_record !== null ||
                approval.approved_at !== null,
        ) ||
        defaultManifest.validity.starts_at !== null ||
        defaultManifest.validity.expires_at !== null ||
        defaultManifest.rollback.kill_switch_default_state !== "engaged" ||
        defaultManifest.rollback.rollback_target_release_id !== null ||
        defaultManifest.rollback.rollback_verification_record !== null
    ) {
        fail("committed manifest must remain empty and default-off");
    }

    if (
        foundation.paths === null ||
        typeof foundation.paths !== "object" ||
        Array.isArray(foundation.paths) ||
        Object.keys(foundation.paths).length !== 0
    ) {
        fail("executable Nightingale contract must retain zero paths");
    }
    const activation = foundation["x-nightingale-activation"];
    for (const field of [
        "routes_registered",
        "route_registration_permitted",
        "network_clients_permitted",
        "identity_enabled",
        "inpatient_source_enabled",
        "production_source_query_permitted",
        "clinical_approval_recorded",
        "patient_content_released",
        "feature_activated",
        "pilot_enrollment_confirmed",
        "source_connector_deployed",
        "patient_disclosure_enabled",
        "patient_mutation_enabled",
        "production_enabled",
    ]) {
        if (activation?.[field] !== false) {
            fail(`foundation activation must remain false: ${field}`);
        }
    }
    for (const requiredConfigDefault of [
        "'routes_registered' => false",
        "'network_clients_permitted' => false",
        "'pilot_enrollment_state' => 'not_enrolled'",
        "'patient_disclosure_enabled' => false",
        "'patient_mutation_enabled' => false",
        "'production_enabled' => false",
    ]) {
        if (!config.includes(requiredConfigDefault)) {
            fail(`backend default drift: ${requiredConfigDefault}`);
        }
    }
    if (/env\s*\(/.test(config)) {
        fail("Nightingale foundation configuration must remain code-owned");
    }

    if (
        !sameKeys(fixtures, [
            "fixture_set_id",
            "fixture_class",
            "evaluation_time",
            "complete_synthetic_manifest",
            "cases",
            "expected_summary",
        ])
    ) {
        fail("fixture field inventory changed");
    }
    if (
        fixtures.fixture_set_id !==
            "nightingale.controlled-pilot-manifest.v0-fixtures" ||
        fixtures.fixture_class !== "synthetic-no-phi" ||
        fixtures.evaluation_time !== GENERATED_AT
    ) {
        fail("fixture identity changed");
    }
    validateManifestShape(
        fixtures.complete_synthetic_manifest,
        "complete synthetic manifest",
    );
    if (
        fixtures.complete_synthetic_manifest.runtime_activation_permitted !==
        false
    ) {
        fail("synthetic manifest must not permit runtime activation");
    }

    if (
        !Array.isArray(fixtures.cases) ||
        fixtures.cases.length !== EXPECTED_CASE_COUNT ||
        new Set(fixtures.cases.map((fixture) => fixture.case_id)).size !==
            fixtures.cases.length
    ) {
        fail(`fixture cases must contain ${EXPECTED_CASE_COUNT} unique cases`);
    }

    let holdCount = 0;
    let eligibleCount = 0;
    for (const fixture of fixtures.cases) {
        if (
            !sameKeys(fixture, [
                "case_id",
                "input",
                "mutations",
                "expected_disposition",
                "expected_primary_reason",
            ])
        ) {
            fail(`fixture field inventory changed: ${fixture.case_id}`);
        }
        if (
            !nonEmptyString(fixture.case_id) ||
            ![
                "committed_default_template",
                "complete_synthetic_manifest",
            ].includes(fixture.input) ||
            !Array.isArray(fixture.mutations)
        ) {
            fail(`invalid fixture structure: ${fixture.case_id}`);
        }
        const manifest =
            fixture.input === "committed_default_template"
                ? clone(candidate.committed_default_manifest)
                : clone(fixtures.complete_synthetic_manifest);
        for (const mutation of fixture.mutations) {
            if (
                !sameKeys(mutation, ["path", "value"]) ||
                !nonEmptyString(mutation.path)
            ) {
                fail(`invalid mutation in ${fixture.case_id}`);
            }
            applyMutation(manifest, mutation);
        }
        validateManifestShape(manifest, `fixture ${fixture.case_id}`);
        const result = evaluateManifest(manifest, fixtures.evaluation_time);
        if (result.disposition !== fixture.expected_disposition) {
            fail(
                `${fixture.case_id} expected ${fixture.expected_disposition}, found ${result.disposition}: ${result.reasons.join(", ")}`,
            );
        }
        if (
            fixture.expected_primary_reason === null
                ? result.reasons.length !== 0
                : !result.reasons.includes(fixture.expected_primary_reason)
        ) {
            fail(
                `${fixture.case_id} primary reason mismatch: ${result.reasons.join(", ")}`,
            );
        }
        if (result.disposition === "hold") holdCount += 1;
        else eligibleCount += 1;
    }

    if (
        holdCount !== EXPECTED_HOLD_COUNT ||
        eligibleCount !== EXPECTED_ELIGIBLE_COUNT ||
        JSON.stringify(fixtures.expected_summary) !==
            JSON.stringify({
                case_count: EXPECTED_CASE_COUNT,
                eligible_for_external_go_no_go_review_only:
                    EXPECTED_ELIGIBLE_COUNT,
                hold: EXPECTED_HOLD_COUNT,
            })
    ) {
        fail("fixture summary changed");
    }

    if (verifySources) {
        if (
            !Array.isArray(candidate.source_evidence) ||
            candidate.source_evidence.length !== sourcePaths.length ||
            !sameArray(
                candidate.source_evidence.map((entry) => entry.path),
                sourcePaths,
            )
        ) {
            fail("source-evidence path inventory changed");
        }
        for (const entry of candidate.source_evidence) {
            if (
                !sameKeys(entry, ["path", "sha256"]) ||
                !/^[0-9a-f]{64}$/.test(entry.sha256) ||
                entry.sha256 !== sha256(read(entry.path))
            ) {
                fail(`source checksum drift: ${entry.path}`);
            }
        }
    }

    const serialized = `${JSON.stringify(candidate)}${JSON.stringify(fixtures)}`;
    for (const prohibited of [
        "pgsql.acumenus.net",
        "pgslq.acumenus.net",
        "Acumenus321",
        "zephyrus.acumenus.net",
        "/api/nightingale/v1/",
    ]) {
        if (serialized.includes(prohibited)) {
            fail(
                `prohibited runtime/credential value in candidate: ${prohibited}`,
            );
        }
    }

    if (verifyGenerated) {
        let built;
        try {
            built = JSON.parse(
                execFileSync(
                    process.execPath,
                    [path.join(repoRoot, builderPath), repoRoot],
                    { encoding: "utf8" },
                ),
            );
        } catch (error) {
            fail(`builder execution failed: ${error.message}`);
        }
        if (
            JSON.stringify(candidate) !==
                JSON.stringify(built["candidate.json"]) ||
            JSON.stringify(fixtures) !== JSON.stringify(built["fixtures.json"])
        ) {
            fail("committed artifacts do not exactly match the builder");
        }
    }

    return {
        cases: fixtures.cases.length,
        hold: holdCount,
        eligible: eligibleCount,
        sources: candidate.source_evidence.length,
    };
}

const candidate = parseJson(candidatePath);
const fixtures = parseJson(fixturesPath);
const foundation = parseJson(foundationPath);
const config = read(configPath);
const summary = validate(candidate, fixtures, foundation, config);

let negativeSelfTests = 0;
if (selfTest) {
    const mutations = [
        [
            "candidate status",
            (c) => {
                c.status = "approved_for_pilot";
            },
        ],
        [
            "runtime constraint",
            (c) => {
                c.constraints.pilot_activation_permitted = true;
            },
        ],
        [
            "default scope",
            (c) => {
                c.committed_default_manifest.scope.facility_handles = [
                    "ngf_synthetic_alpha",
                ];
            },
        ],
        [
            "default validity",
            (c) => {
                c.committed_default_manifest.validity.expires_at =
                    "2026-08-03T19:00:00Z";
            },
        ],
        [
            "default approval",
            (c) => {
                c.committed_default_manifest.approvals.product_owner.approver_subject =
                    "synthetic-owner";
            },
        ],
        [
            "source checksum",
            (c) => {
                c.source_evidence[0].sha256 = "0".repeat(64);
            },
        ],
        [
            "eligibility boundary",
            (c) => {
                c.eligibility_boundary.positive_disposition_is_activation = true;
            },
        ],
        [
            "technical duration ceiling",
            (c) => {
                c.technical_ceilings.maximum_validity_hours = 720;
            },
        ],
        [
            "approval role inventory",
            (c) => {
                c.required_approval_roles.pop();
            },
        ],
        [
            "audit event inventory",
            (c) => {
                c.required_audit_event_types.pop();
            },
        ],
        [
            "audit field inventory",
            (c) => {
                c.required_audit_fields.pop();
            },
        ],
    ];

    const fixtureMutations = [
        [
            "fixture count",
            (f) => {
                f.cases.pop();
            },
        ],
        [
            "positive expected result",
            (f) => {
                f.cases[0].expected_disposition = "hold";
            },
        ],
        [
            "hold expected result",
            (f) => {
                f.cases[1].expected_disposition =
                    "eligible_for_external_go_no_go_review_only";
            },
        ],
        [
            "patient identifier",
            (f) => {
                f.complete_synthetic_manifest.patient_id = "synthetic";
            },
        ],
        [
            "runtime activation",
            (f) => {
                f.complete_synthetic_manifest.runtime_activation_permitted = true;
            },
        ],
        [
            "automatic enrollment",
            (f) => {
                f.complete_synthetic_manifest.scope.cohort.enrollment_is_automatic = true;
            },
        ],
        [
            "unbounded cohort",
            (f) => {
                f.complete_synthetic_manifest.scope.cohort.maximum_active_enrollments = 1000;
            },
        ],
        [
            "unbounded validity",
            (f) => {
                f.complete_synthetic_manifest.validity.expires_at =
                    "2026-09-27T19:00:00Z";
            },
        ],
        [
            "audit content",
            (f) => {
                f.complete_synthetic_manifest.audit.clinical_content_permitted = true;
            },
        ],
        [
            "kill switch released",
            (f) => {
                f.complete_synthetic_manifest.rollback.kill_switch_default_state =
                    "released";
            },
        ],
        [
            "post-expiry disclosure",
            (f) => {
                f.complete_synthetic_manifest.rollback.enrollment_and_disclosure_after_expiry_permitted = true;
            },
        ],
        [
            "support gap policy",
            (f) => {
                f.complete_synthetic_manifest.support_coverage.uncovered_window_disposition =
                    "continue";
            },
        ],
        [
            "exclusion disclosure",
            (f) => {
                f.complete_synthetic_manifest.scope.exclusions.exclusion_reason_disclosure_permitted = true;
            },
        ],
        [
            "language fallback",
            (f) => {
                f.complete_synthetic_manifest.scope.languages.unapproved_locale_fallback_permitted = true;
            },
        ],
    ];

    for (const [label, mutate] of mutations) {
        const mutatedCandidate = clone(candidate);
        mutate(mutatedCandidate);
        let rejected = false;
        try {
            validate(mutatedCandidate, clone(fixtures), foundation, config, {
                verifyGenerated: false,
            });
        } catch (error) {
            if (
                !String(error.message).startsWith(
                    "Nightingale controlled-pilot manifest violation:",
                )
            ) {
                throw error;
            }
            rejected = true;
        }
        if (!rejected) fail(`negative self-test did not fail: ${label}`);
        negativeSelfTests += 1;
    }

    for (const [label, mutate] of fixtureMutations) {
        const mutatedFixtures = clone(fixtures);
        mutate(mutatedFixtures);
        let rejected = false;
        try {
            validate(clone(candidate), mutatedFixtures, foundation, config, {
                verifyGenerated: false,
            });
        } catch (error) {
            if (
                !String(error.message).startsWith(
                    "Nightingale controlled-pilot manifest violation:",
                )
            ) {
                throw error;
            }
            rejected = true;
        }
        if (!rejected) fail(`negative self-test did not fail: ${label}`);
        negativeSelfTests += 1;
    }
}

process.stdout.write(
    `Nightingale controlled-pilot manifest verified: ${summary.cases} synthetic cases (${summary.hold} hold, ${summary.eligible} external go/no-go review only), ${summary.sources} checksum-bound sources${
        selfTest ? `, ${negativeSelfTests} negative self-tests` : ""
    }.\n`,
);
