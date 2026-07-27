#!/usr/bin/env bash
#
# archive-testflight.sh — kept as the historical entry point for Hummingbird.
#
# Superseded by ./testflight.sh at the repo root, which ships BOTH iOS apps
# (Zephyrus-Hummingbird and Zephyrus-Nightingale) from one place and reads its
# credentials from .appledeploy. This is now a thin shim so there is only one
# implementation to keep correct.
#
#   ./testflight.sh ship hummingbird [--build N] [--no-upload] [--wait] [--to GROUP]
#
# See docs/hummingbird/TESTFLIGHT.md.
#
# Usage:  ./archive-testflight.sh [BUILD_NUMBER]
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "note: archive-testflight.sh now delegates to ./testflight.sh ship hummingbird" >&2
echo "      (see docs/hummingbird/TESTFLIGHT.md)" >&2
echo >&2

if [ $# -gt 0 ]; then
  exec "$REPO_ROOT/testflight.sh" ship hummingbird --build "$1"
fi
exec "$REPO_ROOT/testflight.sh" ship hummingbird
