#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

TOOL_ROOT="${ZEPHYRUS_SECURITY_TOOL_ROOT:-${HOME}/.cache/zephyrus-security-tools}"
GITLEAKS="$TOOL_ROOT/bin/gitleaks"
PIP_AUDIT="$TOOL_ROOT/python/bin/pip-audit"
SEMGREP="$TOOL_ROOT/python/bin/semgrep"
EVIDENCE_ROOT="${RELEASE_EVIDENCE_DIR:-$PROJECT_ROOT/artifacts/release-evidence/security}"
mkdir -p "$EVIDENCE_ROOT"

if [[ ! -x "$GITLEAKS" || ! -x "$PIP_AUDIT" || ! -x "$SEMGREP" ]]; then
    bash scripts/security/install-tools.sh
fi

composer audit --locked --abandoned=fail
npm audit --audit-level=high
"$PIP_AUDIT" --requirement arena/requirements.txt
"$PIP_AUDIT" --requirement eddy/requirements.txt

# History and working-tree scans are both required. Redaction prevents a
# finding from echoing credential material into CI logs or retained evidence.
"$GITLEAKS" git --no-banner --redact=100 --config .gitleaks.toml \
    --report-format json --report-path "$EVIDENCE_ROOT/gitleaks-history.json"
"$GITLEAKS" dir . --no-banner --redact=100 --config .gitleaks.toml \
    --report-format json --report-path "$EVIDENCE_ROOT/gitleaks-working-tree.json"

"$SEMGREP" scan --config security/semgrep.yml --metrics=off --error \
    --severity ERROR --exclude vendor --exclude node_modules --exclude public/build \
    --exclude artifacts --json-output "$EVIDENCE_ROOT/semgrep.json" \
    app bootstrap config routes resources/js arena eddy

php scripts/security/verify-edge-security.php --contract
