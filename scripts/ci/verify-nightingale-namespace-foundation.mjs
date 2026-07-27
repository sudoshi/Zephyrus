#!/usr/bin/env node

import crypto from "node:crypto";
import { spawnSync } from "node:child_process";
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
        "Usage: verify-nightingale-namespace-foundation.mjs [repository-root] [--self-test]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const manifestPath = "docs/nightingale/namespace/foundation-namespace.v1.json";
const fail = (message) => {
    process.stderr.write(`Nightingale namespace violation: ${message}\n`);
    process.exit(1);
};
const read = (relativePath) => {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath) || !fs.statSync(absolutePath).isFile()) {
        fail(`missing ${relativePath}`);
    }
    return fs.readFileSync(absolutePath, "utf8");
};
const sha256 = (value) =>
    crypto.createHash("sha256").update(value).digest("hex");
const clone = (value) => JSON.parse(JSON.stringify(value));
const sameValues = (actual, expected) =>
    Array.isArray(actual) &&
    actual.length === expected.length &&
    [...actual]
        .sort()
        .every((value, index) => value === [...expected].sort()[index]);
const sameKeys = (value, keys) =>
    value !== null &&
    typeof value === "object" &&
    !Array.isArray(value) &&
    sameValues(Object.keys(value), keys);

const expectedSourcePaths = [
    "nightingale/iosApp/project.yml",
    "nightingale/iosApp/Nightingale/Info.plist",
    "nightingale/iosApp/Nightingale/Localizable.xcstrings",
    "nightingale/iosApp/Nightingale/NightingaleApp.swift",
    "nightingale/iosApp/Nightingale/NightingaleVisualFoundation.swift",
    "nightingale/iosApp/Nightingale/NightingalePresentationPreferences.swift",
    "nightingale/iosApp/Nightingale/NightingaleProtectedState.swift",
    "nightingale/androidApp/app/build.gradle.kts",
    "nightingale/androidApp/app/src/main/res/values/strings.xml",
    "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleVisualFoundation.kt",
    "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingalePresentationPreferences.kt",
    "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleProtectedState.kt",
];
const expectedAccessibilityIdentifiers = [
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
];
const expectedTestHooks = [
    "NIGHTINGALE_SHOW_PRIVACY_COVER",
    "NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE",
    "NIGHTINGALE_TEST_LAYOUT_DIRECTION",
    "NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES",
];
const expectedStorageIdentifiers = [
    ["ios", "keychain_service", "net.acumenus.nightingale.protected-state.v1"],
    [
        "ios",
        "keychain_account",
        "net.acumenus.nightingale.protected-state.v1.future-session-binding",
    ],
    [
        "ios",
        "preference_key",
        "net.acumenus.nightingale.presentation.v1.reduce-motion",
    ],
    [
        "ios",
        "preference_key",
        "net.acumenus.nightingale.presentation.v1.hide-decorative-imagery",
    ],
    ["android", "preference_file", "net.acumenus.nightingale.presentation.v1"],
    [
        "android",
        "preference_key",
        "net.acumenus.nightingale.presentation.v1.reduce-motion",
    ],
    [
        "android",
        "preference_key",
        "net.acumenus.nightingale.presentation.v1.hide-decorative-imagery",
    ],
    [
        "android",
        "keystore_alias",
        "net.acumenus.nightingale.protected-state-key.v1",
    ],
    [
        "android",
        "preference_file",
        "net.acumenus.nightingale.protected-state-ciphertext.v1",
    ],
    [
        "android",
        "preference_key",
        "net.acumenus.nightingale.protected-state.v1.future-session-binding",
    ],
];
const expectedConstraintKeys = [
    "legacy_hummingbird_namespace_permitted",
    "legacy_patient_product_copy_permitted",
    "unnamespaced_storage_identifier_permitted",
    "release_test_hooks_permitted",
    "telemetry_implemented",
    "diagnostics_implemented",
    "runtime_logging_permitted",
];

