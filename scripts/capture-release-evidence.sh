#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if [[ $# -lt 2 ]]; then
    echo "Usage: $0 <lane> <command> [args...]" >&2
    exit 64
fi

LANE="$1"
shift
if [[ ! "$LANE" =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
    echo "Evidence lane must be a safe machine identifier." >&2
    exit 64
fi

EVIDENCE_ROOT="${RELEASE_EVIDENCE_DIR:-$PROJECT_ROOT/artifacts/release-evidence}"
mkdir -p "$EVIDENCE_ROOT"

STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SHA="$(git rev-parse HEAD 2>/dev/null || printf 'uncommitted')"
LOG="$EVIDENCE_ROOT/${STAMP}-${LANE}.log"
META="$EVIDENCE_ROOT/${STAMP}-${LANE}.json"
CHECKSUM="$LOG.sha256"
printf -v COMMAND_DISPLAY '%q ' "$@"
COMMAND_DISPLAY="$(printf '%s' "$COMMAND_DISPLAY" | php scripts/redact-clinical-output.php)"

set +e
"$@" 2>&1 | php scripts/redact-clinical-output.php | tee "$LOG"
STATUS=${PIPESTATUS[0]}
set -e

FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
sha256sum "$LOG" > "$CHECKSUM"
LOG_SHA256="$(cut -d' ' -f1 "$CHECKSUM")"

EVIDENCE_META_PATH="$META" \
EVIDENCE_SCHEMA="zephyrus.release-evidence.v1" \
EVIDENCE_LANE="$LANE" \
EVIDENCE_STARTED_AT="$STARTED_AT" \
EVIDENCE_FINISHED_AT="$FINISHED_AT" \
EVIDENCE_COMMIT="$SHA" \
EVIDENCE_COMMAND="$COMMAND_DISPLAY" \
EVIDENCE_EXIT_CODE="$STATUS" \
EVIDENCE_LOG="$(basename "$LOG")" \
EVIDENCE_LOG_SHA256="$LOG_SHA256" \
php -r '
    $data = [
        "schema" => getenv("EVIDENCE_SCHEMA"),
        "lane" => getenv("EVIDENCE_LANE"),
        "started_at" => getenv("EVIDENCE_STARTED_AT"),
        "finished_at" => getenv("EVIDENCE_FINISHED_AT"),
        "commit" => getenv("EVIDENCE_COMMIT"),
        "command" => trim((string) getenv("EVIDENCE_COMMAND")),
        "exit_code" => (int) getenv("EVIDENCE_EXIT_CODE"),
        "log" => getenv("EVIDENCE_LOG"),
        "log_sha256" => getenv("EVIDENCE_LOG_SHA256"),
    ];
    file_put_contents((string) getenv("EVIDENCE_META_PATH"), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
'

echo "Evidence: $META"
exit "$STATUS"
