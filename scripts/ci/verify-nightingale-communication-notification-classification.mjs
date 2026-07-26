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
        `Nightingale communication/notification classification violation: ${message}\n`,
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
    "docs/nightingale/migration/candidates/v0/communication-notification-source-classification.json";
const expectedInventoryDigest =
    "ca199936c1d6dc58fb9ba21accbd7376a1d72512f6b1adc42d8f9719672306a0";
const expectedClassificationId =
    "nightingale-communication-notification-source-classification.v1";
const expectedStatus = "evidence_only_not_approved_for_implementation";
const expectedSourceCount = 130;
const expectedDecisionCount = 17;

const expectedScope = [
    "patient_communication_contract",
    "patient_mutation_delivery",
    "staff_handoff_routing",
    "notification_registration_delivery",
    "native_patient_experience",
    "error_offline_urgency",
];
const expectedCategoryCounts = {
    patient_communication_contract: 77,
    patient_mutation_delivery: 103,
    staff_handoff_routing: 89,
    notification_registration_delivery: 69,
    native_patient_experience: 48,
    error_offline_urgency: 130,
};
const expectedSurfaceCounts = {
    legacy_contract: 3,
    legacy_backend: 65,
    legacy_database: 5,
    legacy_test: 9,
    legacy_ios: 6,
    legacy_ios_test: 6,
    legacy_android: 8,
    legacy_android_test: 5,
    legacy_staff_ios: 8,
    legacy_staff_android: 7,
    legacy_staff_web: 8,
};
const expectedDispositionCounts = {
    reimplement_principle_only: 27,
    evidence_only: 55,
    held: 42,
    reject: 6,
};
const expectedConstraintKeys = [
    "implementation_permitted",
    "runtime_adoption_permitted",
    "route_registration_permitted",
    "legacy_route_alias_permitted",
    "legacy_topic_copy_permitted",
    "legacy_urgent_copy_permitted",
    "notification_provider_enabled",
    "notification_device_registration_enabled",
    "patient_push_delivery_enabled",
    "patient_email_delivery_enabled",
    "patient_sms_delivery_enabled",
    "notification_payload_permitted",
    "patient_foreground_polling_enabled",
    "offline_mutation_queue_enabled",
    "retry_identity_regeneration_permitted",
    "server_acceptance_counts_as_care_team_delivery",
    "production_data_used",
    "production_query_permitted",
    "production_replay_permitted",
    "patient_or_principal_created",
];
const expectedFindingValues = {
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
};
const expectedDispositions = Object.keys(expectedDispositionCounts);
const forbiddenLiteralPatterns = [
    /(?:postgres(?:ql)?|https?):\/\/[^/\s]+:[^@\s]+@/i,
    /\b(?:password|secret|access_token|refresh_token|bearer_token)\b\s*[:=]\s*["'][^"']+["']/i,
    /\b(?:\d{1,3}\.){3}\d{1,3}\b/,
];

function read(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath)) fail(`missing ${relativePath}`);
    return fs.readFileSync(absolutePath, "utf8");
}

