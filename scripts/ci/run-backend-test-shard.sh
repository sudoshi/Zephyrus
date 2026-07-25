#!/usr/bin/env bash

set -Eeuo pipefail

readonly SHARD="${1:-}"
readonly MANIFEST="tests/ci/shard-manifest.json"

phpunit_evidence_args=()
if [[ -n "${RELEASE_EVIDENCE_DIR:-}" ]]; then
    mkdir -p "$RELEASE_EVIDENCE_DIR"
    phpunit_evidence_args+=("--log-junit=$RELEASE_EVIDENCE_DIR/phpunit-$SHARD.xml")
fi

if [[ "$SHARD" == "unit" ]]; then
    exec php artisan test --testsuite=Unit --no-ansi "${phpunit_evidence_args[@]}"
fi

if [[ ! "$SHARD" =~ ^feature-([0-7])$ ]]; then
    echo "Usage: $0 unit|feature-{0..7}" >&2
    exit 2
fi

readonly FEATURE_SHARD_INDEX="${BASH_REMATCH[1]}"

# Shard membership comes from the committed LPT manifest (weights = per-file
# medians of the Q6 timing evidence; plan S1), not from a filename-modulo
# deal: adding one test file must never reshuffle the heavy classes onto a
# different shard. Files missing from the manifest (new tests) are slotted
# deterministically at runtime; stale manifest entries fail hard — see
# scripts/ci/resolve-shard-files.py.
# Captured via command substitution (not process substitution) so a resolver
# failure — e.g. manifest drift, exit 4 — aborts with its own exit code under
# pipefail instead of masquerading as an empty shard.
resolved_files="$(
    find tests/Feature -type f -name '*Test.php' \
        ! -path 'tests/Feature/Api/ProcessAnalysisTest.php' \
        ! -path 'tests/Feature/Auth/AuthenticationFlowTest.php' \
        -print \
        | LC_ALL=C sort \
        | python3 scripts/ci/resolve-shard-files.py --manifest "$MANIFEST" --shard "$FEATURE_SHARD_INDEX"
)"

if [[ -z "$resolved_files" ]]; then
    echo "No tests resolved for shard $SHARD" >&2
    exit 3
fi

feature_tests=()
while IFS= read -r feature_test; do
    [[ -n "$feature_test" ]] && feature_tests+=("$feature_test")
done <<< "$resolved_files"

if [[ "${SHARD_LIST_ONLY:-0}" == "1" ]]; then
    printf '%s\n' "${feature_tests[@]}"
    exit 0
fi

echo "Running ${#feature_tests[@]} feature test files in $SHARD:"
printf '  %s\n' "${feature_tests[@]}"

exec php artisan test --no-ansi "${phpunit_evidence_args[@]}" "${feature_tests[@]}"
