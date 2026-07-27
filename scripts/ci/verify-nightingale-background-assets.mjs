#!/usr/bin/env node

import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import process from "node:process";

const repoRoot = path.resolve(process.argv[2] ?? ".");
const selfTest = process.argv.includes("--self-test");
const manifestRelativePath = "nightingale/backgrounds/backgrounds.v1.json";
const derivativeRootRelativePath =
    "nightingale/backgrounds/optimized/drawable-nodpi";
const expectedSchema = "net.acumenus.nightingale.background-assets.v1";
const expectedAssetCount = 7;
const expectedDerivativeNames = Array.from(
    { length: expectedAssetCount },
    (_, index) =>
        `nightingale_background_${String(index + 1).padStart(2, "0")}.jpg`,
);
class VerificationError extends Error {}

function violation(message) {
    throw new VerificationError(
        `Nightingale background asset violation: ${message}`,
    );
}

function readJson(filePath) {
    try {
        return JSON.parse(fs.readFileSync(filePath, "utf8"));
    } catch (error) {
        violation(`cannot parse ${filePath}: ${error.message}`);
    }
}

function sha256(bytes) {
    return crypto.createHash("sha256").update(bytes).digest("hex");
}

function parseJpeg(bytes, fileName) {
    if (
        bytes.length < 4 ||
        bytes[0] !== 0xff ||
        bytes[1] !== 0xd8 ||
        bytes.at(-2) !== 0xff ||
        bytes.at(-1) !== 0xd9
    ) {
        violation(`${fileName} is not a complete JPEG`);
    }

    let offset = 2;
    let width;
    let height;
    let progressive = false;
    let reachedEndOfImage = false;
    const metadataMarkers = [];

    while (offset < bytes.length) {
        if (bytes[offset] !== 0xff) {
            violation(
                `${fileName} has malformed marker data at byte ${offset}`,
            );
        }
        while (bytes[offset] === 0xff) {
            offset += 1;
        }

        const marker = bytes[offset];
        offset += 1;
        if (marker === 0xd9) {
            reachedEndOfImage = true;
            if (offset !== bytes.length) {
                violation(
                    `${fileName} contains trailing data after its end marker`,
                );
            }
            break;
        }
        if (marker >= 0xd0 && marker <= 0xd7) {
            continue;
        }
        if (offset + 2 > bytes.length) {
            violation(`${fileName} has a truncated JPEG segment`);
        }

        const segmentLength = bytes.readUInt16BE(offset);
        if (segmentLength < 2 || offset + segmentLength > bytes.length) {
            violation(`${fileName} has an invalid JPEG segment length`);
        }

        if ((marker >= 0xe1 && marker <= 0xef) || marker === 0xfe) {
            metadataMarkers.push(marker);
        }

        if (
            (marker >= 0xc0 && marker <= 0xc3) ||
            (marker >= 0xc5 && marker <= 0xc7) ||
            (marker >= 0xc9 && marker <= 0xcb) ||
            (marker >= 0xcd && marker <= 0xcf)
        ) {
            if (segmentLength < 8) {
                violation(`${fileName} has a malformed frame header`);
            }
            height = bytes.readUInt16BE(offset + 3);
            width = bytes.readUInt16BE(offset + 5);
            progressive = marker === 0xc2;
        }
        offset += segmentLength;

        if (marker === 0xda) {
            let nextMarkerOffset;
            while (offset < bytes.length) {
                if (bytes[offset] !== 0xff) {
                    offset += 1;
                    continue;
                }

                let markerOffset = offset;
                while (bytes[offset] === 0xff) {
                    offset += 1;
                }
                if (offset >= bytes.length) {
                    violation(`${fileName} has a truncated entropy-coded scan`);
                }

                const scanMarker = bytes[offset];
                if (
                    scanMarker === 0x00 ||
                    (scanMarker >= 0xd0 && scanMarker <= 0xd7)
                ) {
                    offset += 1;
                    continue;
                }

                nextMarkerOffset = markerOffset;
                break;
            }
            if (nextMarkerOffset === undefined) {
                violation(
                    `${fileName} scan does not terminate with an end marker`,
                );
            }
            offset = nextMarkerOffset;
        }
    }

    if (!Number.isInteger(width) || !Number.isInteger(height)) {
        violation(`${fileName} does not contain a supported JPEG frame header`);
    }
    if (!reachedEndOfImage) {
        violation(`${fileName} does not terminate at an end-of-image marker`);
    }

    return { width, height, progressive, metadataMarkers };
}

