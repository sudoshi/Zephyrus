#!/usr/bin/env bash

set -Eeuo pipefail

readonly SHARD="${1:-}"
readonly MANIFEST="tests/ci/shard-manifest.json"

junit_arg() {
    if [[ -n "${RELEASE_EVIDENCE_DIR:-}" ]]; then
        mkdir -p "$RELEASE_EVIDENCE_DIR"
        printf -- "--log-junit=%s/phpunit-%s.xml" "$RELEASE_EVIDENCE_DIR" "$1"
    fi
}

if [[ "$SHARD" == "unit" ]]; then
    exec php artisan test --testsuite=Unit --no-ansi $(junit_arg "$SHARD")
fi

if [[ ! "$SHARD" =~ ^feature-([0-3])$ ]]; then
    echo "Usage: $0 unit|feature-{0..3}" >&2
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
        -print \
        | LC_ALL=C sort \
        | python3 scripts/ci/resolve-shard-files.py --manifest "$MANIFEST" --shard "$FEATURE_SHARD_INDEX"
)"

if [[ -z "$resolved_files" ]]; then
    echo "No tests resolved for shard $SHARD" >&2
    exit 3
fi

mapfile -t feature_tests <<< "$resolved_files"

if [[ "${SHARD_LIST_ONLY:-0}" == "1" ]]; then
    printf '%s\n' "${feature_tests[@]}"
    exit 0
fi

# Plan D3: two workers on per-process isolated databases (paratest;
# tests/bootstrap.php provisions one zephyrus_test_<token> per worker).
# XCTest-style single-queue parallelism breaks two S2 invariants, so the
# shard runs as ordered PASSES, each its own paratest invocation:
#   1. non-scenario files — the committed-scenario boundary guard must
#      never see a scenario baseline (scenario-last invariant);
#   2. shared-anchor scenario files — rebuild idempotently over each
#      other inside a worker only when every class shares one anchor;
#   3. each anchor-override class (committedScenarioAnchor) alone — one
#      worker holding two anchors triples owned-row counts (spike:
#      1008 = 3x336 on the no-growth assertion).
# paratest takes a directory, never a file list, so each pass runs from a
# generated config that inlines its files (make-paratest-suite.py).
readonly WORKERS="${BACKEND_SHARD_WORKERS:-2}"
pass_dir="$(mktemp -d)"
trap 'rm -rf "$pass_dir"' EXIT

pass_a=()
pass_b=()
anchor_solo=()
for file in "${feature_tests[@]}"; do
    if grep -q "UsesCommittedAncillaryScenario" "$file"; then
        if grep -q "committedScenarioAnchor" "$file"; then
            anchor_solo+=("$file")
        else
            pass_b+=("$file")
        fi
    else
        pass_a+=("$file")
    fi
done

echo "Running ${#feature_tests[@]} feature test files in $SHARD (${#pass_a[@]} standard, ${#pass_b[@]} scenario, ${#anchor_solo[@]} anchor-override; $WORKERS workers):"
printf '  %s\n' "${feature_tests[@]}"

run_pass() {
    local label="$1"
    shift
    [[ $# -eq 0 ]] && return 0
    local config="$pass_dir/phpunit-$label.xml"
    printf '%s\n' "$@" | python3 scripts/ci/make-paratest-suite.py "$config"
    echo "== pass $label ($# files) =="
    vendor/bin/paratest --processes="$WORKERS" --configuration "$config" --no-coverage $(junit_arg "$SHARD-$label")
}

run_pass a "${pass_a[@]+"${pass_a[@]}"}"
run_pass b "${pass_b[@]+"${pass_b[@]}"}"

anchor_index=0
for file in ${anchor_solo[@]+"${anchor_solo[@]}"}; do
    run_pass "anchor-$anchor_index" "$file"
    anchor_index=$((anchor_index + 1))
done
