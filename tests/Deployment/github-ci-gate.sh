#!/bin/bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERIFY="$PROJECT_ROOT/scripts/deployment/verify-github-ci.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/zephyrus-ci-gate-test.XXXXXX")"
COMMIT="1111111111111111111111111111111111111111"

cleanup() {
    rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

write_fixture() {
    local status="$1"
    local conclusion="$2"

    sed \
        -e "s/STATUS_VALUE/$status/" \
        -e "s/CONCLUSION_VALUE/$conclusion/" \
        > "$TEST_ROOT/runs.json" <<JSON
{
  "workflow_runs": [
    {
      "head_sha": "$COMMIT",
      "head_branch": "main",
      "event": "push",
      "status": "STATUS_VALUE",
      "conclusion": "CONCLUSION_VALUE",
      "created_at": "2026-07-23T12:00:00Z",
      "html_url": "https://github.com/sudoshi/Zephyrus/actions/runs/1"
    }
  ]
}
JSON
}

write_fixture completed success
ZEPHYRUS_GITHUB_RUNS_JSON="$TEST_ROOT/runs.json" "$VERIFY" "$COMMIT" >/dev/null

write_fixture in_progress ""
if ZEPHYRUS_GITHUB_RUNS_JSON="$TEST_ROOT/runs.json" "$VERIFY" "$COMMIT" >/dev/null 2>&1; then
    echo "FAIL: an in-progress CI run passed the gate" >&2
    exit 1
fi

write_fixture completed failure
if ZEPHYRUS_GITHUB_RUNS_JSON="$TEST_ROOT/runs.json" "$VERIFY" "$COMMIT" >/dev/null 2>&1; then
    echo "FAIL: a failed CI run passed the gate" >&2
    exit 1
fi

cat > "$TEST_ROOT/runs.json" <<JSON
{"workflow_runs":[]}
JSON
if ZEPHYRUS_GITHUB_RUNS_JSON="$TEST_ROOT/runs.json" "$VERIFY" "$COMMIT" >/dev/null 2>&1; then
    echo "FAIL: a missing CI run passed the gate" >&2
    exit 1
fi

echo "GitHub CI release gate regression test passed."
