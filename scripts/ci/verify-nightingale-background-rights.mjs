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

if (unknownOptions.length > 0 || positional.length > 1) {
    process.stderr.write(
        "Usage: verify-nightingale-background-rights.mjs [repository-root] [--self-test]\n",
    );
    process.exit(64);
}

const repoRoot = path.resolve(positional[0] ?? ".");
const catalogPath = "nightingale/backgrounds/backgrounds.v1.json";
const reviewPath = "nightingale/backgrounds/rights/rights-review.v0.json";
const expectedSchema = "net.acumenus.nightingale.background-rights-review.v0";
const expectedReviewId = "nightingale-background-rights-review-2026-07-27-v0";
const expectedAssetCount = 7;
const expectedProviderAssetIds = new Map([
    ["nightingale-background-05", "VCq1vozVMbw"],
    ["nightingale-background-06", "g3mOCylYkmk"],
]);

class RightsVerificationError extends Error {}

function fail(message) {
    throw new RightsVerificationError(
        `Nightingale background rights violation: ${message}`,
    );
}

function read(relativePath) {
    const absolutePath = path.join(repoRoot, relativePath);
    if (!fs.existsSync(absolutePath) || !fs.statSync(absolutePath).isFile()) {
        fail(`missing ${relativePath}`);
    }
    return fs.readFileSync(absolutePath);
}

function parse(bytes, relativePath) {
    try {
        return JSON.parse(bytes.toString("utf8"));
    } catch (error) {
        fail(`invalid JSON in ${relativePath}: ${error.message}`);
    }
}

