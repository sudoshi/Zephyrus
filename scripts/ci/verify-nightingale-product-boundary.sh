#!/usr/bin/env bash

set -euo pipefail

repo_root="${1:-.}"
ios_source="$repo_root/nightingale/iosApp/Nightingale"
android_source="$repo_root/nightingale/androidApp/app/src/main"
ios_project="$repo_root/nightingale/iosApp/project.yml"
android_project="$repo_root/nightingale/androidApp/app/build.gradle.kts"
android_manifest="$android_source/AndroidManifest.xml"

for required_path in \
    "$ios_source" \
    "$android_source" \
    "$ios_project" \
    "$android_project" \
    "$android_manifest"
do
    [[ -e "$required_path" ]] || {
        echo "Missing Nightingale boundary input: $required_path" >&2
        exit 66
    }
done

for forbidden in \
    "net.acumenus.hummingbird" \
    "hummingbird.patient" \
    "/api/mobile" \
    "/api/auth"
do
    if rg -n --fixed-strings "$forbidden" "$ios_source" "$android_source"; then
        echo "Nightingale source contains forbidden staff or legacy patient token: $forbidden" >&2
        exit 1
    fi
done

if rg -n --fixed-strings "android.permission.INTERNET" "$android_manifest"; then
    echo "The Nightingale foundation must not request Android network access." >&2
    exit 1
fi

if rg -n 'URLSession|NSURLSession|OkHttpClient|java\.net\.' "$ios_source" "$android_source"; then
    echo "The Nightingale foundation must not contain a network client." >&2
    exit 1
fi

rg -q 'PRODUCT_BUNDLE_IDENTIFIER: net\.acumenus\.nightingale$' "$ios_project" || {
    echo "The Nightingale iOS bundle identifier is missing or incorrect." >&2
    exit 1
}

rg -q 'applicationId = "net\.acumenus\.nightingale"' "$android_project" || {
    echo "The Nightingale Android application identifier is missing or incorrect." >&2
    exit 1
}

echo "Nightingale native product boundary verified"
