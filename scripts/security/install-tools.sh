#!/usr/bin/env bash

set -Eeuo pipefail

GITLEAKS_VERSION="8.28.0"
GITLEAKS_LINUX_X64_SHA256="a65b5253807a68ac0cafa4414031fd740aeb55f54fb7e55f386acb52e6a840eb"
PIP_AUDIT_VERSION="2.10.1"
SEMGREP_VERSION="1.169.0"

TOOL_ROOT="${ZEPHYRUS_SECURITY_TOOL_ROOT:-${HOME}/.cache/zephyrus-security-tools}"
BIN_ROOT="$TOOL_ROOT/bin"
PYTHON_ENV="$TOOL_ROOT/python"
mkdir -p "$BIN_ROOT"

ARCH="$(uname -m)"
if [[ "$ARCH" != "x86_64" ]]; then
    echo "Unsupported architecture for the pinned Gitleaks artifact: $ARCH" >&2
    exit 1
fi

GITLEAKS="$BIN_ROOT/gitleaks"
if [[ ! -x "$GITLEAKS" ]] || [[ "$($GITLEAKS version)" != "$GITLEAKS_VERSION" ]]; then
    ARCHIVE="$(mktemp "${TMPDIR:-/tmp}/gitleaks.XXXXXX.tar.gz")"
    TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/gitleaks.XXXXXX")"
    trap 'rm -f "$ARCHIVE"; rm -rf "$TEMP_DIR"' EXIT
    curl --fail --location --silent --show-error \
        "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz" \
        --output "$ARCHIVE"
    printf '%s  %s\n' "$GITLEAKS_LINUX_X64_SHA256" "$ARCHIVE" | sha256sum --check --status
    tar -xzf "$ARCHIVE" -C "$TEMP_DIR" gitleaks
    install -m 0755 "$TEMP_DIR/gitleaks" "$GITLEAKS"
    rm -f "$ARCHIVE"
    rm -rf "$TEMP_DIR"
    trap - EXIT
fi

if [[ ! -x "$PYTHON_ENV/bin/python" ]]; then
    python3 -m venv "$PYTHON_ENV"
fi

"$PYTHON_ENV/bin/python" -m pip install --disable-pip-version-check --quiet \
    "pip-audit==$PIP_AUDIT_VERSION" \
    "semgrep==$SEMGREP_VERSION"

"$GITLEAKS" version
"$PYTHON_ENV/bin/pip-audit" --version
"$PYTHON_ENV/bin/semgrep" --version

