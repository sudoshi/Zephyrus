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
        "Usage: build-nightingale-communication-notification-classification.mjs [repository-root] [--write]\n",
    );
    process.exit(64);
}
const repoRoot = path.resolve(positional[0] ?? ".");
const outputPath = path.join(
    repoRoot,
    "docs/nightingale/migration/candidates/v0/communication-notification-source-classification.json",
);

const categories = {
    contract: [
        "patient_communication_contract",
        "patient_mutation_delivery",
        "staff_handoff_routing",
        "notification_registration_delivery",
        "error_offline_urgency",
    ],
    patientMutation: [
        "patient_communication_contract",
        "patient_mutation_delivery",
        "error_offline_urgency",
    ],
    staffHandoff: [
        "patient_mutation_delivery",
        "staff_handoff_routing",
        "error_offline_urgency",
    ],
    notification: [
        "notification_registration_delivery",
        "error_offline_urgency",
    ],
    patientNative: [
        "patient_communication_contract",
        "patient_mutation_delivery",
        "notification_registration_delivery",
        "native_patient_experience",
        "error_offline_urgency",
    ],
    staffNative: [
        "staff_handoff_routing",
        "native_patient_experience",
        "error_offline_urgency",
    ],
};

const groups = [
    {
        surface: "legacy_contract",
        decisionId: "contract_evidence_only",
        categories: categories.contract,
        paths: [
            "docs/hummingbird/api-contract/hummingbird-patient.v1.yaml",
            "docs/hummingbird/PUSH.md",
            "docs/hummingbird/reference/05-notifications-earned-urgency.md",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "configuration_rejected",
        categories: categories.contract,
        paths: [
            "config/hummingbird-patient.php",
            "config/hummingbird-patient-content.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "routes_rejected",
        categories: categories.contract,
        paths: [
            "routes/patient.php",
            "routes/api.php",
            "routes/web.php",
            "routes/console.php",
        ],
    },
    {
        surface: "legacy_database",
        decisionId: "ledger_principles_held",
        categories: categories.contract,
        paths: [
            "database/migrations/2026_07_19_000300_create_patient_experience_messaging_foundation.php",
            "database/migrations/2026_07_19_000400_create_patient_communications_staff_handoff_foundation.php",
            "database/migrations/2026_07_21_000900_limit_patient_message_amendments.php",
            "database/migrations/2026_07_21_001000_extend_patient_message_routing_events_for_amendments.php",
            "database/migrations/2026_07_22_001100_create_patient_notification_devices.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "staff_dispatch_evidence_only",
        categories: categories.staffHandoff,
        paths: [
            "app/Console/Commands/HummingbirdConsumePatientMessageHandoffCommand.php",
            "app/Console/Commands/HummingbirdEscalatePatientCommunicationsCommand.php",
            "app/Console/Commands/HummingbirdReconcilePatientCommunicationsCommand.php",
            "app/Contracts/Patient/PatientMessageHandoffReadiness.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "controller_boundary_evidence_only",
        categories: categories.contract,
        paths: [
            "app/Http/Controllers/Api/Patient/MessagingController.php",
            "app/Http/Controllers/Api/Patient/NotificationDeviceController.php",
            "app/Http/Controllers/Api/Patient/EducationClarificationController.php",
            "app/Http/Controllers/Api/Mobile/PatientCommunicationController.php",
            "app/Http/Controllers/PatientCommunicationController.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "default_off_gates_principle_only",
        categories: categories.contract,
        paths: [
            "app/Http/Middleware/EnsureHummingbirdPatientEnabled.php",
            "app/Http/Middleware/EnsureHummingbirdPatientFeatureEnabled.php",
            "app/Http/Middleware/EnsurePatientStaffMessagingEnabled.php",
            "app/Http/Middleware/ProtectPatientCommunicationResponse.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "request_shapes_held",
        categories: categories.patientMutation,
        paths: [
            "app/Http/Requests/Patient/AmendPatientMessageRequest.php",
            "app/Http/Requests/Patient/CloseMessageThreadRequest.php",
            "app/Http/Requests/Patient/CreateEducationClarificationRequest.php",
            "app/Http/Requests/Patient/CreateMessageThreadRequest.php",
            "app/Http/Requests/Patient/SendPatientMessageRequest.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "notification_registration_held",
        categories: categories.notification,
        paths: [
            "app/Http/Requests/Patient/RegisterPatientNotificationDeviceRequest.php",
            "app/Services/Patient/PatientNotificationDeviceCipher.php",
            "app/Services/Patient/PatientNotificationDeviceFailure.php",
            "app/Services/Patient/PatientNotificationDeviceRegistry.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "staff_request_shapes_held",
        categories: categories.staffHandoff,
        paths: [
            "app/Http/Requests/PatientCommunication/ClaimPatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/ClosePatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/ReassignPatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/ReleasePatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/ReplyPatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/ReroutePatientCommunicationRequest.php",
            "app/Http/Requests/PatientCommunication/StaffMessageMutationRequest.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "patient_ledgers_principle_only",
        categories: categories.contract,
        paths: [
            "app/Models/Patient/PatientEducationClarificationRequest.php",
            "app/Models/Patient/PatientMessage.php",
            "app/Models/Patient/PatientMessageDeliveryReceipt.php",
            "app/Models/Patient/PatientMessageRoutingEvent.php",
            "app/Models/Patient/PatientMessageThread.php",
            "app/Models/Patient/PatientNotificationDeliveryAttempt.php",
            "app/Models/Patient/PatientNotificationDevice.php",
            "app/Models/Patient/PatientNotificationOutbox.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "staff_projection_held",
        categories: categories.staffHandoff,
        paths: [
            "app/Models/PatientCommunication/ConsumerHeartbeat.php",
            "app/Models/PatientCommunication/PoolMembership.php",
            "app/Models/PatientCommunication/ResponsibilityPool.php",
            "app/Models/PatientCommunication/RoundQuestionPromotion.php",
            "app/Models/PatientCommunication/RoundQuestionPromotionOutcome.php",
            "app/Models/PatientCommunication/StaffActionEvent.php",
            "app/Models/PatientCommunication/ThreadWorkItem.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "messaging_policy_principle_only",
        categories: categories.patientMutation,
        paths: [
            "app/Policies/Patient/PatientMessageThreadPolicy.php",
            "app/Services/Patient/Messaging/PatientCommunicationEncounterGuard.php",
            "app/Services/Patient/Messaging/PatientMessageCipher.php",
            "app/Services/Patient/Messaging/PatientMessagingFailure.php",
            "app/Services/Patient/Messaging/PatientMessagingPolicyRegistry.php",
            "app/Services/Patient/Messaging/PatientMessagingService.php",
            "app/Services/Patient/Education/PatientEducationClarificationService.php",
        ],
    },
    {
        surface: "legacy_backend",
        decisionId: "staff_handoff_principle_only",
        categories: categories.staffHandoff,
        paths: [
            "app/Services/Patient/Messaging/DatabasePatientMessageHandoffConsumer.php",
            "app/Services/Patient/Messaging/PatientCommunicationEscalationService.php",
            "app/Services/Patient/Messaging/PatientCommunicationLifecycleReconciliationService.php",
            "app/Services/Patient/Messaging/PatientCommunicationPoolResolver.php",
            "app/Services/Patient/Messaging/PatientCommunicationResponderEligibility.php",
            "app/Services/Patient/Messaging/StaffPatientCommunicationFailure.php",
            "app/Services/Patient/Messaging/StaffPatientCommunicationRoutingPolicy.php",
            "app/Services/Patient/Messaging/StaffPatientCommunicationService.php",
        ],
    },
    {
        surface: "legacy_test",
        decisionId: "test_evidence_only",
        categories: categories.contract,
        paths: [
            "tests/Feature/Patient/PatientCommunicationEscalationServiceTest.php",
            "tests/Feature/Patient/PatientCommunicationLifecycleReconciliationServiceTest.php",
            "tests/Feature/Patient/PatientCommunicationPoolResolverTest.php",
            "tests/Feature/Patient/PatientEducationClarificationApiTest.php",
            "tests/Feature/Patient/PatientMessagingApiTest.php",
            "tests/Feature/Patient/PatientProjectionOutboxTest.php",
            "tests/Feature/Patient/PatientStaffMessageHandoffConsumerTest.php",
            "tests/Feature/Patient/StaffPatientCommunicationApiTest.php",
            "tests/Feature/Patient/StaffPatientCommunicationWebTest.php",
        ],
    },
    {
        surface: "legacy_ios",
        decisionId: "patient_client_held",
        categories: categories.patientNative,
        paths: [
            "hummingbird/iosPatientApp/HummingbirdPatient/App/PatientAppViewModel.swift",
            "hummingbird/iosPatientApp/HummingbirdPatient/Features/Messages/PatientMessagesView.swift",
            "hummingbird/iosPatientApp/HummingbirdPatient/Features/Path/PatientPathView.swift",
            "hummingbird/iosPatientApp/HummingbirdPatient/Models/PatientMessagingState.swift",
            "hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIClient.swift",
            "hummingbird/iosPatientApp/HummingbirdPatient/Networking/PatientAPIModels.swift",
        ],
    },
    {
        surface: "legacy_ios_test",
        decisionId: "test_evidence_only",
        categories: categories.patientNative,
        paths: [
            "hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIBoundaryTests.swift",
            "hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIClientTests.swift",
            "hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAPIModelTests.swift",
            "hummingbird/iosPatientApp/HummingbirdPatientTests/PatientAppViewModelTests.swift",
            "hummingbird/iosPatientApp/HummingbirdPatientTests/PatientRoundsQuestionTopicTests.swift",
            "hummingbird/iosPatientApp/HummingbirdPatientUITests/PatientReferenceJourneyUITests.swift",
        ],
    },
    {
        surface: "legacy_android",
        decisionId: "patient_client_held",
        categories: categories.patientNative,
        paths: [
            "hummingbird/androidPatientApp/app/src/main/AndroidManifest.xml",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/PatientAppViewModel.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/PatientExperienceModels.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiClient.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientApiModels.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/data/PatientSessionCoordinator.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientExperienceScreen.kt",
            "hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientMessagingPanel.kt",
        ],
    },
    {
        surface: "legacy_android_test",
        decisionId: "test_evidence_only",
        categories: categories.patientNative,
        paths: [
            "hummingbird/androidPatientApp/app/src/androidTest/java/net/acumenus/hummingbird/patient/PatientPrimaryJourneyInstrumentedTest.kt",
            "hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/PatientAppViewModelTest.kt",
            "hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientEndpointBoundaryTest.kt",
            "hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientEnvelopeDecoderTest.kt",
            "hummingbird/androidPatientApp/app/src/test/java/net/acumenus/hummingbird/patient/data/PatientSessionCoordinatorTest.kt",
        ],
    },
    {
        surface: "legacy_staff_ios",
        decisionId: "staff_client_evidence_only",
        categories: categories.staffNative,
        paths: [
            "hummingbird/iosApp/Hummingbird/Features/PatientCommunications/PatientCommunicationDetailView.swift",
            "hummingbird/iosApp/Hummingbird/Features/PatientCommunications/PatientCommunicationRoutingView.swift",
            "hummingbird/iosApp/Hummingbird/Features/PatientCommunications/PatientCommunicationsView.swift",
            "hummingbird/iosApp/Hummingbird/Features/PatientCommunications/PatientCommunicationsViewModel.swift",
            "hummingbird/iosApp/Hummingbird/Networking/PatientCommunicationModels.swift",
            "hummingbird/iosApp/HummingbirdTests/PatientCommunicationRoutingTests.swift",
            "hummingbird/iosApp/HummingbirdTests/PatientCommunicationsTests.swift",
            "hummingbird/iosApp/HummingbirdUITests/PatientCommunicationsUITests.swift",
        ],
    },
    {
        surface: "legacy_staff_android",
        decisionId: "staff_client_evidence_only",
        categories: categories.staffNative,
        paths: [
            "hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/PatientCommunicationModels.kt",
            "hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/data/PatientCommunicationsViewModel.kt",
            "hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/communications/PatientCommunicationsPolling.kt",
            "hummingbird/androidApp/app/src/main/java/net/acumenus/hummingbird/ui/communications/PatientCommunicationsScreens.kt",
            "hummingbird/androidApp/app/src/test/java/net/acumenus/hummingbird/data/PatientCommunicationsContractTest.kt",
            "hummingbird/androidApp/app/src/test/java/net/acumenus/hummingbird/data/PatientCommunicationsViewModelPrivacyTest.kt",
            "hummingbird/androidApp/app/src/androidTest/java/net/acumenus/hummingbird/ui/communications/PatientCommunicationsUiTest.kt",
        ],
    },
    {
        surface: "legacy_staff_web",
        decisionId: "staff_client_evidence_only",
        categories: categories.staffNative,
        paths: [
            "resources/js/Pages/PatientCommunications/Index.tsx",
            "resources/js/Pages/PatientCommunications/RoutingControls.tsx",
            "resources/js/Pages/PatientCommunications/mutationSafety.ts",
            "resources/js/Pages/PatientCommunications/routingPolicy.ts",
            "tests/js/pages/PatientCommunicationMutationSafety.test.ts",
            "tests/js/pages/PatientCommunicationRoutingPolicy.test.ts",
            "tests/js/pages/PatientCommunicationTransitionPolling.test.tsx",
            "tests/js/pages/PatientCommunications.test.tsx",
        ],
    },
];

const decisions = {
    contract_evidence_only: {
        disposition: "evidence_only",
        rationale:
            "Inventory legacy operations, states, copy, provider assumptions, and hazards only; no path, operation identifier, schema, topic, notification copy, or security scheme becomes Nightingale authority.",
    },
    configuration_rejected: {
        disposition: "reject",
        rationale:
            "Reject Hummingbird flags, policy/version values, topic copy, urgent guidance, response-window copy, provider settings, token names, and environment activation as Nightingale defaults.",
    },
    routes_rejected: {
        disposition: "reject",
        rationale:
            "Reject legacy patient and staff routes, middleware compositions, schedules, aliases, redirects, and proxies; Nightingale remains a separate zero-operation contract.",
    },
    ledger_principles_held: {
        disposition: "held",
        rationale:
            "Append-only events, encrypted content, content-free routing, idempotency, optimistic concurrency, and immutable delivery attempts are candidate properties, but every Nightingale table, state, retention rule, key lifecycle, and projection remains unapproved.",
    },
    staff_dispatch_evidence_only: {
        disposition: "evidence_only",
        rationale:
            "Consumer heartbeat, bounded dispatch, reconciliation, and escalation scheduling are operational evidence only; no legacy command, queue, cadence, consumer, or worker is approved.",
    },
    controller_boundary_evidence_only: {
        disposition: "evidence_only",
        rationale:
            "Request-time authorization, generic non-disclosure, no-store response handling, and audit boundaries are evidence; the controllers and their response semantics are not reusable Nightingale endpoints.",
    },
    default_off_gates_principle_only: {
        disposition: "reimplement_principle_only",
        rationale:
            "Layered default-off product, operation, policy, source, and staff-readiness gates are required in principle; Hummingbird middleware and configuration are rejected.",
    },
    request_shapes_held: {
        disposition: "held",
        rationale:
            "Bounded text, control-character rejection, explicit concurrency, and correction/retraction separation are candidate controls; patient topics, body limits, close reasons, urgent versioning, and mutation shapes require Nightingale approval.",
    },
    notification_registration_held: {
        disposition: "held",
        rationale:
            "Token encryption, digest lookup, principal ownership, rebind/revoke transactions, and audit are candidate controls, but registration alone is not delivery and no Nightingale provider, payload, consent, lifecycle, or native registration exists.",
    },
    staff_request_shapes_held: {
        disposition: "held",
        rationale:
            "Claim, response, close, release, reassignment, reroute, version, and idempotency inputs remain staff-workflow evidence; Nightingale needs independently approved patient-to-team responsibility semantics.",
    },
    patient_ledgers_principle_only: {
        disposition: "reimplement_principle_only",
        rationale:
            "Immutable encrypted message content, append-only receipts/routing, content-free outbox, and explicit device/delivery records are useful principles; legacy models, states, identifiers, retention, and access relationships are not approved.",
    },
    staff_projection_held: {
        disposition: "held",
        rationale:
            "Responsibility pools, eligible responders, fresh heartbeats, work-item projection, actions, and round-question promotion are useful evidence but require clinical operations ownership, audit, downtime, and staffing approval.",
    },
    messaging_policy_principle_only: {
        disposition: "reimplement_principle_only",
        rationale:
            "Reimplement exact current encounter/grant authorization, immutable encrypted content, append-only corrections, content-free routing, and replay safety only after a Nightingale contract and named approvals exist.",
    },
    staff_handoff_principle_only: {
        disposition: "reimplement_principle_only",
        rationale:
            "Reimplement verified routing readiness, responsibility ownership, bounded escalation, lifecycle reconciliation, and patient-visible receipts as independently specified Nightingale behavior; legacy states and services are evidence only.",
    },
    test_evidence_only: {
        disposition: "evidence_only",
        rationale:
            "Retain test cases as hazard, privacy, authorization, replay, routing, and rendering evidence; no fixture, assertion vocabulary, test route, synthetic identity, or expected copy authorizes product behavior.",
    },
    patient_client_held: {
        disposition: "held",
        rationale:
            "The legacy patient clients expose useful failure and accessibility lessons but are not migrated: their retry identity, delivery wording, enum handling, refresh behavior, notification preferences, copy, and offline behavior require new Nightingale decisions.",
    },
    staff_client_evidence_only: {
        disposition: "evidence_only",
        rationale:
            "Foreground polling, privacy-aware mutation handling, routing controls, and staff state rendering are compatibility evidence only; Nightingale must not copy staff Hummingbird code or operational identity.",
    },
};

const sha256 = (value) =>
    crypto.createHash("sha256").update(value).digest("hex");

const sources = groups
    .flatMap((group) =>
        group.paths.map((relativePath) => {
            const absolutePath = path.join(repoRoot, relativePath);
            if (!fs.existsSync(absolutePath)) {
                throw new Error(`Missing source: ${relativePath}`);
            }
            return {
                path: relativePath,
                sha256: sha256(fs.readFileSync(absolutePath)),
                surface: group.surface,
                categories: group.categories,
                decision_id: group.decisionId,
            };
        }),
    )
    .sort((left, right) => left.path.localeCompare(right.path));

const manifest = {
    schema_version: 1,
    classification_id:
        "nightingale-communication-notification-source-classification.v1",
    reviewed_source_commit: "be8405a0f768bf239862b790b3eeae80b8aad2ad",
    reviewed_at: "2026-07-26",
    status: "evidence_only_not_approved_for_implementation",
    scope:
        Object.keys(categories).length > 0
            ? [
                  "patient_communication_contract",
                  "patient_mutation_delivery",
                  "staff_handoff_routing",
                  "notification_registration_delivery",
                  "native_patient_experience",
                  "error_offline_urgency",
              ]
            : [],
    global_constraints: {
        implementation_permitted: false,
        runtime_adoption_permitted: false,
        route_registration_permitted: false,
        legacy_route_alias_permitted: false,
        legacy_topic_copy_permitted: false,
        legacy_urgent_copy_permitted: false,
        notification_provider_enabled: false,
        notification_device_registration_enabled: false,
        patient_push_delivery_enabled: false,
        patient_email_delivery_enabled: false,
        patient_sms_delivery_enabled: false,
        notification_payload_permitted: false,
        patient_foreground_polling_enabled: false,
        offline_mutation_queue_enabled: false,
        retry_identity_regeneration_permitted: false,
        server_acceptance_counts_as_care_team_delivery: false,
        production_data_used: false,
        production_query_permitted: false,
        production_replay_permitted: false,
        patient_or_principal_created: false,
    },
    required_findings: {
        patient_push_delivery_absent: true,
        patient_native_automatic_refresh_absent: true,
        ambiguous_retry_reuses_operation_identity: false,
        server_acceptance_proves_staff_projection: false,
        ios_accepts_backend_escalated_delivery_state: false,
        android_maps_all_backend_ownership_states: false,
        android_maps_all_backend_delivery_states: false,
        urgent_guidance_is_locale_bound_release_content: false,
        notification_preferences_match_available_delivery_channels: false,
        staff_close_reason_breaks_patient_thread_decode: false,
    },
    dispositions: {
        reimplement_principle_only:
            "A bounded safety property may inform a new Nightingale-owned design, but no code, identifier, route, schema, copy, state, provider, credential, or product policy is approved for reuse.",
        evidence_only:
            "The source or test is retained as evidence about current behavior and coverage. It is not a Nightingale requirement and cannot authorize implementation.",
        held: "The behavior depends on unresolved ownership, assurance, clinical, privacy, source, contract, content, accessibility, notification, or patient-language decisions.",
        reject: "The behavior is incompatible with the independent Nightingale boundary and must not be migrated.",
    },
    decisions,
    sources,
};

const rendered = `${JSON.stringify(manifest, null, 4)}\n`;
if (write) {
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, rendered);
} else {
    process.stdout.write(rendered);
}
