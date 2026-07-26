#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <ios-app-root>" >&2
    exit 64
fi

app_root="$1"
project_name="Nightingale.xcodeproj"

[[ -f "$app_root/project.yml" && -d "$app_root/Nightingale" ]] || {
    echo "Incomplete Nightingale XcodeGen source at: $app_root" >&2
    exit 66
}

command -v xcodegen >/dev/null 2>&1 || {
    echo "xcodegen is required to verify the generated Nightingale project" >&2
    exit 69
}

# The generated project is intentionally reproducible from project.yml. Refresh it in the
# checkout, then compare it with a clean regeneration to catch generator/specification drift.
(
    cd "$app_root"
    xcodegen generate --spec project.yml --quiet
)

verification_root="$(mktemp -d)"
trap 'rm -rf "$verification_root"' EXIT

cp -R "$app_root" "$verification_root/iosApp"
(
    cd "$verification_root/iosApp"
    xcodegen generate --spec project.yml --quiet
)

diff -ru \
    --exclude="xcuserdata" \
    "$app_root/$project_name" \
    "$verification_root/iosApp/$project_name"

echo "Nightingale Xcode project matches project.yml"