const inspect = (document, { verifySources = true } = {}) => {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(
        sameKeys(document, [
            "schema_version",
            "contract_id",
            "status",
            "generated_at",
            "product",
            "namespace_rules",
            "inventories",
            "constraints",
            "sources",
        ]),
        "top-level field inventory changed",
    );
    assert(document.schema_version === 1, "schema_version must remain 1");
    assert(
        document.contract_id === "nightingale-foundation-namespace.v1",
        "contract_id changed",
    );
    assert(
        document.status === "offline_foundation_only",
        "status must remain offline_foundation_only",
    );
    assert(document.generated_at === "2026-07-27", "generated_at changed");
    assert(
        JSON.stringify(document.product) ===
            JSON.stringify({
                name: "Nightingale",
                reverse_dns_prefix: "net.acumenus.nightingale",
                ios_bundle_id: "net.acumenus.nightingale",
                android_application_id: "net.acumenus.nightingale",
            }),
        "product identity changed",
    );
    assert(
        JSON.stringify(document.namespace_rules) ===
            JSON.stringify({
                accessibility_identifier_prefix: "nightingale-",
                test_hook_prefix: "NIGHTINGALE_",
                storage_identifier_prefix: "net.acumenus.nightingale.",
                telemetry_event_prefix:
                    "net.acumenus.nightingale.telemetry.v1.",
                diagnostic_channel_prefix:
                    "net.acumenus.nightingale.diagnostics.v1.",
            }),
        "namespace rules changed",
    );

    const inventories = document.inventories ?? {};
    assert(
        sameKeys(inventories, [
            "accessibility_identifiers",
            "test_hooks",
            "storage_identifiers",
            "telemetry_event_names",
            "diagnostic_channel_names",
        ]),
        "inventory field set changed",
    );
    assert(
        sameValues(
            inventories.accessibility_identifiers,
            expectedAccessibilityIdentifiers,
        ),
        "accessibility identifier inventory changed",
    );
    assert(
        sameValues(inventories.test_hooks, expectedTestHooks),
        "test-hook inventory changed",
    );
    const actualStorage = (inventories.storage_identifiers ?? []).map(
        (entry) => [entry.platform, entry.kind, entry.value],
    );
    assert(
        JSON.stringify(actualStorage) ===
            JSON.stringify(expectedStorageIdentifiers),
        "storage identifier inventory changed",
    );
    for (const entry of inventories.storage_identifiers ?? []) {
        assert(
            sameKeys(entry, ["platform", "kind", "value"]),
            "storage identifier field inventory changed",
        );
        assert(
            typeof entry.value === "string" &&
                entry.value.startsWith("net.acumenus.nightingale."),
            "storage identifier is not Nightingale namespaced",
        );
    }
    assert(
        Array.isArray(inventories.telemetry_event_names) &&
            inventories.telemetry_event_names.length === 0,
        "telemetry event inventory must remain empty",
    );
    assert(
        Array.isArray(inventories.diagnostic_channel_names) &&
            inventories.diagnostic_channel_names.length === 0,
        "diagnostic channel inventory must remain empty",
    );

    assert(
        sameKeys(document.constraints, expectedConstraintKeys),
        "constraint field inventory changed",
    );
    for (const key of expectedConstraintKeys) {
        assert(
            document.constraints?.[key] === false,
            `${key} must remain false`,
        );
    }

    assert(Array.isArray(document.sources), "sources must be an array");
    const sourcePaths = (document.sources ?? []).map((source) => source.path);
    assert(
        JSON.stringify(sourcePaths) === JSON.stringify(expectedSourcePaths),
        "exact namespace source inventory changed",
    );
    for (const source of document.sources ?? []) {
        assert(
            sameKeys(source, ["path", "sha256", "responsibility"]),
            `source field inventory changed for ${source.path ?? "unknown"}`,
        );
        assert(
            /^[0-9a-f]{64}$/.test(source.sha256 ?? ""),
            `source digest is invalid for ${source.path ?? "unknown"}`,
        );
        assert(
            typeof source.responsibility === "string" &&
                source.responsibility.trim().length >= 30,
            `source responsibility is incomplete for ${source.path ?? "unknown"}`,
        );
        if (verifySources && typeof source.path === "string") {
            assert(
                sha256(read(source.path)) === source.sha256,
                `source digest changed: ${source.path}`,
            );
        }
    }

    return violations;
};

let document;
let manifestRaw;
try {
    manifestRaw = read(manifestPath);
    document = JSON.parse(manifestRaw);
} catch (error) {
    fail(`invalid namespace manifest JSON: ${error.message}`);
}
const violations = inspect(document);
if (violations.length > 0) fail(violations.join("; "));

const builder = spawnSync(
    process.execPath,
    [
        path.join(
            repoRoot,
            "scripts/ci/build-nightingale-namespace-foundation.mjs",
        ),
        repoRoot,
    ],
    { encoding: "utf8" },
);
if (builder.status !== 0) {
    fail(
        `deterministic builder failed: ${builder.stderr.trim() || `exit ${builder.status}`}`,
    );
}
if (builder.stdout !== manifestRaw) {
    fail("namespace manifest does not match deterministic builder output");
}

