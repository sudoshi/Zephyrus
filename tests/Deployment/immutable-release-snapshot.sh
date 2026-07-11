#!/bin/bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SNAPSHOT_HELPER="$PROJECT_ROOT/scripts/deployment/create-release-snapshot.sh"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/zephyrus-release-test.XXXXXX")"
WRITER_PID=""

cleanup() {
    touch "$TEST_ROOT/stop-writer" 2>/dev/null || true
    if [[ -n "$WRITER_PID" ]]; then
        kill "$WRITER_PID" 2>/dev/null || true
        wait "$WRITER_PID" 2>/dev/null || true
    fi
    rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

assert_file_equals() {
    local expected="$1"
    local path="$2"
    local actual

    [[ -f "$path" ]] || fail "expected file is missing: $path"
    actual="$(cat "$path")"
    [[ "$actual" == "$expected" ]] \
        || fail "$path contained '$actual'; expected '$expected'"
}

REPOSITORY="$TEST_ROOT/repository"
SNAPSHOT="$TEST_ROOT/snapshot"

git init --quiet --initial-branch=main "$REPOSITORY"
git -C "$REPOSITORY" config user.name "Zephyrus CI"
git -C "$REPOSITORY" config user.email "ci@zephyrus.invalid"
printf 'committed source\n' > "$REPOSITORY/tracked.txt"
mkdir -p "$REPOSITORY/nested"
printf 'committed nested source\n' > "$REPOSITORY/nested/tracked.txt"
git -C "$REPOSITORY" add tracked.txt nested/tracked.txt
git -C "$REPOSITORY" commit --quiet -m "fixture"
COMMIT="$(git -C "$REPOSITORY" rev-parse HEAD)"

# Keep changing tracked and untracked worktree files until the immutable
# snapshot has been created. This reproduces the deploy time-of-check/time-of-
# use window without allowing the writer to modify committed Git objects.
(
    touch "$TEST_ROOT/writer-ready"
    mutation=0
    while [[ ! -f "$TEST_ROOT/stop-writer" ]]; do
        mutation=$((mutation + 1))
        printf 'concurrent mutation %s\n' "$mutation" > "$REPOSITORY/tracked.txt"
        printf 'untracked mutation %s\n' "$mutation" > "$REPOSITORY/rogue.php"
    done
) &
WRITER_PID=$!

while [[ ! -f "$TEST_ROOT/writer-ready" ]]; do
    sleep 0.01
done

"$SNAPSHOT_HELPER" "$REPOSITORY" "$COMMIT" "$SNAPSHOT"
touch "$TEST_ROOT/stop-writer"
wait "$WRITER_PID"
WRITER_PID=""

assert_file_equals "committed source" "$SNAPSHOT/tracked.txt"
assert_file_equals "committed nested source" "$SNAPSHOT/nested/tracked.txt"
assert_file_equals "$COMMIT" "$SNAPSHOT/.release-commit"
[[ ! -e "$SNAPSHOT/rogue.php" ]] \
    || fail "an untracked concurrent write entered the release snapshot"

OCCUPIED="$TEST_ROOT/occupied"
mkdir -p "$OCCUPIED"
touch "$OCCUPIED/existing-file"
if "$SNAPSHOT_HELPER" "$REPOSITORY" "$COMMIT" "$OCCUPIED" >/dev/null 2>&1; then
    fail "helper accepted a non-empty snapshot destination"
fi

echo "Immutable release snapshot regression test passed."
