#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const args = process.argv.slice(2);
const write = args.includes("--write");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--write",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

if (unknownOptions.length > 0 || positional.length > 1) {
    process.stderr.write(
        "Usage: build-nightingale-patient-journey-reference-candidate.mjs [repository-root] [--write]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const outputDirectory = path.join(
    repoRoot,
    "docs/nightingale/api-contract/candidates/patient-journeys/v0",
);

const CANDIDATE_ID = "nightingale.patient-journey-reference.v0-candidate";
const POLICY_VERSION = "nightingale-patient-journey-reference.v0-candidate";
const REVIEWED_SOURCE_COMMIT = "a825db89ec1efaf4b2c55a26e04d41161445b001";
const FIXED_TIME = "2026-07-27T17:00:00Z";

const sourcePaths = [
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

const globalApprovalGates = [
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

const globalConstraints = {
    implementation_permitted: false,
    executable_contract_path_permitted: false,
    route_registration_permitted: false,
    controller_or_provider_binding_permitted: false,
    client_generation_permitted: false,
    native_networking_permitted: false,
    identity_provider_selection_permitted: false,
    source_adapter_selection_permitted: false,
    database_query_permitted: false,
    production_data_use_permitted: false,
    production_mutation_permitted: false,
    patient_or_representative_creation_permitted: false,
    clinical_content_release_permitted: false,
    communication_mutation_permitted: false,
    notification_delivery_permitted: false,
    offline_phi_storage_permitted: false,
    migration_execution_permitted: false,
    deployment_permitted: false,
    pilot_activation_permitted: false,
};

const sharedNondisclosure = [
    "no-source-system-identifier",
    "no-patient-principal-grant-encounter-or-staff-identifier",
    "no-unreleased-clinical-value",
    "no-withheld-resource-existence-or-denial-reason",
    "no-internal-routing-pool-or-staffing-detail",
    "no-approval-actor-or-internal-correction-target",
];

const sharedAudit = [
    "journey-evaluation-with-policy-and-case-id",
    "authorization-and-current-context-outcome",
    "disclosed-field-keys-without-values",
    "communication-state-transition-without-message-body",
    "withhold-correction-retraction-or-outage-reason-code-without-clinical-content",
];

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function sourceEvidence(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    return {
        path: relativePath,
        sha256: sha256(fs.readFileSync(absolutePath)),
    };
}

function journeyCase({
    caseId,
    familyId,
    actor = "self",
    encounterState = "current-inpatient",
    preconditions,
    event,
    disclosureMode,
    surfaces,
    patientMessageKey,
    stateTransitions,
    communication = {
        compose_state: "not-applicable",
        accepted_state_meaning: "not-applicable",
        routing_state: "not-applicable",
        notification_state: "not-available",
        volatile_draft_action: "clear-on-inactive-or-context-loss",
    },
    specificApprovals = [],
    prohibitedInferences = [],
    accessibilityProfile = "same-information-and-actions-through-supported-presentation",
}) {
    return {
        case_id: caseId,
        family_id: familyId,
        fixture_class: "synthetic-no-phi",
        execution_status: "held-not-runnable",
        actor,
        encounter_state: encounterState,
        preconditions,
        event,
        expected: {
            authorization_result:
                disclosureMode === "generic-withhold"
                    ? "indistinguishable-withhold"
                    : "eligible-only-after-every-required-gate",
            disclosure: {
                mode: disclosureMode,
                surfaces,
                patient_message_key: patientMessageKey,
                values_must_be_separately_released: true,
                atomic_response_required: true,
                no_store_required: true,
            },
            state_transitions: stateTransitions,
            communication,
            nondisclosure: [...sharedNondisclosure],
            prohibited_inferences: prohibitedInferences,
            accessibility: {
                profile: accessibilityProfile,
                essential_information_in_imagery_permitted: false,
                color_only_state_permitted: false,
                patient_action_loss_permitted: false,
            },
            audit: [...sharedAudit],
            offline: {
                phi_cache_permitted: false,
                stale_value_may_be_presented_as_current: false,
                queued_mutation_permitted: false,
                last_known_value_requires_separate_approved_policy: true,
            },
            rollback: {
                kill_switch_required: true,
                release_withdrawal_required: true,
                cached_handle_and_volatile_draft_purge_required: true,
            },
        },
        required_approval_gates: [...globalApprovalGates, ...specificApprovals],
    };
}

const cases = [
    journeyCase({
        caseId: "adult_admission_context_established",
        familyId: "admission",
        preconditions: [
            "self-identity-and-session-approved",
            "authoritative-current-inpatient-context-established",
            "no-patient-facing-projection-yet-released",
        ],
        event: "admission-context-becomes-current",
        disclosureMode: "released-empty-or-not-available",
        surfaces: ["today", "my-path", "care-team"],
        patientMessageKey: "journey.admission.waiting_for_released_information",
        stateTransitions: [
            "encounter-access-empty-to-one-current-context",
            "each-unreleased-surface-remains-not-available",
            "released-empty-never-means-no-care-is-planned",
        ],
        prohibitedInferences: [
            "admission-context-implies-diagnosis",
            "absence-of-projection-implies-absence-of-plan",
        ],
        specificApprovals: [
            "approved-admission-context-and-first-patient-release-policy",
        ],
    }),
    journeyCase({
        caseId: "unit_transfer_old_location_withdrawn",
        familyId: "unit-transfer",
        preconditions: [
            "one-current-inpatient-context",
            "old-care-location-was-released",
            "authoritative-transfer-event-reconciled",
        ],
        event: "intra-hospital-unit-transfer",
        disclosureMode: "correction-replacement-or-not-available",
        surfaces: ["today", "care-team", "communication"],
        patientMessageKey: "journey.transfer.location_updating",
        stateTransitions: [
            "old-location-withdrawn-before-new-location-disclosure",
            "new-location-shown-only-after-separate-release",
            "open-accountable-work-rerouted-without-copying-message-content",
        ],
        communication: {
            compose_state: "held-until-current-context-and-routing-revalidated",
            accepted_state_meaning: "server-acceptance-only",
            routing_state: "content-free-reroute-or-unresolved",
            notification_state: "not-available",
            volatile_draft_action: "preserve-only-if-same-authorized-context",
        },
        prohibitedInferences: [
            "transfer-implies-clinical-condition",
            "routing-change-implies-a-specific-staff-member-read-the-message",
        ],
        specificApprovals: [
            "approved-transfer-source-and-current-location-release-policy",
            "approved-transfer-communication-reroute-policy",
        ],
    }),
    journeyCase({
        caseId: "procedure_plan_released_without_outcome_inference",
        familyId: "procedure",
        preconditions: [
            "procedure-plan-field-separately-released",
            "timing-confidence-and-can-change-state-present",
            "no-procedure-outcome-released",
        ],
        event: "procedure-during-admission",
        disclosureMode: "released-fields-only",
        surfaces: ["today", "my-path"],
        patientMessageKey: "journey.procedure.released_plan_only",
        stateTransitions: [
            "planned-to-confirmed-only-from-authoritative-released-state",
            "in-progress-or-completed-never-inferred-from-clock-time",
            "outcome-remains-absent-until-separately-released",
        ],
        prohibitedInferences: [
            "scheduled-time-implies-procedure-started",
            "procedure-completed-implies-result-or-outcome",
        ],
        specificApprovals: [
            "approved-procedure-vocabulary-timing-and-content-release-policy",
        ],
    }),
    journeyCase({
        caseId: "ancillary_test_delay_released",
        familyId: "ancillary-test-and-result",
        preconditions: [
            "test-plan-was-released",
            "authoritative-delay-state-reconciled",
            "delay-copy-and-uncertainty-level-approved",
        ],
        event: "ancillary-test-delayed",
        disclosureMode: "released-correction",
        surfaces: ["today", "my-path"],
        patientMessageKey: "journey.test.delay_released",
        stateTransitions: [
            "prior-timing-withdrawn",
            "delayed-state-and-updated-window-shown-only-if-released",
            "no-cause-or-blame-disclosed-without-separate-release",
        ],
        prohibitedInferences: [
            "delay-implies-cancellation",
            "delay-copy-identifies-staff-or-operational-cause",
        ],
        specificApprovals: [
            "approved-test-delay-source-vocabulary-and-uncertainty-policy",
        ],
    }),
    journeyCase({
        caseId: "ancillary_result_not_released",
        familyId: "ancillary-test-and-result",
        preconditions: [
            "test-completion-may-exist-in-source",
            "patient-result-release-is-absent-pending-or-withheld",
        ],
        event: "result-source-fact-arrives-without-patient-release",
        disclosureMode: "generic-withhold",
        surfaces: ["today", "my-path"],
        patientMessageKey: "journey.result.not_available",
        stateTransitions: [
            "source-fact-does-not-create-patient-release",
            "prior-section-remains-not-available-or-released-empty",
        ],
        prohibitedInferences: [
            "result-exists",
            "result-is-normal-abnormal-final-or-reviewed",
        ],
        specificApprovals: [
            "approved-result-release-and-sensitive-content-policy",
        ],
    }),
    journeyCase({
        caseId: "ancillary_result_separately_released",
        familyId: "ancillary-test-and-result",
        preconditions: [
            "result-field-separately-approved-and-released",
            "review-language-freshness-and-correction-context-present",
        ],
        event: "patient-result-release-becomes-effective",
        disclosureMode: "released-fields-only",
        surfaces: ["my-path"],
        patientMessageKey: "journey.result.released",
        stateTransitions: [
            "not-available-to-released-only-at-effective-release",
            "replacement-release-supersedes-prior-released-value",
        ],
        prohibitedInferences: [
            "released-result-implies-clinical-interpretation",
            "display-implies-care-team-discussion-occurred",
        ],
        specificApprovals: [
            "approved-result-release-interpretation-and-follow-up-language",
        ],
    }),
    journeyCase({
        caseId: "pre_round_question_server_accepted",
        familyId: "pre-round-question",
        preconditions: [
            "communication-operation-approved-and-enabled",
            "current-self-context-and-capability-revalidated",
            "accountable-routing-consumer-ready",
            "exact-idempotency-and-client-message-identities-present",
        ],
        event: "patient-submits-pre-round-question",
        disclosureMode: "communication-state-only",
        surfaces: ["today", "care-team", "communication"],
        patientMessageKey: "journey.question.server_accepted",
        stateTransitions: [
            "draft-to-server-accepted",
            "server-accepted-does-not-equal-staff-delivery-or-review",
            "content-free-accountable-routing-fact-appended",
        ],
        communication: {
            compose_state: "available-only-after-all-gates",
            accepted_state_meaning: "durably-accepted-by-server",
            routing_state: "pending-accountable-staff-projection",
            notification_state: "not-available",
            volatile_draft_action: "clear-only-after-exact-accepted-response",
        },
        prohibitedInferences: [
            "care-team-received-message",
            "specific-response-time",
            "urgent-monitoring-or-emergency-service",
        ],
        specificApprovals: [
            "approved-pre-round-topic-and-urgent-help-copy",
            "approved-idempotent-retry-and-ambiguous-result-recovery",
        ],
    }),
    journeyCase({
        caseId: "pre_round_response_separately_released",
        familyId: "pre-round-question",
        preconditions: [
            "prior-question-server-accepted",
            "accountable-staff-response-exists",
            "response-content-separately-approved-for-patient-release",
        ],
        event: "approved-summary-response-released",
        disclosureMode: "released-fields-only",
        surfaces: ["communication", "today"],
        patientMessageKey: "journey.question.response_released",
        stateTransitions: [
            "accepted-question-remains-immutable",
            "staff-response-is-distinct-released-message",
            "read-state-never-inferred-from-delivery-state",
        ],
        communication: {
            compose_state: "depends-on-current-thread-capability",
            accepted_state_meaning: "prior-question-durably-accepted",
            routing_state: "accountable-response-released",
            notification_state: "held-until-provider-and-payload-approval",
            volatile_draft_action: "clear-only-after-exact-accepted-response",
        },
        prohibitedInferences: [
            "response-is-a-complete-clinical-summary",
            "notification-was-delivered",
        ],
        specificApprovals: [
            "approved-staff-response-release-and-patient-language-policy",
        ],
    }),
    journeyCase({
        caseId: "shift_handoff_preserves_thread_accountability",
        familyId: "shift-handoff",
        preconditions: [
            "open-thread-with-server-accepted-message",
            "current-responsibility-membership-changes",
            "eligible-destination-resolves-or-fails-unresolved",
        ],
        event: "staff-shift-handoff",
        disclosureMode: "communication-state-only",
        surfaces: ["communication", "care-team"],
        patientMessageKey: "journey.handoff.review_in_progress",
        stateTransitions: [
            "thread-and-message-identities-remain-stable",
            "content-free-routing-ownership-changes-append-only",
            "no-eligible-destination-produces-unresolved-not-guessed-owner",
        ],
        communication: {
            compose_state: "available-only-if-fresh-accountable-routing-exists",
            accepted_state_meaning: "server-acceptance-only",
            routing_state: "assigned-rerouted-or-unresolved",
            notification_state: "not-available",
            volatile_draft_action: "preserve-only-if-same-authorized-context",
        },
        prohibitedInferences: [
            "named-staff-member-owns-or-read-thread",
            "handoff-guarantees-response-time",
        ],
        specificApprovals: [
            "approved-authoritative-workforce-and-shift-handoff-input",
        ],
    }),
    journeyCase({
        caseId: "discharge_estimate_corrected",
        familyId: "discharge-estimate",
        preconditions: [
            "prior-discharge-estimate-was-released",
            "new-estimate-is-separately-reviewed-and-released",
            "correction-notice-copy-approved",
        ],
        event: "estimated-discharge-date-changes",
        disclosureMode: "correction-replacement",
        surfaces: ["today", "my-path"],
        patientMessageKey: "journey.discharge.estimate_updated",
        stateTransitions: [
            "old-estimate-withdrawn-atomically",
            "new-estimate-shown-with-can-change-and-confidence-context",
            "internal-correction-target-and-reason-remain-hidden",
        ],
        prohibitedInferences: [
            "estimate-is-a-promise",
            "changed-estimate-identifies-cause-or-accountability",
        ],
        specificApprovals: [
            "approved-discharge-estimate-source-confidence-and-language-policy",
        ],
    }),
    journeyCase({
        caseId: "discharge_closes_open_thread",
        familyId: "discharge-with-open-thread",
        encounterState: "closed",
        preconditions: [
            "open-thread-existed-before-discharge",
            "authoritative-discharge-reconciled",
            "post-discharge-portal-retention-and-support-policy-not-approved",
        ],
        event: "patient-discharged-while-thread-open",
        disclosureMode: "generic-withhold",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.discharge.access_changed",
        stateTransitions: [
            "current-inpatient-context-withdrawn",
            "compose-capability-revoked-before-next-mutation",
            "open-thread-closed-or-omitted-by-approved-lifecycle-policy",
            "volatile-draft-and-cached-handle-purged",
        ],
        communication: {
            compose_state: "unavailable",
            accepted_state_meaning:
                "historical-server-acceptance-not-current-access",
            routing_state: "closed-by-encounter-lifecycle",
            notification_state: "not-available",
            volatile_draft_action: "purge",
        },
        prohibitedInferences: [
            "thread-never-existed",
            "care-team-read-or-resolved-question",
            "post-discharge-support-channel",
        ],
        specificApprovals: [
            "approved-discharge-thread-retention-closure-and-portal-handoff-policy",
        ],
    }),
    journeyCase({
        caseId: "identity_correction_revokes_old_context",
        familyId: "identity-correction",
        encounterState: "requires-reproof",
        preconditions: [
            "identity-link-is-merged-corrected-or-superseded",
            "old-session-and-context-handle-may-reference-prior-link",
        ],
        event: "patient-identity-merge-or-correction",
        disclosureMode: "generic-withhold",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.identity.access_requires_review",
        stateTransitions: [
            "old-session-family-and-context-handles-revoked",
            "cached-patient-state-and-drafts-purged",
            "no-automatic-cross-link-or-record-union",
            "new-access-requires-approved-reproof-and-authoritative-linkage",
        ],
        prohibitedInferences: [
            "other-record-or-identity-exists",
            "merge-target-identifier",
            "prior-and-corrected-record-content",
        ],
        specificApprovals: [
            "approved-identity-merge-correction-reproof-and-audit-policy",
        ],
    }),
    journeyCase({
        caseId: "representative_invited_without_access",
        familyId: "representative-lifecycle",
        actor: "representative-candidate",
        preconditions: [
            "invitation-created-in-future-approved-workflow",
            "identity-proof-consent-scope-and-acceptance-incomplete",
        ],
        event: "representative-invited",
        disclosureMode: "generic-withhold",
        surfaces: [],
        patientMessageKey: "journey.representative.access_not_available",
        stateTransitions: [
            "invitation-never-grants-access",
            "no-patient-context-handle-issued",
        ],
        prohibitedInferences: [
            "patient-has-an-admission",
            "invitation-target-is-associated-with-patient",
        ],
        specificApprovals: [
            "approved-representative-invitation-proofing-and-consent-policy",
        ],
    }),
    journeyCase({
        caseId: "representative_scoped_candidate",
        familyId: "representative-lifecycle",
        actor: "representative-candidate",
        preconditions: [
            "representative-identity-and-relationship-verified",
            "patient-consent-or-other-authority-current",
            "operation-and-field-scopes-effective",
            "sensitive-service-exclusions-evaluated",
        ],
        event: "representative-scope-becomes-effective",
        disclosureMode: "generic-withhold",
        surfaces: [],
        patientMessageKey: "journey.representative.held_pending_approval",
        stateTransitions: [
            "candidate-remains-held-until-representative-model-approved",
            "future-access-must-be-operation-and-field-scoped",
            "self-and-representative-realms-must-not-share-handles-or-caches",
        ],
        prohibitedInferences: [
            "all-patient-content-is-in-scope",
            "representative-can-communicate-or-act-as-patient",
        ],
        specificApprovals: [
            "approved-representative-operation-field-and-communication-scopes",
        ],
    }),
    journeyCase({
        caseId: "representative_scope_expired",
        familyId: "representative-lifecycle",
        actor: "representative-candidate",
        encounterState: "access-expired",
        preconditions: [
            "prior-representative-scope-reached-expiry",
            "request-or-refresh-occurs-after-expiry",
        ],
        event: "representative-scope-expires",
        disclosureMode: "generic-withhold",
        surfaces: [],
        patientMessageKey: "journey.representative.access_not_available",
        stateTransitions: [
            "all-representative-capabilities-denied",
            "context-handles-caches-and-drafts-purged",
            "no-offline-display-after-expiry",
        ],
        prohibitedInferences: [
            "patient-or-encounter-current-state",
            "whether-access-ended-by-time-or-another-reason",
        ],
        specificApprovals: [
            "approved-representative-expiry-and-session-revocation-policy",
        ],
    }),
    journeyCase({
        caseId: "representative_scope_revoked",
        familyId: "representative-lifecycle",
        actor: "representative-candidate",
        encounterState: "access-revoked",
        preconditions: [
            "prior-representative-scope-revoked",
            "request-mutation-refresh-or-offline-open-occurs-after-revocation",
        ],
        event: "representative-scope-revoked",
        disclosureMode: "generic-withhold",
        surfaces: [],
        patientMessageKey: "journey.representative.access_not_available",
        stateTransitions: [
            "authorization-rechecked-before-every-disclosure-and-mutation",
            "session-family-context-handles-caches-and-drafts-purged",
            "queued-or-replayed-mutation-rejected",
        ],
        prohibitedInferences: [
            "who-revoked-access",
            "revocation-reason",
            "patient-or-encounter-current-state",
        ],
        specificApprovals: [
            "approved-representative-revocation-propagation-and-support-policy",
        ],
    }),
    journeyCase({
        caseId: "limited_english_interpreter_support",
        familyId: "language-and-interpreter",
        preconditions: [
            "account-language-preference-approved",
            "each-patient-visible-field-has-approved-language-release",
            "interpreter-request-status-has-separate-authoritative-source",
        ],
        event: "limited-english-patient-uses-approved-language-and-interpreter-support",
        disclosureMode: "released-fields-only",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey:
            "journey.language.approved_content_and_interpreter_status",
        stateTransitions: [
            "approved-translation-or-approved-source-language-selected-per-field",
            "unapproved-translation-withheld-without-machine-translation-fallback",
            "interpreter-status-shown-only-if-separately-released",
        ],
        prohibitedInferences: [
            "interpreter-is-confirmed-from-language-preference-alone",
            "unreviewed-machine-translation-is-clinical-content",
        ],
        specificApprovals: [
            "approved-professional-translation-fallback-and-interpreter-operating-model",
        ],
    }),
    journeyCase({
        caseId: "visual_accommodation_equivalence",
        familyId: "accommodations",
        preconditions: [
            "visual-accommodation-preference-or-supported-system-setting-active",
        ],
        event: "patient-uses-visual-accommodation",
        disclosureMode: "accessibility-equivalent",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.accommodation.visual",
        stateTransitions: [
            "text-reflows-at-supported-system-size",
            "decorative-imagery-can-be-hidden",
            "state-retains-text-shape-and-noncolor-cue",
        ],
        prohibitedInferences: [
            "hidden-image-removes-information",
            "color-alone-communicates-status",
        ],
        specificApprovals: [
            "approved-screen-reader-magnification-contrast-and-visual-design-review",
        ],
    }),
    journeyCase({
        caseId: "hearing_accommodation_equivalence",
        familyId: "accommodations",
        preconditions: [
            "hearing-accommodation-required",
            "future-audio-or-video-content-may-be-present",
        ],
        event: "patient-uses-hearing-accommodation",
        disclosureMode: "accessibility-equivalent",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.accommodation.hearing",
        stateTransitions: [
            "no-essential-alert-relies-on-sound-alone",
            "approved-caption-or-transcript-required-for-future-media",
            "communication-state-remains-visible-in-text",
        ],
        prohibitedInferences: ["audio-cue-alone-conveys-urgency-or-completion"],
        specificApprovals: [
            "approved-caption-transcript-and-hearing-access-review",
        ],
    }),
    journeyCase({
        caseId: "motor_accommodation_equivalence",
        familyId: "accommodations",
        preconditions: ["motor-or-alternative-input-accommodation-required"],
        event: "patient-uses-motor-or-alternative-input",
        disclosureMode: "accessibility-equivalent",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.accommodation.motor",
        stateTransitions: [
            "all-actions-reachable-with-supported-alternative-input",
            "target-size-and-focus-order-retained",
            "timeout-does-not-discard-unsubmitted-input-without-warning",
        ],
        prohibitedInferences: ["gesture-only-action-is-equivalent"],
        specificApprovals: [
            "approved-switch-control-voice-control-keyboard-and-target-review",
        ],
    }),
    journeyCase({
        caseId: "cognitive_accommodation_equivalence",
        familyId: "accommodations",
        preconditions: ["cognitive-accommodation-required"],
        event: "patient-uses-cognitive-accommodation",
        disclosureMode: "accessibility-equivalent",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.accommodation.cognitive",
        stateTransitions: [
            "one-primary-purpose-per-step",
            "timing-uncertainty-and-changes-explained-consistently",
            "urgent-help-path-remains-distinct-from-routine-message",
        ],
        prohibitedInferences: [
            "simplified-copy-removes-material-risk-or-uncertainty",
        ],
        specificApprovals: [
            "approved-cognitive-load-comprehension-and-patient-advisor-review",
        ],
    }),
    journeyCase({
        caseId: "low_literacy_teach_back_equivalence",
        familyId: "accommodations",
        preconditions: [
            "low-literacy-support-required",
            "education-or-teach-back-capability-future-approved",
        ],
        event: "patient-uses-low-literacy-and-teach-back-support",
        disclosureMode: "accessibility-equivalent",
        surfaces: ["my-path", "care-team", "communication"],
        patientMessageKey: "journey.accommodation.low_literacy",
        stateTransitions: [
            "approved-plain-language-content-retains-clinical-meaning",
            "teach-back-response-recorded-only-through-approved-capability",
            "nonresponse-never-treated-as-understanding-or-refusal",
        ],
        prohibitedInferences: [
            "reading-level-predicts-capacity",
            "completed-screen-implies-understanding",
        ],
        specificApprovals: [
            "approved-health-literacy-education-and-teach-back-policy",
        ],
    }),
    journeyCase({
        caseId: "sensitive_data_fails_closed",
        familyId: "sensitive-data",
        preconditions: [
            "resource-or-encounter-requires-sensitive-service-policy",
            "effective-sensitive-service-authorization-is-absent-ambiguous-or-denied",
        ],
        event: "sensitive-resource-requested",
        disclosureMode: "generic-withhold",
        surfaces: [],
        patientMessageKey: "journey.sensitive.access_not_available",
        stateTransitions: [
            "no-field-section-thread-or-capability-disclosed",
            "cached-value-handle-and-draft-purged",
            "denial-indistinguishable-from-unknown-or-unavailable",
        ],
        prohibitedInferences: [
            "sensitive-service-exists",
            "resource-type",
            "restriction-policy-or-denial-reason",
        ],
        specificApprovals: [
            "approved-sensitive-service-legal-privacy-clinical-and-representative-policy",
        ],
    }),
    journeyCase({
        caseId: "source_outage_sections_not_available",
        familyId: "source-outage-and-staleness",
        preconditions: [
            "required-authoritative-source-is-unavailable",
            "complete-fresh-evaluation-cannot-finish",
        ],
        event: "authoritative-source-outage",
        disclosureMode: "not-available",
        surfaces: ["today", "my-path", "care-team"],
        patientMessageKey: "journey.source.temporarily_not_available",
        stateTransitions: [
            "affected-sections-become-not-available-not-released-empty",
            "unaffected-separately-governed-fields-may-remain",
            "last-known-value-not-presented-as-current",
        ],
        prohibitedInferences: [
            "no-care-is-planned",
            "source-outage-means-discharge-transfer-or-cancellation",
        ],
        specificApprovals: [
            "approved-source-outage-partial-response-downtime-and-support-policy",
        ],
    }),
    journeyCase({
        caseId: "stale_projection_policy_evaluated_per_field",
        familyId: "source-outage-and-staleness",
        preconditions: [
            "released-field-observation-is-older-than-current-source-policy-target",
            "field-specific-stale-display-policy-evaluated",
        ],
        event: "projection-becomes-stale",
        disclosureMode: "approved-stale-or-not-available",
        surfaces: ["today", "my-path", "care-team"],
        patientMessageKey: "journey.source.stale_policy_applied",
        stateTransitions: [
            "approved-stale-field-shows-observed-time-and-patient-notice",
            "field-without-approved-stale-policy-is-withheld",
            "document-level-freshness-never-overrides-field-state",
        ],
        prohibitedInferences: [
            "stale-value-is-current",
            "all-fields-share-the-same-freshness",
        ],
        specificApprovals: [
            "approved-field-specific-staleness-threshold-copy-and-withhold-policy",
        ],
    }),
    journeyCase({
        caseId: "incorrect_content_retracted",
        familyId: "content-retraction-and-correction",
        preconditions: [
            "patient-facing-value-was-released",
            "effective-retraction-fact-withdraws-that-release",
            "no-approved-replacement-is-effective",
        ],
        event: "incorrect-patient-facing-content-retracted",
        disclosureMode: "not-available",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.content.withdrawn",
        stateTransitions: [
            "withdrawn-value-removed-atomically-from-all-surfaces",
            "cached-and-offline-copies-purged",
            "no-internal-reason-actor-or-target-identifier-disclosed",
        ],
        prohibitedInferences: [
            "withdrawn-value",
            "why-or-who-retracted-content",
            "replacement-exists",
        ],
        specificApprovals: [
            "approved-cross-surface-retraction-propagation-and-support-policy",
        ],
    }),
    journeyCase({
        caseId: "incorrect_content_corrected_replacement",
        familyId: "content-retraction-and-correction",
        preconditions: [
            "prior-release-withdrawn",
            "replacement-content-independently-reviewed-and-released",
            "patient-correction-notice-approved",
        ],
        event: "corrected-replacement-becomes-effective",
        disclosureMode: "correction-replacement",
        surfaces: ["today", "my-path", "care-team", "communication"],
        patientMessageKey: "journey.content.corrected",
        stateTransitions: [
            "old-value-never-serialized-with-replacement",
            "replacement-carries-its-own-complete-field-context",
            "source-free-patient-correction-notice-shown",
        ],
        prohibitedInferences: [
            "internal-correction-reason-or-actor",
            "replacement-is-a-complete-clinical-explanation",
        ],
        specificApprovals: [
            "approved-cross-surface-correction-review-release-and-notice-policy",
        ],
    }),
];

const familyDefinitions = [
    {
        family_id: "admission",
        required_case_ids: ["adult_admission_context_established"],
    },
    {
        family_id: "unit-transfer",
        required_case_ids: ["unit_transfer_old_location_withdrawn"],
    },
    {
        family_id: "procedure",
        required_case_ids: [
            "procedure_plan_released_without_outcome_inference",
        ],
    },
    {
        family_id: "ancillary-test-and-result",
        required_case_ids: [
            "ancillary_test_delay_released",
            "ancillary_result_not_released",
            "ancillary_result_separately_released",
        ],
    },
    {
        family_id: "pre-round-question",
        required_case_ids: [
            "pre_round_question_server_accepted",
            "pre_round_response_separately_released",
        ],
    },
    {
        family_id: "shift-handoff",
        required_case_ids: ["shift_handoff_preserves_thread_accountability"],
    },
    {
        family_id: "discharge-estimate",
        required_case_ids: ["discharge_estimate_corrected"],
    },
    {
        family_id: "discharge-with-open-thread",
        required_case_ids: ["discharge_closes_open_thread"],
    },
    {
        family_id: "identity-correction",
        required_case_ids: ["identity_correction_revokes_old_context"],
    },
    {
        family_id: "representative-lifecycle",
        required_case_ids: [
            "representative_invited_without_access",
            "representative_scoped_candidate",
            "representative_scope_expired",
            "representative_scope_revoked",
        ],
    },
    {
        family_id: "language-and-interpreter",
        required_case_ids: ["limited_english_interpreter_support"],
    },
    {
        family_id: "accommodations",
        required_case_ids: [
            "visual_accommodation_equivalence",
            "hearing_accommodation_equivalence",
            "motor_accommodation_equivalence",
            "cognitive_accommodation_equivalence",
            "low_literacy_teach_back_equivalence",
        ],
    },
    {
        family_id: "sensitive-data",
        required_case_ids: ["sensitive_data_fails_closed"],
    },
    {
        family_id: "source-outage-and-staleness",
        required_case_ids: [
            "source_outage_sections_not_available",
            "stale_projection_policy_evaluated_per_field",
        ],
    },
    {
        family_id: "content-retraction-and-correction",
        required_case_ids: [
            "incorrect_content_retracted",
            "incorrect_content_corrected_replacement",
        ],
    },
];

const fixtures = {
    schema_version: 1,
    candidate_id: CANDIDATE_ID,
    policy_version: POLICY_VERSION,
    fixed_clock: FIXED_TIME,
    fixture_class: "synthetic-no-phi",
    case_count: cases.length,
    cases,
};

const fixturesSerialized = `${JSON.stringify(fixtures, null, 4)}\n`;
const sources = sourcePaths.map(sourceEvidence);
const sourceInventoryDigest = sha256(
    sources
        .map((source) => `${source.path}\0${source.sha256}`)
        .sort()
        .join("\n"),
);

const candidate = {
    schema_version: 1,
    candidate_id: CANDIDATE_ID,
    policy_version: POLICY_VERSION,
    reviewed_source_commit: REVIEWED_SOURCE_COMMIT,
    reviewed_at: "2026-07-27",
    status: "synthetic_reference_only_not_approved_for_implementation",
    purpose:
        "Define deterministic patient-journey safety and non-disclosure expectations before any Nightingale journey contract or runtime exists.",
    executable_boundary: {
        foundation_contract_path_count: 0,
        candidate_operation_count: 0,
        runtime_binding_count: 0,
        native_client_operation_count: 0,
        route_namespace_reserved_only: "/api/nightingale/v1",
    },
    global_constraints: globalConstraints,
    approval_gates: globalApprovalGates,
    required_surfaces: ["today", "my-path", "care-team", "communication"],
    disclosure_modes: [
        "released-empty-or-not-available",
        "correction-replacement-or-not-available",
        "released-fields-only",
        "released-correction",
        "generic-withhold",
        "communication-state-only",
        "correction-replacement",
        "accessibility-equivalent",
        "not-available",
        "approved-stale-or-not-available",
    ],
    family_count: familyDefinitions.length,
    case_count: cases.length,
    families: familyDefinitions,
    required_case_ids: cases.map(({ case_id }) => case_id),
    required_findings: {
        generated_artifacts_match_builder: true,
        every_fixture_is_synthetic_and_contains_no_phi: true,
        every_case_is_held_and_non_runnable: true,
        every_case_requires_all_global_approval_gates: true,
        every_disclosed_value_requires_separate_release: true,
        every_response_is_atomic_and_no_store: true,
        unavailable_and_released_empty_are_distinct: true,
        source_fact_does_not_equal_patient_release: true,
        server_acceptance_does_not_equal_staff_delivery_or_review: true,
        notification_delivery_is_not_available: true,
        representative_access_remains_held: true,
        sensitive_service_requests_fail_closed_without_existence_disclosure: true,
        identity_correction_revokes_old_handles_and_requires_reproof: true,
        source_outage_never_presents_last_known_value_as_current: true,
        stale_display_requires_field_specific_approval_and_notice: true,
        correction_replaces_and_retraction_withholds: true,
        accessibility_changes_never_remove_information_or_actions: true,
        no_offline_phi_or_queued_mutation_is_permitted: true,
        production_data_was_not_used: true,
    },
    evidence: {
        source_count: sources.length,
        source_inventory_digest: sourceInventoryDigest,
        sources,
        fixtures_sha256: sha256(fixturesSerialized),
    },
};

const candidateSerialized = `${JSON.stringify(candidate, null, 4)}\n`;

if (write) {
    fs.mkdirSync(outputDirectory, { recursive: true });
    fs.writeFileSync(
        path.join(outputDirectory, "candidate.json"),
        candidateSerialized,
    );
    fs.writeFileSync(
        path.join(outputDirectory, "fixtures.json"),
        fixturesSerialized,
    );
    process.stdout.write(
        `Built held Nightingale patient-journey reference candidate with ${familyDefinitions.length} families and ${cases.length} synthetic cases.\n`,
    );
} else {
    process.stdout.write(
        JSON.stringify(
            {
                candidate,
                fixtures,
            },
            null,
            4,
        ) + "\n",
    );
}
