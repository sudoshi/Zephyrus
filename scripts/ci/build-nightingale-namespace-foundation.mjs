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
        "Usage: build-nightingale-namespace-foundation.mjs [repository-root] [--write]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const outputPath = path.join(
    repoRoot,
    "docs/nightingale/namespace/foundation-namespace.v1.json",
);
const sha256 = (value) =>
    crypto.createHash("sha256").update(value).digest("hex");

const sourceDefinitions = [
    [
        "nightingale/iosApp/project.yml",
        "iOS application, unit-test, and UI-test bundle identities",
    ],
    [
        "nightingale/iosApp/Nightingale/Info.plist",
        "iOS patient-visible product identity",
    ],
    [
        "nightingale/iosApp/Nightingale/Localizable.xcstrings",
        "iOS product-owned patient copy catalog",
    ],
    [
        "nightingale/iosApp/Nightingale/NightingaleApp.swift",
        "iOS Debug hooks and accessibility identifiers",
    ],
    [
        "nightingale/iosApp/Nightingale/NightingaleVisualFoundation.swift",
        "iOS privacy-cover accessibility identifier",
    ],
    [
        "nightingale/iosApp/Nightingale/NightingalePresentationPreferences.swift",
        "iOS presentation storage keys and Debug reset hook",
    ],
    [
        "nightingale/iosApp/Nightingale/NightingaleProtectedState.swift",
        "iOS protected-state service and account keys",
    ],
    [
        "nightingale/androidApp/app/build.gradle.kts",
        "Android application and test package identities",
    ],
    [
        "nightingale/androidApp/app/src/main/res/values/strings.xml",
        "Android product-owned patient copy catalog",
    ],
    [
        "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleVisualFoundation.kt",
        "Android patient-shell semantics and test-tag identifiers",
    ],
    [
        "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingalePresentationPreferences.kt",
        "Android presentation storage file and keys",
    ],
    [
        "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleProtectedState.kt",
        "Android protected-state aliases, file, key, and authenticated context",
    ],
];

const sources = sourceDefinitions.map(([relativePath, responsibility]) => {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath) || !fs.statSync(absolutePath).isFile()) {
        throw new Error(`Missing namespace source: ${relativePath}`);
    }

    return {
        path: relativePath,
        sha256: sha256(fs.readFileSync(absolutePath)),
        responsibility,
    };
});

const manifest = {
    schema_version: 1,
    contract_id: "nightingale-foundation-namespace.v1",
    status: "offline_foundation_only",
    generated_at: "2026-07-27",
    product: {
        name: "Nightingale",
        reverse_dns_prefix: "net.acumenus.nightingale",
        ios_bundle_id: "net.acumenus.nightingale",
        android_application_id: "net.acumenus.nightingale",
    },
    namespace_rules: {
        accessibility_identifier_prefix: "nightingale-",
        test_hook_prefix: "NIGHTINGALE_",
        storage_identifier_prefix: "net.acumenus.nightingale.",
        telemetry_event_prefix: "net.acumenus.nightingale.telemetry.v1.",
        diagnostic_channel_prefix: "net.acumenus.nightingale.diagnostics.v1.",
    },
    inventories: {
        accessibility_identifiers: [
            "nightingale-safe-shell",
            "nightingale-product-heading",
            "nightingale-foundation-mission",
            "nightingale-privacy-status-heading",
            "nightingale-display-comfort-heading",
            "nightingale-reduce-motion-toggle",
            "nightingale-motion-status",
            "nightingale-hide-imagery-toggle",
            "nightingale-imagery-status",
            "nightingale-privacy-cover",
        ],
        test_hooks: [
            "NIGHTINGALE_SHOW_PRIVACY_COVER",
            "NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE",
            "NIGHTINGALE_TEST_LAYOUT_DIRECTION",
            "NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES",
        ],
        storage_identifiers: [
            {
                platform: "ios",
                kind: "keychain_service",
                value: "net.acumenus.nightingale.protected-state.v1",
            },
            {
                platform: "ios",
                kind: "keychain_account",
                value: "net.acumenus.nightingale.protected-state.v1.future-session-binding",
            },
            {
                platform: "ios",
                kind: "preference_key",
                value: "net.acumenus.nightingale.presentation.v1.reduce-motion",
            },
            {
                platform: "ios",
                kind: "preference_key",
                value: "net.acumenus.nightingale.presentation.v1.hide-decorative-imagery",
            },
            {
                platform: "android",
                kind: "preference_file",
                value: "net.acumenus.nightingale.presentation.v1",
            },
            {
                platform: "android",
                kind: "preference_key",
                value: "net.acumenus.nightingale.presentation.v1.reduce-motion",
            },
            {
                platform: "android",
                kind: "preference_key",
                value: "net.acumenus.nightingale.presentation.v1.hide-decorative-imagery",
            },
            {
                platform: "android",
                kind: "keystore_alias",
                value: "net.acumenus.nightingale.protected-state-key.v1",
            },
            {
                platform: "android",
                kind: "preference_file",
                value: "net.acumenus.nightingale.protected-state-ciphertext.v1",
            },
            {
                platform: "android",
                kind: "preference_key",
                value: "net.acumenus.nightingale.protected-state.v1.future-session-binding",
            },
        ],
        telemetry_event_names: [],
        diagnostic_channel_names: [],
    },
    constraints: {
        legacy_hummingbird_namespace_permitted: false,
        legacy_patient_product_copy_permitted: false,
        unnamespaced_storage_identifier_permitted: false,
        release_test_hooks_permitted: false,
        telemetry_implemented: false,
        diagnostics_implemented: false,
        runtime_logging_permitted: false,
    },
    sources,
};

const rendered = `${JSON.stringify(manifest, null, 4)}\n`;
if (write) {
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, rendered);
} else {
    process.stdout.write(rendered);
}
