#!/bin/bash

set -Eeuo pipefail

usage() {
    echo "Usage: $0 <repository-root> <commit> <empty-destination>" >&2
}

if [[ $# -ne 3 ]]; then
    usage
    exit 64
fi

REPOSITORY_ROOT="$1"
COMMIT="$2"
DESTINATION="$3"

if [[ ! -d "$REPOSITORY_ROOT/.git" && ! -f "$REPOSITORY_ROOT/.git" ]]; then
    echo "Error: repository root is not a Git worktree: $REPOSITORY_ROOT" >&2
    exit 1
fi

RESOLVED_COMMIT="$(git -C "$REPOSITORY_ROOT" rev-parse --verify "${COMMIT}^{commit}")"

if [[ -L "$DESTINATION" ]]; then
    echo "Error: snapshot destination must not be a symbolic link: $DESTINATION" >&2
    exit 1
fi

if [[ -e "$DESTINATION" && ! -d "$DESTINATION" ]]; then
    echo "Error: snapshot destination is not a directory: $DESTINATION" >&2
    exit 1
fi

mkdir -p "$DESTINATION"

if [[ -n "$(find "$DESTINATION" -mindepth 1 -print -quit)" ]]; then
    echo "Error: snapshot destination is not empty: $DESTINATION" >&2
    exit 1
fi

# Read only committed Git objects. Untracked files and worktree edits cannot
# enter this payload, even if another process changes the checkout concurrently.
git -C "$REPOSITORY_ROOT" archive --format=tar "$RESOLVED_COMMIT" \
    | tar -xf - -C "$DESTINATION"

printf '%s\n' "$RESOLVED_COMMIT" > "$DESTINATION/.release-commit"
