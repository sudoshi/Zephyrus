#!/usr/bin/env node

import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const args = process.argv.slice(2);
const check = args.includes("--check");
const unknownOptions = args.filter(
    (argument) => argument.startsWith("--") && argument !== "--check",
);
const positional = args.filter((argument) => !argument.startsWith("--"));

function fail(message) {
    process.stderr.write(
        `Nightingale dependency-inventory generation failed: ${message}\n`,
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
const androidRoot = path.join(repoRoot, "nightingale/androidApp");
const iosRoot = path.join(repoRoot, "nightingale/iosApp");
const canonicalPath = path.join(
    repoRoot,
    "docs/nightingale/supply-chain/foundation-dependency-inventory.v0.json",
);
const gradleReportPath = path.join(
    androidRoot,
    "app/build/reports/nightingale/release-runtime-dependency-resolution.json",
);
const generationCommand =
    "JAVA_HOME=<JDK_17_HOME> node scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs .";

const sourceFiles = [
    "nightingale/androidApp/app/build.gradle.kts",
    "nightingale/androidApp/build.gradle.kts",
    "nightingale/androidApp/settings.gradle.kts",
    "nightingale/androidApp/gradle/wrapper/gradle-wrapper.properties",
    "nightingale/iosApp/project.yml",
    "scripts/ci/generate-nightingale-foundation-dependency-inventory.mjs",
];

for (const sourceFile of sourceFiles) {
    if (!fs.existsSync(path.join(repoRoot, sourceFile))) {
        fail(`missing source file: ${sourceFile}`);
    }
}

function read(relativePath) {
    return fs.readFileSync(path.join(repoRoot, relativePath), "utf8");
}

function sha256(relativePath) {
    return crypto
        .createHash("sha256")
        .update(fs.readFileSync(path.join(repoRoot, relativePath)))
        .digest("hex");
}

function requiredMatch(raw, pattern, label) {
    const match = raw.match(pattern);
    if (!match) fail(`could not determine ${label}`);
    return match[1];
}

function runGradleResolution() {
    const executable =
        process.platform === "win32" ? "gradlew.bat" : "./gradlew";
    fs.rmSync(gradleReportPath, { force: true });
    const result = spawnSync(
        executable,
        [
            "--no-daemon",
            "--console=plain",
            ":app:writeNightingaleReleaseDependencyResolution",
        ],
        {
            cwd: androidRoot,
            encoding: "utf8",
            env: process.env,
        },
    );

    if (result.error) {
        fail(`could not start Gradle: ${result.error.message}`);
    }
    if (result.status !== 0) {
        const detail = [result.stdout, result.stderr]
            .filter(Boolean)
            .join("\n")
            .trim();
        fail(
            `Gradle resolution exited ${result.status}. Use a JDK 17 JAVA_HOME.${detail ? `\n${detail}` : ""}`,
        );
    }
    if (!fs.existsSync(gradleReportPath)) {
        fail(
            `Gradle did not write ${path.relative(repoRoot, gradleReportPath)}`,
        );
    }
}

function listSwiftApplicationModules() {
    const sourceRoot = path.join(iosRoot, "Nightingale");
    const modules = new Set();
    for (const entry of fs.readdirSync(sourceRoot, { withFileTypes: true })) {
        if (!entry.isFile() || !entry.name.endsWith(".swift")) continue;
        const raw = fs.readFileSync(path.join(sourceRoot, entry.name), "utf8");
        for (const match of raw.matchAll(/^import\s+([A-Za-z0-9_]+)\s*$/gm)) {
            modules.add(match[1]);
        }
    }
    return [...modules].sort();
}

function assertNoIosPackageManagerInputs(projectYaml) {
    const prohibitedSourceInputs = [
        "nightingale/iosApp/Package.swift",
        "nightingale/iosApp/Package.resolved",
        "nightingale/iosApp/Podfile",
        "nightingale/iosApp/Podfile.lock",
        "nightingale/iosApp/Cartfile",
        "nightingale/iosApp/Cartfile.resolved",
    ];
    const present = prohibitedSourceInputs.filter((relativePath) =>
        fs.existsSync(path.join(repoRoot, relativePath)),
    );
    if (present.length > 0) {
        fail(
            `iOS package-manager inputs require inventory support before generation: ${present.join(", ")}`,
        );
    }
    if (
        /^\s*packages\s*:/m.test(projectYaml) ||
        /^\s*-\s*package\s*:/m.test(projectYaml)
    ) {
        fail(
            "XcodeGen package dependencies require inventory support before generation",
        );
    }
}

runGradleResolution();

let androidResolution;
try {
    androidResolution = JSON.parse(fs.readFileSync(gradleReportPath, "utf8"));
} catch (error) {
    fail(`invalid Gradle resolution report: ${error.message}`);
}

const androidAppBuild = read("nightingale/androidApp/app/build.gradle.kts");
const androidRootBuild = read("nightingale/androidApp/build.gradle.kts");
const gradleWrapper = read(
    "nightingale/androidApp/gradle/wrapper/gradle-wrapper.properties",
);
const iosProject = read("nightingale/iosApp/project.yml");
assertNoIosPackageManagerInputs(iosProject);

const resolvedComponentCount = androidResolution.resolved_components?.length;
const dependencyEdgeCount = androidResolution.dependency_edges?.length;
if (!Number.isInteger(resolvedComponentCount) || resolvedComponentCount < 1) {
    fail("Gradle resolution report contains no resolved components");
}
if (!Number.isInteger(dependencyEdgeCount) || dependencyEdgeCount < 1) {
    fail("Gradle resolution report contains no dependency edges");
}

const inventory = {
    schema: "net.acumenus.nightingale.foundation-dependency-inventory",
    schema_version: 1,
    product: {
        name: "Nightingale",
        audience: "inpatients and permitted representatives",
        apple_bundle_id: "net.acumenus.nightingale",
        android_application_id: "net.acumenus.nightingale",
        release_state:
            "offline foundation; not approved for distribution or live use",
    },
    scope: {
        inventory_kind: "governed foundation dependency inventory",
        android_configuration: "releaseRuntimeClasspath",
        ios_configuration: "Nightingale application target declarations",
        includes: [
            "Android direct Release runtime declarations",
            "Android resolved external Release runtime components",
            "Android resolved dependency edges",
            "iOS application-target third-party package declarations",
            "iOS application-target Apple system-module imports",
            "declared native build requirements",
        ],
        excludes: [
            "licenses and legal conclusions",
            "vulnerability status or exploitability",
            "artifact checksums and signing provenance",
            "package-registry provenance and source-repository identity",
            "resolved build-plugin and build-tool transitive dependencies",
            "build-host, compiler, SDK-image, and operating-system contents",
            "test-only and debug-only dependency resolution",
            "CycloneDX, SPDX, or other standards conformance",
        ],
        contains_patient_data: false,
        production_system_access_used: false,
    },
    generation: {
        command: generationCommand,
        gradle_task: ":app:writeNightingaleReleaseDependencyResolution",
        deterministic_timestamp_policy:
            "No generation timestamp is stored; source hashes define inventory identity.",
        source_sha256: Object.fromEntries(
            sourceFiles.map((sourceFile) => [sourceFile, sha256(sourceFile)]),
        ),
    },
    build_requirements: {
        android: {
            gradle_wrapper: requiredMatch(
                gradleWrapper,
                /gradle-([0-9.]+)-bin\.zip/,
                "Gradle wrapper version",
            ),
            android_gradle_plugin: requiredMatch(
                androidRootBuild,
                /id\("com\.android\.application"\)\s+version\s+"([^"]+)"/,
                "Android Gradle plugin version",
            ),
            kotlin_plugins: requiredMatch(
                androidRootBuild,
                /id\("org\.jetbrains\.kotlin\.android"\)\s+version\s+"([^"]+)"/,
                "Kotlin plugin version",
            ),
            java_source_and_target: requiredMatch(
                androidAppBuild,
                /sourceCompatibility\s*=\s*JavaVersion\.VERSION_([0-9_]+)/,
                "Android Java source level",
            ).replaceAll("_", "."),
            compile_sdk: Number(
                requiredMatch(
                    androidAppBuild,
                    /compileSdk\s*=\s*(\d+)/,
                    "Android compile SDK",
                ),
            ),
            min_sdk: Number(
                requiredMatch(
                    androidAppBuild,
                    /minSdk\s*=\s*(\d+)/,
                    "Android minimum SDK",
                ),
            ),
            target_sdk: Number(
                requiredMatch(
                    androidAppBuild,
                    /targetSdk\s*=\s*(\d+)/,
                    "Android target SDK",
                ),
            ),
        },
        ios: {
            xcodegen_spec: "nightingale/iosApp/project.yml",
            swift_language_version: requiredMatch(
                iosProject,
                /SWIFT_VERSION:\s*"([^"]+)"/,
                "Swift language version",
            ),
            minimum_ios: requiredMatch(
                iosProject,
                /deploymentTarget:\s*\n\s+iOS:\s*"([^"]+)"/,
                "minimum iOS version",
            ),
            third_party_package_manager: "none declared",
        },
    },
    android: {
        configuration: androidResolution.configuration,
        declared_dependencies: androidResolution.declared_dependencies,
        resolved_components: androidResolution.resolved_components,
        dependency_edges: androidResolution.dependency_edges,
        counts: {
            declared_dependencies:
                androidResolution.declared_dependencies.length,
            resolved_components: resolvedComponentCount,
            dependency_edges: dependencyEdgeCount,
        },
    },
    ios: {
        target: "Nightingale",
        third_party_runtime_packages: [],
        apple_system_modules: listSwiftApplicationModules(),
        package_manager_inputs_present: false,
        counts: {
            third_party_runtime_packages: 0,
            apple_system_modules: listSwiftApplicationModules().length,
        },
    },
    interpretation: [
        "This file is an exact generated inventory of the bounded foundation inputs described in scope.",
        "An empty iOS third-party package list means no third-party package manager is declared in the current XcodeGen source; it does not inventory Apple SDK or operating-system components.",
        "Android entries are resolution facts for the named Release runtime configuration, not approval, license, provenance, or vulnerability findings.",
        "Any source-hash or resolved-graph change requires regeneration, review, and a fresh exact-SHA CI result.",
        "This inventory does not authorize patient data, networking, identity, disclosure, messaging, production access, distribution, or clinical use.",
    ],
};

const serialized = `${JSON.stringify(inventory, null, 4)}\n`;

if (check) {
    if (!fs.existsSync(canonicalPath)) {
        fail(
            `missing canonical inventory: ${path.relative(repoRoot, canonicalPath)}`,
        );
    }
    const current = fs.readFileSync(canonicalPath, "utf8");
    if (current !== serialized) {
        fail(
            `canonical inventory is stale; run \`${generationCommand}\` and review the delta`,
        );
    }
    process.stdout.write(
        `Nightingale foundation dependency inventory is current (${resolvedComponentCount} Android Release runtime components; 0 iOS third-party packages).\n`,
    );
    process.exit(0);
}

fs.mkdirSync(path.dirname(canonicalPath), { recursive: true });
fs.writeFileSync(canonicalPath, serialized);
process.stdout.write(
    `Wrote ${path.relative(repoRoot, canonicalPath)} (${resolvedComponentCount} Android Release runtime components; 0 iOS third-party packages).\n`,
);
