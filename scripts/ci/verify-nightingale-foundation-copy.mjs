#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const selfTest = args.includes("--self-test");
const positional = args.filter((argument) => argument !== "--self-test");
const repoRoot = path.resolve(positional[0] ?? ".");

const expectedCopy = {
    app_name: "Nightingale",
    display_comfort_heading: "Display comfort",
    display_comfort_scope:
        "These settings are stored by Nightingale, not your care account. They never change your care information.",
    foundation_mission:
        "A calm place to understand, prepare, and connect with your care team.",
    foundation_no_patient_data:
        "No patient information is stored or requested by this build.",
    foundation_unavailable:
        "Live patient access is not available in this foundation build. Please ask your care team for current information.",
    hide_imagery_label: "Hide decorative imagery",
    imagery_hidden_status:
        "Decorative imagery is hidden. Essential text and controls remain available.",
    imagery_shown_status:
        "A calming Nightingale background is shown softly behind the page.",
    motion_reduced_status:
        "Motion is reduced. Nightingale changes views without decorative movement.",
    motion_standard_status:
        "Gentle transitions are enabled. Nightingale also follows your system Reduce Motion setting.",
    privacy_cover_accessibility_label:
        "Privacy cover. Your care information is hidden while Nightingale is not active.",
    privacy_cover_message:
        "Your care information is covered while the app is not active.",
    privacy_heading: "Your privacy comes first",
    reduce_motion_label: "Reduce motion in Nightingale",
};

const paths = {
    iosCatalog: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/Localizable.xcstrings",
    ),
    iosKeys: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/NightingaleCopy.swift",
    ),
    iosScreen: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/NightingaleApp.swift",
    ),
    iosVisual: path.join(
        repoRoot,
        "nightingale/iosApp/Nightingale/NightingaleVisualFoundation.swift",
    ),
    iosUiTests: path.join(
        repoRoot,
        "nightingale/iosApp/NightingaleUITests/NightingaleLaunchUITests.swift",
    ),
    androidStrings: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/res/values/strings.xml",
    ),
    androidScreen: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/java/net/acumenus/nightingale/NightingaleVisualFoundation.kt",
    ),
    androidTests: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/androidTest/java/net/acumenus/nightingale/NightingaleLaunchInstrumentedTest.kt",
    ),
    androidGradle: path.join(
        repoRoot,
        "nightingale/androidApp/app/build.gradle.kts",
    ),
    androidManifest: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/main/AndroidManifest.xml",
    ),
    androidDebugLanguagePolicy: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/debug/java/net/acumenus/nightingale/NightingaleLanguageReadinessPolicy.kt",
    ),
    androidReleaseLanguagePolicy: path.join(
        repoRoot,
        "nightingale/androidApp/app/src/release/java/net/acumenus/nightingale/NightingaleLanguageReadinessPolicy.kt",
    ),
};

for (const [name, filePath] of Object.entries(paths)) {
    if (!fs.existsSync(filePath)) {
        throw new Error(`Missing ${name}: ${filePath}`);
    }
}

function decodeXml(value) {
    return value
        .replaceAll("&lt;", "<")
        .replaceAll("&gt;", ">")
        .replaceAll("&quot;", '"')
        .replaceAll("&apos;", "'")
        .replaceAll("&amp;", "&");
}

function parseAndroidStrings(xml) {
    const entries = {};
    const pattern = /<string\s+name="([^"]+)"([^>]*)>([\s\S]*?)<\/string>/g;
    for (const match of xml.matchAll(pattern)) {
        const [, key, attributes, rawValue] = match;
        if (attributes.includes('translatable="false"')) {
            throw new Error(`Android copy key is not translatable: ${key}`);
        }
        if (Object.hasOwn(entries, key)) {
            throw new Error(`Duplicate Android copy key: ${key}`);
        }
        if (/<[a-zA-Z]/.test(rawValue)) {
            throw new Error(
                `Android copy key contains unsupported inline markup: ${key}`,
            );
        }
        entries[key] = decodeXml(rawValue.trim());
    }
    return entries;
}

function sortedRecord(record) {
    return Object.fromEntries(
        Object.entries(record).sort(([a], [b]) => a.localeCompare(b)),
    );
}

function sameRecord(actual, expected) {
    return (
        JSON.stringify(sortedRecord(actual)) ===
        JSON.stringify(sortedRecord(expected))
    );
}

function count(source, token) {
    return source.split(token).length - 1;
}

