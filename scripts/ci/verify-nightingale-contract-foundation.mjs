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
        `Nightingale contract foundation violation: ${message}\n`,
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
const contractPath = path.join(
    repoRoot,
    "docs/nightingale/api-contract/nightingale-foundation.v0.json",
);

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function inspect(document, raw) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    assert(document.openapi === "3.1.1", "OpenAPI version must be 3.1.1");
    assert(
        document.info?.title === "Nightingale API Governance Foundation",
        "unexpected contract title",
    );
    assert(
        document.info?.version === "0.0.0-governance",
        "foundation version must remain non-runnable",
    );

    const ownership = document["x-nightingale-contract"];
    assert(
        ownership?.product === "Nightingale",
        "product identity is not Nightingale",
    );
    assert(
        ownership?.governance_status === "foundation-no-operations",
        "governance status must remain foundation-no-operations",
    );
    assert(
        ownership?.compatibility_input_only === true,
        "legacy compatibility must remain input-only",
    );
    assert(
        ownership?.route_namespace_reserved === false,
        "a route namespace was reserved",
    );
    assert(
        ownership?.client_generation_permitted === false,
        "client generation was enabled",
    );

    const activation = document["x-nightingale-activation"];
    assert(
        activation?.default === "disabled",
        "activation default is not disabled",
    );
    for (const field of [
        "routes_registered",
        "network_clients_permitted",
        "identity_enabled",
        "patient_disclosure_enabled",
        "patient_mutation_enabled",
        "production_enabled",
    ]) {
        assert(activation?.[field] === false, `${field} must be false`);
    }
    assert(
        Array.isArray(activation?.required_before_first_operation) &&
            activation.required_before_first_operation.length >= 6,
        "pre-operation gates are incomplete",
    );

    assert(
        Array.isArray(document.servers) &&
            document.servers.length === 1 &&
            document.servers[0]?.url === "https://nightingale-api.invalid",
        "the only server must be the reserved non-routable .invalid host",
    );
    assert(
        Array.isArray(document.security) && document.security.length === 0,
        "security must be empty",
    );
    assert(
        Array.isArray(document.tags) && document.tags.length === 0,
        "tags must be empty",
    );
    assert(
        isRecord(document.paths) && Object.keys(document.paths).length === 0,
        "paths must contain zero operations",
    );
    assert(
        isRecord(document.webhooks) &&
            Object.keys(document.webhooks).length === 0,
        "webhooks must be empty",
    );
    assert(
        isRecord(document.components) &&
            Object.keys(document.components).length === 0,
        "components and security schemes must be empty",
    );

    for (const forbidden of [
        "Hummingbird",
        "/api/patient",
        "/api/mobile",
        "patientBearer",
        "zephyrus.acumenus.net",
        "pgsql.acumenus.net",
    ]) {
        assert(
            !raw.includes(forbidden),
            `forbidden legacy, staff, or production token: ${forbidden}`,
        );
    }

    return violations;
}

function cloned(document) {
    return JSON.parse(JSON.stringify(document));
}

function runNegativeSelfTests(document) {
    const cases = [
        {
            name: "operation insertion",
            expected: "paths must contain zero operations",
            mutate(candidate) {
                candidate.paths["/v1/me"] = { get: { responses: {} } };
            },
        },
        {
            name: "network activation",
            expected: "network_clients_permitted must be false",
            mutate(candidate) {
                candidate[
                    "x-nightingale-activation"
                ].network_clients_permitted = true;
            },
        },
        {
            name: "usable server",
            expected:
                "the only server must be the reserved non-routable .invalid host",
            mutate(candidate) {
                candidate.servers[0].url = "https://api.example.com";
            },
        },
        {
            name: "security-scheme insertion",
            expected: "components and security schemes must be empty",
            mutate(candidate) {
                candidate.components.securitySchemes = {
                    bearer: { type: "http", scheme: "bearer" },
                };
            },
        },
        {
            name: "legacy product contamination",
            expected:
                "forbidden legacy, staff, or production token: Hummingbird",
            mutate(candidate) {
                candidate.info.description = "Hummingbird compatibility alias";
            },
        },
    ];

    for (const testCase of cases) {
        const candidate = cloned(document);
        testCase.mutate(candidate);
        const raw = JSON.stringify(candidate);
        const violations = inspect(candidate, raw);
        if (!violations.includes(testCase.expected)) {
            fail(
                `negative self-test "${testCase.name}" did not produce expected rejection: ${testCase.expected}`,
            );
        }
    }
}

if (!fs.existsSync(contractPath)) {
    fail(`missing ${contractPath}`);
}

const raw = fs.readFileSync(contractPath, "utf8");
let document;
try {
    document = JSON.parse(raw);
} catch (error) {
    fail(`invalid JSON: ${error.message}`);
}

const violations = inspect(document, raw);
if (violations.length > 0) {
    fail(violations.join("; "));
}

if (selfTest) {
    runNegativeSelfTests(document);
}

process.stdout.write(
    `Nightingale empty/default-off contract foundation verified${selfTest ? " with negative self-tests" : ""}\n`,
);
