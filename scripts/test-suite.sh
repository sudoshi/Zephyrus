#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

SUITE="${1:-list}"

case "$SUITE" in
    list)
        printf '%s\n' unit contract integration admin migration conformance browser full
        ;;
    unit)
        php artisan test --compact --testsuite=Unit
        ;;
    contract)
        php artisan test --compact \
            tests/Feature/ApiAuthorizationTest.php \
            tests/Feature/ApiIngressContractTest.php \
            tests/Feature/MobileBffTest.php \
            tests/Feature/MobileRoleCatalogParityTest.php \
            tests/Feature/MobileSharedDtoFixtureTest.php \
            tests/Feature/MobileUiVocabularyParityTest.php \
            tests/Feature/Rtdc/ApiSessionMiddlewareTest.php
        ;;
    integration)
        php artisan test --compact \
            tests/Feature/Integrations \
            tests/Feature/Governance \
            tests/Feature/PatientFlow/PatientFlowSecurityHotfixTest.php
        ;;
    admin)
        mapfile -t admin_tests < <(find \
            tests/Feature/Admin \
            tests/Feature/Auth \
            tests/Feature/Authorization \
            tests/Feature/Governance \
            tests/Feature/Integrations \
            tests/Feature/Security \
            -type f -name '*.php' \
            ! -path 'tests/Feature/Auth/AuthenticationFlowTest.php' \
            -print | sort)
        if [[ ${#admin_tests[@]} -eq 0 ]]; then
            echo "No Admin tests were discovered." >&2
            exit 1
        fi
        php artisan test --compact "${admin_tests[@]}"
        ;;
    migration)
        mapfile -t schema_tests < <(find tests/Feature -type f -name '*SchemaTest.php' -print | sort)
        if [[ ${#schema_tests[@]} -eq 0 ]]; then
            echo "No migration/schema tests were discovered." >&2
            exit 1
        fi
        php artisan test --compact "${schema_tests[@]}" \
            tests/Feature/Security/ProductionWebBoundaryTest.php
        ;;
    conformance)
        php artisan test --compact \
            tests/Feature/PatientFlow/PatientFlowSecurityHotfixTest.php \
            tests/Feature/MobileBffTest.php \
            tests/Feature/MobileSharedDtoFixtureTest.php \
            tests/Feature/MobileUiVocabularyParityTest.php
        ARENA_PYTHON="${ARENA_PYTHON:-python}"
        (cd arena && "$ARENA_PYTHON" -m pytest tests -q)
        ;;
    browser)
        bash scripts/run-browser-suite.sh
        ;;
    full)
        php artisan test --compact
        ;;
    *)
        echo "Unknown suite: $SUITE" >&2
        "$0" list >&2
        exit 64
        ;;
esac
