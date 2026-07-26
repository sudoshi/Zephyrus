#!/usr/bin/env node

import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";
import { execFileSync } from "node:child_process";

const args = process.argv.slice(2);
const write = args.includes("--write");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--write",
);
const positional = args.filter((argument) => !argument.startsWith("--"));
if (unknownOptions.length > 0 || positional.length > 1) {
    process.stderr.write(
        "Usage: build-nightingale-journey-preference-release-classification.mjs [repository-root] [--write]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const outputPath = path.join(
    repoRoot,
    "docs/nightingale/migration/candidates/v0/journey-preference-presentation-release-source-classification.json",
);
const reviewedSourceCommit = "e8f2b33bca79c4134f2476f41702430da72816d7";
const priorManifestPaths = [
    "docs/nightingale/migration/candidates/v0/source-classification.json",
    "docs/nightingale/migration/candidates/v0/communication-notification-source-classification.json",
];

const decisions = {
    pathway_draft_command_held: {
        classification: "reusable_product_behavior",
        disposition: "held",
        rationale:
            "The command demonstrates an explicit draft-only pathway workflow and machine-readable preview, but its Hummingbird configuration, source binding, content contract, and operational ownership are not Nightingale decisions. Retain the workflow as evidence and do not copy, register, or execute it.",
        categories: [
            "journey_projection_content",
            "source_reconciliation_release",
        ],
    },
    reference_provisioning_rejected: {
        classification: "rejected_legacy_behavior",
        disposition: "reject",
        rationale:
            "These command-accessible Hummingbird reference provisioners can create or bind synthetic operational encounters, principals, grants, enrollment challenges, secrets, policies, cursors, and draft projections. Their safeguards are useful audit evidence, but Nightingale must not inherit their identities, ownership markers, write paths, or production-runtime execution model.",
        categories: [
            "synthetic_debug",
            "release_packaging_identity",
            "governance_audit_persistence",
        ],
    },
    account_preference_contract_held: {
        classification: "reusable_product_behavior",
        disposition: "held",
        rationale:
            "The request validates account presentation and delivery choices, but its seven-field server surface is not matched by either native preference editor and includes delivery channels without an implemented patient delivery capability. Nightingale needs separate presentation, locale, consent, and communication-preference ownership before adopting any field.",
        categories: ["account_preferences", "accessibility_presentation"],
    },
    append_only_audit_safety_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "Append-only mutation resistance, external UUID assignment, content-free audit facts, source cursors, immutable status history, and explicit projection failure records are portable safety principles. The Hummingbird table names, model schemas, identifiers, and release semantics remain legacy-specific and are not approved Nightingale implementation.",
        categories: [
            "governance_audit_persistence",
            "source_reconciliation_release",
        ],
    },
    patient_authored_input_behavior_held: {
        classification: "reusable_product_behavior",
        disposition: "held",
        rationale:
            "The services preserve patient-authored preferences and personal goals as immutable, content-free associations to encrypted accountable messages and explicitly avoid clinical care-plan mutation. Nightingale may reuse that separation only after its messaging contract, idempotency, routing, language, review outcome, and patient-visible status are independently approved.",
        categories: [
            "journey_projection_content",
            "governance_audit_persistence",
        ],
    },
    projection_release_safety_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "Version-pinned drafts, immutable history, source reconciliation, independent clinical approval, a distinct catalog release manager, transactional release, and content-free actor digests are strong safety patterns. They do not prove that any pathway definition, source adapter, content, policy, reviewer, or release is approved for Nightingale.",
        categories: [
            "journey_projection_content",
            "source_reconciliation_release",
            "governance_audit_persistence",
        ],
    },
    pathway_source_contract_held: {
        classification: "reusable_product_behavior",
        disposition: "held",
        rationale:
            "The source snapshot and status-observation value objects establish explicit source identity, observation time, version, and status rather than inferring a patient pathway from raw operational rows. Their vocabulary and adapter ownership remain unapproved, so Nightingale holds the behavior until a governed source contract and freshness policy exist.",
        categories: [
            "journey_projection_content",
            "source_reconciliation_release",
        ],
    },
    testing_only_projection_fixture: {
        classification: "test_fixture_only",
        disposition: "test_only",
        rationale:
            "The deterministic projection provisioner refuses non-testing environments and creates synthetic released content for automated verification. It is useful only as a source of test dimensions and must not become Nightingale runtime code, release content, a production fixture, or a shortcut around clinical/content approval.",
        categories: [
            "test_fixture_evidence",
            "synthetic_debug",
            "journey_projection_content",
        ],
    },
    append_only_projection_schema_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "The migrations provide useful evidence for append-only patient-authored facts, pathway history, review/release separation, released-projection outbox creation, and database-level validation. Nightingale adopts no table, trigger, enum, policy, migration path, or production schema change from this classification.",
        categories: [
            "governance_audit_persistence",
            "source_reconciliation_release",
            "journey_projection_content",
        ],
    },
    asset_provenance_evidence: {
        classification: "test_fixture_only",
        disposition: "test_only",
        rationale:
            "The legacy asset record is retained as non-runnable provenance and verification evidence. Its Hummingbird sources, derived files, visual ownership, and product identity do not transfer to Nightingale, whose separately supplied nightingale artwork and provenance remain authoritative.",
        categories: ["test_fixture_evidence", "release_packaging_identity"],
    },
    legacy_packaging_identity_rejected: {
        classification: "rejected_legacy_behavior",
        disposition: "reject",
        rationale:
            "These Xcode, Gradle, manifest, wrapper, signing, build, package, bundle, version, and pilot-release files are bound to the legacy Hummingbird Patient product. Nightingale already has independent targets and must not copy legacy identifiers, build activation, version values, signing assumptions, or release configuration.",
        categories: ["release_packaging_identity"],
    },
    test_fixture_evidence: {
        classification: "test_fixture_only",
        disposition: "test_only",
        rationale:
            "The source is an automated test or UI/instrumentation fixture. It may inform Nightingale test dimensions, negative cases, accessibility assertions, and platform verification, but its Hummingbird identifiers, fixtures, assertions, launch controls, and expected product behavior are not production implementation or approval evidence.",
        categories: ["test_fixture_evidence"],
    },
    debug_synthetic_fixture: {
        classification: "test_fixture_only",
        disposition: "test_only",
        rationale:
            "The source is compiled only into the Android debug source set and supplies emulator navigation or a clearly marked synthetic reference patient. Nightingale may design independent synthetic tests, but must not copy these extras, identifiers, payloads, wording, or any route from a debug scenario into a release artifact.",
        categories: [
            "test_fixture_evidence",
            "synthetic_debug",
            "journey_projection_content",
        ],
    },
    native_journey_behavior_held: {
        classification: "reusable_product_behavior",
        disposition: "held",
        rationale:
            "The native source contains useful patient-centered journey, navigation, session, empty-state, provenance, freshness, urgent-help, or account behavior. It remains tied to Hummingbird models, copy, identifiers, activation, and contract assumptions; Nightingale must reissue it only after field-level contracts and named reviews.",
        categories: [
            "journey_projection_content",
            "accessibility_presentation",
        ],
    },
    native_privacy_configuration_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "Screen-capture protection, backup/transfer exclusion, cleartext-network denial, privacy-cover behavior, and platform-safe manifest configuration are portable defense principles. Nightingale must retain its independent package, threat model, test evidence, and release configuration rather than reuse these Hummingbird files.",
        categories: [
            "accessibility_presentation",
            "release_packaging_identity",
            "governance_audit_persistence",
        ],
    },
    legacy_brand_assets_rejected: {
        classification: "rejected_legacy_behavior",
        disposition: "reject",
        rationale:
            "The images, app icon, launcher resources, colors, strings, and themes carry Hummingbird product identity or legacy scenic artwork. They are not Nightingale assets and cannot be copied or shipped in the independent patient product; only the separately governed Nightingale artwork and design system may be used.",
        categories: [
            "accessibility_presentation",
            "release_packaging_identity",
        ],
    },
    native_presentation_safety_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "Respecting a stronger system text-size setting, high-contrast presentation, reduced-transparency behavior, decorative-image semantics, privacy covers, and bounded motion are useful accessibility/privacy principles. Nightingale must independently implement and test them; the Android reduced-motion account choice currently has no rendering-control branch.",
        categories: ["accessibility_presentation", "account_preferences"],
    },
    release_synthetic_exclusion_principle: {
        classification: "reusable_safety_primitive",
        disposition: "principle_only",
        rationale:
            "The Android release source set replaces launch hooks and synthetic content with inert stubs, providing strong evidence for compile-time exclusion. Nightingale should preserve the property with its own namespace, binary scans, and tests, never by copying the Hummingbird hook names or payload contract.",
        categories: ["synthetic_debug", "release_packaging_identity"],
    },
    legacy_runtime_activation_rejected: {
        classification: "rejected_legacy_behavior",
        disposition: "reject",
        rationale:
            "The iOS configuration can select a live Hummingbird API through plist or process-environment values and validates the legacy production host. Nightingale rejects this activation mechanism, host ownership, keys, and endpoint boundary; any future client activation must be Nightingale-owned, signed-release governed, and independently reviewed.",
        categories: ["release_packaging_identity"],
    },
};