function readManifest() {
    try {
        const raw = read(manifestPath);
        return { document: JSON.parse(raw), raw };
    } catch (error) {
        fail(`invalid JSON in ${manifestPath}: ${error.message}`);
    }
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
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

function sha256(value) {
    return crypto.createHash("sha256").update(value).digest("hex");
}

function sourceDigest(relativePath) {
    return sha256(fs.readFileSync(path.join(repoRoot, relativePath)));
}

function inventoryDigest(sources) {
    const paths = sources.map((source) => source.path).sort();
    return sha256(`${paths.join("\n")}\n`);
}

function countBy(values) {
    return values.reduce((counts, value) => {
        counts[value] = (counts[value] ?? 0) + 1;
        return counts;
    }, {});
}

function inspect(document, { verifyFiles = true } = {}) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(
        document.schema_version === 1,
        "schema_version must remain exactly 1",
    );
    assert(
        document.classification_id === expectedClassificationId,
        "classification_id changed",
    );
    assert(
        document.status === expectedStatus,
        "status must remain evidence-only and unapproved",
    );
    assert(
        document.reviewed_source_commit ===
            "be8405a0f768bf239862b790b3eeae80b8aad2ad",
        "reviewed_source_commit changed without a new classification version",
    );
    assert(
        document.reviewed_at === "2026-07-26",
        "review date changed without a new classification version",
    );
    assert(
        sameMembers(document.scope, expectedScope),
        "classification scope changed",
    );

    const constraints = document.global_constraints;
    assert(isRecord(constraints), "global_constraints must be an object");
    if (isRecord(constraints)) {
        assert(
            sameMembers(Object.keys(constraints), expectedConstraintKeys),
            "global constraint inventory changed",
        );
        for (const key of expectedConstraintKeys) {
            assert(constraints[key] === false, `${key} must remain false`);
        }
    }

    const findings = document.required_findings;
    assert(isRecord(findings), "required_findings must be an object");
    if (isRecord(findings)) {
        assert(
            sameMembers(
                Object.keys(findings),
                Object.keys(expectedFindingValues),
            ),
            "required finding inventory changed",
        );
        for (const [key, expected] of Object.entries(expectedFindingValues)) {
            assert(
                findings[key] === expected,
                `${key} must remain ${expected}`,
            );
        }
    }

    assert(isRecord(document.dispositions), "dispositions must be an object");
    if (isRecord(document.dispositions)) {
        assert(
            sameMembers(
                Object.keys(document.dispositions),
                expectedDispositions,
            ),
            "disposition definitions changed",
        );
        for (const key of expectedDispositions) {
            assert(
                typeof document.dispositions[key] === "string" &&
                    document.dispositions[key].trim().length >= 80,
                `disposition ${key} must retain a substantive definition`,
            );
        }
    }

    const decisions = document.decisions;
    assert(isRecord(decisions), "decisions must be an object");
    if (isRecord(decisions)) {
        assert(
            Object.keys(decisions).length === expectedDecisionCount,
            `expected ${expectedDecisionCount} decision records`,
        );
        for (const [decisionId, decision] of Object.entries(decisions)) {
            assert(
                isRecord(decision),
                `decision ${decisionId} must be an object`,
            );
            if (!isRecord(decision)) continue;
            assert(
                sameMembers(Object.keys(decision), [
                    "disposition",
                    "rationale",
                ]),
                `decision ${decisionId} field inventory changed`,
            );
            assert(
                expectedDispositions.includes(decision.disposition),
                `decision ${decisionId} has an unknown disposition`,
            );
            assert(
                typeof decision.rationale === "string" &&
                    decision.rationale.trim().length >= 100,
                `decision ${decisionId} requires a substantive rationale`,
            );
        }
    }

    const sources = document.sources;
    assert(Array.isArray(sources), "sources must be an array");
    if (!Array.isArray(sources)) return violations;
    assert(
        sources.length === expectedSourceCount,
        `expected ${expectedSourceCount} classified sources`,
    );
    assert(
        inventoryDigest(sources) === expectedInventoryDigest,
        "exact source inventory changed",
    );

    const seenPaths = new Set();
    for (const [index, source] of sources.entries()) {
        const label = `sources[${index}]`;
        assert(isRecord(source), `${label} must be an object`);
        if (!isRecord(source)) continue;
        assert(
            sameMembers(Object.keys(source), [
                "path",
                "sha256",
                "surface",
                "categories",
                "decision_id",
            ]),
            `${label} field inventory changed`,
        );
        assert(
            typeof source.path === "string" &&
                source.path.length > 0 &&
                !path.isAbsolute(source.path) &&
                !source.path.split("/").includes(".."),
            `${label} path must be a safe repository-relative path`,
        );
        if (typeof source.path !== "string") continue;

        assert(!seenPaths.has(source.path), `duplicate source ${source.path}`);
        seenPaths.add(source.path);
        assert(
            /^[0-9a-f]{64}$/.test(source.sha256 ?? ""),
            `${source.path} must have a SHA-256 digest`,
        );
        assert(
            Object.hasOwn(expectedSurfaceCounts, source.surface),
            `${source.path} has an unknown surface`,
        );
        assert(
            Array.isArray(source.categories) &&
                source.categories.length > 0 &&
                new Set(source.categories).size === source.categories.length &&
                source.categories.every((category) =>
                    expectedScope.includes(category),
                ),
            `${source.path} has invalid categories`,
        );
        assert(
            typeof source.decision_id === "string" &&
                isRecord(decisions?.[source.decision_id]),
            `${source.path} references an unknown decision`,
        );

        if (verifyFiles) {
            const absolutePath = path.join(repoRoot, source.path);
            assert(
                fs.existsSync(absolutePath) &&
                    fs.statSync(absolutePath).isFile(),
                `classified source is missing: ${source.path}`,
            );
            if (fs.existsSync(absolutePath)) {
                assert(
                    sourceDigest(source.path) === source.sha256,
                    `source digest changed: ${source.path}`,
                );
            }
        }
    }

    const actualCategoryCounts = countBy(
        sources.flatMap((source) => source.categories ?? []),
    );
    for (const [category, expected] of Object.entries(expectedCategoryCounts)) {
        assert(
            actualCategoryCounts[category] === expected,
            `${category} source count changed`,
        );
    }

    const actualSurfaceCounts = countBy(
        sources.map((source) => source.surface),
    );
    for (const [surface, expected] of Object.entries(expectedSurfaceCounts)) {
        assert(
            actualSurfaceCounts[surface] === expected,
            `${surface} source count changed`,
        );
    }

    const actualDispositionCounts = countBy(
        sources.map(
            (source) => decisions?.[source.decision_id]?.disposition ?? "none",
        ),
    );
    for (const [disposition, expected] of Object.entries(
        expectedDispositionCounts,
    )) {
        assert(
            actualDispositionCounts[disposition] === expected,
            `${disposition} source count changed`,
        );
    }

    for (const decisionId of Object.keys(decisions ?? {})) {
        assert(
            sources.some((source) => source.decision_id === decisionId),
            `decision ${decisionId} is unused`,
        );
    }

    return violations;
}

