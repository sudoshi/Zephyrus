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

function fail(message) {
    process.stderr.write(
        `Nightingale journey/preference/release classification violation: ${message}\n`,
    );
    process.exit(1);
}

if (unknownOptions.length > 0) {
    fail(`unknown option(s): ${unknownOptions.join(", ")}`);
}
if (positional.length > 1) {
    fail("expected at most one repository-root argument");
}

const repoRoot = path.resolve(positional[0] ?? ".");
const manifestPath =
    "docs/nightingale/migration/candidates/v0/journey-preference-presentation-release-source-classification.json";
const reportPath =
    "docs/nightingale/JOURNEY-PREFERENCE-PRESENTATION-RELEASE-SOURCE-CLASSIFICATION-2026-07-26.md";
const builderPath =
    "scripts/ci/build-nightingale-journey-preference-release-classification.mjs";
const priorManifestPaths = [
    "docs/nightingale/migration/candidates/v0/source-classification.json",
    "docs/nightingale/migration/candidates/v0/communication-notification-source-classification.json",
];
const expectedReviewedCommit = "e8f2b33bca79c4134f2476f41702430da72816d7";
const expectedClassificationId =
    "nightingale-journey-preference-presentation-release-source-classification.v1";
const expectedStatus = "evidence_only_not_approved_for_implementation";
const expectedUniverseCount = 255;
const expectedPriorCount = 122;
const expectedSourceCount = 133;
const expectedUniverseDigest =
    "d6f680b73278786f8004826029e6a9413f921db4ce03df8873bde4c23c62d99c";
const expectedSliceDigest =
    "dd74e3d050839815f731b02af1b2d3d4886e1837913f17e8bc87244c4ad172d2";

