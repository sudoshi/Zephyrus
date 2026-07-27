#!/usr/bin/env python3
"""Verify the identity boundary of an exported iOS IPA without extracting it."""

from __future__ import annotations

import plistlib
import re
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath


BUNDLE_PATTERN = re.compile(r"[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+")
BUILD_PATTERN = re.compile(r"[0-9]+")


class VerificationError(ValueError):
    """The IPA does not satisfy the requested application identity."""


def verify_ipa(
    ipa_path: Path,
    expected_bundle_identifier: str,
    expected_build_number: str,
) -> dict[str, object]:
    if BUNDLE_PATTERN.fullmatch(expected_bundle_identifier) is None:
        raise VerificationError("expected bundle identifier is malformed")
    if BUILD_PATTERN.fullmatch(expected_build_number) is None:
        raise VerificationError("expected build number must contain decimal digits only")
    if not ipa_path.is_file():
        raise VerificationError(f"IPA does not exist: {ipa_path}")

    try:
        with zipfile.ZipFile(ipa_path) as archive:
            unsafe_paths = []
            info_paths = []
            for item in archive.infolist():
                path = PurePosixPath(item.filename)
                if (
                    item.filename.startswith("/")
                    or "\\" in item.filename
                    or ".." in path.parts
                ):
                    unsafe_paths.append(item.filename)
                if (
                    len(path.parts) == 3
                    and path.parts[0] == "Payload"
                    and path.parts[1].endswith(".app")
                    and path.parts[2] == "Info.plist"
                ):
                    info_paths.append(item.filename)

            if unsafe_paths:
                raise VerificationError(
                    f"IPA contains unsafe archive path(s): {sorted(unsafe_paths)!r}"
                )
            if len(info_paths) != 1:
                raise VerificationError(
                    "IPA must contain exactly one top-level application Info.plist; "
                    f"found {sorted(info_paths)!r}"
                )

            try:
                info = plistlib.loads(archive.read(info_paths[0]))
            except (KeyError, plistlib.InvalidFileException) as error:
                raise VerificationError(f"cannot parse application Info.plist: {error}") from error
    except (OSError, zipfile.BadZipFile) as error:
        raise VerificationError(f"cannot read IPA: {error}") from error

    actual_bundle_identifier = str(info.get("CFBundleIdentifier", ""))
    actual_build_number = str(info.get("CFBundleVersion", ""))
    marketing_version = str(info.get("CFBundleShortVersionString", ""))
    package_type = str(info.get("CFBundlePackageType", ""))

    if actual_bundle_identifier != expected_bundle_identifier:
        raise VerificationError(
            "bundle identifier mismatch: "
            f"expected {expected_bundle_identifier!r}, found {actual_bundle_identifier!r}"
        )
    if actual_build_number != expected_build_number:
        raise VerificationError(
            "build number mismatch: "
            f"expected {expected_build_number!r}, found {actual_build_number!r}"
        )
    if not marketing_version:
        raise VerificationError("CFBundleShortVersionString is missing")
    if package_type != "APPL":
        raise VerificationError(
            f"CFBundlePackageType must be 'APPL', found {package_type!r}"
        )

    return {
        "bundle_identifier": actual_bundle_identifier,
        "build_number": actual_build_number,
        "marketing_version": marketing_version,
        "info_path": info_paths[0],
    }


def make_test_ipa(
    path: Path,
    *,
    bundle_identifier: str = "net.acumenus.nightingale",
    build_number: str = "42",
    duplicate_app: bool = False,
) -> None:
    info = {
        "CFBundleIdentifier": bundle_identifier,
        "CFBundlePackageType": "APPL",
        "CFBundleShortVersionString": "0.1.0",
        "CFBundleVersion": build_number,
    }
    payload = plistlib.dumps(info, fmt=plistlib.FMT_BINARY)
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("Payload/Nightingale.app/Info.plist", payload)
        if duplicate_app:
            archive.writestr("Payload/Other.app/Info.plist", payload)


def run_self_test() -> None:
    with tempfile.TemporaryDirectory(prefix="nightingale-ipa-verifier-") as directory:
        root = Path(directory)
        valid = root / "valid.ipa"
        make_test_ipa(valid)
        result = verify_ipa(valid, "net.acumenus.nightingale", "42")
        if result["marketing_version"] != "0.1.0":
            raise AssertionError("valid IPA did not preserve the marketing version")

        mutations = {
            "wrong bundle": ("net.acumenus.other", "42", valid),
            "wrong build": ("net.acumenus.nightingale", "43", valid),
        }
        duplicate = root / "duplicate.ipa"
        make_test_ipa(duplicate, duplicate_app=True)
        mutations["duplicate app"] = (
            "net.acumenus.nightingale",
            "42",
            duplicate,
        )
        malformed = root / "malformed.ipa"
        malformed.write_bytes(b"not a zip")
        mutations["malformed archive"] = (
            "net.acumenus.nightingale",
            "42",
            malformed,
        )

        for label, (bundle, build, candidate) in mutations.items():
            try:
                verify_ipa(candidate, bundle, build)
            except VerificationError:
                continue
            raise AssertionError(f"negative self-test did not reject {label}")

    print("IPA identity verifier self-test passed: one positive and four negatives.")


def usage() -> str:
    return (
        "Usage: verify_ipa.py --self-test | "
        "verify_ipa.py <ipa> <expected-bundle-id> <expected-build-number>"
    )


def main(arguments: list[str]) -> int:
    if arguments == ["--self-test"]:
        run_self_test()
        return 0
    if len(arguments) != 3 or any(argument.startswith("--") for argument in arguments):
        print(usage(), file=sys.stderr)
        return 64

    try:
        result = verify_ipa(Path(arguments[0]), arguments[1], arguments[2])
    except VerificationError as error:
        print(f"IPA identity verification failed: {error}", file=sys.stderr)
        return 1

    print(
        "IPA identity verified: "
        f"{result['bundle_identifier']} "
        f"{result['marketing_version']} ({result['build_number']})."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
