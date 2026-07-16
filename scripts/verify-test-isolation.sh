#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

MODE="${1:---full}"
case "$MODE" in
    --full)
        COMMAND=(bash scripts/test-suite.sh full)
        ;;
    --focused)
        COMMAND=(php artisan test --compact \
            tests/Feature/Security/ProductionWebBoundaryTest.php \
            tests/Feature/Security/TestResourceIsolationTest.php \
            tests/Feature/Admin/AuthProviderControllerTest.php)
        ;;
    *)
        echo "Usage: $0 [--full|--focused]" >&2
        exit 64
        ;;
esac

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
export RELEASE_EVIDENCE_DIR="${RELEASE_EVIDENCE_DIR:-$PROJECT_ROOT/artifacts/release-evidence/concurrency-$STAMP}"
mkdir -p "$RELEASE_EVIDENCE_DIR"

bash scripts/capture-release-evidence.sh concurrent-a "${COMMAND[@]}" &
PID_A=$!
bash scripts/capture-release-evidence.sh concurrent-b "${COMMAND[@]}" &
PID_B=$!

STATUS=0
wait "$PID_A" || STATUS=1
wait "$PID_B" || STATUS=1

if [[ $STATUS -ne 0 ]]; then
    echo "Concurrent PHPUnit verification failed; inspect $RELEASE_EVIDENCE_DIR" >&2
    exit 1
fi

ORPHANS="$(php scripts/manage-test-database.php list-orphans)"
if [[ "$ORPHANS" != "[]" ]]; then
    echo "Concurrent suites left disposable databases behind: ${ORPHANS:-unknown}" >&2
    exit 1
fi

echo "Concurrent PHPUnit verification passed with separate databases and no leftovers."
