#!/usr/bin/env bash

# §3.2.9 shard-manifest verifier (plan S1): drive the REAL shard scripts in
# list-only mode and prove the union of all shards equals discovery exactly —
# every feature test file appears once, no duplicates, no silent exclusions.
# Runs in the backend-quality job so drift fails CI before any shard spends
# compute.

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

for shard in 0 1 2 3 4 5 6 7; do
    SHARD_LIST_ONLY=1 bash scripts/ci/run-backend-test-shard.sh "feature-$shard" >> "$workdir/assigned"
done

# Same discovery pipeline as run-backend-test-shard.sh.
find tests/Feature -type f -name '*Test.php' \
    ! -path 'tests/Feature/Api/ProcessAnalysisTest.php' \
    ! -path 'tests/Feature/Auth/AuthenticationFlowTest.php' \
    -print | LC_ALL=C sort > "$workdir/discovered"

LC_ALL=C sort "$workdir/assigned" > "$workdir/assigned.sorted"

duplicates="$(uniq -d "$workdir/assigned.sorted")"
if [[ -n "$duplicates" ]]; then
    echo "Test files assigned to more than one shard:" >&2
    echo "$duplicates" >&2
    exit 1
fi

if ! diff -u "$workdir/discovered" "$workdir/assigned.sorted"; then
    echo "Shard union does not match discovered feature tests (see diff above)" >&2
    exit 1
fi

echo "Shard manifest verified: $(wc -l < "$workdir/discovered") feature test files, each on exactly one shard"
