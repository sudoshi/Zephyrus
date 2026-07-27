#!/usr/bin/env bash

set -euo pipefail

repo_root="${1:-.}"
ios_source="$repo_root/nightingale/iosApp/Nightingale"
android_source="$repo_root/nightingale/androidApp/app/src/main"
ios_project="$repo_root/nightingale/iosApp/project.yml"
ios_info="$ios_source/Info.plist"
ios_privacy_manifest="$ios_source/PrivacyInfo.xcprivacy"
android_project="$repo_root/nightingale/androidApp/app/build.gradle.kts"
android_manifest="$android_source/AndroidManifest.xml"
android_network_security="$android_source/res/xml/network_security_config.xml"
ios_presentation="$ios_source/NightingalePresentationPreferences.swift"
android_presentation="$android_source/java/net/acumenus/nightingale/NightingalePresentationPreferences.kt"
ios_visual="$ios_source/NightingaleVisualFoundation.swift"
ios_screen="$ios_source/NightingaleApp.swift"
ios_tests="$repo_root/nightingale/iosApp/NightingaleTests/NightingaleProductBoundaryTests.swift"
ios_ui_tests="$repo_root/nightingale/iosApp/NightingaleUITests/NightingaleLaunchUITests.swift"
android_visual="$android_source/java/net/acumenus/nightingale/NightingaleVisualFoundation.kt"
android_tests="$repo_root/nightingale/androidApp/app/src/test/java/net/acumenus/nightingale/NightingaleProductBoundaryTest.kt"
android_ui_tests="$repo_root/nightingale/androidApp/app/src/androidTest/java/net/acumenus/nightingale/NightingaleLaunchInstrumentedTest.kt"
background_manifest="$repo_root/nightingale/backgrounds/backgrounds.v1.json"
background_assets="$repo_root/nightingale/backgrounds/optimized/drawable-nodpi"
background_verifier="$repo_root/scripts/ci/verify-nightingale-background-assets.mjs"
copy_verifier="$repo_root/scripts/ci/verify-nightingale-foundation-copy.mjs"
ios_background_catalog="$ios_source/NightingaleBackgroundCatalog.swift"
ios_background_link="$repo_root/nightingale/iosApp/NightingaleBackgrounds"
android_background_catalog="$android_source/java/net/acumenus/nightingale/NightingaleBackgroundCatalog.kt"

for required_path in \
    "$ios_source" \
    "$android_source" \
    "$ios_project" \
    "$ios_info" \
    "$ios_privacy_manifest" \
    "$android_project" \
    "$android_manifest" \
    "$android_network_security" \
    "$ios_presentation" \
    "$android_presentation" \
    "$ios_visual" \
    "$ios_screen" \
    "$ios_tests" \
    "$ios_ui_tests" \
    "$android_visual" \
    "$android_tests" \
    "$android_ui_tests" \
    "$background_manifest" \
    "$background_assets" \
    "$background_verifier" \
    "$copy_verifier" \
    "$ios_background_catalog" \
    "$ios_background_link" \
    "$android_background_catalog"
do
    [[ -e "$required_path" ]] || {
        echo "Missing Nightingale boundary input: $required_path" >&2
        exit 66
    }
done

command -v node >/dev/null 2>&1 || {
    echo "Node is required to verify the Nightingale background catalog." >&2
    exit 69
}

node "$background_verifier" "$repo_root" --self-test
node "$copy_verifier" "$repo_root" --self-test

[[ -L "$ios_background_link" ]] || {
    echo "The Nightingale iOS background resource boundary must remain a relative symlink." >&2
    exit 1
}

[[ "$(readlink "$ios_background_link")" == "../backgrounds/optimized/drawable-nodpi" ]] || {
    echo "The Nightingale iOS background resource symlink target changed." >&2
    exit 1
}

grep -Fq -- 'path: NightingaleBackgrounds' "$ios_project" || {
    echo "The Nightingale iOS XcodeGen spec is missing the governed background resources." >&2
    exit 1
}

grep -Fq -- 'res.srcDir("../../backgrounds/optimized")' "$android_project" || {
    echo "The Nightingale Android source set is missing the governed background resources." >&2
    exit 1
}

for catalog in "$ios_background_catalog" "$android_background_catalog"
do
    for sequence in 01 02 03 04 05 06 07
    do
        grep -Fq -- "nightingale_background_$sequence" "$catalog" || {
            echo "Nightingale native catalog is missing background $sequence: $catalog" >&2
            exit 1
        }
    done
done