const dispositions = {
    principle_only:
        "Retain only the control objective and evidence. Reimplement it independently under Nightingale ownership after the applicable architecture and review gates; do not copy or activate the legacy source.",
    held: "Retain as candidate product behavior only. No Nightingale contract, route, client, copy, state, data access, or runtime behavior is authorized until the documented blockers and named approvals are closed.",
    test_only:
        "Use only as synthetic test-dimension or provenance evidence. It cannot authorize runtime behavior, release content, production data, a patient record, an endpoint, a provider, or deployment.",
    reject: "Do not migrate this legacy identity, packaging, activation, asset, provisioning, or runtime behavior into Nightingale. A new product-owned design and separate approval are required.",
};

function isProductSource(relativePath) {
    return (
        relativePath.startsWith("hummingbird/iosPatientApp/") ||
        relativePath.startsWith("hummingbird/androidPatientApp/") ||
        /^config\/hummingbird-patient(?:-content)?\.php$/.test(relativePath) ||
        relativePath === "routes/patient.php" ||
        relativePath.startsWith("app/Http/Controllers/Api/Patient/") ||
        relativePath.startsWith("app/Http/Requests/Patient/") ||
        relativePath.startsWith("app/Models/Patient/") ||
        relativePath.startsWith("app/Policies/Patient/") ||
        relativePath.startsWith("app/Services/Patient/") ||
        relativePath.startsWith("app/Contracts/Patient/") ||
        relativePath.startsWith("tests/Feature/Patient/") ||
        relativePath.startsWith("tests/Unit/Patient/") ||
        (/^database\/migrations\/.*patient.*\.php$/i.test(relativePath) &&
            !relativePath.includes("patient_flow")) ||
        /^app\/Console\/Commands\/Hummingbird.*Patient.*Command\.php$/.test(
            relativePath,
        )
    );
}