function sha256(bytes) {
    return crypto.createHash("sha256").update(bytes).digest("hex");
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function sameArray(actual, expected) {
    return (
        Array.isArray(actual) &&
        actual.length === expected.length &&
        actual.every((value, index) => value === expected[index])
    );
}

function sameKeys(value, expected) {
    return (
        value !== null &&
        typeof value === "object" &&
        !Array.isArray(value) &&
        sameArray(Object.keys(value).sort(), [...expected].sort())
    );
}

function requireKeys(value, expected, label) {
    if (!sameKeys(value, expected)) {
        fail(`${label} fields changed`);
    }
}

function requireNullArchive(asset) {
    requireKeys(
        asset.archive,
        [
            "original_binary_location",
            "original_binary_sha256_verified",
            "source_record_location",
            "license_terms_snapshot_location",
            "license_terms_snapshot_sha256",
        ],
        `${asset.id} archive`,
    );
    if (
        asset.archive.original_binary_location !== null ||
        asset.archive.original_binary_sha256_verified !== false ||
        asset.archive.source_record_location !== null ||
        asset.archive.license_terms_snapshot_location !== null ||
        asset.archive.license_terms_snapshot_sha256 !== null
    ) {
        fail(
            `${asset.id} v0 record must remain unarchived until a reviewed successor record is issued`,
        );
    }
}

function verifyUnresolvedAsset(asset) {
    if (
        asset.provider_source_status !== "unresolved_exact_source" ||
        asset.provider !== null ||
        asset.provider_asset_id !== null ||
        asset.creator_or_rightsholder !== null ||
        asset.canonical_source_url !== null ||
        !sameArray(asset.source_identity_evidence, []) ||
        asset.license_evidence_status !== "not_identified" ||
        asset.license_name !== null ||
        asset.license_url !== null ||
        asset.attribution_requirement !== "unknown"
    ) {
        fail(
            `${asset.id} unresolved source must not imply provider or rights evidence`,
        );
    }

    const expectedBlockers = [
        "exact provider source unresolved",
        "applicable license or purchase record absent",
        "durable original archive absent",
        "release owner approval absent",
    ];
    if (!sameArray(asset.blockers, expectedBlockers)) {
        fail(`${asset.id} unresolved blocker inventory changed`);
    }
}

function verifyIdentifiedAsset(asset) {
    const providerAssetId = expectedProviderAssetIds.get(asset.id);
    if (
        asset.provider !== "Unsplash" ||
        asset.provider_asset_id !== providerAssetId ||
        asset.license_evidence_status !==
            "current_provider_terms_observed_not_archived" ||
        asset.license_name !== "Unsplash License" ||
        asset.license_url !== "https://unsplash.com/license" ||
        asset.attribution_requirement !==
            "provider_currently_states_not_required_but_appreciated" ||
        !Array.isArray(asset.source_identity_evidence) ||
        asset.source_identity_evidence.length !== 1
    ) {
        fail(`${asset.id} provider observation changed`);
    }

    const evidence = asset.source_identity_evidence[0];
    if (
        evidence.observed_on !== "2026-07-27" ||
        !/^[a-f0-9]{64}$/.test(
            evidence.current_provider_download_sha256 ?? "",
        ) ||
        !Number.isInteger(
            evidence.current_provider_download_dimensions?.width,
        ) ||
        !Number.isInteger(
            evidence.current_provider_download_dimensions?.height,
        ) ||
        evidence.current_provider_download_dimensions.width <= 0 ||
        evidence.current_provider_download_dimensions.height <= 0 ||
        typeof evidence.method_limit !== "string" ||
        !evidence.method_limit.includes("does not")
    ) {
        fail(`${asset.id} source-identity observation is incomplete`);
    }

    if (asset.id === "nightingale-background-05") {
        if (
            asset.provider_source_status !==
                "provider_asset_identity_corroborated_resized_input" ||
            asset.creator_or_rightsholder !== "Miguel Alcântara" ||
            asset.canonical_source_url !==
                "https://unsplash.com/photos/a-small-bird-perched-on-a-tree-branch-VCq1vozVMbw" ||
            evidence.page_title !==
                "A small bird perched on a tree branch photo – Free Bird Image on Unsplash" ||
            evidence.page_reported_dimensions?.width !== 2608 ||
            evidence.page_reported_dimensions?.height !== 3912 ||
            evidence.page_published_at !== "2024-04-09T07:48:49.000Z" ||
            evidence.current_provider_download_sha256 !==
                "43a46fa15e8317105c3478193a48f5f9ab5e3d81d2b1f9c4dd24a8a287ed78ab" ||
            evidence.current_provider_download_dimensions.width !== 2608 ||
            evidence.current_provider_download_dimensions.height !== 3912 ||
            evidence.catalog_source_relation !==
                "same_provider_asset_resized_and_reencoded" ||
            evidence.normalized_rmse !== 0.0112669 ||
            evidence.perceptual_hash_distance !== 0.0247332
        ) {
            fail(`${asset.id} corroboration record changed`);
        }
    } else if (asset.id === "nightingale-background-06") {
        if (
            asset.provider_source_status !==
                "current_provider_download_binary_matched" ||
            asset.creator_or_rightsholder !== "Muhammad Shakir" ||
            asset.canonical_source_url !==
                "https://unsplash.com/photos/a-small-bird-sitting-on-top-of-a-brick-wall-g3mOCylYkmk" ||
            evidence.page_title !==
                "A small bird sitting on top of a brick wall photo – Free Forest Image on Unsplash" ||
            evidence.page_reported_dimensions?.width !== 3456 ||
            evidence.page_reported_dimensions?.height !== 5184 ||
            evidence.page_published_at !== "2023-12-30T08:54:25.000Z" ||
            evidence.current_provider_download_sha256 !== asset.source_sha256 ||
            evidence.current_provider_download_dimensions.width !== 3456 ||
            evidence.current_provider_download_dimensions.height !== 5184 ||
            evidence.catalog_source_relation !== "byte_for_byte_match" ||
            "normalized_rmse" in evidence ||
            "perceptual_hash_distance" in evidence
        ) {
            fail(`${asset.id} exact binary-match record changed`);
        }
    } else {
        fail(`${asset.id} is not an allowed identified provider asset`);
    }

    const expectedBlockers = [
        "catalog source binary has no durable organization-controlled archive",
        "provider page and applicable terms have no durable snapshot",
        "release owner approval absent",
    ];
    if (!sameArray(asset.blockers, expectedBlockers)) {
        fail(`${asset.id} identified-source blocker inventory changed`);
    }
}

function verifyReview(review, catalog, catalogBytes) {
    requireKeys(
        review,
        [
            "schema",
            "review_id",
            "as_of",
            "scope",
            "legal_determination",
            "release_owner_approval",
            "distribution_status",
            "automated_release_permitted",
            "catalog",
            "counts",
            "archive_policy",
            "provider_terms_observations",
            "assets",
            "required_next_actions",
        ],
        "review",
    );

    if (
        review.schema !== expectedSchema ||
        review.review_id !== expectedReviewId ||
        review.as_of !== "2026-07-27" ||
        review.scope !==
            "source_identity_archive_and_provider_terms_evidence_only"
    ) {
        fail("review identity or scope changed");
    }
    if (
        review.legal_determination !== "none" ||
        review.release_owner_approval !== null ||
        review.distribution_status !== "hold" ||
        review.automated_release_permitted !== false
    ) {
        fail(
            "v0 review must not claim legal approval or distribution eligibility",
        );
    }

    requireKeys(
        review.catalog,
        ["path", "sha256", "asset_count"],
        "catalog binding",
    );
    if (
        review.catalog.path !== catalogPath ||
        review.catalog.sha256 !== sha256(catalogBytes) ||
        review.catalog.asset_count !== expectedAssetCount ||
        catalog.rights?.review_record !== reviewPath ||
        catalog.rights?.review_record_status !== "distribution_hold" ||
        catalog.rights?.distribution_status !==
            "foundation_only_pending_release_rights_record"
    ) {
        fail("catalog binding or distribution hold changed");
    }

    if (
        !Array.isArray(review.assets) ||
        review.assets.length !== expectedAssetCount ||
        !Array.isArray(catalog.assets) ||
        catalog.assets.length !== expectedAssetCount
    ) {
        fail(
            `review and catalog must contain exactly ${expectedAssetCount} assets`,
        );
    }

    const unresolvedIds = new Set([
        "nightingale-background-01",
        "nightingale-background-02",
        "nightingale-background-03",
        "nightingale-background-04",
        "nightingale-background-07",
    ]);
    const seenIds = new Set();
    for (let index = 0; index < expectedAssetCount; index += 1) {
        const asset = review.assets[index];
        const catalogAsset = catalog.assets[index];
        requireKeys(
            asset,
            [
                "id",
                "source_filename",
                "source_sha256",
                "source_dimensions",
                "provider_source_status",
                "provider",
                "provider_asset_id",
                "creator_or_rightsholder",
                "canonical_source_url",
                "source_identity_evidence",
                "license_evidence_status",
                "license_name",
                "license_url",
                "attribution_requirement",
                "archive",
                "release_owner_approval_record",
                "release_eligible",
                "blockers",
            ],
            `asset ${index + 1}`,
        );
        if (
            asset.id !== catalogAsset.id ||
            asset.source_filename !== catalogAsset.source.filename ||
            asset.source_sha256 !== catalogAsset.source.sha256 ||
            asset.source_dimensions?.width !== catalogAsset.source.width ||
            asset.source_dimensions?.height !== catalogAsset.source.height
        ) {
            fail(
                `${asset.id ?? `asset ${index + 1}`} does not match catalog lineage`,
            );
        }
        if (seenIds.has(asset.id)) {
            fail(`duplicate asset ${asset.id}`);
        }
        seenIds.add(asset.id);
        if (
            asset.release_owner_approval_record !== null ||
            asset.release_eligible !== false
        ) {
            fail(`${asset.id} must remain release-ineligible`);
        }
        requireNullArchive(asset);
        if (unresolvedIds.has(asset.id)) {
            verifyUnresolvedAsset(asset);
        } else {
            verifyIdentifiedAsset(asset);
        }
    }

    requireKeys(
        review.counts,
        [
            "assets",
            "provider_pages_identified",
            "current_provider_download_binary_matches",
            "source_identity_unresolved",
            "durably_archived_sources",
            "durably_archived_license_records",
            "rights_cleared_assets",
            "distribution_eligible_assets",
        ],
        "counts",
    );
    const expectedCounts = {
        assets: expectedAssetCount,
        provider_pages_identified: 2,
        current_provider_download_binary_matches: 1,
        source_identity_unresolved: 5,
        durably_archived_sources: 0,
        durably_archived_license_records: 0,
        rights_cleared_assets: 0,
        distribution_eligible_assets: 0,
    };
    if (
        Object.entries(expectedCounts).some(
            ([key, expected]) => review.counts[key] !== expected,
        )
    ) {
        fail("summary counts changed or overstate readiness");
    }

    if (
        review.provider_terms_observations?.length !== 1 ||
        review.provider_terms_observations[0]?.provider !== "Unsplash" ||
        review.provider_terms_observations[0]?.license_name !==
            "Unsplash License" ||
        review.provider_terms_observations[0]?.license_url !==
            "https://unsplash.com/license" ||
        review.provider_terms_observations[0]?.terms_url !==
            "https://unsplash.com/terms" ||
        review.provider_terms_observations[0]?.observed_on !== "2026-07-27" ||
        review.provider_terms_observations[0]?.durable_snapshot_location !==
            null ||
        review.provider_terms_observations[0]?.durable_snapshot_sha256 !==
            null ||
        review.provider_terms_observations[0]?.release_owner_reviewed !== false
    ) {
        fail("provider terms observation changed or implies archived approval");
    }

    const archivePolicy = review.archive_policy;
    if (
        archivePolicy?.machine_local_path_is_durable !== false ||
        archivePolicy?.repository_derivative_is_original_archive !== false ||
        archivePolicy?.filename_is_rights_evidence !== false ||
        archivePolicy?.provider_profile_is_asset_identity_evidence !== false ||
        archivePolicy?.provider_page_without_source_binary_reconciliation_is_sufficient !==
            false ||
        archivePolicy?.required_for_each_asset?.length !== 7 ||
        archivePolicy?.acceptable_archive_properties?.length !== 6
    ) {
        fail("fail-closed archive policy changed");
    }

    if (
        !Array.isArray(review.required_next_actions) ||
        review.required_next_actions.length !== 6 ||
        !review.required_next_actions.every(
            (action) => typeof action === "string" && action.length > 30,
        )
    ) {
        fail("rights-closure action queue is incomplete");
    }

    return {
        assets: expectedAssetCount,
        providerPagesIdentified: 2,
        binaryMatches: 1,
        unresolved: 5,
        releaseEligible: 0,
    };
}

function expectFailure(label, operation) {
    try {
        operation();
    } catch (error) {
        if (error instanceof RightsVerificationError) return;
        throw error;
    }
    throw new Error(`self-test ${label} did not fail closed`);
}

function runSelfTests(review, catalog, catalogBytes) {
    const mutations = [
        [
            "distribution approved",
            (value) => (value.distribution_status = "approved"),
        ],
        [
            "legal determination",
            (value) => (value.legal_determination = "approved"),
        ],
        [
            "release owner approval",
            (value) => (value.release_owner_approval = "record-1"),
        ],
        [
            "automated release",
            (value) => (value.automated_release_permitted = true),
        ],
        ["missing asset", (value) => value.assets.pop()],
        [
            "duplicate asset",
            (value) => (value.assets[1].id = value.assets[0].id),
        ],
        [
            "source hash drift",
            (value) => (value.assets[0].source_sha256 = "0".repeat(64)),
        ],
        [
            "release eligible",
            (value) => (value.assets[0].release_eligible = true),
        ],
        [
            "local path promoted to archive",
            (value) =>
                (value.assets[0].archive.original_binary_location =
                    "/Users/example/source.jpg"),
        ],
        [
            "archive checksum claimed",
            (value) =>
                (value.assets[0].archive.original_binary_sha256_verified = true),
        ],
        [
            "unknown source guessed",
            (value) => (value.assets[0].provider = "wallpaper site"),
        ],
        [
            "provider asset id drift",
            (value) => (value.assets[4].provider_asset_id = "other"),
        ],
        [
            "corroboration promoted to binary match",
            (value) =>
                (value.assets[4].provider_source_status =
                    "current_provider_download_binary_matched"),
        ],
        [
            "binary hash drift",
            (value) =>
                (value.assets[5].source_identity_evidence[0].current_provider_download_sha256 =
                    "f".repeat(64)),
        ],
        [
            "terms snapshot invented",
            (value) =>
                (value.provider_terms_observations[0].durable_snapshot_location =
                    "s3://unverified/license"),
        ],
        [
            "terms review invented",
            (value) =>
                (value.provider_terms_observations[0].release_owner_reviewed = true),
        ],
        [
            "rights cleared count",
            (value) => (value.counts.rights_cleared_assets = 1),
        ],
        [
            "distribution eligible count",
            (value) => (value.counts.distribution_eligible_assets = 1),
        ],
        [
            "filename accepted as evidence",
            (value) =>
                (value.archive_policy.filename_is_rights_evidence = true),
        ],
        [
            "catalog digest drift",
            (value) => (value.catalog.sha256 = "a".repeat(64)),
        ],
    ];

    for (const [label, mutate] of mutations) {
        const mutated = clone(review);
        mutate(mutated);
        expectFailure(label, () =>
            verifyReview(mutated, catalog, catalogBytes),
        );
    }
    return mutations.length;
}

try {
    const catalogBytes = read(catalogPath);
    const catalog = parse(catalogBytes, catalogPath);
    const review = parse(read(reviewPath), reviewPath);
    const result = verifyReview(review, catalog, catalogBytes);
    const mutationCount = selfTest
        ? runSelfTests(review, catalog, catalogBytes)
        : 0;
    process.stdout.write(
        `Nightingale background rights review verified: ${result.assets} catalog-bound assets, ` +
            `${result.providerPagesIdentified} provider pages identified, ` +
            `${result.binaryMatches} current provider binary match, ` +
            `${result.unresolved} exact sources unresolved, ` +
            `${result.releaseEligible} distribution-eligible assets; release remains on hold` +
            `${selfTest ? `; ${mutationCount} negative mutations passed` : ""}.\n`,
    );
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exit(1);
}
