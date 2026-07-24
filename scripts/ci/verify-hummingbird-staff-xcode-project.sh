#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <ios-staff-app-root>" >&2
    exit 64
fi

staff_root="$1"
project_name="Hummingbird.xcodeproj"

[[ -f "$staff_root/project.yml" && -d "$staff_root/Hummingbird" ]] || {
    echo "Incomplete Hummingbird staff XcodeGen source at: $staff_root" >&2
    exit 66
}

command -v xcodegen >/dev/null 2>&1 || {
    echo "xcodegen is required to verify the generated staff project" >&2
    exit 69
}

# The staff .xcodeproj is intentionally ignored. Generate it in the checkout so
# every subsequent xcodebuild invocation uses the committed specification and
# sources, including in a pristine CI clone.
(
    cd "$staff_root"
    xcodegen generate --spec project.yml --quiet
)

verification_root="$(mktemp -d)"
trap 'rm -rf "$verification_root"' EXIT

cp -R "$staff_root" "$verification_root/iosApp"
(
    cd "$verification_root/iosApp"
    xcodegen generate --spec project.yml --quiet
)

diff -ru \
    --exclude="xcuserdata" \
    "$staff_root/$project_name" \
    "$verification_root/iosApp/$project_name"

echo "Hummingbird staff Xcode project matches project.yml"