function decisionIdFor(relativePath) {
    if (
        relativePath.startsWith("tests/") ||
        relativePath.includes("/HummingbirdPatientTests/") ||
        relativePath.includes("/HummingbirdPatientUITests/") ||
        relativePath.includes("/src/androidTest/") ||
        relativePath.includes("/src/test/") ||
        relativePath.includes("/src/testDebug/") ||
        relativePath.includes("/src/testRelease/")
    ) {
        return "test_fixture_evidence";
    }
    if (relativePath.includes("/src/debug/")) {
        return "debug_synthetic_fixture";
    }
    if (
        relativePath ===
        "app/Services/Patient/Projection/SyntheticPatientProjectionProvisioner.php"
    ) {
        return "testing_only_projection_fixture";
    }
    if (relativePath.startsWith("database/migrations/")) {
        return "append_only_projection_schema_principle";
    }
    if (
        relativePath ===
        "app/Http/Requests/Patient/UpdatePreferencesRequest.php"
    ) {
        return "account_preference_contract_held";
    }
    if (relativePath.startsWith("app/Console/Commands/")) {
        return relativePath.includes("DraftPatientPathwayHistory")
            ? "pathway_draft_command_held"
            : "reference_provisioning_rejected";
    }
    if (relativePath.startsWith("app/Services/Patient/Demo/")) {
        return "reference_provisioning_rejected";
    }
    if (
        relativePath.includes("/Goals/") ||
        relativePath.includes("/Preferences/") ||
        relativePath.endsWith("/PatientAuthoredGoal.php") ||
        relativePath.endsWith("/PatientCarePreference.php")
    ) {
        return "patient_authored_input_behavior_held";
    }
    if (relativePath.includes("/Pathway/Source/")) {
        return "pathway_source_contract_held";
    }
    if (
        relativePath.includes("/Pathway/") ||
        relativePath.includes("/Projection/PatientPathway")
    ) {
        return "projection_release_safety_principle";
    }
    if (
        relativePath.endsWith("/PatientHmac.php") ||
        relativePath.endsWith("/PatientAccessAuditRecorder.php") ||
        relativePath.includes("/Concerns/") ||
        /PatientAccessAuditEvent|PatientContentAction|PatientProjectionCursor|PatientProjectionFailure|PatientPathwayProjection|PatientPathwayInstance|PatientPathwayMilestone|PatientPathwayStage/.test(
            relativePath,
        )
    ) {
        return "append_only_audit_safety_principle";
    }
    if (relativePath.startsWith("app/Models/Patient/")) {
        return "append_only_audit_safety_principle";
    }
    if (relativePath.startsWith("hummingbird/iosPatientApp/")) {
        if (relativePath.includes("/Assets.xcassets/")) {
            return "legacy_brand_assets_rejected";
        }
        if (
            relativePath.includes(".xcodeproj/") ||
            relativePath.endsWith("/project.yml") ||
            relativePath.endsWith("/Info.plist")
        ) {
            return "legacy_packaging_identity_rejected";
        }
        if (relativePath.endsWith("/PatientAppConfiguration.swift")) {
            return "legacy_runtime_activation_rejected";
        }
        if (
            relativePath.endsWith("/PatientPresentationPreferences.swift") ||
            relativePath.endsWith("/PatientPhotoBackground.swift") ||
            relativePath.endsWith("/PatientComponents.swift") ||
            relativePath.endsWith("/PatientPrivacyCoverView.swift")
        ) {
            return "native_presentation_safety_principle";
        }
        return "native_journey_behavior_held";
    }
    if (relativePath.startsWith("hummingbird/androidPatientApp/")) {
        if (
            relativePath === "hummingbird/androidPatientApp/ASSET_PROVENANCE.md"
        ) {
            return "asset_provenance_evidence";
        }
        if (relativePath.includes("/src/release/")) {
            return "release_synthetic_exclusion_principle";
        }
        if (
            relativePath.includes("/res/xml/data_extraction_rules.xml") ||
            relativePath.includes("/res/xml/network_security_config.xml") ||
            relativePath.endsWith("/AndroidManifest.xml") ||
            relativePath.endsWith("/PatientPrivacyPolicy.kt")
        ) {
            return "native_privacy_configuration_principle";
        }
        if (
            relativePath.includes("/res/") ||
            relativePath.endsWith("/HummingbirdPatientTheme.kt")
        ) {
            return "legacy_brand_assets_rejected";
        }
        if (
            relativePath.endsWith("/PatientPresentationAccessibility.kt") ||
            relativePath.endsWith("/PatientScenicBackground.kt")
        ) {
            return "native_presentation_safety_principle";
        }
        if (
            relativePath.endsWith(".gradle.kts") ||
            relativePath.endsWith("gradle.properties") ||
            relativePath.includes("/gradle/wrapper/") ||
            relativePath.endsWith("/gradlew") ||
            relativePath.endsWith("/gradlew.bat") ||
            relativePath.endsWith("/proguard-rules.pro")
        ) {
            return "legacy_packaging_identity_rejected";
        }
        return "native_journey_behavior_held";
    }

    throw new Error(`unclassified final-slice source: ${relativePath}`);
}

