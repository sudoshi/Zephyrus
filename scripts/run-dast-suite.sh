#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

ZAP_IMAGE="ghcr.io/zaproxy/zaproxy:stable@sha256:8d387b1a63e3425beef4846e39719f5af2a787753af2d8b6558c6257d7a577a2"
PORT="${DAST_PORT:-$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); if ($socket === false) { fwrite(STDERR, $error); exit(1); } $name = stream_socket_get_name($socket, false); echo substr(strrchr($name, ":"), 1); fclose($socket);')}"
TOKEN="$(php -r 'echo substr(hash("sha256", getmypid()."|".random_bytes(16)), 0, 12);')"
# Reuse the guarded browser-test namespace: manage-test-database.php will only
# create/drop random databases with this exact prefix on a loopback server.
DATABASE="zephyrus_test_e2e${TOKEN}"
EVIDENCE_ROOT="${RELEASE_EVIDENCE_DIR:-$PROJECT_ROOT/artifacts/release-evidence/dast}"
if [[ "$EVIDENCE_ROOT" != /* ]]; then
    EVIDENCE_ROOT="$PROJECT_ROOT/$EVIDENCE_ROOT"
fi
SERVER_LOG="$EVIDENCE_ROOT/server.log"
SERVER_PID=""

if ! [[ "$PORT" =~ ^[0-9]{2,5}$ ]]; then
    echo "DAST_PORT must be numeric." >&2
    exit 64
fi
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required for the pinned ZAP DAST image." >&2
    exit 1
fi

export APP_ENV=testing
export APP_KEY='base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='
export APP_URL="http://127.0.0.1:$PORT"
export DB_CONNECTION=pgsql
export DB_DATABASE="$DATABASE"
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export SESSION_DRIVER=database
export LOCAL_AUTH_ENABLED=true
export LOCAL_REGISTRATION_ENABLED=false
export DEMO_AUTO_LOGIN_ENABLED=false
export TEST_USERNAME="${TEST_USERNAME:-e2e_admin}"
export TEST_PASSWORD="${TEST_PASSWORD:-BrowserOnlyPassword!123}"

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
    exit "$STATUS"
}
trap cleanup EXIT

mkdir -p "$EVIDENCE_ROOT"
php scripts/manage-test-database.php create "$DATABASE"
php artisan migrate --force --no-interaction
php artisan db:seed --class=Database\\Seeders\\E2eTestSeeder --force --no-interaction

(
    cd public
    exec php -d expose_php=0 -S "127.0.0.1:$PORT" "$PROJECT_ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
) > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!

for attempt in {1..30}; do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "DAST server exited before readiness; inspect $SERVER_LOG" >&2
        exit 1
    fi
    if curl --fail --silent "http://127.0.0.1:$PORT/login" >/dev/null; then
        break
    fi
    if [[ "$attempt" -eq 30 ]]; then
        echo "DAST server did not become ready; inspect $SERVER_LOG" >&2
        exit 1
    fi
    sleep 1
done

# The ZAP baseline is passive and exercises only the unauthenticated web
# boundary. Authenticated browser coverage remains a separate required CI lane.
docker run --rm --network host \
    -v "$PROJECT_ROOT/security/zap:/zap/policy:ro" \
    -v "$EVIDENCE_ROOT:/zap/wrk:rw" \
    "$ZAP_IMAGE" zap-baseline.py \
    -t "http://127.0.0.1:$PORT/login" \
    -c /zap/policy/baseline.conf \
    -I -m 3 -T 10 \
    -J zap-report.json -r zap-report.html -w zap-report.md
