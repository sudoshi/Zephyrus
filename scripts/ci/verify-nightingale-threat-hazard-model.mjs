#!/usr/bin/env node

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
        `Nightingale threat/hazard model violation: ${message}\n`,
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
const paths = {
    model: path.join(
        repoRoot,
        "docs/nightingale/FOUNDATION-THREAT-AND-HAZARD-MODEL-2026-07-26.md",
    ),
    config: path.join(repoRoot, "config/nightingale.php"),
    contract: path.join(
        repoRoot,
        "docs/nightingale/api-contract/nightingale-foundation.v0.json",
    ),
    androidManifest: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/AndroidManifest.xml",
    ),
    iosApp: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/NightingaleApp.swift",
    ),
    iosPrivacyManifest: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/PrivacyInfo.xcprivacy",
    ),
    androidApp: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/MainActivity.kt",
    ),
    androidNetworkSecurity: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/res/xml/network_security_config.xml",
    ),
};

for (const [name, requiredPath] of Object.entries(paths)) {
    if (!fs.existsSync(requiredPath)) {
        fail(`missing ${name}: ${requiredPath}`);
    }
}

function sequential(prefix, count, width = 2) {
    return Array.from(
        { length: count },
        (_, index) => `${prefix}-${String(index + 1).padStart(width, "0")}`,
    );
}

const expectedIds = {
    AST: sequential("AST", 18),
    TB: sequential("TB", 14),
    CTRL: sequential("CTRL", 26, 3),
    THR: [
        "THR-S-001",
        "THR-S-002",
        "THR-S-003",
        "THR-T-001",
        "THR-T-002",
        "THR-T-003",
        "THR-R-001",
        "THR-I-001",
        "THR-I-002",
        "THR-I-003",
        "THR-I-004",
        "THR-I-005",
        "THR-I-006",
        "THR-D-001",
        "THR-D-002",
        "THR-E-001",
        "THR-E-002",
        "THR-E-003",
        "THR-CFG-001",
        "THR-SC-001",
        "THR-OPS-001",
        "THR-INS-001",
    ],
    HZ: sequential("HZ", 22, 3),
    ABUSE: sequential("ABUSE", 18, 3),
    GATE: sequential("GATE", 20),
    VER: sequential("VER", 18, 3),
    INC: sequential("INC", 8),
    RISK: sequential("RISK", 17, 3),
};

function tableIds(raw, prefix) {
    const threeDigitPrefixes = new Set(["CTRL", "HZ", "ABUSE", "VER", "RISK"]);
    const pattern =
        prefix === "THR"
            ? /^\|\s*(THR-[A-Z]+-\d{3})\s*\|/gm
            : new RegExp(
                  `^\\|\\s*(${prefix}-\\d{${threeDigitPrefixes.has(prefix) ? 3 : 2}})\\s*\\|`,
                  "gm",
              );
    return [...raw.matchAll(pattern)].map((match) => match[1]);
}

function sameMembers(actual, expected) {
    return (
        actual.length === expected.length &&
        [...actual]
            .sort()
            .every((value, index) => value === [...expected].sort()[index])
    );
}

function cloneJson(value) {
    return JSON.parse(JSON.stringify(value));
}