function surfaceFor(relativePath) {
    if (relativePath.startsWith("app/Console/Commands/")) {
        return "legacy_backend_command";
    }
    if (relativePath.startsWith("app/Http/Requests/")) {
        return "legacy_backend_request";
    }
    if (relativePath.startsWith("app/Models/")) {
        return "legacy_backend_model";
    }
    if (relativePath.startsWith("app/Services/")) {
        return "legacy_backend_service";
    }
    if (relativePath.startsWith("database/migrations/")) {
        return "legacy_database";
    }
    if (relativePath.startsWith("tests/")) {
        return "legacy_backend_test";
    }
    if (relativePath.includes("/HummingbirdPatientTests/")) {
        return "legacy_ios_test";
    }
    if (relativePath.includes("/HummingbirdPatientUITests/")) {
        return "legacy_ios_ui_test";
    }
    if (relativePath.startsWith("hummingbird/iosPatientApp/")) {
        if (relativePath.includes("/Assets.xcassets/")) {
            return "legacy_ios_asset";
        }
        if (
            relativePath.includes(".xcodeproj/") ||
            relativePath.endsWith("/project.yml") ||
            relativePath.endsWith("/Info.plist")
        ) {
            return "legacy_ios_packaging";
        }
        return "legacy_ios_source";
    }
    if (
        relativePath.includes("/src/androidTest/") ||
        relativePath.includes("/src/test/") ||
        relativePath.includes("/src/testDebug/") ||
        relativePath.includes("/src/testRelease/")
    ) {
        return "legacy_android_test";
    }
    if (relativePath.includes("/src/debug/")) {
        return "legacy_android_debug_source";
    }
    if (relativePath.includes("/src/release/")) {
        return "legacy_android_release_source";
    }
    if (
        relativePath.startsWith("hummingbird/androidPatientApp/") &&
        relativePath.includes("/res/")
    ) {
        return "legacy_android_resource";
    }
    if (
        relativePath.startsWith("hummingbird/androidPatientApp/") &&
        (relativePath.endsWith(".gradle.kts") ||
            relativePath.endsWith("gradle.properties") ||
            relativePath.includes("/gradle/wrapper/") ||
            relativePath.endsWith("/gradlew") ||
            relativePath.endsWith("/gradlew.bat") ||
            relativePath.endsWith("/proguard-rules.pro"))
    ) {
        return "legacy_android_packaging";
    }
    if (relativePath.startsWith("hummingbird/androidPatientApp/")) {
        return relativePath.endsWith(".md")
            ? "legacy_android_evidence"
            : "legacy_android_source";
    }
    throw new Error(`unknown source surface: ${relativePath}`);
}

