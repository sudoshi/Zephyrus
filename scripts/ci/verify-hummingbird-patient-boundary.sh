#!/usr/bin/env bash

set -euo pipefail

usage() {
    echo "Usage: $0 source <patient-app-root-or-file> | artifact <apk-or-app-bundle>" >&2
    exit 64
}

[[ $# -eq 2 ]] || usage

scan_kind="$1"
scan_target="$2"

[[ -e "$scan_target" ]] || {
    echo "Patient boundary scan target does not exist: $scan_target" >&2
    exit 66
}

case "$scan_kind" in
    source | artifact) ;;
    *) usage ;;
esac

# The patient products have one fail-closed HTTP namespace. Source and Android
# DEX/resource scans reject every /api/* path outside /api/patient/v1. iOS
# Mach-O string sections receive the same exact check, while the whole binary is
# additionally checked for staff roots and unambiguous multi-segment API paths.
# Parsing string sections avoids treating printable ARM instruction bytes as a
# route. APKs are read through Python's ZIP implementation instead of extracted
# with unzip; Android resource archives can contain duplicate entry names, which
# makes unzip interactive and non-deterministic in CI.
python3 - "$scan_kind" "$scan_target" <<'PY'
from __future__ import annotations

import re
import subprocess
import sys
import zipfile
from pathlib import Path


scan_kind = sys.argv[1]
scan_target = Path(sys.argv[2])
allowed_root = b"/api/patient/v1"
known_staff_prefixes = (b"/api/auth", b"/api/mobile")

# Tests deliberately contain negative-control staff paths. Generated/cache
# trees likewise are not product source. Release artifacts are scanned in full
# below, so excluding these trees cannot hide compiled production behavior.
source_excluded_components = {
    ".build",
    ".git",
    ".gradle",
    ".swiftpm",
    "DerivedData",
    "HummingbirdPatientTests",
    "HummingbirdPatientUITests",
    "androidTest",
    "build",
    "test",
    "testDebug",
    "testRelease",
    "xcuserdata",
}

# Require an endpoint boundary before /api/ so package/class descriptors such
# as com/google/api/client are not mistaken for network routes. Stop at bytes
# that cannot be part of a URI path/query/fragment. This remains useful for
# compiled binaries because endpoint literals are stored as ASCII or UTF-8 byte
# sequences even when the surrounding file is not textual.
api_path_pattern = re.compile(
    rb"(?<![A-Za-z0-9._~%/-])/api/[A-Za-z0-9._~!$&'()*+,;=:@%/?#\[\]-]*"
)
absolute_api_url_pattern = re.compile(
    rb"https?://[A-Za-z0-9._~%:\[\]-]+"
    rb"(?P<api_path>/api/[A-Za-z0-9._~!$&'()*+,;=:@%/?#\[\]-]*)"
)


def is_allowed(candidate: bytes) -> bool:
    if candidate == allowed_root:
        return True
    return any(
        candidate.startswith(allowed_root + separator)
        for separator in (b"/", b"?", b"#")
    )


violations: set[tuple[str, str]] = set()
allowed_observations = 0


def scan_bytes(label: str, payload: bytes, *, exact: bool) -> None:
    global allowed_observations

    candidates = [match.group(0) for match in api_path_pattern.finditer(payload)]
    candidates.extend(
        match.group("api_path") for match in absolute_api_url_pattern.finditer(payload)
    )
    for candidate in candidates:
        if is_allowed(candidate):
            allowed_observations += 1
            continue
        is_unambiguous_compiled_path = candidate.count(b"/") >= 3
        is_known_staff_path = candidate.startswith(known_staff_prefixes)
        if exact or is_known_staff_path or is_unambiguous_compiled_path:
            violations.add((label, candidate.decode("ascii", errors="backslashreplace")))


def read_mach_o_cstrings(path: Path) -> bytes:
    result = subprocess.run(
        ["xcrun", "otool", "-s", "__TEXT", "__cstring", str(path)],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        return b""

    output = bytearray()
    for line in result.stdout.splitlines():
        parts = line.split()
        if (
            parts
            and re.fullmatch(r"[0-9A-Fa-f]+", parts[0])
            and all(re.fullmatch(r"[0-9A-Fa-f]{2}", part) for part in parts[1:])
        ):
            output.extend(bytes.fromhex("".join(parts[1:])))
        else:
            # Do not join the final cstring of one universal-binary architecture
            # to the first cstring of the next architecture.
            output.extend(b"\0")
    return bytes(output)


mach_o_magics = {
    b"\xca\xfe\xba\xbe",
    b"\xca\xfe\xba\xbf",
    b"\xce\xfa\xed\xfe",
    b"\xcf\xfa\xed\xfe",
    b"\xbe\xba\xfe\xca",
    b"\xbf\xba\xfe\xca",
    b"\xfe\xed\xfa\xce",
    b"\xfe\xed\xfa\xcf",
}


def scan_path_tree(root: Path, *, scan_mode: str) -> None:
    files = (
        [root]
        if root.is_file()
        else sorted(path for path in root.rglob("*") if path.is_file())
    )
    for path in files:
        relative = path.name if root.is_file() else str(path.relative_to(root))
        if scan_mode == "source" and any(
            part in source_excluded_components for part in path.relative_to(root).parts
        ):
            continue
        try:
            payload = path.read_bytes()
        except OSError as error:
            raise SystemExit(f"Unable to read patient boundary scan input {path}: {error}") from error

        if scan_mode == "ios-artifact" and payload[:4] in mach_o_magics:
            # Known staff roots and multi-segment paths are still searched in
            # the complete binary. Exact allowlisting is applied to the Mach-O
            # cstring section, where actual endpoint literals live.
            scan_bytes(relative, payload, exact=False)
            scan_bytes(relative, read_mach_o_cstrings(path), exact=True)
        else:
            scan_bytes(relative, payload, exact=True)


if scan_kind == "source":
    scan_path_tree(scan_target, scan_mode="source")
elif scan_target.is_file() and scan_target.suffix == ".apk":
    try:
        with zipfile.ZipFile(scan_target) as archive:
            for entry in archive.infolist():
                if entry.is_dir():
                    continue
                with archive.open(entry) as stream:
                    scan_bytes(entry.filename, stream.read(), exact=True)
    except (OSError, zipfile.BadZipFile) as error:
        raise SystemExit(f"Unable to inspect patient APK {scan_target}: {error}") from error
elif scan_target.is_dir() and scan_target.suffix == ".app":
    scan_path_tree(scan_target, scan_mode="ios-artifact")
else:
    raise SystemExit(f"Unsupported patient release artifact: {scan_target}")

if violations:
    print(
        "Patient product boundary violation: only /api/patient/v1 is allowed; "
        "found:",
        file=sys.stderr,
    )
    for label, candidate in sorted(violations):
        print(f"  {label}: {candidate}", file=sys.stderr)
    raise SystemExit(1)

if allowed_observations == 0:
    raise SystemExit(
        "Patient product boundary scan found no /api/patient/v1 path; "
        "the target is incomplete or the scanner did not inspect the endpoint inventory"
    )
PY

echo "Hummingbird patient $scan_kind boundary verified: $scan_target"