function verifyManifest(manifest, root) {
    if (manifest.schema !== expectedSchema) {
        violation(`schema must be ${expectedSchema}`);
    }
    if (manifest.catalog_version !== 1) {
        violation("catalog_version must remain 1 for this immutable catalog");
    }
    if (
        manifest.asset_count !== expectedAssetCount ||
        manifest.assets?.length !== expectedAssetCount
    ) {
        violation(`catalog must contain exactly ${expectedAssetCount} assets`);
    }
    if (
        manifest.purpose !== "decorative_background_only" ||
        manifest.clinical_semantics_permitted !== false ||
        manifest.species_labels_permitted_in_patient_ui !== false ||
        manifest.automatic_motion_permitted !== false
    ) {
        violation("decorative-only and no-motion product boundaries changed");
    }
    if (
        manifest.selection_policy !== "one_stable_asset_per_local_calendar_day"
    ) {
        violation("selection policy must remain stable for each local day");
    }
    if (
        manifest.selection_index !==
        "floor_mod(local_gregorian_epoch_day_since_1970_01_01, 7)"
    ) {
        violation("cross-platform local-day selection index changed");
    }

    const accessibility = manifest.accessibility ?? {};
    for (const requiredTrue of [
        "hidden_from_accessibility_tree",
        "patient_hide_control_required",
        "hide_when_increased_contrast_or_reduced_transparency",
        "opaque_or_governed_scrim_behind_all_text",
    ]) {
        if (accessibility[requiredTrue] !== true) {
            violation(
                `accessibility safeguard must remain true: ${requiredTrue}`,
            );
        }
    }

    if (
        manifest.rights?.source !== "user_supplied_by_project_owner" ||
        manifest.rights?.license_or_attribution_record !==
            "pending_release_owner_confirmation" ||
        manifest.rights?.distribution_status !==
            "foundation_only_pending_release_rights_record"
    ) {
        violation(
            "source and pre-distribution rights status must remain explicit until release evidence is recorded",
        );
    }
    if (
        manifest.source_retention?.original_binaries_committed !== false ||
        manifest.derivative_recipe?.maximum_long_edge_pixels !== 2400 ||
        manifest.derivative_recipe?.upscaling_permitted !== false
    ) {
        violation("source-retention or derivative constraints changed");
    }

    const derivativeRoot = path.join(root, derivativeRootRelativePath);
    let actualNames;
    try {
        actualNames = fs
            .readdirSync(derivativeRoot, { withFileTypes: true })
            .filter((entry) => entry.isFile())
            .map((entry) => entry.name)
            .sort();
    } catch (error) {
        violation(`cannot read derivative directory: ${error.message}`);
    }
    assert.deepEqual(
        actualNames,
        expectedDerivativeNames,
        "derivative directory must contain the exact governed JPEG set",
    );

    const ids = new Set();
    const sourceHashes = new Set();
    const derivativeHashes = new Set();
    const paths = new Set();

    manifest.assets.forEach((asset, index) => {
        const sequence = String(index + 1).padStart(2, "0");
        const expectedId = `nightingale-background-${sequence}`;
        const expectedPath = `${derivativeRootRelativePath}/nightingale_background_${sequence}.jpg`;

        if (asset.id !== expectedId) {
            violation(`asset ${index + 1} id must be ${expectedId}`);
        }
        if (asset.derivative?.path !== expectedPath) {
            violation(`${expectedId} derivative path must be ${expectedPath}`);
        }
        if (!/^[a-f0-9]{64}$/.test(asset.source?.sha256 ?? "")) {
            violation(`${expectedId} source SHA-256 is malformed`);
        }
        if (!/^[a-f0-9]{64}$/.test(asset.derivative?.sha256 ?? "")) {
            violation(`${expectedId} derivative SHA-256 is malformed`);
        }
        if (
            !Number.isInteger(asset.source?.bytes) ||
            !Number.isInteger(asset.source?.width) ||
            !Number.isInteger(asset.source?.height) ||
            asset.source.bytes <= 0 ||
            asset.source.width <= 0 ||
            asset.source.height <= 0
        ) {
            violation(`${expectedId} source lineage is incomplete`);
        }
        if (
            asset.source.width > asset.source.height ||
            asset.derivative.width > asset.derivative.height
        ) {
            violation(`${expectedId} must remain portrait-oriented`);
        }
        if (
            asset.derivative.width > asset.source.width ||
            asset.derivative.height > asset.source.height
        ) {
            violation(`${expectedId} derivative was upscaled`);
        }
        if (Math.max(asset.derivative.width, asset.derivative.height) > 2400) {
            violation(`${expectedId} exceeds the 2400-pixel long-edge limit`);
        }

        const derivativePath = path.join(root, asset.derivative.path);
        let bytes;
        try {
            bytes = fs.readFileSync(derivativePath);
        } catch (error) {
            violation(`cannot read ${asset.derivative.path}: ${error.message}`);
        }

        if (bytes.length !== asset.derivative.bytes) {
            violation(
                `${expectedId} byte count changed: expected ${asset.derivative.bytes}, found ${bytes.length}`,
            );
        }
        const actualHash = sha256(bytes);
        if (actualHash !== asset.derivative.sha256) {
            violation(
                `${expectedId} SHA-256 changed: expected ${asset.derivative.sha256}, found ${actualHash}`,
            );
        }

        const jpeg = parseJpeg(bytes, asset.derivative.path);
        if (
            jpeg.width !== asset.derivative.width ||
            jpeg.height !== asset.derivative.height
        ) {
            violation(
                `${expectedId} dimensions changed: expected ${asset.derivative.width}x${asset.derivative.height}, found ${jpeg.width}x${jpeg.height}`,
            );
        }
        if (!jpeg.progressive) {
            violation(
                `${expectedId} must remain an optimized progressive JPEG`,
            );
        }
        if (jpeg.metadataMarkers.length > 0) {
            violation(
                `${expectedId} contains prohibited metadata marker(s): ${jpeg.metadataMarkers
                    .map((marker) => `0x${marker.toString(16)}`)
                    .join(", ")}`,
            );
        }

        ids.add(asset.id);
        sourceHashes.add(asset.source.sha256);
        derivativeHashes.add(asset.derivative.sha256);
        paths.add(asset.derivative.path);
    });

    for (const [label, values] of [
        ["ids", ids],
        ["source hashes", sourceHashes],
        ["derivative hashes", derivativeHashes],
        ["derivative paths", paths],
    ]) {
        if (values.size !== expectedAssetCount) {
            violation(
                `${label} must be unique across all ${expectedAssetCount} assets`,
            );
        }
    }

    return {
        assetCount: expectedAssetCount,
        totalDerivativeBytes: manifest.assets.reduce(
            (sum, asset) => sum + asset.derivative.bytes,
            0,
        ),
    };
}

