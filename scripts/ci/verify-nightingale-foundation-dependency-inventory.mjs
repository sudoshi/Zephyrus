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

function fail(message) {
    process.stderr.write(
        `Nightingale dependency-inventory violation: ${message}\n`,
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
const inventoryPath = path.join(
    repoRoot,
    "docs/nightingale/supply-chain/foundation-dependency-inventory.v0.json",
);

if (!fs.existsSync(inventoryPath)) {
    fail(`missing canonical inventory: ${inventoryPath}`);
}

let inventory;
try {
    inventory = JSON.parse(fs.readFileSync(inventoryPath, "utf8"));
} catch (error) {
    fail(`invalid canonical JSON: ${error.message}`);
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function hashFile(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath)) return null;
    return crypto
        .createHash("sha256")
        .update(fs.readFileSync(absolutePath))
        .digest("hex");
}

function sameMembers(actual, expected) {
    return (
        actual.length === expected.length &&
        [...actual]
            .sort()
            .every((value, index) => value === [...expected].sort()[index])
    );
}

function tupleSorted(rows, fields) {
    return rows.every((row, index) => {
        if (index === 0) return true;
        const previous = rows[index - 1];
        for (const field of fields) {
            const left = String(previous[field] ?? "");
            const right = String(row[field] ?? "");
            if (left < right) return true;
            if (left > right) return false;
        }
        return true;
    });
}

function inspect(candidate) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(
        candidate.schema ===
            "net.acumenus.nightingale.foundation-dependency-inventory",
        "schema identifier changed",
    );
    assert(candidate.schema_version === 1, "schema version changed");
    assert(candidate.product?.name === "Nightingale", "product name changed");
    assert(
        candidate.product?.apple_bundle_id === "net.acumenus.nightingale" &&
            candidate.product?.android_application_id ===
                "net.acumenus.nightingale",
        "native product identifier changed",
    );
    assert(
        candidate.product?.release_state ===
            "offline foundation; not approved for distribution or live use",
        "release state is no longer explicitly unapproved",
    );

    const scope = candidate.scope ?? {};
    assert(
        scope.inventory_kind === "governed foundation dependency inventory",
        "inventory kind changed or implies unsupported standard conformance",
    );
    assert(
        scope.android_configuration === "releaseRuntimeClasspath",
        "scope is not the Android Release runtime configuration",
    );
    assert(
        scope.ios_configuration ===
            "Nightingale application target declarations",
        "scope is not the iOS application target",
    );
    assert(
        scope.contains_patient_data === false,
        "inventory claims or permits patient data",
    );
    assert(
        scope.production_system_access_used === false,
        "inventory claims or permits production access",
    );
    assert(
        scope.excludes?.includes(
            "CycloneDX, SPDX, or other standards conformance",
        ),
        "standards-conformance limitation is missing",
    );
    for (const requiredExclusion of [
        "licenses and legal conclusions",
        "vulnerability status or exploitability",
        "artifact checksums and signing provenance",
        "package-registry provenance and source-repository identity",
        "resolved build-plugin and build-tool transitive dependencies",
        "test-only and debug-only dependency resolution",
    ]) {
        assert(
            scope.excludes?.includes(requiredExclusion),
            `missing scope exclusion: ${requiredExclusion}`,
        );
    }

    const expectedSourceFiles = [
        "nightingale/androidApp/app/build.gradle.kts",
        "nightingale/androidApp/build.gradle.kts",
        "nightingale/androidApp/settings.gradle.kts",
        "nightingale/androidApp/gradle/wrapper/gradle-wrapper.properties",
        "nightingale/iosApp/project.yml",
        "scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs",
    ];
    const recordedHashes = candidate.generation?.source_sha256 ?? {};
    assert(
        sameMembers(Object.keys(recordedHashes), expectedSourceFiles),
        "source-hash file set changed",
    );
    for (const relativePath of expectedSourceFiles) {
        const actualHash = hashFile(relativePath);
        assert(actualHash !== null, `missing hashed source: ${relativePath}`);
        assert(
            recordedHashes[relativePath] === actualHash,
            `source hash is stale: ${relativePath}`,
        );
    }
    assert(
        candidate.generation?.gradle_task ===
            ":app:writeNightingaleReleaseDependencyResolution",
        "Gradle resolution task changed",
    );
    assert(
        candidate.generation?.command ===
            "JAVA_HOME=<JDK_17_HOME> node scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs .",
        "regeneration command changed",
    );

    const direct = candidate.android?.declared_dependencies ?? [];
    const directCoordinates = direct.map(
        (dependency) =>
            `${dependency.group}:${dependency.module}:${dependency.requested_version ?? "<platform-managed>"}`,
    );
    const expectedDirectCoordinates = [
        "androidx.activity:activity-compose:1.10.0",
        "androidx.compose:compose-bom:2025.02.00",
        "androidx.compose.animation:animation-core:<platform-managed>",
        "androidx.compose.foundation:foundation:<platform-managed>",
        "androidx.compose.material3:material3:<platform-managed>",
        "androidx.compose.ui:ui-tooling-preview:<platform-managed>",
        "androidx.compose.ui:ui:<platform-managed>",
    ];
    assert(
        sameMembers(directCoordinates, expectedDirectCoordinates),
        "Android direct Release runtime declarations changed",
    );
    assert(
        new Set(directCoordinates).size === directCoordinates.length,
        "Android direct declarations contain duplicates",
    );

    const components = candidate.android?.resolved_components ?? [];
    const componentCoordinates = components.map(
        (component) =>
            `${component.group}:${component.module}:${component.version}`,
    );
    assert(components.length > 0, "Android resolved component set is empty");
    assert(
        new Set(componentCoordinates).size === componentCoordinates.length,
        "Android resolved component set contains duplicates",
    );
    assert(
        tupleSorted(components, ["group", "module", "version"]),
        "Android resolved components are not deterministically sorted",
    );
    for (const requiredComponent of [
        "androidx.activity:activity-compose:1.10.0",
        "androidx.compose:compose-bom:2025.02.00",
        "androidx.compose.animation:animation-core:1.7.8",
        "androidx.compose.foundation:foundation:1.7.8",
        "androidx.compose.material3:material3:1.3.1",
        "androidx.compose.ui:ui:1.7.8",
        "androidx.compose.ui:ui-tooling-preview:1.7.8",
        "org.jetbrains.kotlin:kotlin-stdlib:2.1.20",
    ]) {
        assert(
            componentCoordinates.includes(requiredComponent),
            `missing required resolved component: ${requiredComponent}`,
        );
    }

    const componentSet = new Set(componentCoordinates);
    const edges = candidate.android?.dependency_edges ?? [];
    const edgeKeys = edges.map(
        (edge) => `${edge.from}|${edge.requested}|${edge.selected}`,
    );
    assert(edges.length > 0, "Android dependency-edge set is empty");
    assert(
        new Set(edgeKeys).size === edgeKeys.length,
        "Android dependency-edge set contains duplicates",
    );
    assert(
        tupleSorted(edges, ["from", "requested", "selected"]),
        "Android dependency edges are not deterministically sorted",
    );
    for (const edge of edges) {
        assert(
            edge.from === "project :app" || componentSet.has(edge.from),
            `dependency edge has unknown source: ${edge.from}`,
        );
        assert(
            componentSet.has(edge.selected),
            `dependency edge has unknown target: ${edge.selected}`,
        );
        assert(
            typeof edge.requested === "string" && edge.requested.length > 0,
            "dependency edge has an empty request",
        );
    }

    assert(
        candidate.android?.configuration === "releaseRuntimeClasspath",
        "Android resolved configuration changed",
    );
    assert(
        candidate.android?.counts?.declared_dependencies === direct.length,
        "Android direct-dependency count is stale",
    );
    assert(
        candidate.android?.counts?.resolved_components === components.length,
        "Android resolved-component count is stale",
    );
    assert(
        candidate.android?.counts?.dependency_edges === edges.length,
        "Android dependency-edge count is stale",
    );

    const androidRequirements = candidate.build_requirements?.android ?? {};
    assert(
        androidRequirements.gradle_wrapper === "8.11.1" &&
            androidRequirements.android_gradle_plugin === "8.7.3" &&
            androidRequirements.kotlin_plugins === "2.1.20" &&
            androidRequirements.java_source_and_target === "17" &&
            androidRequirements.compile_sdk === 35 &&
            androidRequirements.min_sdk === 26 &&
            androidRequirements.target_sdk === 35,
        "Android build requirements changed",
    );

    const ios = candidate.ios ?? {};
    assert(ios.target === "Nightingale", "iOS target changed");
    assert(
        Array.isArray(ios.third_party_runtime_packages) &&
            ios.third_party_runtime_packages.length === 0 &&
            ios.counts?.third_party_runtime_packages === 0,
        "iOS third-party package set is no longer empty",
    );
    assert(
        sameMembers(ios.apple_system_modules ?? [], [
            "Combine",
            "Foundation",
            "Security",
            "SwiftUI",
        ]) && ios.counts?.apple_system_modules === 4,
        "iOS Apple system-module inventory changed",
    );
    assert(
        ios.package_manager_inputs_present === false,
        "iOS package-manager input flag changed",
    );
    const iosRequirements = candidate.build_requirements?.ios ?? {};
    assert(
        iosRequirements.xcodegen_spec === "nightingale/iosApp/project.yml" &&
            iosRequirements.swift_language_version === "5.0" &&
            iosRequirements.minimum_ios === "17.0" &&
            iosRequirements.third_party_package_manager === "none declared",
        "iOS build requirements changed",
    );

    const prohibitedIosInputs = [
        "nightingale/iosApp/Package.swift",
        "nightingale/iosApp/Package.resolved",
        "nightingale/iosApp/Podfile",
        "nightingale/iosApp/Podfile.lock",
        "nightingale/iosApp/Cartfile",
        "nightingale/iosApp/Cartfile.resolved",
    ];
    for (const relativePath of prohibitedIosInputs) {
        assert(
            !fs.existsSync(path.join(repoRoot, relativePath)),
            `unrecorded iOS package-manager input exists: ${relativePath}`,
        );
    }
    const iosProjectPath = path.join(
        repoRoot,
        "nightingale/iosApp/project.yml",
    );
    if (fs.existsSync(iosProjectPath)) {
        const iosProject = fs.readFileSync(iosProjectPath, "utf8");
        assert(
            !/^\s*packages\s*:/m.test(iosProject) &&
                !/^\s*-\s*package\s*:/m.test(iosProject),
            "XcodeGen third-party package declaration is not inventoried",
        );
    }

    const interpretation = candidate.interpretation ?? [];
    for (const requiredStatement of [
        "Android entries are resolution facts for the named Release runtime configuration, not approval, license, provenance, or vulnerability findings.",
        "This inventory does not authorize patient data, networking, identity, disclosure, messaging, production access, distribution, or clinical use.",
    ]) {
        assert(
            interpretation.includes(requiredStatement),
            `missing limitation statement: ${requiredStatement}`,
        );
    }

    return violations;
}