function categoriesFor(relativePath, decisionId) {
    const categories = new Set(decisions[decisionId].categories);
    if (decisionId === "test_fixture_evidence") {
        const name = relativePath.toLowerCase();
        if (/preference|presentation|accessib/.test(name)) {
            categories.add("account_preferences");
            categories.add("accessibility_presentation");
        }
        if (/pathway|journey|statevocabulary/.test(name)) {
            categories.add("journey_projection_content");
        }
        if (/provision|synthetic|launchhook/.test(name)) {
            categories.add("synthetic_debug");
        }
        if (/session|auth|secure|privacy|release/.test(name)) {
            categories.add("governance_audit_persistence");
            categories.add("release_packaging_identity");
        }
    }
    return [...categories].sort();
}

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function readJson(relativePath) {
    return JSON.parse(
        fs.readFileSync(path.join(repoRoot, relativePath), "utf8"),
    );
}

const trackedPaths = execFileSync("git", ["ls-files", "-z"], {
    cwd: repoRoot,
    encoding: "utf8",
})
    .split("\0")
    .filter(Boolean)
    .sort();
const productUniverse = trackedPaths.filter(isProductSource);
const previouslyClassified = new Set(
    priorManifestPaths.flatMap((relativePath) =>
        readJson(relativePath).sources.map((source) => source.path),
    ),
);
const finalSlicePaths = productUniverse.filter(
    (relativePath) => !previouslyClassified.has(relativePath),
);

const sources = finalSlicePaths.map((relativePath) => {
    const decisionId = decisionIdFor(relativePath);
    const decision = decisions[decisionId];
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath)) {
        throw new Error(`missing classified source: ${relativePath}`);
    }
    return {
        path: relativePath,
        sha256: sha256(fs.readFileSync(absolutePath)),
        surface: surfaceFor(relativePath),
        classification: decision.classification,
        disposition: decision.disposition,
        categories: categoriesFor(relativePath, decisionId),
        decision_id: decisionId,
    };
});

