#!/usr/bin/env bash

set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
build_root="$(mktemp -d "${TMPDIR:-/tmp}/hummingbird-shared-fixture-decoder.XXXXXX")"
trap 'rm -rf "$build_root"' EXIT

swiftc \
  "$repo_root/hummingbird/iosApp/Hummingbird/DesignSystem/CapacityStatus.swift" \
  "$repo_root/hummingbird/iosApp/Hummingbird/Networking/ISO8601DateFormatter+Flexible.swift" \
  "$repo_root/hummingbird/iosApp/Hummingbird/Networking/Models.swift" \
  "$repo_root/hummingbird/iosApp/Hummingbird/Features/Flow/FlowModels.swift" \
  "$repo_root/hummingbird/iosApp/scripts/decode-shared-fixtures.swift" \
  -o "$build_root/hummingbird-fixture-decoder"

"$build_root/hummingbird-fixture-decoder" "$repo_root"
