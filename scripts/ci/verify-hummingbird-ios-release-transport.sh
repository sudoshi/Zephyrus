#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <release-app-bundle> [staff|patient]" >&2
    exit 64
fi

app_bundle="$1"
product="${2:-staff}"
info_plist="$app_bundle/Info.plist"

[[ -d "$app_bundle" && -f "$info_plist" ]] || {
    echo "Missing iOS Release application bundle: $app_bundle" >&2
    exit 66
}
[[ "$product" == "staff" || "$product" == "patient" ]] || {
    echo "Product must be staff or patient" >&2
    exit 64
}

executable_name="$(
    /usr/libexec/PlistBuddy -c "Print :CFBundleExecutable" "$info_plist"
)"
executable_path="$app_bundle/$executable_name"
[[ -f "$executable_path" ]] || {
    echo "Release application executable is missing: $executable_path" >&2
    exit 66
}

if /usr/libexec/PlistBuddy -c "Print :NSAppTransportSecurity" "$info_plist" \
    >/dev/null 2>&1; then
    echo "Release Info.plist contains an App Transport Security exception" >&2
    exit 1
fi

if /usr/libexec/PlistBuddy -c "Print :NSAllowsArbitraryLoads" "$info_plist" \
    >/dev/null 2>&1; then
    echo "Release Info.plist enables arbitrary network loads" >&2
    exit 1
fi

if [[ "$product" == "patient" ]]; then
    patient_enabled="$(
        /usr/libexec/PlistBuddy -c "Print :HBPPatientAPIEnabled" "$info_plist"
    )"
    patient_base_url="$(
        /usr/libexec/PlistBuddy -c "Print :HBPPatientAPIBaseURL" "$info_plist"
    )"
    [[ "$patient_enabled" == "false" && -z "$patient_base_url" ]] || {
        echo "CI patient Release artifact must remain default-off with no endpoint in Info.plist" >&2
        exit 1
    }
fi

release_strings="$(strings "$executable_path")"
grep -Fq 'zephyrus.acumenus.net' <<<"$release_strings" || {
    echo "Release executable is not bound to the approved production hostname" >&2
    exit 1
}
if grep -Eq 'http://localhost:8001|ws://localhost:8080' <<<"$release_strings"; then
    echo "Release executable contains a staff Debug cleartext origin" >&2
    exit 1
fi

echo "Hummingbird ${product} iOS Release transport artifact verified"
