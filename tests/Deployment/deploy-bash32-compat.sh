#!/bin/bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_SCRIPT="$PROJECT_ROOT/deploy.sh"

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

unsafe_loop='for migration_path in "${MIGRATION_PATHS[@]}"; do'
unsafe_argument='"${MIGRATION_PATHS[@]}" <<'
safe_expansion='${MIGRATION_PATHS[@]+"${MIGRATION_PATHS[@]}"}'

if grep -Fq "$unsafe_loop" "$DEPLOY_SCRIPT"; then
    fail "deploy.sh contains a Bash 3.2-unsafe empty-array loop"
fi
if grep -Fq "$unsafe_argument" "$DEPLOY_SCRIPT"; then
    fail "deploy.sh contains a Bash 3.2-unsafe empty-array argument expansion"
fi

safe_count="$(grep -Fo "$safe_expansion" "$DEPLOY_SCRIPT" | wc -l | tr -d ' ')"
[[ "$safe_count" -ge 4 ]] \
    || fail "deploy.sh does not consistently use the nounset-safe migration array expansion"

# This expression must expand to zero words for an empty array and preserve all
# words for a populated array. It runs under macOS Bash 3.2 on developer Macs
# and remains a portable behavior check in Linux CI.
bash -uc '
    paths=()
    set -- base "${paths[@]+"${paths[@]}"}"
    [[ "$#" -eq 1 ]]

    paths=("database/migrations/one.php" "database/migrations/two.php")
    set -- base "${paths[@]+"${paths[@]}"}"
    [[ "$#" -eq 3 ]]
    [[ "$2" == "database/migrations/one.php" ]]
    [[ "$3" == "database/migrations/two.php" ]]
'

echo "Deployment Bash 3.2 empty-array compatibility test passed."