function assertFoundationStillDormant() {
    const config = read("config/nightingale.php");
    for (const assignment of [
        "'routes_registered' => false",
        "'network_clients_permitted' => false",
        "'production_query_permitted' => false",
        "'patient_disclosure_enabled' => false",
        "'patient_mutation_enabled' => false",
        "'production_enabled' => false",
    ]) {
        if (!config.includes(assignment)) {
            fail(`Nightingale foundation no longer pins ${assignment}`);
        }
    }

    const foundation = JSON.parse(
        read("docs/nightingale/api-contract/nightingale-foundation.v0.json"),
    );
    const activation = foundation["x-nightingale-activation"];
    for (const key of [
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
        if (activation?.[key] !== false) {
            fail(`Nightingale contract foundation no longer pins ${key}=false`);
        }
    }
    if (Object.keys(foundation.paths ?? {}).length !== 0) {
        fail("Nightingale contract foundation must retain zero operations");
    }
}

function assertNoCredentialLiterals(raw) {
    for (const pattern of forbiddenLiteralPatterns) {
        if (pattern.test(raw)) {
            fail(
                `classification manifest contains forbidden literal ${pattern}`,
            );
        }
    }
}

function requireMutationFailure(document, mutate, expectedFragment) {
    const candidate = clone(document);
    mutate(candidate);
    const violations = inspect(candidate, { verifyFiles: false });
    if (!violations.some((violation) => violation.includes(expectedFragment))) {
        fail(
            `self-test mutation was not rejected for ${expectedFragment}; violations=${JSON.stringify(violations)}`,
        );
    }
}

const { document, raw } = readManifest();
assertNoCredentialLiterals(raw);
const violations = inspect(document);
if (violations.length > 0) fail(violations.join("; "));
assertFoundationStillDormant();

if (selfTest) {
    const mutations = [
        [
            (candidate) => {
                candidate.global_constraints.notification_provider_enabled = true;
            },
            "notification_provider_enabled must remain false",
        ],
        [
            (candidate) => {
                candidate.global_constraints.patient_foreground_polling_enabled = true;
            },
            "patient_foreground_polling_enabled must remain false",
        ],
        [
            (candidate) => {
                candidate.global_constraints.offline_mutation_queue_enabled = true;
            },
            "offline_mutation_queue_enabled must remain false",
        ],
        [
            (candidate) => {
                candidate.global_constraints.retry_identity_regeneration_permitted = true;
            },
            "retry_identity_regeneration_permitted must remain false",
        ],
        [
            (candidate) => {
                candidate.global_constraints.server_acceptance_counts_as_care_team_delivery = true;
            },
            "server_acceptance_counts_as_care_team_delivery must remain false",
        ],
        [
            (candidate) => {
                candidate.global_constraints.production_replay_permitted = true;
            },
            "production_replay_permitted must remain false",
        ],
        [
            (candidate) => {
                candidate.required_findings.ios_accepts_backend_escalated_delivery_state = true;
            },
            "ios_accepts_backend_escalated_delivery_state must remain false",
        ],
        [
            (candidate) => {
                candidate.required_findings.staff_close_reason_breaks_patient_thread_decode = true;
            },
            "staff_close_reason_breaks_patient_thread_decode must remain false",
        ],
        [
            (candidate) => {
                candidate.sources[0].sha256 = "0";
            },
            "must have a SHA-256 digest",
        ],
        [
            (candidate) => {
                candidate.sources.pop();
            },
            "expected 130 classified sources",
        ],
        [
            (candidate) => {
                candidate.sources[1].path = candidate.sources[0].path;
            },
            "exact source inventory changed",
        ],
        [
            (candidate) => {
                candidate.decisions[
                    candidate.sources[0].decision_id
                ].disposition = "approved";
            },
            "unknown disposition",
        ],
    ];
    for (const [mutate, expectedFragment] of mutations) {
        requireMutationFailure(document, mutate, expectedFragment);
    }
}

process.stdout.write(
    `Nightingale communication/notification classification verified: ${expectedSourceCount} exact sources, six required domains, ten pinned findings, zero runtime adoption, zero production use.\n`,
);
