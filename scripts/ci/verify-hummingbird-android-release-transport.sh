#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <release-apk>" >&2
    exit 64
fi

release_apk="$1"
[[ -f "$release_apk" ]] || {
    echo "Missing Android Release APK: $release_apk" >&2
    exit 66
}

android_sdk_root="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
[[ -n "$android_sdk_root" && -d "$android_sdk_root/build-tools" ]] || {
    echo "ANDROID_HOME or ANDROID_SDK_ROOT must identify an installed Android SDK" >&2
    exit 69
}

aapt2="$(
    find "$android_sdk_root/build-tools" -type f -name aapt2 -perm -111 -print \
        | sort \
        | tail -n 1
)"
[[ -n "$aapt2" ]] || {
    echo "Unable to locate aapt2 in the Android SDK" >&2
    exit 69
}

manifest_dump="$("$aapt2" dump xmltree "$release_apk" --file AndroidManifest.xml)"

grep -Fq 'android:usesCleartextTraffic' <<<"$manifest_dump" || {
    echo "Release manifest does not declare usesCleartextTraffic" >&2
    exit 1
}
grep -Eq 'android:usesCleartextTraffic[^=]*=false' <<<"$manifest_dump" || {
    echo "Release manifest permits cleartext traffic" >&2
    exit 1
}
grep -Fq 'android:networkSecurityConfig' <<<"$manifest_dump" || {
    echo "Release manifest is not bound to a Network Security Configuration" >&2
    exit 1
}

resource_dump="$("$aapt2" dump resources "$release_apk")"
network_policy_path="$(
    awk '
        /resource .* xml\/network_security_config$/ {
            found = 1
            next
        }
        found && /\(file\)/ {
            for (field = 1; field <= NF; field++) {
                if ($field ~ /^res\/.*\.xml$/) {
                    print $field
                    exit
                }
            }
        }
    ' <<<"$resource_dump"
)"
[[ -n "$network_policy_path" ]] || {
    echo "Release APK does not package network_security_config" >&2
    exit 1
}

network_policy_dump="$(
    "$aapt2" dump xmltree "$release_apk" --file "$network_policy_path"
)"
grep -Eq 'A: cleartextTrafficPermitted=false' <<<"$network_policy_dump" || {
    echo "Packaged Release network policy does not deny cleartext" >&2
    exit 1
}
[[ "$(grep -Ec 'A: src=\"system\"' <<<"$network_policy_dump")" -eq 1 ]] || {
    echo "Packaged Release network policy must contain exactly one system trust anchor" >&2
    exit 1
}
if grep -Eq 'A: src=\"user\"|E: pin-set|E: domain-config' <<<"$network_policy_dump"; then
    echo "Packaged Release network policy contains an ungoverned trust or domain override" >&2
    exit 1
fi

dex_strings="$(mktemp)"
trap 'rm -f "$dex_strings"' EXIT
unzip -p "$release_apk" 'classes*.dex' | strings >"$dex_strings"

grep -Fq 'zephyrus.acumenus.net' "$dex_strings" || {
    echo "Release bytecode is not bound to the approved production hostname" >&2
    exit 1
}
if grep -Eq \
    'http://(10\.0\.2\.2|localhost|127\.0\.0\.1)(:[0-9]+)?|ws://(10\.0\.2\.2|localhost|127\.0\.0\.1)(:[0-9]+)?' \
    "$dex_strings"; then
    echo "Release bytecode contains a Debug cleartext origin" >&2
    exit 1
fi

echo "Hummingbird Android Release transport artifact verified"
