#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const repoRoot = path.resolve(process.argv[2] ?? ".");
const outputDirectory = path.join(
    repoRoot,
    "docs/nightingale/api-contract/candidates/today/v0",
);

const CANDIDATE_ID = "nightingale.today-projection.v0-candidate";
const POLICY_VERSION = "nightingale-today-policy.v0-candidate";
const CONTENT_POLICY_VERSION = "nightingale-patient-language.v0-candidate";
const ROUTE_NAMESPACE = "/api/nightingale/v1";
const CANDIDATE_PATH = "/inpatient-contexts/{encounter_handle}/today";
const OPERATION_ID = "getNightingaleTodayProjection";
const FIXED_TIME = "2026-07-26T15:00:00Z";
const HANDLE = `ntg_enc_${"a".repeat(50)}`;
const REQUEST_ID = `ntg_req_${"a".repeat(50)}`;
const REVISION = `ntg_tdyrev_${"a".repeat(50)}`;
const ITEM_HANDLE = `ntg_tdyitem_${"a".repeat(50)}`;

const sourcePaths = [
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

function sha256(filePath) {
    return crypto
        .createHash("sha256")
        .update(fs.readFileSync(filePath))
        .digest("hex");
}

function sourceEvidence(relativePath) {
    return {
        path: relativePath,
        sha256: sha256(path.join(repoRoot, relativePath)),
    };
}

function field(
    value,
    {
        freshness = "current",
        uncertainty = "low",
        canChange = true,
        locale = "en-US",
        languageRelease = "approved-source-language",
        correction = "original",
        patientNotice = null,
        observedAt = "2026-07-26T14:55:00Z",
        releasedAt = "2026-07-26T14:58:00Z",
    } = {},
) {
    return {
        value,
        context: {
            release: {
                state: "released",
                released_at: releasedAt,
                content_policy_version: CONTENT_POLICY_VERSION,
            },
            freshness: {
                status: freshness,
                observed_at: observedAt,
                patient_notice: patientNotice,
            },
            uncertainty: {
                level: uncertainty,
                explanation:
                    uncertainty === "low"
                        ? "This is the latest released information available for this item."
                        : "This information may change as your care team learns more.",
                can_change: canChange,
            },
            language: {
                locale,
                release_state: languageRelease,
                plain_language_review: "approved",
            },
            correction: {
                state: correction,
                patient_notice:
                    correction === "corrected"
                        ? "Your care team updated this information. Please use the details shown here."
                        : null,
            },
            offline: {
                availability: "online-only",
                durable_storage_permitted: false,
            },
        },
    };
}

function releasedList(items) {
    return {
        state: "released",
        items,
        patient_notice: null,
    };
}

function releasedEmptyList() {
    return {
        state: "released-empty",
        items: [],
        patient_notice:
            "No items have been released for this section. This does not mean that no care is planned.",
    };
}

function notAvailableSection() {
    return {
        state: "not-available",
        patient_notice:
            "This part of today’s plan is not available here. Ask your care team for the current information.",
    };
}

function visitGovernedFields(value, callback) {
    if (Array.isArray(value)) {
        for (const item of value) visitGovernedFields(item, callback);
        return;
    }

    if (value === null || typeof value !== "object") return;

    if (
        Object.hasOwn(value, "value") &&
        Object.hasOwn(value, "context") &&
        Object.keys(value).length === 2
    ) {
        callback(value);
        return;
    }

    for (const child of Object.values(value)) {
        visitGovernedFields(child, callback);
    }
}

function baseSections() {
    return {
        headline: {
            state: "released",
            field: field("Your care plan for today"),
        },
        summary: {
            state: "released",
            field: field(
                "Your care team has released the following information for today.",
            ),
        },
        schedule: releasedList([
            {
                item_handle: ITEM_HANDLE,
                label: field("Morning test"),
                detail: field(
                    "Your care team will explain what to expect before the test.",
                ),
                status: field("planned"),
                time_window: field("This morning", {
                    uncertainty: "medium",
                }),
                timing_confidence: field("estimated", {
                    uncertainty: "medium",
                }),
                preparation: field(
                    "Please ask your nurse whether you need to prepare.",
                    {
                        uncertainty: "medium",
                    },
                ),
                can_change: field(true, {
                    uncertainty: "medium",
                }),
            },
        ]),
        next_steps: releasedList([
            field("Talk with your care team during rounds."),
        ]),
        care_location: {
            state: "released",
            fields: {
                facility_display_name: field("Acumenus Medical Center"),
                unit_display_name: field("Medical unit"),
                room_display_name: field("Your current room"),
                status: field("current"),
            },
            patient_notice: null,
        },
        discharge_outlook: {
            state: "released",
            fields: {
                estimated_range: field(
                    "Your care team is still reviewing timing.",
                    {
                        uncertainty: "unknown",
                    },
                ),
                confidence: field("unknown", {
                    uncertainty: "unknown",
                }),
                readiness_topics: releasedList([
                    field("Safe movement and support at home."),
                ]),
                remaining_steps: releasedList([
                    field("Your team will review what needs to happen next."),
                ]),
                can_change: field(true, {
                    uncertainty: "unknown",
                }),
            },
            patient_notice:
                "This is not a promised discharge date. Timing can change.",
        },
        questions: releasedList([
            field("What should I expect before my next planned step?"),
        ]),
        notices: releasedList([
            field(
                "Care plans can change. Ask your care team if this does not match what you were told.",
            ),
        ]),
    };
}

function successBody(sections = baseSections()) {
    return {
        data: {
            kind: "today",
            content_revision: REVISION,
            sections,
            revision_notice: null,
        },
        meta: {
            request_id: REQUEST_ID,
            generated_at: FIXED_TIME,
            policy_version: POLICY_VERSION,
            authorization_evaluated_at: FIXED_TIME,
            inpatient_context_evaluated_at: FIXED_TIME,
            locale: "en-US",
            completeness: "governed-evaluation-complete",
        },
        links: {},
    };
}

const responseHeaders = {
    "Cache-Control": "private, no-store, max-age=0",
    Pragma: "no-cache",
    "X-Robots-Tag": "noindex, nofollow, noarchive",
    Vary: "Authorization, Accept-Language",
    "Content-Type": "application/json",
};

function response(status, body) {
    return {
        status,
        headers: responseHeaders,
        body,
    };
}

function errorBody(code, message, completeness = "withheld") {
    return {
        data: null,
        error: { code, message },
        meta: {
            request_id: REQUEST_ID,
            generated_at: FIXED_TIME,
            policy_version: POLICY_VERSION,
            authorization_evaluated_at: FIXED_TIME,
            inpatient_context_evaluated_at: FIXED_TIME,
            locale: "en-US",
            completeness,
        },
        links: {},
    };
}

const fullSections = baseSections();

const minimalSections = baseSections();
minimalSections.schedule = notAvailableSection();
minimalSections.next_steps = notAvailableSection();
minimalSections.care_location = notAvailableSection();
minimalSections.discharge_outlook = notAvailableSection();
minimalSections.questions = notAvailableSection();
minimalSections.notices = notAvailableSection();

const emptyScheduleSections = baseSections();
emptyScheduleSections.schedule = releasedEmptyList();

const unavailableSections = baseSections();
unavailableSections.care_location = notAvailableSection();
unavailableSections.discharge_outlook = notAvailableSection();

const staleSections = baseSections();
staleSections.schedule.items[0].time_window = field("This morning", {
    freshness: "approved-stale",
    uncertainty: "high",
    patientNotice:
        "This time window has not been refreshed recently and may have changed. Ask your care team before relying on it.",
    observedAt: "2026-07-26T12:00:00Z",
    releasedAt: "2026-07-26T12:05:00Z",
});

const correctedSections = baseSections();
correctedSections.schedule.items[0].time_window = field("Later today", {
    correction: "corrected",
    uncertainty: "medium",
});
const correctionBody = successBody(correctedSections);
correctionBody.data.revision_notice = {
    kind: "correction",
    message:
        "Your care team updated part of today’s plan. Please use the information shown here.",
};

const translatedSections = baseSections();
visitGovernedFields(translatedSections, (governedField) => {
    governedField.context.language.release_state = "approved-translation";
});

const sourceLanguageSections = baseSections();

const delayedSections = baseSections();
delayedSections.schedule.items[0].status = field("delayed", {
    uncertainty: "high",
});
delayedSections.schedule.items[0].time_window = field(
    "Your care team is reviewing the timing.",
    {
        uncertainty: "high",
    },
);

const dischargeUnknownSections = baseSections();
dischargeUnknownSections.discharge_outlook.fields.estimated_range = field(
    "Discharge timing is not known yet.",
    {
        uncertainty: "unknown",
    },
);
dischargeUnknownSections.discharge_outlook.fields.confidence = field(
    "unknown",
    {
        uncertainty: "unknown",
    },
);
dischargeUnknownSections.discharge_outlook.fields.can_change = field(true, {
    uncertainty: "unknown",
});

const locationUpdatingSections = baseSections();
locationUpdatingSections.care_location.fields.room_display_name = field(
    "Your care location is being updated.",
    {
        uncertainty: "medium",
    },
);
locationUpdatingSections.care_location.fields.status = field("updating", {
    uncertainty: "medium",
});

const distinctContextSections = baseSections();
distinctContextSections.schedule.items[0].time_window = field("This morning", {
    freshness: "approved-stale",
    uncertainty: "high",
    patientNotice:
        "This time window has not been refreshed recently and may have changed. Ask your care team before relying on it.",
    observedAt: "2026-07-26T12:00:00Z",
    releasedAt: "2026-07-26T12:05:00Z",
});
distinctContextSections.schedule.items[0].preparation = field(
    "Please ask your nurse whether you need to prepare.",
    {
        languageRelease: "approved-translation",
        uncertainty: "medium",
    },
);
distinctContextSections.next_steps.items[0] = field(
    "Talk with your care team during rounds.",
    {
        correction: "corrected",
        uncertainty: "medium",
    },
);

const responseTemplates = {
    success_full_current: response(200, successBody(fullSections)),
    success_minimal: response(200, successBody(minimalSections)),
    success_schedule_empty: response(200, successBody(emptyScheduleSections)),
    success_optional_unavailable: response(
        200,
        successBody(unavailableSections),
    ),
    success_approved_stale: response(200, successBody(staleSections)),
    success_correction: response(200, correctionBody),
    success_approved_translation: response(
        200,
        successBody(translatedSections),
    ),
    success_approved_source_language: response(
        200,
        successBody(sourceLanguageSections),
    ),
    success_delayed_high_uncertainty: response(
        200,
        successBody(delayedSections),
    ),
    success_discharge_unknown_timing: response(
        200,
        successBody(dischargeUnknownSections),
    ),
    success_location_updating: response(
        200,
        successBody(locationUpdatingSections),
    ),
    success_distinct_field_contexts: response(
        200,
        successBody(distinctContextSections),
    ),
    not_found: response(
        404,
        errorBody("not_found", "This experience is not available."),
    ),
    authentication_required: response(
        401,
        errorBody("authentication_required", "Sign in is required."),
    ),
    access_unavailable: response(
        403,
        errorBody(
            "access_unavailable",
            "This experience is not available for this account.",
        ),
    ),
    account_state_requires_review: response(
        409,
        errorBody(
            "account_state_requires_review",
            "We could not safely confirm the hospital stay for this request. Please ask your care team for help.",
        ),
    ),
    rate_limited: response(
        429,
        errorBody(
            "rate_limited",
            "Too many requests were submitted. Please try again later.",
        ),
    ),
    temporarily_unavailable: response(
        503,
        errorBody(
            "temporarily_unavailable",
            "Today’s released information is temporarily unavailable. Please ask your care team if you need help now.",
            "evaluation-incomplete",
        ),
    ),
};

const SUCCESS_AUDIT = "durable-evaluation-and-disclosure";
const DENIAL_AUDIT = "best-effort-indistinguishable-denial";
const SAFETY_AUDIT = "best-effort-safety-failure";

const cases = [
    [
        "current_full",
        "allow",
        "success_full_current",
        SUCCESS_AUDIT,
        "all gates pass and every Today section has governed released content",
    ],
    [
        "current_minimal",
        "allow",
        "success_minimal",
        SUCCESS_AUDIT,
        "mandatory headline and summary are released while optional sections are explicitly unavailable",
    ],
    [
        "schedule_released_empty",
        "allow",
        "success_schedule_empty",
        SUCCESS_AUDIT,
        "authoritative evaluation completed and no schedule items were released",
    ],
    [
        "optional_sections_not_available",
        "allow",
        "success_optional_unavailable",
        SUCCESS_AUDIT,
        "care location and discharge outlook are explicitly unavailable without implying absence",
    ],
    [
        "approved_stale_with_notice",
        "allow",
        "success_approved_stale",
        SUCCESS_AUDIT,
        "field-specific stale use is clinically approved and carries a patient notice",
    ],
    [
        "corrected_replacement",
        "allow",
        "success_correction",
        SUCCESS_AUDIT,
        "a corrected replacement release is effective and the withdrawn value is not serialized",
    ],
    [
        "approved_translation",
        "allow",
        "success_approved_translation",
        SUCCESS_AUDIT,
        "every displayed field has an approved translation for the response locale",
    ],
    [
        "approved_source_language",
        "allow",
        "success_approved_source_language",
        SUCCESS_AUDIT,
        "the approved source language matches the response locale",
    ],
    [
        "delayed_schedule_high_uncertainty",
        "allow",
        "success_delayed_high_uncertainty",
        SUCCESS_AUDIT,
        "a delayed item uses approved patient language and field-specific high uncertainty",
    ],
    [
        "discharge_outlook_unknown_timing",
        "allow",
        "success_discharge_unknown_timing",
        SUCCESS_AUDIT,
        "the discharge outlook explicitly communicates unknown timing and that it can change",
    ],
    [
        "care_location_updating",
        "allow",
        "success_location_updating",
        SUCCESS_AUDIT,
        "location status is released as updating without asserting an unconfirmed destination",
    ],
    [
        "distinct_field_contexts",
        "allow",
        "success_distinct_field_contexts",
        SUCCESS_AUDIT,
        "each displayed field retains its own release, freshness, uncertainty, language, correction, and offline context",
    ],
    [
        "product_disabled",
        "release-gate",
        "not_found",
        DENIAL_AUDIT,
        "Nightingale product activation is false",
    ],
    [
        "operation_disabled",
        "release-gate",
        "not_found",
        DENIAL_AUDIT,
        "Today operation activation is false",
    ],
    [
        "facility_disabled",
        "release-gate",
        "not_found",
        DENIAL_AUDIT,
        "the facility is outside the approved Nightingale rollout",
    ],
    [
        "cohort_ineligible",
        "authorization",
        "access_unavailable",
        DENIAL_AUDIT,
        "the authenticated person is outside the approved inpatient cohort",
    ],
    [
        "authentication_missing",
        "identity",
        "authentication_required",
        DENIAL_AUDIT,
        "no approved Nightingale session is present",
    ],
    [
        "wrong_identity_realm",
        "identity",
        "access_unavailable",
        DENIAL_AUDIT,
        "a staff or legacy patient credential is presented",
    ],
    [
        "principal_inactive",
        "identity",
        "access_unavailable",
        DENIAL_AUDIT,
        "the Nightingale principal is inactive",
    ],
    [
        "session_missing",
        "identity",
        "authentication_required",
        DENIAL_AUDIT,
        "identity is known but no active request session exists",
    ],
    [
        "session_expired",
        "identity",
        "authentication_required",
        DENIAL_AUDIT,
        "the Nightingale session is expired",
    ],
    [
        "session_revoked",
        "identity",
        "authentication_required",
        DENIAL_AUDIT,
        "the Nightingale session is revoked",
    ],
    [
        "identity_link_missing",
        "identity",
        "access_unavailable",
        DENIAL_AUDIT,
        "no verified self identity link is available",
    ],
    [
        "identity_link_revoked",
        "identity",
        "access_unavailable",
        DENIAL_AUDIT,
        "the self identity link is revoked",
    ],
    [
        "wrong_principal_handle",
        "non-disclosure",
        "not_found",
        DENIAL_AUDIT,
        "the opaque context handle belongs to a different principal",
    ],
    [
        "unknown_handle",
        "non-disclosure",
        "not_found",
        DENIAL_AUDIT,
        "the opaque context handle is not known",
    ],
    [
        "malformed_handle",
        "non-disclosure",
        "not_found",
        DENIAL_AUDIT,
        "the context handle fails the Nightingale format",
    ],
    [
        "representative_relationship_held",
        "authorization",
        "access_unavailable",
        DENIAL_AUDIT,
        "representative access has not been approved",
    ],
    [
        "sensitive_encounter_held",
        "authorization",
        "access_unavailable",
        DENIAL_AUDIT,
        "the encounter requires a sensitive-service policy that is not approved",
    ],
    [
        "current_context_closed",
        "lifecycle",
        "not_found",
        DENIAL_AUDIT,
        "the authoritative inpatient context is closed before disclosure",
    ],
    [
        "current_context_changed",
        "race",
        "not_found",
        DENIAL_AUDIT,
        "the context becomes ineligible during the pre-serialization recheck",
    ],
    [
        "handle_mapping_inconsistent",
        "integrity",
        "account_state_requires_review",
        SAFETY_AUDIT,
        "the handle mapping is missing, duplicated, or points to inconsistent context state",
    ],
    [
        "source_unavailable",
        "dependency",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the approved current-inpatient or projection source is unavailable",
    ],
    [
        "database_unavailable",
        "dependency",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the governed projection store is unavailable",
    ],
    [
        "today_capability_denied",
        "authorization",
        "not_found",
        DENIAL_AUDIT,
        "server-side authorization denies the Today capability",
    ],
    [
        "no_released_projection",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "no released Today projection exists",
    ],
    [
        "draft_projection",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "only a draft Today projection exists",
    ],
    [
        "future_release",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "the Today release is not yet effective",
    ],
    [
        "retracted_projection",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "the effective Today release is retracted",
    ],
    [
        "correction_without_replacement",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "a correction withdraws the target but no effective replacement is released",
    ],
    [
        "conflicting_active_releases",
        "integrity",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "more than one effective release claims the same sequence or authority",
    ],
    [
        "policy_version_mismatch",
        "release",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the projection and active Nightingale release policy versions do not match",
    ],
    [
        "clinical_approval_missing",
        "release",
        "not_found",
        DENIAL_AUDIT,
        "clinical approval is absent for a patient-visible field",
    ],
    [
        "patient_language_approval_missing",
        "language",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "plain-language approval is absent for a displayed field",
    ],
    [
        "translation_approval_missing",
        "language",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the requested-locale translation is not approved",
    ],
    [
        "locale_mismatch",
        "language",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "field locale and response locale differ without an approved fallback",
    ],
    [
        "freshness_decision_missing",
        "freshness",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a displayed field has no approved freshness decision",
    ],
    [
        "unapproved_stale_content",
        "freshness",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a stale field lacks an approved stale-use policy and patient notice",
    ],
    [
        "unknown_freshness",
        "freshness",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "mandatory content has unknown freshness",
    ],
    [
        "mandatory_headline_missing",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the released document has no governed headline",
    ],
    [
        "mandatory_summary_missing",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the released document has no governed summary",
    ],
    [
        "ungoverned_field",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a patient-visible value lacks its complete field context",
    ],
    [
        "aggregate_context_only",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "freshness, uncertainty, or provenance exists only at document level",
    ],
    [
        "internal_identifier_present",
        "content-safety",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "content or context contains an internal, source, grant, encounter, staff, or patient identifier",
    ],
    [
        "staff_only_content_present",
        "content-safety",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "content includes staff-only prose, prioritization, disagreement, or an unreleased result",
    ],
    [
        "invalid_schedule_status",
        "vocabulary",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a schedule status is outside the approved Nightingale patient vocabulary",
    ],
    [
        "invalid_timing_confidence",
        "vocabulary",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "timing confidence is outside the approved patient vocabulary",
    ],
    [
        "invalid_item_handle",
        "identifier",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a schedule item handle fails the Nightingale format",
    ],
    [
        "duplicate_item_handle",
        "identifier",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "two schedule entries use the same item handle",
    ],
    [
        "invalid_section_state",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "an optional section has an unknown availability state",
    ],
    [
        "released_empty_with_items",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a released-empty section also contains items",
    ],
    [
        "not_available_with_content",
        "schema",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a not-available section also contains patient content",
    ],
    [
        "inconsistent_timestamps",
        "integrity",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "observed, released, generated, or authorization timestamps are not coherently ordered",
    ],
    [
        "correction_actor_exposed",
        "content-safety",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "a correction notice exposes an actor, reason, target identifier, or withdrawn value",
    ],
    [
        "request_audit_unavailable",
        "audit",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "durable request evaluation audit cannot be recorded",
    ],
    [
        "disclosure_audit_unavailable",
        "audit",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "durable field disclosure audit cannot be recorded",
    ],
    [
        "response_serialization_failure",
        "integrity",
        "temporarily_unavailable",
        SAFETY_AUDIT,
        "the governed response cannot be serialized atomically",
    ],
    [
        "rate_limited",
        "ingress",
        "rate_limited",
        DENIAL_AUDIT,
        "the approved principal-specific rate limit is exceeded",
    ],
].map(([case_id, category, expected_template, audit_mode, precondition]) => ({
    case_id,
    category,
    preconditions: [precondition],
    expected_template,
    audit_mode,
}));

const candidate = {
    artifact_kind: "nightingale-nonrunnable-api-candidate",
    candidate_id: CANDIDATE_ID,
    status: "held-no-operation",
    purpose:
        "Describe one patient-safe Today projection using field-level governance without adding an API operation, route, provider, client, source query, patient, or activation.",
    operation: {
        method: "GET",
        route_namespace: ROUTE_NAMESPACE,
        path: CANDIDATE_PATH,
        operation_id: OPERATION_ID,
        openapi_inclusion: false,
        route_registration_permitted: false,
        client_generation_permitted: false,
        network_client_permitted: false,
        legacy_alias_permitted: false,
    },
    activation: {
        product: false,
        operation: false,
        identity: false,
        inpatient_source: false,
        projection_source: false,
        clinical_content_release: false,
        localization: false,
        disclosure: false,
        native_clients: false,
        nonproduction_integration: false,
        production: false,
    },
    request: {
        path_parameter: "encounter_handle",
        encounter_handle_pattern: "^ntg_enc_[a-z2-7]{50}$",
        query_parameters: [],
        request_body_permitted: false,
        source_identifier_input_permitted: false,
        legacy_identifier_input_permitted: false,
        durable_client_storage_permitted: false,
    },
    audience: {
        permitted_relationships: ["self"],
        representative_access: "held",
        sensitive_encounter_access: "held",
    },
    response_contract: {
        envelope_fields: ["data", "meta", "links"],
        data_fields: [
            "kind",
            "content_revision",
            "sections",
            "revision_notice",
        ],
        kind: "today",
        content_revision_pattern: "^ntg_tdyrev_[a-z2-7]{50}$",
        schedule_item_handle_pattern: "^ntg_tdyitem_[a-z2-7]{50}$",
        section_names: [
            "headline",
            "summary",
            "schedule",
            "next_steps",
            "care_location",
            "discharge_outlook",
            "questions",
            "notices",
        ],
        mandatory_released_sections: ["headline", "summary"],
        optional_section_states: [
            "released",
            "released-empty",
            "not-available",
        ],
        governed_value_fields: ["value", "context"],
        field_context_fields: [
            "release",
            "freshness",
            "uncertainty",
            "language",
            "correction",
            "offline",
        ],
        root_aggregate_provenance_permitted: false,
        root_aggregate_freshness_permitted: false,
        root_aggregate_uncertainty_permitted: false,
        patient_visible_value_without_field_context_permitted: false,
        released_empty_means_no_care_planned: false,
        not_available_means_no_care_planned: false,
        durable_client_cache_permitted: false,
        cache_control: "private, no-store, max-age=0",
    },
    field_context: {
        release_fields: ["state", "released_at", "content_policy_version"],
        release_states: ["released"],
        freshness_fields: ["status", "observed_at", "patient_notice"],
        freshness_states: ["current", "approved-stale"],
        approved_stale_requires_patient_notice: true,
        uncertainty_fields: ["level", "explanation", "can_change"],
        uncertainty_levels: ["low", "medium", "high", "unknown"],
        language_fields: ["locale", "release_state", "plain_language_review"],
        language_release_states: [
            "approved-source-language",
            "approved-translation",
        ],
        plain_language_review_states: ["approved"],
        correction_fields: ["state", "patient_notice"],
        correction_states: ["original", "corrected"],
        corrected_requires_patient_notice: true,
        offline_fields: ["availability", "durable_storage_permitted"],
        offline_availability: ["online-only"],
        offline_durable_storage_permitted: false,
    },
    content_rules: {
        schedule_statuses: [
            "requested",
            "planned",
            "confirmed",
            "in-progress",
            "completed",
            "delayed",
            "canceled",
        ],
        timing_confidence: ["confirmed", "estimated", "unknown"],
        care_location_statuses: ["current", "updating", "unknown"],
        correction_notice_kinds: ["correction"],
        exact_time_or_eta_claim_permitted: false,
        raw_source_text_permitted: false,
        staff_only_text_permitted: false,
        internal_identifier_permitted: false,
        field_level_locale_required: true,
        field_level_release_required: true,
        field_level_freshness_required: true,
        field_level_uncertainty_required: true,
        field_level_correction_required: true,
        field_level_offline_rule_required: true,
    },
    authorization_gates: [
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
    ],
    failure_codes: {
        not_found: 404,
        authentication_required: 401,
        access_unavailable: 403,
        account_state_requires_review: 409,
        rate_limited: 429,
        temporarily_unavailable: 503,
    },
    audit: {
        request_event: "nightingale.today.evaluated",
        disclosure_event: "nightingale.today.fields_disclosed",
        success_requires_durable_request_audit: true,
        success_requires_durable_field_disclosure_audit: true,
        raw_patient_value_recording_permitted: false,
        raw_handle_recording_permitted: false,
        source_identifier_recording_permitted: false,
        staff_actor_recording_permitted: false,
        free_text_recording_permitted: false,
    },
    evidence: {
        product_universe_source_count: 256,
        product_universe_inventory_digest:
            "a307e1957df7ef78eb61a9a9123f3902fd8929ebb3aaeb4dce48f2c88fb4a881",
        classification_ids: [
            "nightingale-source-classification.v1",
            "nightingale-journey-preference-presentation-release-source-classification.v1",
        ],
        direct_sources: sourcePaths.map(sourceEvidence),
    },
    required_fixture_case_ids: cases.map(({ case_id }) => case_id),
    approval_gates: [
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
    ],
};

const fixtures = {
    fixture_schema: "nightingale.today-projection.candidate-fixtures.v0",
    candidate_id: CANDIDATE_ID,
    synthetic_only: true,
    production_replay_permitted: false,
    contains_real_patient_data: false,
    request_template: {
        method: "GET",
        route_namespace: ROUTE_NAMESPACE,
        path: CANDIDATE_PATH,
        path_parameters: {
            encounter_handle: HANDLE,
        },
        query_parameters: {},
        body: null,
        authentication_context: "approved-nightingale-session-required",
    },
    response_templates: responseTemplates,
    cases,
};

fs.mkdirSync(outputDirectory, { recursive: true });
fs.writeFileSync(
    path.join(outputDirectory, "candidate.json"),
    `${JSON.stringify(candidate, null, 2)}\n`,
);
fs.writeFileSync(
    path.join(outputDirectory, "fixtures.json"),
    `${JSON.stringify(fixtures, null, 2)}\n`,
);

process.stdout.write(
    `Built held Nightingale Today candidate with ${cases.length} synthetic cases.\n`,
);