const document = {
    schema_version: 1,
    classification_id:
        "nightingale-journey-preference-presentation-release-source-classification.v1",
    reviewed_source_commit: reviewedSourceCommit,
    reviewed_at: "2026-07-26",
    status: "evidence_only_not_approved_for_implementation",
    scope: [
        "journey_projection_content",
        "account_preferences",
        "accessibility_presentation",
        "synthetic_debug",
        "release_packaging_identity",
        "governance_audit_persistence",
        "source_reconciliation_release",
        "test_fixture_evidence",
    ],
    product_universe: {
        definition:
            "Tracked legacy Hummingbird Patient native roots plus patient contract/backend/model/service/test/migration/command sources, excluding unrelated patient_flow migrations.",
        source_count: productUniverse.length,
        inventory_digest: sha256(`${productUniverse.join("\n")}\n`),
        previously_classified_unique_count: productUniverse.filter(
            (relativePath) => previouslyClassified.has(relativePath),
        ).length,
        final_slice_count: sources.length,
        final_slice_inventory_digest: sha256(
            `${sources.map((source) => source.path).join("\n")}\n`,
        ),
        prior_manifests: priorManifestPaths,
        excluded_path_rules: [
            "database/migrations/*patient_flow*.php",
            "staff/general mobile, web, and operational sources outside explicit prior communication evidence",
            "generated build outputs and untracked emulator/tool caches",
        ],
    },
    global_constraints: {
        implementation_permitted: false,
        runtime_adoption_permitted: false,
        route_registration_permitted: false,
        client_networking_permitted: false,
        legacy_identifier_reuse_permitted: false,
        legacy_asset_reuse_permitted: false,
        legacy_activation_reuse_permitted: false,
        synthetic_runtime_permitted: false,
        synthetic_release_content_permitted: false,
        reference_provisioning_permitted: false,
        patient_preference_persistence_permitted: false,
        patient_authored_mutation_permitted: false,
        pathway_source_adapter_permitted: false,
        pathway_draft_generation_permitted: false,
        pathway_release_permitted: false,
        migration_execution_permitted: false,
        production_data_used: false,
        production_query_permitted: false,
        production_replay_permitted: false,
        patient_or_principal_created: false,
        deployment_permitted: false,
    },
    required_findings: {
        product_source_universe_count: productUniverse.length,
        previously_classified_unique_count: productUniverse.filter(
            (relativePath) => previouslyClassified.has(relativePath),
        ).length,
        final_slice_source_count: sources.length,
        all_product_sources_classified: true,
        all_product_sources_approved_for_migration: false,
        server_and_native_preference_surfaces_match: false,
        native_locale_and_timezone_controls_present: false,
        patient_delivery_preferences_match_available_channels: false,
        android_reduced_motion_has_rendering_control_branch: false,
        ios_reduced_motion_controls_transition_or_background_motion: true,
        synthetic_reference_content_is_compile_excluded_from_release: true,
        reference_provisioning_is_approved_for_nightingale_or_production: false,
        legacy_release_configuration_is_nightingale_owned: false,
        patient_journeys_expose_empty_or_uncertain_released_content_patterns: true,
        legacy_first_record_encounter_selection_is_patient_safe: false,
        ios_journey_context_is_field_level_per_projection: false,
        android_composite_path_context_covers_every_displayed_projection: false,
        legacy_native_apps_expose_messages_as_top_level_navigation: true,
        nightingale_charter_approves_top_level_messages_navigation: false,
        independent_clinical_and_release_manager_controls_exist: true,
        two_person_release_control_covers_every_projection_kind: false,
        pathway_release_is_approved_for_nightingale: false,
        legacy_brand_assets_or_identifiers_are_reusable_in_nightingale: false,
    },
    classifications: {
        reusable_safety_primitive:
            "A control objective may be independently reimplemented after approval; no legacy schema, code, identifier, copy, asset, or activation transfers.",
        reusable_product_behavior:
            "A patient need or behavior is a held design input only; its contract, content, state, ownership, authorization, and implementation remain unapproved.",
        test_fixture_only:
            "The source may inform synthetic tests, negative cases, or provenance only and can never become runtime, production, patient, or release content.",
        rejected_legacy_behavior:
            "The legacy product identity, activation, packaging, asset, provisioning, or behavior must not migrate into Nightingale.",
    },
    dispositions,
    decisions,
    sources,
};

const serialized = `${JSON.stringify(document, null, 4)}\n`;
if (write) {
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, serialized);
} else {
    process.stdout.write(serialized);
}