grep -Fq -- 'one_stable_asset_per_local_calendar_day' "$background_manifest" || {
    echo "The Nightingale background selection must remain stable for each local day." >&2
    exit 1
}

grep -Fq -- '.accessibilityHidden(true)' "$ios_visual" || {
    echo "The Nightingale iOS decorative background must remain accessibility-hidden." >&2
    exit 1
}

grep -Fq -- 'contentDescription = null' "$android_visual" || {
    echo "The Nightingale Android decorative backgrounds must remain unannounced." >&2
    exit 1
}

for forbidden in \
    "net.acumenus.hummingbird" \
    "hummingbird.patient" \
    "/api/mobile" \
    "/api/auth"
do
    if grep -RInF -- "$forbidden" "$ios_source" "$android_source"; then
        echo "Nightingale source contains forbidden staff or legacy patient token: $forbidden" >&2
        exit 1
    fi
done

if grep -nF -- "android.permission.INTERNET" "$android_manifest"; then
    echo "The Nightingale foundation must not request Android network access." >&2
    exit 1
fi

grep -Eq 'android:allowBackup="false"' "$android_manifest" || {
    echo "The Nightingale foundation must disable Android application backup." >&2
    exit 1
}

grep -Eq 'android:dataExtractionRules="@xml/data_extraction_rules"' "$android_manifest" || {
    echo "The Nightingale foundation must exclude cloud backup and device transfer." >&2
    exit 1
}

grep -Eq 'android:usesCleartextTraffic="false"' "$android_manifest" || {
    echo "The Nightingale foundation must explicitly disable Android cleartext traffic." >&2
    exit 1
}

grep -Eq 'android:networkSecurityConfig="@xml/network_security_config"' "$android_manifest" || {
    echo "The Nightingale foundation must retain its network-security configuration." >&2
    exit 1
}

grep -Eq 'cleartextTrafficPermitted="false"' "$android_network_security" || {
    echo "The Nightingale Android network-security configuration must deny cleartext." >&2
    exit 1
}

grep -Eq '<certificates src="system"' "$android_network_security" || {
    echo "The Nightingale Android network-security configuration must trust system authorities only." >&2
    exit 1
}

if grep -nF -- "<debug-overrides" "$android_network_security"; then
    echo "The Nightingale Android foundation must not include a debug trust override." >&2
    exit 1
fi

python3 - "$ios_privacy_manifest" <<'PY'
import plistlib
import sys
from pathlib import Path

try:
    manifest = plistlib.loads(Path(sys.argv[1]).read_bytes())
except (OSError, plistlib.InvalidFileException) as error:
    raise SystemExit(
        f"The Nightingale iOS privacy manifest is not a valid property list: {error}"
    )
expected = {
    "NSPrivacyAccessedAPITypes": [
        {
            "NSPrivacyAccessedAPIType": "NSPrivacyAccessedAPICategoryUserDefaults",
            "NSPrivacyAccessedAPITypeReasons": ["CA92.1"],
        }
    ],
    "NSPrivacyCollectedDataTypes": [],
    "NSPrivacyTracking": False,
}
if manifest != expected:
    raise SystemExit(
        "The Nightingale iOS privacy manifest changed from the exact "
        "offline foundation declaration."
    )
PY

if grep -RInE 'URLSession|NSURLSession|OkHttpClient|java\.net\.' "$ios_source" "$android_source"; then
    echo "The Nightingale foundation must not contain a network client." >&2
    exit 1
fi

if grep -RInE \
    '(^|[^A-Za-z])(os_log|Logger|print|NSLog|UIPasteboard|FirebaseAnalytics|Crashlytics|ClipboardManager|Log\.[vdiew])([^A-Za-z]|$)' \
    "$ios_source" "$android_source"
then
    echo "The Nightingale offline foundation must not log, track, or copy patient-facing runtime state." >&2
    exit 1
fi

grep -Fq -- 'net.acumenus.nightingale.presentation.v1.reduce-motion' "$ios_presentation" || {
    echo "The Nightingale iOS reduce-motion preference key is missing or renamed." >&2
    exit 1
}

grep -Fq -- 'net.acumenus.nightingale.presentation.v1.hide-decorative-imagery' "$ios_presentation" || {
    echo "The Nightingale iOS imagery preference key is missing or renamed." >&2
    exit 1
}

grep -Fq -- 'net.acumenus.nightingale.presentation.v1' "$android_presentation" || {
    echo "The Nightingale Android presentation preference namespace is missing or renamed." >&2
    exit 1
}

for preference_name in "reduce-motion" "hide-decorative-imagery"
do
    grep -Fq -- "\"$preference_name\"" "$android_presentation" || {
        echo "The Nightingale Android presentation preference is missing: $preference_name" >&2
        exit 1
    }