const expectedScope = [
    "journey_projection_content",
    "account_preferences",
    "accessibility_presentation",
    "synthetic_debug",
    "release_packaging_identity",
    "governance_audit_persistence",
    "source_reconciliation_release",
    "test_fixture_evidence",
];
const expectedClassCounts = {
    reusable_product_behavior: 20,
    rejected_legacy_behavior: 44,
    reusable_safety_primitive: 41,
    test_fixture_only: 28,
};
const expectedDispositionCounts = {
    held: 20,
    reject: 44,
    principle_only: 41,
    test_only: 28,
};
const classDisposition = {
    reusable_safety_primitive: "principle_only",
    reusable_product_behavior: "held",
    test_fixture_only: "test_only",
    rejected_legacy_behavior: "reject",
};
const expectedSurfaceCounts = {
    legacy_backend_command: 4,
    legacy_backend_request: 1,
    legacy_backend_model: 15,
    legacy_backend_service: 14,
    legacy_database: 11,
    legacy_android_evidence: 1,
    legacy_android_packaging: 9,
    legacy_android_test: 12,
    legacy_android_debug_source: 2,
    legacy_android_source: 9,
    legacy_android_resource: 13,
    legacy_android_release_source: 2,
    legacy_ios_packaging: 5,
    legacy_ios_source: 12,
    legacy_ios_asset: 11,
    legacy_ios_test: 2,
    legacy_ios_ui_test: 1,
    legacy_backend_test: 9,
};
const expectedDecisionCounts = {
    pathway_draft_command_held: 1,
    reference_provisioning_rejected: 6,
    account_preference_contract_held: 1,
    append_only_audit_safety_principle: 15,
    patient_authored_input_behavior_held: 4,
    projection_release_safety_principle: 4,
    pathway_source_contract_held: 2,
    testing_only_projection_fixture: 1,
    append_only_projection_schema_principle: 11,
    asset_provenance_evidence: 1,
    legacy_packaging_identity_rejected: 14,
    test_fixture_evidence: 24,
    debug_synthetic_fixture: 2,
    native_journey_behavior_held: 12,
    native_privacy_configuration_principle: 3,
    legacy_brand_assets_rejected: 23,
    native_presentation_safety_principle: 6,
    release_synthetic_exclusion_principle: 2,
    legacy_runtime_activation_rejected: 1,
};
const expectedCategoryCounts = {
    journey_projection_content: 44,
    source_reconciliation_release: 33,
    governance_audit_persistence: 53,
    release_packaging_identity: 60,
    synthetic_debug: 16,
    accessibility_presentation: 48,
    account_preferences: 10,
    test_fixture_evidence: 28,
};
const expectedConstraintKeys = [
    "implementation_permitted",
    "runtime_adoption_permitted",
    "route_registration_permitted",
    "client_networking_permitted",
    "legacy_identifier_reuse_permitted",
    "legacy_asset_reuse_permitted",
    "legacy_activation_reuse_permitted",
    "synthetic_runtime_permitted",
    "synthetic_release_content_permitted",
    "reference_provisioning_permitted",
    "patient_preference_persistence_permitted",
    "patient_authored_mutation_permitted",
    "pathway_source_adapter_permitted",
    "pathway_draft_generation_permitted",
    "pathway_release_permitted",
    "migration_execution_permitted",
    "production_data_used",
    "production_query_permitted",
    "production_replay_permitted",
    "patient_or_principal_created",
    "deployment_permitted",
];
const expectedFindingValues = {
    product_source_universe_count: 255,
    previously_classified_unique_count: 122,
    final_slice_source_count: 133,
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
};
const expectedSourceFields = [
    "path",
    "sha256",
    "surface",
    "classification",
    "disposition",
    "categories",
    "decision_id",
];
const requiredReportFragments = [
    "the union covers all **255 of 255** sources",
    "Android reduced motion is currently semantically inert",
    "iOS context aggregation is not field-level provenance",
    "Android composite My Path context is incomplete",
    "Messages destination is therefore **not migration-approved**",
    "Command-accessible reference provisioning is rejected",
    "No production database was accessed.",
];
const forbiddenLiteralPatterns = [
    /(?:postgres(?:ql)?|https?):\/\/[^/\s]+:[^@\s]+@/i,
    /\b(?:password|secret|access_token|refresh_token|bearer_token)\b\s*[:=]\s*["'][^"']+["']/i,
    /\b(?:\d{1,3}\.){3}\d{1,3}\b/,
];

function read(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath)) {
        fail(`missing ${relativePath}`);
    }
    return fs.readFileSync(absolutePath, "utf8");
}

