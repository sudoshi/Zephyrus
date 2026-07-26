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
        `Nightingale source-classification violation: ${message}\n`,
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
    "docs/nightingale/migration/candidates/v0/source-classification.json";
const expectedInventoryDigest =
    "7bfaf8c3ba44909e8c6aba82d66663f81ad8f48e8b8acc393316119c40d7ae4b";
const expectedClassificationId = "nightingale-source-classification.v1";
const expectedStatus = "evidence_only_not_approved_for_implementation";
const expectedSourceCount = 65;

const expectedScope = [
    "identity_input",
    "enrollment_recovery",
    "first_read_projection",
    "error_non_disclosure",
];
const expectedCategoryCounts = {
    identity_input: 44,
    enrollment_recovery: 37,
    first_read_projection: 47,
    error_non_disclosure: 65,
};
const expectedConstraintKeys = [
    "implementation_permitted",
    "runtime_adoption_permitted",
    "route_registration_permitted",
    "legacy_route_alias_permitted",
    "legacy_identity_provider_reuse_permitted",
    "legacy_credential_migration_permitted",
    "legacy_device_identity_reuse_permitted",
    "first_record_selection_permitted",
    "server_message_passthrough_permitted",
    "projection_absence_conflation_permitted",
    "production_data_used",
    "production_query_permitted",
    "production_replay_permitted",
    "patient_or_principal_created",
];
const expectedDispositions = [
    "reimplement_principle_only",
    "evidence_only",
    "held",
    "reject",
];
const expectedSurfaces = [
    "legacy_contract",
    "legacy_backend",
    "legacy_database",
    "legacy_test",
    "legacy_ios",
    "legacy_ios_test",
    "legacy_android",
    "legacy_android_test",
];

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
        /^[0-9a-f]{40}$/.test(document.reviewed_source_commit ?? ""),
        "reviewed_source_commit must be an exact Git SHA",
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

        const keys = Object.keys(source);
        assert(
            sameMembers(keys, [
                "path",
                "sha256",
                "surface",
                "categories",
                "disposition",
                "decision",
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
            expectedSurfaces.includes(source.surface),
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
            expectedDispositions.includes(source.disposition),
            `${source.path} has an unknown disposition`,
        );
        assert(
            typeof source.decision === "string" &&
                source.decision.trim().length >= 80,
            `${source.path} requires a substantive source-specific decision`,
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

    for (const category of expectedScope) {
        const count = sources.filter(
            (source) =>
                Array.isArray(source.categories) &&
                source.categories.includes(category),
        ).length;
        assert(
            count === expectedCategoryCounts[category],
            `${category} source count changed`,
        );
    }

    for (const surface of expectedSurfaces) {
        assert(
            sources.some((source) => source.surface === surface),
            `surface ${surface} has no classified source`,
        );
    }
    for (const disposition of expectedDispositions) {
        assert(
            sources.some((source) => source.disposition === disposition),
            `disposition ${disposition} has no classified source`,
        );
    }

    return violations;
}

function assertFoundationStillDormant() {
    const config = read("config/nightingale.php");
    const requiredFalseAssignments = [
        "'routes_registered' => false",
        "'network_clients_permitted' => false",
        "'legacy_patient_realm_reuse_permitted' => false",
        "'production_query_permitted' => false",
        "'patient_disclosure_enabled' => false",
        "'patient_mutation_enabled' => false",
        "'production_enabled' => false",
    ];
    for (const assignment of requiredFalseAssignments) {
        if (!config.includes(assignment)) {
            fail(`Nightingale foundation no longer pins ${assignment}`);
        }
    }
    if (!config.includes("'provider' => null")) {
        fail("Nightingale identity provider must remain null");
    }

    const foundation = JSON.parse(
        read("docs/nightingale/api-contract/nightingale-foundation.v0.json"),
    );
    const activation = foundation["x-nightingale-activation"];
    const forbiddenFoundationValues = [
        activation?.routes_registered,
        activation?.route_registration_permitted,
        activation?.network_clients_permitted,
        activation?.identity_enabled,
        activation?.inpatient_source_enabled,
        activation?.production_source_query_permitted,
        activation?.patient_disclosure_enabled,
        activation?.patient_mutation_enabled,
        activation?.production_enabled,
    ];
    if (forbiddenFoundationValues.some((value) => value !== false)) {
        fail("Nightingale contract foundation is no longer fully dormant");
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
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.implementation_permitted = true;
        },
        "implementation_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.runtime_adoption_permitted = true;
        },
        "runtime_adoption_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.first_record_selection_permitted = true;
        },
        "first_record_selection_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.server_message_passthrough_permitted = true;
        },
        "server_message_passthrough_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.projection_absence_conflation_permitted = true;
        },
        "projection_absence_conflation_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.global_constraints.production_replay_permitted = true;
        },
        "production_replay_permitted must remain false",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.sources[0].sha256 = "0";
        },
        "must have a SHA-256 digest",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.sources.pop();
        },
        "expected 65 classified sources",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.sources[1].path = candidate.sources[0].path;
        },
        "exact source inventory changed",
    );
    requireMutationFailure(
        document,
        (candidate) => {
            candidate.sources[0].disposition = "approved";
        },
        "unknown disposition",
    );
}

process.stdout.write(
    `Nightingale source classification verified: ${expectedSourceCount} exact evidence sources, four required domains, zero runtime adoption, zero production use.\n`,
);