const iosApp = read("nightingale/iosApp/Nightingale/NightingaleApp.swift");
const iosVisual = read(
    "nightingale/iosApp/Nightingale/NightingaleVisualFoundation.swift",
);
const androidVisual = read(
    "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleVisualFoundation.kt",
);
const accessibilityIdentifiers = [
    ...[
        ...`${iosApp}\n${iosVisual}`.matchAll(
            /\.accessibilityIdentifier\("([^"]+)"\)/g,
        ),
    ].map((match) => match[1]),
    ...[...androidVisual.matchAll(/\.testTag\("([^"]+)"\)/g)].map(
        (match) => match[1],
    ),
];
if (
    !sameValues(
        [...new Set(accessibilityIdentifiers)],
        expectedAccessibilityIdentifiers,
    )
) {
    fail(
        "native accessibility/test-tag declarations do not match the manifest",
    );
}
for (const identifier of accessibilityIdentifiers) {
    if (!identifier.startsWith("nightingale-")) {
        fail(
            `accessibility identifier is not Nightingale namespaced: ${identifier}`,
        );
    }
}

const hookSources = [
    iosApp,
    read(
        "nightingale/iosApp/Nightingale/NightingalePresentationPreferences.swift",
    ),
].join("\n");
const discoveredHooks = [
    ...new Set(hookSources.match(/NIGHTINGALE_[A-Z0-9_]+/g) ?? []),
];
if (!sameValues(discoveredHooks, expectedTestHooks)) {
    fail("native test-hook declarations do not match the manifest");
}

const runtimeSource = expectedSourcePaths.map(read).join("\n");
for (const forbidden of [
    "net.acumenus.hummingbird",
    "hummingbird.patient",
    "Hummingbird Patient",
]) {
    if (runtimeSource.includes(forbidden)) {
        fail(
            `legacy patient-product namespace escaped into Nightingale: ${forbidden}`,
        );
    }
}
for (const forbiddenRuntimePrimitive of [
    "FirebaseAnalytics",
    "Crashlytics",
    "os_log",
    "NSLog",
    "android.util.Log",
]) {
    if (runtimeSource.includes(forbiddenRuntimePrimitive)) {
        fail(
            `unregistered telemetry, diagnostics, or logging primitive found: ${forbiddenRuntimePrimitive}`,
        );
    }
}
for (const storageIdentifier of expectedStorageIdentifiers.map(
    ([, , value]) => value,
)) {
    if (!runtimeSource.includes(storageIdentifier)) {
        fail(
            `declared storage identifier is absent from native sources: ${storageIdentifier}`,
        );
    }
}

if (selfTest) {
    const cases = [
        [
            (candidate) => {
                candidate.product.ios_bundle_id =
                    "net.acumenus.hummingbird.patient";
            },
            "product identity changed",
        ],
        [
            (candidate) => {
                candidate.inventories.accessibility_identifiers.pop();
            },
            "accessibility identifier inventory changed",
        ],
        [
            (candidate) => {
                candidate.inventories.test_hooks.push("PATIENT_TEST_MODE");
            },
            "test-hook inventory changed",
        ],
        [
            (candidate) => {
                candidate.inventories.storage_identifiers[0].value =
                    "future-session-binding";
            },
            "storage identifier inventory changed",
        ],
        [
            (candidate) => {
                candidate.inventories.telemetry_event_names.push(
                    "patient.opened",
                );
            },
            "telemetry event inventory must remain empty",
        ],
        [
            (candidate) => {
                candidate.constraints.diagnostics_implemented = true;
            },
            "diagnostics_implemented must remain false",
        ],
        [
            (candidate) => {
                candidate.sources[0].sha256 = "0";
            },
            "source digest is invalid",
        ],
        [
            (candidate) => {
                candidate.sources.pop();
            },
            "exact namespace source inventory changed",
        ],
    ];
    for (const [mutate, expected] of cases) {
        const candidate = clone(document);
        mutate(candidate);
        const caseViolations = inspect(candidate, { verifySources: false });
        if (!caseViolations.some((message) => message.includes(expected))) {
            fail(
                `negative self-test did not reject ${expected}: ${JSON.stringify(caseViolations)}`,
            );
        }
    }
}

process.stdout.write(
    "Nightingale namespace foundation verified: 10 accessibility IDs, " +
        "4 Debug hooks, 10 storage identifiers, zero telemetry events, " +
        "zero diagnostic channels, and no legacy patient namespace" +
        (selfTest ? "; negative self-tests passed" : "") +
        ".\n",
);