function readJson(relativePath) {
    try {
        return JSON.parse(read(relativePath));
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

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function sourceDigest(relativePath) {
    return sha256(fs.readFileSync(path.join(repoRoot, relativePath)));
}

function inventoryDigest(paths) {
    return sha256(`${[...paths].sort().join("\n")}\n`);
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
        sameMembers(Object.keys(actual), Object.keys(expected)) &&
        Object.entries(expected).every(([key, value]) => actual[key] === value)
    );
}

function countBy(values) {
    return values.reduce((counts, value) => {
        counts[value] = (counts[value] ?? 0) + 1;
        return counts;
    }, {});
}

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

function repositoryInventory() {
    const trackedPaths = execFileSync("git", ["ls-files", "-z"], {
        cwd: repoRoot,
        encoding: "utf8",
    })
        .split("\0")
        .filter(Boolean)
        .sort();
    const universe = trackedPaths.filter(isProductSource);
    const priorSources = new Set(
        priorManifestPaths.flatMap((relativePath) =>
            readJson(relativePath).sources.map((source) => source.path),
        ),
    );
    const priorProductPaths = universe.filter((relativePath) =>
        priorSources.has(relativePath),
    );
    const expectedSlicePaths = universe.filter(
        (relativePath) => !priorSources.has(relativePath),
    );
    return { universe, priorSources, priorProductPaths, expectedSlicePaths };
}

function inspect(
    document,
    {
        verifyFiles = true,
        verifyRepository = true,
        verifyNarrative = true,
    } = {},
) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(document.schema_version === 1, "schema_version must remain 1");
    assert(
        document.classification_id === expectedClassificationId,
        "classification_id changed",
    );
    assert(
        document.reviewed_source_commit === expectedReviewedCommit,
        "reviewed_source_commit changed without a new classification version",
    );
    assert(
        document.reviewed_at === "2026-07-26",
        "reviewed_at changed without a new classification version",
    );
    assert(
        document.status === expectedStatus,
        "status must remain evidence-only and unapproved",
    );
    assert(sameMembers(document.scope, expectedScope), "scope changed");

    assert(
        isRecord(document.global_constraints),
        "global_constraints must be an object",
    );
    if (isRecord(document.global_constraints)) {
        assert(
            sameMembers(
                Object.keys(document.global_constraints),
                expectedConstraintKeys,
            ),
            "global constraint inventory changed",
        );
        for (const key of expectedConstraintKeys) {
            assert(
                document.global_constraints[key] === false,
                `${key} must remain false`,
            );
        }
    }

    assert(
        sameRecord(document.required_findings, expectedFindingValues),
        "required findings changed",
    );
    assert(
        sameMembers(
            Object.keys(document.classifications ?? {}),
            Object.keys(classDisposition),
        ),
        "classification definitions changed",
    );
    for (const [key, definition] of Object.entries(
        document.classifications ?? {},
    )) {
        assert(
            typeof definition === "string" && definition.trim().length >= 100,
            `classification ${key} requires a substantive definition`,
        );
    }
    assert(
        sameMembers(
            Object.keys(document.dispositions ?? {}),
            Object.values(classDisposition),
        ),
        "disposition definitions changed",
    );
    for (const [key, definition] of Object.entries(
        document.dispositions ?? {},
    )) {
        assert(
            typeof definition === "string" && definition.trim().length >= 100,
            `disposition ${key} requires a substantive definition`,
        );
    }

    assert(isRecord(document.decisions), "decisions must be an object");
    if (isRecord(document.decisions)) {
        assert(
            sameMembers(
                Object.keys(document.decisions),
                Object.keys(expectedDecisionCounts),
            ),
            "decision inventory changed",
        );
        for (const [decisionId, decision] of Object.entries(
            document.decisions,
        )) {
            assert(
                isRecord(decision),
                `decision ${decisionId} must be an object`,
            );
            if (!isRecord(decision)) continue;
            assert(
                sameMembers(Object.keys(decision), [
                    "classification",
                    "disposition",
                    "rationale",
                    "categories",
                ]),
                `decision ${decisionId} field inventory changed`,
            );
            assert(
                classDisposition[decision.classification] ===
                    decision.disposition,
                `decision ${decisionId} has incompatible class/disposition`,
            );
            assert(
                typeof decision.rationale === "string" &&
                    decision.rationale.trim().length >= 180,
                `decision ${decisionId} requires a substantive rationale`,
            );
            assert(
                Array.isArray(decision.categories) &&
                    decision.categories.length > 0 &&
                    decision.categories.every((category) =>
                        expectedScope.includes(category),
                    ),
                `decision ${decisionId} has invalid categories`,
            );
        }
    }

    const sources = document.sources;
    assert(Array.isArray(sources), "sources must be an array");
    if (!Array.isArray(sources)) return violations;
    assert(
        sources.length === expectedSourceCount,
        `expected ${expectedSourceCount} final-slice sources`,
    );
    const paths = sources.map((source) => source.path);
    assert(new Set(paths).size === paths.length, "source paths must be unique");
    assert(
        paths.every((value, index) => index === 0 || paths[index - 1] < value),
        "source paths must be lexicographically sorted",
    );

    const categoryCounts = {};
    for (const source of sources) {
        assert(isRecord(source), "every source must be an object");
        if (!isRecord(source)) continue;
        assert(
            sameMembers(Object.keys(source), expectedSourceFields),
            `source ${source.path ?? "[unknown]"} field inventory changed`,
        );
        assert(
            typeof source.path === "string" &&
                source.path.length > 0 &&
                !path.isAbsolute(source.path) &&
                !source.path.includes(".."),
            "source path must be safe and repository-relative",
        );
        assert(
            /^[0-9a-f]{64}$/.test(source.sha256 ?? ""),
            `source ${source.path} has invalid sha256`,
        );
        assert(
            classDisposition[source.classification] === source.disposition,
            `source ${source.path} has incompatible class/disposition`,
        );
        const decision = document.decisions?.[source.decision_id];
        assert(
            isRecord(decision),
            `source ${source.path} references unknown decision`,
        );
        if (isRecord(decision)) {
            assert(
                decision.classification === source.classification &&
                    decision.disposition === source.disposition,
                `source ${source.path} differs from its decision`,
            );
            assert(
                source.categories.every(
                    (category) =>
                        decision.categories.includes(category) ||
                        source.decision_id === "test_fixture_evidence",
                ),
                `source ${source.path} has a category outside its decision`,
            );
        }
        assert(
            Array.isArray(source.categories) &&
                source.categories.length > 0 &&
                new Set(source.categories).size === source.categories.length &&
                source.categories.every((category) =>
                    expectedScope.includes(category),
                ),
            `source ${source.path} categories are invalid`,
        );
        for (const category of source.categories ?? []) {
            categoryCounts[category] = (categoryCounts[category] ?? 0) + 1;
        }
        if (verifyFiles && typeof source.path === "string") {
            const absolutePath = path.join(repoRoot, source.path);
            assert(
                fs.existsSync(absolutePath),
                `missing source ${source.path}`,
            );
            if (fs.existsSync(absolutePath)) {
                assert(
                    sourceDigest(source.path) === source.sha256,
                    `checksum drift for ${source.path}`,
                );
            }
        }
    }
    assert(
        sameRecord(
            countBy(sources.map((source) => source.classification)),
            expectedClassCounts,
        ),
        "classification counts changed",
    );
    assert(
        sameRecord(
            countBy(sources.map((source) => source.disposition)),
            expectedDispositionCounts,
        ),
        "disposition counts changed",
    );
    assert(
        sameRecord(
            countBy(sources.map((source) => source.surface)),
            expectedSurfaceCounts,
        ),
        "surface counts changed",
    );
    assert(
        sameRecord(
            countBy(sources.map((source) => source.decision_id)),
            expectedDecisionCounts,
        ),
        "decision counts changed",
    );
    assert(
        sameRecord(categoryCounts, expectedCategoryCounts),
        "category counts changed",
    );
    assert(
        inventoryDigest(paths) === expectedSliceDigest,
        "final-slice inventory digest changed",
    );

    const universe = document.product_universe;
    assert(isRecord(universe), "product_universe must be an object");
    if (isRecord(universe)) {
        assert(
            universe.source_count === expectedUniverseCount,
            "product universe count changed",
        );
        assert(
            universe.inventory_digest === expectedUniverseDigest,
            "product universe digest changed",
        );
        assert(
            universe.previously_classified_unique_count === expectedPriorCount,
            "prior covered-source count changed",
        );
        assert(
            universe.final_slice_count === expectedSourceCount,
            "final-slice count changed in product_universe",
        );
        assert(
            universe.final_slice_inventory_digest === expectedSliceDigest,
            "final-slice digest changed in product_universe",
        );
        assert(
            sameMembers(universe.prior_manifests, priorManifestPaths),
            "prior manifest inventory changed",
        );
    }

    if (verifyRepository) {
        const inventory = repositoryInventory();
        assert(
            inventory.universe.length === expectedUniverseCount,
            "tracked product universe count drifted",
        );
        assert(
            inventoryDigest(inventory.universe) === expectedUniverseDigest,
            "tracked product universe digest drifted",
        );
        assert(
            inventory.priorProductPaths.length === expectedPriorCount,
            "prior-ledger product coverage drifted",
        );
        assert(
            inventory.expectedSlicePaths.length === expectedSourceCount,
            "unclassified product-source count drifted",
        );
        assert(
            sameMembers(paths, inventory.expectedSlicePaths),
            "final ledger is not the exact universe remainder",
        );
        assert(
            paths.every(
                (relativePath) => !inventory.priorSources.has(relativePath),
            ),
            "final ledger overlaps a prior classification ledger",
        );
        const covered = new Set([...inventory.priorProductPaths, ...paths]);
        assert(
            covered.size === expectedUniverseCount &&
                inventory.universe.every((relativePath) =>
                    covered.has(relativePath),
                ),
            "the three ledgers do not cover 255 of 255 product sources",
        );
    }

    const sensitiveText = JSON.stringify(document);
    for (const pattern of forbiddenLiteralPatterns) {
        assert(
            !pattern.test(sensitiveText),
            `classification contains forbidden sensitive literal pattern ${pattern}`,
        );
    }

    if (verifyNarrative) {
        const report = read(reportPath);
        for (const fragment of requiredReportFragments) {
            assert(
                report.includes(fragment),
                `report is missing required finding: ${fragment}`,
            );
        }
        for (const pattern of forbiddenLiteralPatterns) {
            assert(
                !pattern.test(report),
                `report contains forbidden sensitive literal pattern ${pattern}`,
            );
        }
        const generated = execFileSync(
            process.execPath,
            [path.join(repoRoot, builderPath), repoRoot],
            { cwd: repoRoot, encoding: "utf8" },
        );
        assert(
            generated === read(manifestPath),
            "builder output does not exactly match the committed manifest",
        );
    }

    return violations;
}