function expectFailure(label, operation, expectedPattern) {
    try {
        operation();
    } catch (error) {
        if (
            error instanceof VerificationError ||
            error instanceof assert.AssertionError
        ) {
            if (!expectedPattern.test(error.message)) {
                throw new Error(
                    `self-test ${label} failed for the wrong reason: ${error.message}`,
                );
            }
            return;
        }
        throw error;
    }
    throw new Error(`self-test ${label} did not fail closed`);
}

function deepClone(value) {
    return JSON.parse(JSON.stringify(value));
}

function runSelfTests(manifest) {
    const wrongCount = deepClone(manifest);
    wrongCount.assets.pop();
    expectFailure(
        "missing catalog entry",
        () => verifyManifest(wrongCount, repoRoot),
        /exactly 7 assets/,
    );

    const wrongRights = deepClone(manifest);
    wrongRights.rights.distribution_status = "approved";
    expectFailure(
        "unsubstantiated rights approval",
        () => verifyManifest(wrongRights, repoRoot),
        /rights status/,
    );

    const wrongSelection = deepClone(manifest);
    wrongSelection.selection_policy = "animated_carousel";
    expectFailure(
        "moving selection",
        () => verifyManifest(wrongSelection, repoRoot),
        /stable for each local day/,
    );

    const duplicateHash = deepClone(manifest);
    duplicateHash.assets[1].derivative.sha256 =
        duplicateHash.assets[0].derivative.sha256;
    expectFailure(
        "duplicate derivative identity",
        () => verifyManifest(duplicateHash, repoRoot),
        /SHA-256 changed|derivative hashes must be unique/,
    );

    const tempRoot = fs.mkdtempSync(
        path.join(os.tmpdir(), "nightingale-background-verifier-"),
    );
    try {
        const tempDerivativeRoot = path.join(
            tempRoot,
            derivativeRootRelativePath,
        );
        fs.mkdirSync(tempDerivativeRoot, { recursive: true });
        for (const fileName of expectedDerivativeNames) {
            fs.copyFileSync(
                path.join(repoRoot, derivativeRootRelativePath, fileName),
                path.join(tempDerivativeRoot, fileName),
            );
        }

        const tamperedPath = path.join(
            tempDerivativeRoot,
            expectedDerivativeNames[0],
        );
        const tampered = fs.readFileSync(tamperedPath);
        tampered[tampered.length - 16] ^= 0x01;
        fs.writeFileSync(tamperedPath, tampered);
        expectFailure(
            "binary tamper",
            () => verifyManifest(manifest, tempRoot),
            /SHA-256 changed/,
        );

        fs.copyFileSync(
            path.join(
                repoRoot,
                derivativeRootRelativePath,
                expectedDerivativeNames[0],
            ),
            tamperedPath,
        );
        fs.writeFileSync(
            path.join(tempDerivativeRoot, "unexpected.jpg"),
            Buffer.from([0xff, 0xd8, 0xff, 0xd9]),
        );
        expectFailure(
            "unexpected derivative",
            () => verifyManifest(manifest, tempRoot),
            /exact governed JPEG set/,
        );

        fs.rmSync(path.join(tempDerivativeRoot, "unexpected.jpg"));
        const metadataPath = path.join(
            tempDerivativeRoot,
            expectedDerivativeNames[0],
        );
        const cleanJpeg = fs.readFileSync(metadataPath);
        const commentSegment = Buffer.from([
            0xff, 0xfe, 0x00, 0x05, 0x78, 0x79, 0x7a,
        ]);
        const metadataBearingJpeg = Buffer.concat([
            cleanJpeg.subarray(0, cleanJpeg.length - 2),
            commentSegment,
            cleanJpeg.subarray(cleanJpeg.length - 2),
        ]);
        fs.writeFileSync(metadataPath, metadataBearingJpeg);
        const metadataManifest = deepClone(manifest);
        metadataManifest.assets[0].derivative.bytes =
            metadataBearingJpeg.length;
        metadataManifest.assets[0].derivative.sha256 =
            sha256(metadataBearingJpeg);
        expectFailure(
            "metadata injected after image scan",
            () => verifyManifest(metadataManifest, tempRoot),
            /prohibited metadata marker/,
        );
    } finally {
        fs.rmSync(tempRoot, { recursive: true, force: true });
    }
}

const manifestPath = path.join(repoRoot, manifestRelativePath);
const manifest = readJson(manifestPath);

try {
    const result = verifyManifest(manifest, repoRoot);
    if (selfTest) {
        runSelfTests(manifest);
    }
    console.log(
        `Nightingale background catalog verified: ${result.assetCount} exact ` +
            `metadata-stripped decorative JPEGs, ${result.totalDerivativeBytes} ` +
            `bytes, stable daily selection policy, accessibility safeguards, ` +
            `source lineage, and explicit pre-distribution rights gate` +
            `${selfTest ? "; fail-closed self-tests passed" : ""}.`,
    );
} catch (error) {
    console.error(error.message);
    process.exit(1);
}
