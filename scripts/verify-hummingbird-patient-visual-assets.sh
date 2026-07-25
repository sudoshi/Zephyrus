#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

sha256_for() {
    local path="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{print $1}'
    else
        shasum -a 256 "$path" | awk '{print $1}'
    fi
}

verify_asset() {
    local relative_path="$1"
    local expected_sha256="$2"
    local absolute_path="$REPOSITORY_ROOT/$relative_path"

    [[ -f "$absolute_path" ]] || {
        echo "Missing Hummingbird Patient visual asset: $relative_path" >&2
        exit 1
    }

    local actual_sha256
    actual_sha256="$(sha256_for "$absolute_path")"
    [[ "$actual_sha256" == "$expected_sha256" ]] || {
        echo "Unexpected SHA-256 for $relative_path" >&2
        echo "Expected: $expected_sha256" >&2
        echo "Actual:   $actual_sha256" >&2
        exit 1
    }
}

# Each source and app-local copy is pinned here and in the human-reviewed
# provenance ledger. This verifier proves technical lineage only; it cannot
# establish a copyright license, attribution obligation, or release approval.
verify_asset "public/images/auth/hummingbirds/hummingbird-01.jpg" "9230a368fd2c0cab308280425b35b645b2a505871277a8136ec1c199cd53d6dc"
verify_asset "public/images/auth/hummingbirds/hummingbird-06.jpg" "65b640f035d8527d879f5b354e36eabb78ec3c9ca5c915356e05912a4aadc008"
verify_asset "public/images/auth/hummingbirds/hummingbird-07.jpg" "b545c010ed87c9ee4150c616b6031f2e66b329bc0e339fbe4ac735d6f4236988"
verify_asset "public/images/auth/hummingbirds/hummingbird-12.jpg" "38e37231c4a14e3223823bbee531590aadd982bc7994c6538b02d291670b729d"

verify_asset "hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientCalmGreen.imageset/hummingbird-01.jpg" "9230a368fd2c0cab308280425b35b645b2a505871277a8136ec1c199cd53d6dc"
verify_asset "hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientAiryFlight.imageset/hummingbird-06.jpg" "65b640f035d8527d879f5b354e36eabb78ec3c9ca5c915356e05912a4aadc008"
verify_asset "hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientCareConnection.imageset/hummingbird-07.jpg" "b545c010ed87c9ee4150c616b6031f2e66b329bc0e339fbe4ac735d6f4236988"
verify_asset "hummingbird/iosPatientApp/HummingbirdPatient/Assets.xcassets/PatientWarmMotion.imageset/hummingbird-12.jpg" "38e37231c4a14e3223823bbee531590aadd982bc7994c6538b02d291670b729d"

verify_asset "hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_calm_green.jpg" "d5dc322481721ac5a29a6fe34c777bf727385dcd9d3d5aad1674182b5ccbec2d"
verify_asset "hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_airy_flight.jpg" "a400063289b0ec8b62a7059c3e1e10618da545fe50871b2f3ddb35935e958117"
verify_asset "hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_care_connection.jpg" "b70ba3d0626f27bd29311a3e70a8e306768fd3c316cfae2554765b89603dcfbd"
verify_asset "hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_warm_motion.jpg" "b3f127a8fbd2754d69adc12432fc01f22a9096ee8850b8fdab10e769e5105fb8"

echo "Hummingbird Patient visual assets verified: 12 SHA-256 pins match the provenance ledger."