const document = readJson(manifestPath);
const violations = inspect(document);
if (violations.length > 0) {
    fail(violations.join("\n- "));
}

if (selfTest) {
    const mutations = [
        {
            name: "runtime adoption",
            mutate(value) {
                value.global_constraints.runtime_adoption_permitted = true;
            },
        },
        {
            name: "production query",
            mutate(value) {
                value.global_constraints.production_query_permitted = true;
            },
        },
        {
            name: "source omission",
            mutate(value) {
                value.sources.pop();
            },
        },
        {
            name: "source checksum drift",
            mutate(value) {
                value.sources[0].sha256 = "0".repeat(64);
            },
        },
        {
            name: "classification weakening",
            mutate(value) {
                value.sources[0].classification = "reusable_safety_primitive";
            },
        },
        {
            name: "android reduced-motion overclaim",
            mutate(value) {
                value.required_findings.android_reduced_motion_has_rendering_control_branch = true;
            },
        },
        {
            name: "global migration approval",
            mutate(value) {
                value.required_findings.all_product_sources_approved_for_migration = true;
            },
        },
        {
            name: "source-universe count",
            mutate(value) {
                value.product_universe.source_count = 254;
            },
        },
        {
            name: "decision rationale removal",
            mutate(value) {
                value.decisions.pathway_draft_command_held.rationale =
                    "Looks reusable.";
            },
        },
        {
            name: "synthetic release activation",
            mutate(value) {
                value.global_constraints.synthetic_release_content_permitted = true;
            },
        },
    ];

    for (const mutation of mutations) {
        const changed = clone(document);
        mutation.mutate(changed);
        const mutationViolations = inspect(changed, {
            verifyFiles: true,
            verifyRepository: false,
            verifyNarrative: false,
        });
        if (mutationViolations.length === 0) {
            fail(`negative self-test did not reject ${mutation.name}`);
        }
    }
    process.stdout.write(
        `Nightingale journey/preference/release classification verifier passed ${mutations.length} negative self-tests.\n`,
    );
} else {
    process.stdout.write(
        `Nightingale journey/preference/release classification verified ${expectedSourceCount} final-slice sources and complete ${expectedUniverseCount}-source product coverage.\n`,
    );
}