done

if grep -RInE 'NSUbiquitousKeyValueStore|android\.accounts|AccountManager' \
    "$ios_presentation" "$android_presentation"
then
    echo "Nightingale presentation preferences must not use an account or cloud-sync store." >&2
    exit 1
fi

awk '
    /#if DEBUG/ { in_debug = 1 }
    /#endif/ { in_debug = 0 }
    in_debug && /NIGHTINGALE_TEST_RESET_PRESENTATION_PREFERENCES/ { found = 1 }
    END { exit found ? 0 : 1 }
' "$ios_presentation" || {
    echo "The Nightingale iOS presentation reset hook must remain DEBUG-only." >&2
    exit 1
}

awk '
    /#if DEBUG/ { in_debug = 1 }
    /#endif/ { in_debug = 0 }
    in_debug && /NIGHTINGALE_TEST_ACCESSIBILITY_TEXT_SIZE/ { found = 1 }
    END { exit found ? 0 : 1 }
' "$ios_screen" || {
    echo "The Nightingale iOS accessibility-size test hook must remain DEBUG-only." >&2
    exit 1
}

if grep -RInE 'clearForTesting|persistedKeysForTesting' "$ios_source" "$android_source"; then
    echo "Nightingale production presentation sources must not expose test-only mutation APIs." >&2
    exit 1
fi

grep -Fq -- 'traits.userInterfaceStyle == .dark' "$ios_visual" || {
    echo "The Nightingale iOS accent must remain appearance-aware." >&2
    exit 1
}

grep -Fq -- '.frame(minHeight: 44)' "$ios_screen" || {
    echo "The Nightingale iOS display-comfort controls must retain 44-point targets." >&2
    exit 1
}

grep -Fq -- 'testForestAccentMeetsTextContrastInLightAndDarkAppearances' "$ios_tests" || {
    echo "The Nightingale iOS light/dark contrast test is missing." >&2
    exit 1
}

grep -Fq -- 'testLargestTextLandscapeKeepsContentOrderedReachableAndTouchable' "$ios_ui_tests" || {
    echo "The Nightingale iOS largest-text landscape journey is missing." >&2
    exit 1
}

grep -Fq -- 'testBackgroundCatalogIsExactStableForLocalDayAndRotatesWithoutMotion' "$ios_tests" || {
    echo "The Nightingale iOS governed background catalog test is missing." >&2
    exit 1
}

for orientation in \
    "UIInterfaceOrientationLandscapeLeft" \
    "UIInterfaceOrientationLandscapeRight"
do
    grep -Fq -- "$orientation" "$ios_project" || {
        echo "The Nightingale iOS project is missing supported orientation: $orientation" >&2
        exit 1
    }
    grep -Fq -- "$orientation" "$ios_info" || {
        echo "The Nightingale iOS Info.plist is missing supported orientation: $orientation" >&2
        exit 1
    }
done

grep -Fq -- 'darkColorScheme(' "$android_visual" || {
    echo "The Nightingale Android dark color scheme is missing." >&2
    exit 1
}

grep -Fq -- 'darkTheme: Boolean = isSystemInDarkTheme()' "$android_visual" || {
    echo "The Nightingale Android theme must follow the system appearance." >&2
    exit 1
}

grep -Fq -- '.heightIn(min = 48.dp)' "$android_visual" || {
    echo "The Nightingale Android display-comfort rows must retain 48-dp targets." >&2
    exit 1
}

grep -Fq -- 'patientTextColorsMeetContrastInLightAndDarkSchemes' "$android_tests" || {
    echo "The Nightingale Android light/dark contrast test is missing." >&2
    exit 1
}

grep -Fq -- 'largestTextLandscapeKeepsContentOrderedReachableAndTouchable' "$android_ui_tests" || {
    echo "The Nightingale Android largest-text landscape journey is missing." >&2
    exit 1
}

grep -Fq -- 'backgroundCatalogIsExactStableForLocalDayAndWrapsDeterministically' "$android_tests" || {
    echo "The Nightingale Android governed background catalog test is missing." >&2
    exit 1
}

grep -Eq 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.nightingale$' "$ios_project" || {
    echo "The Nightingale iOS bundle identifier is missing or incorrect." >&2
    exit 1
}

grep -Eq 'applicationId = "net\.acumenus\.nightingale"' "$android_project" || {
    echo "The Nightingale Android application identifier is missing or incorrect." >&2
    exit 1
}

echo "Nightingale native product boundary verified"
