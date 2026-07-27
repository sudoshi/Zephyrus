#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <ios-patient-app-root>" >&2
    exit 64
fi

patient_root="$1"
project_name="HummingbirdPatient.xcodeproj"

[[ -f "$patient_root/project.yml" && -d "$patient_root/$project_name" ]] || {
    echo "Incomplete Hummingbird patient Xcode project at: $patient_root" >&2
    exit 66
}

command -v xcodegen >/dev/null 2>&1 || {
    echo "xcodegen is required to verify the generated patient project" >&2
    exit 69
}

verification_root="$(mktemp -d)"
trap 'rm -rf "$verification_root"' EXIT

cp -R "$patient_root" "$verification_root/iosPatientApp"
(
    cd "$verification_root/iosPatientApp"
    xcodegen generate --spec project.yml --quiet
)

diff -ru \
    --exclude="xcuserdata" \
    "$patient_root/$project_name" \
    "$verification_root/iosPatientApp/$project_name"

echo "Hummingbird patient Xcode project matches project.yml"
