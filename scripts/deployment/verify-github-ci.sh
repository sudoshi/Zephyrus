#!/bin/bash

set -Eeuo pipefail

usage() {
    echo "Usage: $0 <full-commit-sha>" >&2
}

if [[ $# -ne 1 || ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
    usage
    exit 64
fi

COMMIT="$1"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PARSER="$PROJECT_ROOT/scripts/deployment/verify-github-ci.php"
RESPONSE_FILE="${ZEPHYRUS_GITHUB_RUNS_JSON:-}"
TEMP_RESPONSE=""

cleanup() {
    if [[ -n "$TEMP_RESPONSE" ]]; then
        rm -f "$TEMP_RESPONSE"
    fi
}
trap cleanup EXIT

if [[ -z "$RESPONSE_FILE" ]]; then
    TEMP_RESPONSE="$(mktemp "${TMPDIR:-/tmp}/zephyrus-github-ci.XXXXXX")"
    RESPONSE_FILE="$TEMP_RESPONSE"
    API_URL="https://api.github.com/repos/sudoshi/Zephyrus/actions/workflows/ci.yml/runs?branch=main&event=push&head_sha=${COMMIT}&per_page=10"
    CURL_ARGS=(
        --fail
        --silent
        --show-error
        --location
        --retry 2
        --header "Accept: application/vnd.github+json"
        --header "User-Agent: Zephyrus-production-release"
        --output "$RESPONSE_FILE"
    )

    if [[ -n "${GITHUB_TOKEN:-}" ]]; then
        CURL_ARGS+=(--header "Authorization: Bearer ${GITHUB_TOKEN}")
    fi

    curl "${CURL_ARGS[@]}" "$API_URL"
fi

php "$PARSER" "$RESPONSE_FILE" "$COMMIT"