function inspect(evidence) {
    const violations = [];
    const assert = (condition, message) => {
        if (!condition) violations.push(message);
    };

    let iosCatalog;
    try {
        iosCatalog = JSON.parse(evidence.iosCatalog);
    } catch (error) {
        violations.push(
            `iOS string catalog is not valid JSON: ${error.message}`,
        );
        iosCatalog = {};
    }

    assert(iosCatalog.sourceLanguage === "en", "iOS source language changed");
    assert(iosCatalog.version === "1.0", "iOS string-catalog version changed");
    const iosStrings = iosCatalog.strings ?? {};
    const iosCopy = {};
    for (const [key, entry] of Object.entries(iosStrings)) {
        const localeKeys = Object.keys(entry.localizations ?? {}).sort();
        assert(
            JSON.stringify(localeKeys) === JSON.stringify(["en"]),
            `iOS copy key has an unapproved localization: ${key}`,
        );
        const stringUnit = entry.localizations?.en?.stringUnit;
        assert(
            entry.extractionState === "manual",
            `iOS copy key is not manually governed: ${key}`,
        );
        assert(
            stringUnit?.state === "translated",
            `iOS English source copy is not complete: ${key}`,
        );
        iosCopy[key] = stringUnit?.value;
    }
    assert(
        sameRecord(iosCopy, expectedCopy),
        "iOS English foundation copy changed",
    );

    let androidCopy = {};
    try {
        androidCopy = parseAndroidStrings(evidence.androidStrings);
    } catch (error) {
        violations.push(error.message);
    }
    assert(
        sameRecord(androidCopy, expectedCopy),
        "Android English foundation copy changed",
    );
    assert(
        sameRecord(androidCopy, iosCopy),
        "iOS and Android English foundation copy diverged",
    );

    for (const key of Object.keys(expectedCopy)) {
        assert(
            evidence.iosKeys.includes(`"${key}"`),
            `iOS copy-key registry is missing ${key}`,
        );
        assert(
            evidence.androidScreen.includes(`R.string.${key}`),
            `Android screen does not consume ${key}`,
        );
    }

    for (const [key, value] of Object.entries(expectedCopy)) {
        if (key === "app_name") continue;
        assert(
            !evidence.iosRuntime.includes(value),
            `iOS runtime hardcodes governed copy: ${key}`,
        );
        assert(
            !evidence.androidScreen.includes(value),
            `Android runtime hardcodes governed copy: ${key}`,
        );
    }

    assert(
        count(evidence.iosRuntime, ".accessibilityAddTraits(.isHeader)") === 3,
        "iOS foundation must expose exactly three headings",
    );
    assert(
        count(evidence.iosKeys, "NightingaleAccessibilityAnnouncement.") ===
            0 &&
            count(
                evidence.iosRuntime,
                "NightingaleAccessibilityAnnouncement.",
            ) === 2 &&
            evidence.iosKeys.includes(
                ".accessibilitySpeechAnnouncementPriority:",
            ) &&
            evidence.iosKeys.includes("UIAccessibilityPriority.low") &&
            count(evidence.iosKeys, "UIAccessibility.post(") === 1,
        "iOS low-priority status-announcement policy changed",
    );
    assert(
        count(evidence.androidScreen, ".semantics { heading() }") === 3,
        "Android foundation must expose exactly three headings",
    );
    assert(
        count(
            evidence.androidScreen,
            ".semantics { liveRegion = LiveRegionMode.Polite }",
        ) === 2 && !evidence.androidScreen.includes("LiveRegionMode.Assertive"),
        "Android status live-region policy changed",
    );

    assert(
        evidence.iosUiTests.includes("-NSDoubleLocalizedStrings") &&
            evidence.iosUiTests.includes("-AppleTextDirection") &&
            evidence.iosUiTests.includes(
                "testDoubleLengthPseudolanguageReflowsAndKeepsControlsReachable",
            ) &&
            evidence.iosUiTests.includes(
                "testRightToLeftLayoutDirectionMirrorsTheShellWithoutChangingSemanticOrder",
            ),
        "iOS text-expansion or right-to-left UI journey changed",
    );
    assert(
        evidence.iosRuntime.includes("NIGHTINGALE_TEST_LAYOUT_DIRECTION") &&
            evidence.iosRuntime.includes(
                "environment(\\.layoutDirection, .rightToLeft)",
            ) &&
            evidence.iosUiTests.includes("NIGHTINGALE_TEST_LAYOUT_DIRECTION"),
        "iOS Debug-only right-to-left layout-direction harness changed",
    );
    assert(
        evidence.androidGradle.includes("isPseudoLocalesEnabled = true") &&
            evidence.androidGradle.includes("isPseudoLocalesEnabled = false"),
        "Android Debug/Release pseudolocale boundary changed",
    );
    assert(
        evidence.androidTests.includes('LocaleList.forLanguageTags("en-XA")') &&
            evidence.androidTests.includes(
                'LocaleList.forLanguageTags("ar-XB")',
            ) &&
            evidence.androidTests.includes(
                "debugPseudoLocalesExpandAndMirrorWithoutLosingControls",
            ),
        "Android pseudolocale journey changed",
    );
    assert(
        evidence.androidManifest.includes('android:supportsRtl="true"'),
        "Android RTL support declaration changed",
    );
    assert(
        evidence.androidScreen.includes(
            "nightingaleLanguageReadinessLayoutDirection(",
        ) &&
            evidence.androidDebugLanguagePolicy.includes('"ar-XB"') &&
            evidence.androidDebugLanguagePolicy.includes(
                "LayoutDirection.Rtl",
            ) &&
            !evidence.androidReleaseLanguagePolicy.includes('"ar-XB"') &&
            evidence.androidReleaseLanguagePolicy.includes(
                "): LayoutDirection = platformDirection",
            ),
        "Android Debug-only pseudolocale direction policy changed",
    );

    return violations;
}

