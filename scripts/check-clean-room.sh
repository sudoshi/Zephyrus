#!/usr/bin/env bash
# Fails if the AGPL 'ocelescope' package leaks into a dependency manifest or import.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=$(grep -RInE '(^|[^a-z])ocelescope' \
  arena/requirements.txt \
  arena/app \
  composer.json package.json 2>/dev/null \
  | grep -viE 'CLEAN-ROOM|clean-room|# reference|inspired by' || true)

if [ -n "$hits" ]; then
  echo "❌ clean-room violation: 'ocelescope' referenced as code/dependency:"
  echo "$hits"
  exit 1
fi
echo "✅ clean-room: no ocelescope dependency or import found"
