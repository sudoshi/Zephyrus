#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <nightingale-release-app-bundle>" >&2
    exit 64
fi

app_bundle="$1"
info_plist="$app_bundle/Info.plist"
[[ -d "$app_bundle" && -f "$info_plist" ]] || {
    echo "Missing Nightingale iOS Release application bundle: $app_bundle" >&2
    exit 66
}

python3 - "$app_bundle" <<'PY'
from __future__ import annotations

import plistlib
import re
import subprocess
import sys
import hashlib
from pathlib import Path


app_bundle = Path(sys.argv[1])
info_plist = app_bundle / "Info.plist"


def fail(message: str) -> None:
    raise SystemExit(f"Nightingale iOS Release artifact violation: {message}")


try:
    with info_plist.open("rb") as stream:
        info = plistlib.load(stream)
except (OSError, plistlib.InvalidFileException) as error:
    fail(f"cannot read Info.plist: {error}")

expected_values = {
    "CFBundleIdentifier": "net.acumenus.nightingale",
    "CFBundleDisplayName": "Nightingale",
    "CFBundleShortVersionString": "0.1.0",
    "CFBundleVersion": "1",
    "MinimumOSVersion": "17.0",
}
for key, expected in expected_values.items():
    if str(info.get(key)) != expected:
        fail(f"{key} changed: expected {expected!r}, found {info.get(key)!r}")

expected_orientations = {
    "UIInterfaceOrientationPortrait",
    "UIInterfaceOrientationLandscapeLeft",
    "UIInterfaceOrientationLandscapeRight",
}
orientations = set(info.get("UISupportedInterfaceOrientations", []))
if orientations != expected_orientations:
    fail(
        f"supported orientations changed: expected {sorted(expected_orientations)}, "
        f"found {sorted(orientations)}"
    )

for prohibited_key in (
    "NSAppTransportSecurity",
    "CFBundleURLTypes",
    "NSBonjourServices",
    "NSLocalNetworkUsageDescription",
    "UIBackgroundModes",
):
    if prohibited_key in info:
        fail(f"Info.plist contains unapproved key: {prohibited_key}")

executable_name = info.get("CFBundleExecutable")
if not isinstance(executable_name, str) or not executable_name:
    fail("CFBundleExecutable is missing")
executable = app_bundle / executable_name
if not executable.is_file():
    fail(f"application executable is missing: {executable}")

for prohibited_path in (
    app_bundle / "embedded.mobileprovision",
    app_bundle / "PlugIns",
    app_bundle / "Watch",
    app_bundle / "AppClips",
):
    if prohibited_path.exists():
        fail(f"unapproved bundled surface exists: {prohibited_path.name}")

frameworks = app_bundle / "Frameworks"
if frameworks.exists() and any(frameworks.iterdir()):
    fail("Release bundle contains an unapproved embedded framework or dylib")

privacy_manifests = sorted(
    item.relative_to(app_bundle).as_posix()
    for item in app_bundle.rglob("*.xcprivacy")
    if item.is_file()
)
if privacy_manifests != ["PrivacyInfo.xcprivacy"]:
    fail(
        "privacy-manifest inventory changed: "
        f"expected ['PrivacyInfo.xcprivacy'], found {privacy_manifests}"
    )
try:
    with (app_bundle / "PrivacyInfo.xcprivacy").open("rb") as stream:
        privacy_manifest = plistlib.load(stream)
except (OSError, plistlib.InvalidFileException) as error:
    fail(f"cannot read PrivacyInfo.xcprivacy: {error}")
expected_privacy_manifest = {
    "NSPrivacyAccessedAPITypes": [
        {
            "NSPrivacyAccessedAPIType":
                "NSPrivacyAccessedAPICategoryUserDefaults",
            "NSPrivacyAccessedAPITypeReasons": ["CA92.1"],
        }
    ],
    "NSPrivacyCollectedDataTypes": [],
    "NSPrivacyTracking": False,
}
if privacy_manifest != expected_privacy_manifest:
    fail(
        "PrivacyInfo.xcprivacy changed from the exact offline foundation "
        f"declaration: {privacy_manifest!r}"
    )

expected_background_hashes = {
    "nightingale_background_01.jpg":
        "4a741d9d3add77eac8aad8071bf3c9945bbd2ce4aa0d93b0daa79efe166b30b4",
    "nightingale_background_02.jpg":
        "6f2888ae489e2ca2f268065fc7f3f029a051921b4290200e740e455c17cc3510",
    "nightingale_background_03.jpg":
        "760c01a4ee1a6830d8a975b5626dd4e452702639a562be875dd2e7838d5334d4",
    "nightingale_background_04.jpg":
        "16addb872da986ee99aa3de4db4715f4ff8dc945170934a33eb1192fe3bfb2a3",
    "nightingale_background_05.jpg":
        "339ab8239cab04fa6cac373a00dc6993e30a6b3786d35e065b3e810ce6f52e8d",
    "nightingale_background_06.jpg":
        "e6d9d06cf85ef9360186762e9c0ce0c2297cd6ec445f8409eaa5df49b99086d9",
    "nightingale_background_07.jpg":
        "024349a43146b2682af268f6ae4abe14be7725b04456eaf478cda00869086f2c",
}
actual_background_names = sorted(
    item.name
    for item in app_bundle.iterdir()
    if item.is_file() and item.name.startswith("nightingale_background_")
)
if actual_background_names != sorted(expected_background_hashes):
    fail(
        "background resource inventory changed: "
        f"expected {sorted(expected_background_hashes)}, "
        f"found {actual_background_names}"
    )
for file_name, expected_hash in expected_background_hashes.items():
    try:
        actual_hash = hashlib.sha256((app_bundle / file_name).read_bytes()).hexdigest()
    except OSError as error:
        fail(f"cannot read background resource {file_name}: {error}")
    if actual_hash != expected_hash:
        fail(
            f"background resource {file_name} changed: "
            f"expected {expected_hash}, found {actual_hash}"
        )

try:
    executable_bytes = executable.read_bytes()
except OSError as error:
    fail(f"cannot read executable: {error}")

for token in (
    b"/api/",
    b"acumenus.net",
    b"Hummingbird",
    b"net.acumenus.hummingbird",
    b"NIGHTINGALE_TEST_",
    b"http://localhost",
    b"https://localhost",
    b"127.0.0.1",
    b"ws://",
    b"wss://",
):
    if token in executable_bytes:
        fail(f"Release executable contains forbidden token: {token.decode()}")

linked = subprocess.run(
    ["xcrun", "otool", "-L", str(executable)],
    check=False,
    capture_output=True,
    text=True,
)
if linked.returncode != 0:
    fail(f"otool exited {linked.returncode}: {linked.stdout}{linked.stderr}")

dependencies = []
for line in linked.stdout.splitlines()[1:]:
    match = re.match(r"\s+(\S+)\s+\(", line)
    if match:
        dependencies.append(match.group(1))
for dependency in dependencies:
    if not (
        dependency.startswith("/System/Library/")
        or dependency.startswith("/usr/lib/")
    ):
        fail(f"executable links an unapproved non-system dependency: {dependency}")

print(
    "Nightingale iOS Release artifact verified: exact identity/version/"
    "orientations, no network/deep-link/test hook, no embedded extension/"
    "framework/provisioning profile, system-only linked dependencies, exact "
    "offline privacy manifest, and seven exact governed background JPEGs."
)
PY