const evidence = {
    iosCatalog: fs.readFileSync(paths.iosCatalog, "utf8"),
    iosKeys: fs.readFileSync(paths.iosKeys, "utf8"),
    iosRuntime:
        fs.readFileSync(paths.iosScreen, "utf8") +
        fs.readFileSync(paths.iosVisual, "utf8"),
    iosUiTests: fs.readFileSync(paths.iosUiTests, "utf8"),
    androidStrings: fs.readFileSync(paths.androidStrings, "utf8"),
    androidScreen: fs.readFileSync(paths.androidScreen, "utf8"),
    androidTests: fs.readFileSync(paths.androidTests, "utf8"),
    androidGradle: fs.readFileSync(paths.androidGradle, "utf8"),
    androidManifest: fs.readFileSync(paths.androidManifest, "utf8"),
    androidDebugLanguagePolicy: fs.readFileSync(
        paths.androidDebugLanguagePolicy,
        "utf8",
    ),
    androidReleaseLanguagePolicy: fs.readFileSync(
        paths.androidReleaseLanguagePolicy,
        "utf8",
    ),
};

const violations = inspect(evidence);
if (violations.length > 0) {
    throw new Error(
        `Nightingale foundation-copy violations:\n- ${violations.join("\n- ")}`,
    );
}

if (selfTest) {
    const mutations = [
        {
            name: "missing iOS key",
            mutate(candidate) {
                const parsed = JSON.parse(candidate.iosCatalog);
                delete parsed.strings.reduce_motion_label;
                candidate.iosCatalog = JSON.stringify(parsed);
            },
        },
        {
            name: "Android copy drift",
            mutate(candidate) {
                candidate.androidStrings = candidate.androidStrings.replace(
                    "Display comfort",
                    "Display options",
                );
            },
        },
        {
            name: "unapproved iOS locale",
            mutate(candidate) {
                const parsed = JSON.parse(candidate.iosCatalog);
                parsed.strings.app_name.localizations.es = {
                    stringUnit: { state: "translated", value: "draft" },
                };
                candidate.iosCatalog = JSON.stringify(parsed);
            },
        },
        {
            name: "Release pseudolocale enabled",
            mutate(candidate) {
                candidate.androidGradle = candidate.androidGradle.replace(
                    "isPseudoLocalesEnabled = false",
                    "isPseudoLocalesEnabled = true",
                );
            },
        },
        {
            name: "hardcoded Android copy",
            mutate(candidate) {
                candidate.androidScreen +=
                    '\nval unsafeCopy = "Your privacy comes first"\n';
            },
        },
        {
            name: "missing iOS heading",
            mutate(candidate) {
                candidate.iosRuntime = candidate.iosRuntime.replace(
                    ".accessibilityAddTraits(.isHeader)",
                    "",
                );
            },
        },
        {
            name: "assertive Android live region",
            mutate(candidate) {
                candidate.androidScreen = candidate.androidScreen.replace(
                    "LiveRegionMode.Polite",
                    "LiveRegionMode.Assertive",
                );
            },
        },
        {
            name: "missing iOS double-length journey",
            mutate(candidate) {
                candidate.iosUiTests = candidate.iosUiTests.replace(
                    "-NSDoubleLocalizedStrings",
                    "-RemovedDoubleLocalization",
                );
            },
        },
        {
            name: "missing Android RTL pseudolocale",
            mutate(candidate) {
                candidate.androidTests = candidate.androidTests.replace(
                    'LocaleList.forLanguageTags("ar-XB")',
                    'LocaleList.forLanguageTags("en-US")',
                );
            },
        },
    ];

    for (const mutation of mutations) {
        const candidate = structuredClone(evidence);
        mutation.mutate(candidate);
        if (inspect(candidate).length === 0) {
            throw new Error(
                `Foundation-copy self-test did not reject: ${mutation.name}`,
            );
        }
    }
}

console.log(
    `Nightingale foundation copy verified: ${Object.keys(expectedCopy).length} exact cross-platform English keys, three headings, two restrained status announcements per platform, Debug-only text-expansion/RTL journeys, and no approved translations;${selfTest ? " self-tests passed." : ""}`,
);
