#!/usr/bin/env bash

set -Eeuo pipefail

readonly SHARD="${1:-}"
readonly FEATURE_SHARD_COUNT=8

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

mapfile -t all_feature_tests < <(
    find tests/Feature -type f -name '*Test.php' \
        ! -path 'tests/Feature/Api/ProcessAnalysisTest.php' \
        ! -path 'tests/Feature/Auth/AuthenticationFlowTest.php' \
        -print \
        | LC_ALL=C sort
)

feature_tests=()
for index in "${!all_feature_tests[@]}"; do
    if (( index % FEATURE_SHARD_COUNT == FEATURE_SHARD_INDEX )); then
        feature_tests+=("${all_feature_tests[$index]}")
    fi
done

if (( ${#feature_tests[@]} == 0 )); then
    echo "No tests resolved for shard $SHARD" >&2
    exit 3
fi

if [[ "${SHARD_LIST_ONLY:-0}" == "1" ]]; then
    printf '%s\n' "${feature_tests[@]}"
    exit 0
fi

echo "Running ${#feature_tests[@]} feature test files in $SHARD:"
printf '  %s\n' "${feature_tests[@]}"

exec php artisan test --no-ansi "${phpunit_evidence_args[@]}" "${feature_tests[@]}"