function inspect(evidence) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };
    const {
        model,
        config,
        contract,
        androidManifest,
        iosApp,
        iosPrivacyManifest,
        androidApp,
        androidNetworkSecurity,
    } = evidence;
    const normalizedModel = model.replace(/\s+/g, " ");

    for (const heading of [
        "# Nightingale foundation threat and clinical-hazard model",
        "## 1. Executive disposition",
        "## 4. System and data-flow model",
        "## 8. Implemented foundation-control catalog",
        "## 9. Security and privacy threat register",
        "## 10. Clinical hazard log",
        "## 13. Mandatory activation gates",
        "## 14. Verification program",
        "## 15. Detection, incident response, and recovery requirements",
        "## 16. Open residual risks and current disposition",
        "## 20. Non-authorization statement",
    ]) {
        assert(model.includes(heading), `missing required section: ${heading}`);
    }

    for (const requiredStatement of [
        "**Draft engineering model; not an approved safety case or release authorization**",
        "| Named approvals recorded | **None** |",
        "No numerical “residual risk” is accepted by this document.",
        "has not been made safe for live use",
        "current disposition for every high/critical live-data hazard is **keep the capability disabled**",
        "does not authorize production database access",
        "does not assert regulatory or standards compliance",
        "does not accept any residual risk",
    ]) {
        assert(
            normalizedModel.includes(requiredStatement),
            `missing non-authorization/risk statement: ${requiredStatement}`,
        );
    }

    for (const authoritativeLink of [
        "https://www.nist.gov/publications/nist-cybersecurity-framework-csf-20",
        "https://mas.owasp.org/MASVS/",
        "https://www.hhs.gov/hipaa/for-professionals/security/guidance/final-guidance-risk-analysis/index.html",
        "https://www.hhs.gov/hipaa/for-professionals/privacy/guidance/cell-phone-hipaa/index.html",
        "https://www.england.nhs.uk/long-read/national-review-of-clinical-risk-management-standardsdcb0129-and-dcb0160-supporting-information/",
    ]) {
        assert(
            model.includes(authoritativeLink),
            `missing authoritative method input: ${authoritativeLink}`,
        );
    }

    for (const [prefix, expected] of Object.entries(expectedIds)) {
        const actual = tableIds(model, prefix);
        assert(
            sameMembers(actual, expected),
            `${prefix} table identifiers changed: expected ${expected.join(", ")}, found ${actual.join(", ")}`,
        );
        assert(
            new Set(actual).size === actual.length,
            `${prefix} table contains duplicate identifiers`,
        );
    }

    assert(
        !model.includes("[x]") && !model.includes("[X]"),
        "draft model must not record implied checkbox approvals",
    );
    for (const prohibitedClaim of [
        "Nightingale is HIPAA compliant",
        "Nightingale is MASVS compliant",
        "Nightingale is DCB0129 compliant",
        "Nightingale is approved for pilot",
        "residual risk accepted",
    ]) {
        assert(
            !model.includes(prohibitedClaim),
            `prohibited approval/compliance claim: ${prohibitedClaim}`,
        );
    }

    for (const requiredFalse of [
        "'routes_registered' => false",
        "'network_clients_permitted' => false",
        "'enabled' => false",
        "'production_query_permitted' => false",
        "'patient_disclosure_enabled' => false",
        "'patient_mutation_enabled' => false",
        "'production_enabled' => false",
    ]) {
        assert(
            config.includes(requiredFalse),
            `backend foundation no longer proves ${requiredFalse}`,
        );
    }

    for (const field of [
        "routes_registered",
        "route_registration_permitted",
        "network_clients_permitted",
        "identity_enabled",
        "inpatient_source_enabled",
        "production_source_query_permitted",
        "patient_disclosure_enabled",
        "patient_mutation_enabled",
        "production_enabled",
    ]) {
        assert(
            contract["x-nightingale-activation"]?.[field] === false,
            `contract activation changed: ${field}`,
        );
    }
    assert(
        Object.keys(contract.paths ?? {}).length === 0,
        "contract paths must remain empty",
    );
    assert(
        contract.servers?.length === 1 &&
            contract.servers[0]?.url === "https://nightingale-api.invalid",
        "contract server must remain non-routable",
    );

    assert(
        !androidManifest.includes("android.permission.INTERNET"),
        "Android foundation gained network permission",
    );
    assert(
        androidManifest.includes('android:usesCleartextTraffic="false"') &&
            androidManifest.includes(
                'android:networkSecurityConfig="@xml/network_security_config"',
            ) &&
            androidNetworkSecurity.includes(
                'cleartextTrafficPermitted="false"',
            ) &&
            androidNetworkSecurity.includes('<certificates src="system"') &&
            !androidNetworkSecurity.includes("<debug-overrides"),
        "Android transport defense-in-depth changed",
    );
    assert(
        iosPrivacyManifest.includes("<key>NSPrivacyTracking</key>") &&
            iosPrivacyManifest.includes("<false/>") &&
            iosPrivacyManifest.includes(
                "<key>NSPrivacyCollectedDataTypes</key>",
            ) &&
            iosPrivacyManifest.includes(
                "NSPrivacyAccessedAPICategoryUserDefaults",
            ) &&
            iosPrivacyManifest.includes("<string>CA92.1</string>") &&
            !iosPrivacyManifest.includes("NSPrivacyTrackingDomains"),
        "iOS privacy-manifest foundation changed",
    );
    assert(
        iosApp.includes("static let livePatientAccessEnabled = false") &&
            iosApp.includes("static let staffEndpointsPermitted = false"),
        "iOS product boundary is no longer default-off",
    );
    assert(
        androidApp.includes("const val livePatientAccessEnabled = false") &&
            androidApp.includes("const val staffEndpointsPermitted = false"),
        "Android product boundary is no longer default-off",
    );

    return violations;
}

