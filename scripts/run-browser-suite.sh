#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

PORT="${PLAYWRIGHT_PORT:-8084}"
if ! [[ "$PORT" =~ ^[0-9]{2,5}$ ]]; then
    echo "PLAYWRIGHT_PORT must be numeric." >&2
    exit 64
fi

TOKEN="$(php -r 'echo substr(hash("sha256", getmypid()."|".random_bytes(16)), 0, 12);')"
DATABASE="zephyrus_test_e2e${TOKEN}"
TEST_USERNAME="${TEST_USERNAME:-e2e_admin}"
TEST_PASSWORD="${TEST_PASSWORD:-BrowserOnlyPassword!123}"
EVIDENCE_ROOT="${RELEASE_EVIDENCE_DIR:-$PROJECT_ROOT/artifacts/release-evidence/browser}"
SERVER_LOG="$EVIDENCE_ROOT/server.log"
SERVER_PID=""
TEST_RESOURCE_ROOT="$PROJECT_ROOT/storage/framework/testing/browser/$TOKEN"
SECRET_ROOT="$TEST_RESOURCE_ROOT/secrets"
CLINICAL_PAYLOAD_ROOT="$TEST_RESOURCE_ROOT/clinical-payloads"
CLINICAL_PAYLOAD_KEY="$SECRET_ROOT/clinical-payload-kek"

export APP_ENV=testing
export APP_KEY='base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='
export APP_URL="http://127.0.0.1:$PORT"
export DB_CONNECTION=pgsql
export DB_DATABASE="$DATABASE"
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export BROADCAST_CONNECTION=log
export SESSION_DRIVER=database
export LOCAL_AUTH_ENABLED=true
export LOCAL_REGISTRATION_ENABLED=false
export DEMO_AUTO_LOGIN_ENABLED=false
export INTEGRATION_SECRET_FILE_ROOT="$SECRET_ROOT"
export CLINICAL_PAYLOADS_ENABLED=true
export CLINICAL_PAYLOADS_LOCAL_ROOT="$CLINICAL_PAYLOAD_ROOT"
export CLINICAL_PAYLOADS_KEK_REF="file://$CLINICAL_PAYLOAD_KEY"
export TEST_USERNAME
export TEST_PASSWORD
export PLAYWRIGHT_BASE_URL="http://127.0.0.1:$PORT"
export RELEASE_EVIDENCE_DIR="$EVIDENCE_ROOT"

cleanup() {
    STATUS=$?
    trap - EXIT

    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    if ! php scripts/manage-test-database.php drop "$DATABASE"; then
        STATUS=1
    fi

    rm -rf "$TEST_RESOURCE_ROOT"

    exit "$STATUS"
}
trap cleanup EXIT

mkdir -p "$EVIDENCE_ROOT"
install -d -m 0700 "$SECRET_ROOT" "$CLINICAL_PAYLOAD_ROOT"
php -r 'file_put_contents($argv[1], "base64:".base64_encode(random_bytes(32)), LOCK_EX); chmod($argv[1], 0600);' "$CLINICAL_PAYLOAD_KEY"
php scripts/manage-test-database.php create "$DATABASE"

php artisan migrate --force --no-interaction
php artisan db:seed --class=Database\\Seeders\\E2eTestSeeder --force --no-interaction

if [[ "${PLAYWRIGHT_SKIP_BUILD:-false}" != "true" ]]; then
    npm run build
fi

(
    cd public
    exec php -S "127.0.0.1:$PORT" "$PROJECT_ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
) > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!

for attempt in {1..30}; do
    if curl --fail --silent "http://127.0.0.1:$PORT/login" >/dev/null; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo "Browser test server did not become ready; inspect $SERVER_LOG" >&2
        exit 1
    fi
    sleep 1
done

bash scripts/capture-release-evidence.sh browser-playwright npx playwright test "$@"