const violations = inspect(inventory);
if (violations.length > 0) {
    fail(violations.join("\n"));
}

if (selfTest) {
    const mutations = [
        {
            name: "patient-data claim",
            expected: "patient data",
            mutate(candidate) {
                candidate.scope.contains_patient_data = true;
            },
        },
        {
            name: "production-access claim",
            expected: "production access",
            mutate(candidate) {
                candidate.scope.production_system_access_used = true;
            },
        },
        {
            name: "standards overclaim",
            expected: "inventory kind",
            mutate(candidate) {
                candidate.scope.inventory_kind = "CycloneDX-compliant SBOM";
            },
        },
        {
            name: "stale source hash",
            expected: "source hash is stale",
            mutate(candidate) {
                candidate.generation.source_sha256[
                    "nightingale/iosApp/project.yml"
                ] = "0".repeat(64);
            },
        },
        {
            name: "removed direct dependency",
            expected: "direct Release runtime declarations",
            mutate(candidate) {
                candidate.android.declared_dependencies.pop();
                candidate.android.counts.declared_dependencies -= 1;
            },
        },
        {
            name: "duplicate resolved component",
            expected: "contains duplicates",
            mutate(candidate) {
                candidate.android.resolved_components.push(
                    clone(candidate.android.resolved_components[0]),
                );
                candidate.android.counts.resolved_components += 1;
            },
        },
        {
            name: "unknown dependency target",
            expected: "unknown target",
            mutate(candidate) {
                candidate.android.dependency_edges[0].selected =
                    "example:unrecorded:1.0.0";
            },
        },
        {
            name: "unrecorded iOS package",
            expected: "third-party package set",
            mutate(candidate) {
                candidate.ios.third_party_runtime_packages.push({
                    identity: "Example",
                    version: "1.0.0",
                });
                candidate.ios.counts.third_party_runtime_packages = 1;
            },
        },
        {
            name: "release approval claim",
            expected: "explicitly unapproved",
            mutate(candidate) {
                candidate.product.release_state = "approved for live use";
            },
        },
    ];

    for (const mutation of mutations) {
        const candidate = clone(inventory);
        mutation.mutate(candidate);
        const mutationViolations = inspect(candidate);
        if (
            !mutationViolations.some((violation) =>
                violation.includes(mutation.expected),
            )
        ) {
            fail(
                `self-test did not reject ${mutation.name}; violations: ${mutationViolations.join("; ") || "<none>"}`,
            );
        }
    }
}

process.stdout.write(
    `Nightingale foundation dependency inventory verified (${inventory.android.counts.resolved_components} Android Release runtime components; ${inventory.android.counts.dependency_edges} dependency edges; 0 iOS third-party packages${selfTest ? "; self-test passed" : ""}).\n`,
);
