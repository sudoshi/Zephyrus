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

# Dependency audits query live registries, so a new advisory on an untouched
# dependency can red-flag main hours after an unrelated merge. They hard-gate
# here only when this change set edits the corresponding manifest/lockfile;
# the scheduled security-nightly workflow owns advisory response for
# everything else. SECURITY_AUDIT_DEPENDENCIES=always (the nightly, or a
# manual sweep) forces all four audits regardless of the diff.
audit_scope="${SECURITY_AUDIT_DEPENDENCIES:-diff}"
changed_files=""
if [[ "$audit_scope" != "always" ]]; then
    if ! changed_files="$(git diff --name-only origin/main...HEAD 2>/dev/null)"; then
        # Diff base unavailable (shallow clone, missing ref): fail safe.
        audit_scope="always"
    fi
fi

should_run_dependency_audit() {
    if [[ "$audit_scope" == "always" ]]; then
        return 0
    fi
    local path
    for path in "$@"; do
        if grep -qxF "$path" <<<"$changed_files"; then
            return 0
        fi
    done
    return 1
}

if should_run_dependency_audit composer.json composer.lock; then
    composer audit --locked --abandoned=fail
else
    echo "Skipping composer audit: composer.json/composer.lock untouched (nightly sweep owns advisories)"
fi

if should_run_dependency_audit package.json package-lock.json; then
    npm audit --audit-level=high
else
    echo "Skipping npm audit: package.json/package-lock.json untouched (nightly sweep owns advisories)"
fi

if should_run_dependency_audit arena/requirements.txt; then
    "$PIP_AUDIT" --requirement arena/requirements.txt
else
    echo "Skipping arena pip-audit: arena/requirements.txt untouched (nightly sweep owns advisories)"
fi

if should_run_dependency_audit eddy/requirements.txt; then
    "$PIP_AUDIT" --requirement eddy/requirements.txt
else
    echo "Skipping eddy pip-audit: eddy/requirements.txt untouched (nightly sweep owns advisories)"
fi

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