function runNegativeSelfTests(evidence) {
    const cases = [
        {
            name: "critical messaging hazard removal",
            expected: "HZ table identifiers changed",
            mutate(candidate) {
                candidate.model = candidate.model.replace(
                    /^\|\s*HZ-008\s*\|.*\n/m,
                    "",
                );
            },
        },
        {
            name: "draft status removal",
            expected: "missing non-authorization/risk statement",
            mutate(candidate) {
                candidate.model = candidate.model.replace(
                    "**Draft engineering model; not an approved safety case or release authorization**",
                    "**Approved safety case**",
                );
            },
        },
        {
            name: "NIST method input removal",
            expected: "missing authoritative method input",
            mutate(candidate) {
                candidate.model = candidate.model.replace(
                    "https://www.nist.gov/publications/nist-cybersecurity-framework-csf-20",
                    "https://example.invalid/nist",
                );
            },
        },
        {
            name: "implied approval checkbox",
            expected: "draft model must not record implied checkbox approvals",
            mutate(candidate) {
                candidate.model += "\n- [x] Approved for production\n";
            },
        },
        {
            name: "backend production activation",
            expected:
                "backend foundation no longer proves 'production_enabled' => false",
            mutate(candidate) {
                candidate.config = candidate.config.replace(
                    "'production_enabled' => false",
                    "'production_enabled' => true",
                );
            },
        },
        {
            name: "contract operation insertion",
            expected: "contract paths must remain empty",
            mutate(candidate) {
                candidate.contract.paths["/today"] = { get: {} };
            },
        },
        {
            name: "Android network permission",
            expected: "Android foundation gained network permission",
            mutate(candidate) {
                candidate.androidManifest = candidate.androidManifest.replace(
                    "<application",
                    '<uses-permission android:name="android.permission.INTERNET" />\n\n    <application',
                );
            },
        },
        {
            name: "Android cleartext activation",
            expected: "Android transport defense-in-depth changed",
            mutate(candidate) {
                candidate.androidNetworkSecurity =
                    candidate.androidNetworkSecurity.replace(
                        'cleartextTrafficPermitted="false"',
                        'cleartextTrafficPermitted="true"',
                    );
            },
        },
        {
            name: "iOS tracking declaration activation",
            expected: "iOS privacy-manifest foundation changed",
            mutate(candidate) {
                candidate.iosPrivacyManifest =
                    candidate.iosPrivacyManifest.replace("<false/>", "<true/>");
            },
        },
        {
            name: "iOS live-access activation",
            expected: "iOS product boundary is no longer default-off",
            mutate(candidate) {
                candidate.iosApp = candidate.iosApp.replace(
                    "static let livePatientAccessEnabled = false",
                    "static let livePatientAccessEnabled = true",
                );
            },
        },
    ];

    for (const testCase of cases) {
        const candidate = {
            ...evidence,
            contract: cloneJson(evidence.contract),
        };
        testCase.mutate(candidate);
        const violations = inspect(candidate);
        if (
            !violations.some((violation) =>
                violation.includes(testCase.expected),
            )
        ) {
            fail(
                `negative self-test "${testCase.name}" did not produce expected rejection: ${testCase.expected}`,
            );
        }
    }
}

let contract;
try {
    contract = JSON.parse(fs.readFileSync(paths.contract, "utf8"));
} catch (error) {
    fail(`invalid Nightingale contract JSON: ${error.message}`);
}

const evidence = {
    model: fs.readFileSync(paths.model, "utf8"),
    config: fs.readFileSync(paths.config, "utf8"),
    contract,
    androidManifest: fs.readFileSync(paths.androidManifest, "utf8"),
    iosApp: fs.readFileSync(paths.iosApp, "utf8"),
    iosPrivacyManifest: fs.readFileSync(paths.iosPrivacyManifest, "utf8"),
    androidApp: fs.readFileSync(paths.androidApp, "utf8"),
    androidNetworkSecurity: fs.readFileSync(
        paths.androidNetworkSecurity,
        "utf8",
    ),
};

const violations = inspect(evidence);
if (violations.length > 0) {
    fail(violations.join("; "));
}

if (selfTest) {
    runNegativeSelfTests(evidence);
}

process.stdout.write(
    `Nightingale threat/hazard model verified (${expectedIds.THR.length} threats, ${expectedIds.HZ.length} hazards, ${expectedIds.GATE.length} gates)${selfTest ? " with negative self-tests" : ""}\n`,
);
