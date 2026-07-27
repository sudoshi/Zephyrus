#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <nightingale-release-apk>" >&2
    exit 64
fi

release_apk="$1"
[[ -f "$release_apk" ]] || {
    echo "Missing Nightingale Android Release APK: $release_apk" >&2
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
apksigner="$(
    find "$android_sdk_root/build-tools" -type f -name apksigner -perm -111 -print \
        | sort \
        | tail -n 1
)"
[[ -n "$aapt2" && -n "$apksigner" ]] || {
    echo "Unable to locate aapt2 and apksigner in the Android SDK" >&2
    exit 69
}

python3 - "$release_apk" "$aapt2" "$apksigner" <<'PY'
from __future__ import annotations

import re
import subprocess
import sys
import zipfile
import hashlib
from pathlib import Path


release_apk = Path(sys.argv[1])
aapt2 = sys.argv[2]
apksigner = sys.argv[3]


def fail(message: str) -> None:
    raise SystemExit(f"Nightingale Android Release artifact violation: {message}")


def command(*arguments: str) -> str:
    result = subprocess.run(
        arguments,
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        fail(
            f"command exited {result.returncode}: {' '.join(arguments)}\n"
            f"{result.stdout}{result.stderr}"
        )
    return result.stdout


badging = command(aapt2, "dump", "badging", str(release_apk))
for expected in (
    "package: name='net.acumenus.nightingale'",
    "versionCode='1'",
    "versionName='0.1.0-foundation'",
    "minSdkVersion:'26'",
    "targetSdkVersion:'35'",
    "application-label:'Nightingale'",
):
    if expected not in badging:
        fail(f"badging is missing {expected}")
if "application-debuggable" in badging:
    fail("Release APK is debuggable")

permissions = command(aapt2, "dump", "permissions", str(release_apk))
uses_permissions = set(
    re.findall(r"^uses-permission: name='([^']+)'$", permissions, re.MULTILINE)
)
expected_uses_permissions = {
    "net.acumenus.nightingale.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION"
}
if uses_permissions != expected_uses_permissions:
    fail(
        "permission set changed: "
        f"expected {sorted(expected_uses_permissions)}, found {sorted(uses_permissions)}"
    )
if "android.permission.INTERNET" in permissions:
    fail("Release APK requests network access")

manifest = command(
    aapt2,
    "dump",
    "xmltree",
    str(release_apk),
    "--file",
    "AndroidManifest.xml",
)
for expected in (
    'package="net.acumenus.nightingale"',
    "android:allowBackup",
    "=false",
    "android:fullBackupContent",
    "android:dataExtractionRules",
    "android:networkSecurityConfig",
    "android:usesCleartextTraffic",
    'android:name(0x01010003)="net.acumenus.nightingale.MainActivity"',
    'android:name(0x01010003)="androidx.profileinstaller.ProfileInstallReceiver"',
    'android:permission(0x01010006)="android.permission.DUMP"',
):
    if expected not in manifest:
        fail(f"manifest is missing required boundary: {expected}")

if "android:debuggable" in manifest or "android:testOnly" in manifest:
    fail("Release manifest contains a Debug/test-only application flag")
if not re.search(r"android:usesCleartextTraffic[^\n]*=false", manifest):
    fail("Release manifest does not explicitly deny cleartext traffic")
if (
    "android.intent.action.VIEW" in manifest
    or "android.intent.category.BROWSABLE" in manifest
):
    fail("Release manifest contains an unapproved deep-link surface")
if re.search(r"^\s+E: service\b", manifest, re.MULTILINE):
    fail("Release manifest contains an unapproved service")
if len(re.findall(r"android:exported[^=]*=true", manifest)) != 2:
    fail("Release manifest exported-component count changed")

resources = command(aapt2, "dump", "resources", str(release_apk))
if "xml/network_security_config" not in resources:
    fail("compiled resources are missing xml/network_security_config")
for sequence in range(1, 8):
    resource_name = f"drawable/nightingale_background_{sequence:02d}"
    if resource_name not in resources:
        fail(f"compiled resources are missing {resource_name}")

signing = subprocess.run(
    [apksigner, "verify", "--verbose", str(release_apk)],
    check=False,
    capture_output=True,
    text=True,
)
if signing.returncode == 0:
    fail("CI foundation Release APK is unexpectedly signed")

expected_native_libraries = {
    "lib/arm64-v8a/libandroidx.graphics.path.so",
    "lib/armeabi-v7a/libandroidx.graphics.path.so",
    "lib/x86/libandroidx.graphics.path.so",
    "lib/x86_64/libandroidx.graphics.path.so",
}
expected_background_hashes = {
    "024349a43146b2682af268f6ae4abe14be7725b04456eaf478cda00869086f2c",
    "16addb872da986ee99aa3de4db4715f4ff8dc945170934a33eb1192fe3bfb2a3",
    "339ab8239cab04fa6cac373a00dc6993e30a6b3786d35e065b3e810ce6f52e8d",
    "4a741d9d3add77eac8aad8071bf3c9945bbd2ce4aa0d93b0daa79efe166b30b4",
    "6f2888ae489e2ca2f268065fc7f3f029a051921b4290200e740e455c17cc3510",
    "760c01a4ee1a6830d8a975b5626dd4e452702639a562be875dd2e7838d5334d4",
    "e6d9d06cf85ef9360186762e9c0ce0c2297cd6ec445f8409eaa5df49b99086d9",
}
forbidden_binary_tokens = (
    b"/api/",
    b"acumenus.net",
    b"Hummingbird",
    b"net.acumenus.hummingbird",
    b"NIGHTINGALE_TEST_",
    b"http://localhost",
    b"https://localhost",
    b"10.0.2.2",
    b"127.0.0.1",
    b"ws://",
    b"wss://",
)

try:
    with zipfile.ZipFile(release_apk) as archive:
        names = set(archive.namelist())
        dex_names = sorted(name for name in names if re.fullmatch(r"classes\d*\.dex", name))
        if dex_names != ["classes.dex", "classes2.dex"]:
            fail(f"DEX inventory changed: {dex_names}")

        native_libraries = {
            name for name in names if name.startswith("lib/") and name.endswith(".so")
        }
        if native_libraries != expected_native_libraries:
            fail(
                "embedded native-library inventory changed: "
                f"expected {sorted(expected_native_libraries)}, "
                f"found {sorted(native_libraries)}"
            )

        jpeg_names = sorted(
            name
            for name in names
            if name.startswith("res/") and name.lower().endswith((".jpg", ".jpeg"))
        )
        if len(jpeg_names) != 7:
            fail(
                "packaged JPEG inventory changed: "
                f"expected 7 governed backgrounds, found {jpeg_names}"
            )
        background_hashes = {
            hashlib.sha256(archive.read(name)).hexdigest()
            for name in jpeg_names
        }
        if background_hashes != expected_background_hashes:
            fail(
                "packaged background identities changed: "
                f"expected {sorted(expected_background_hashes)}, "
                f"found {sorted(background_hashes)}"
            )

        dex_payload = b"".join(archive.read(name) for name in dex_names)
except (OSError, zipfile.BadZipFile) as error:
    fail(f"cannot inspect APK: {error}")

for token in forbidden_binary_tokens:
    if token in dex_payload:
        fail(f"Release bytecode contains forbidden token: {token.decode()}")

print(
    "Nightingale Android Release artifact verified: exact identity, one "
    "package-local permission, no network/deep link/test hook, explicit "
    "cleartext denial, unsigned CI state, two DEX files, four expected "
    "AndroidX native libraries, and seven exact governed background JPEGs."
)
PY
