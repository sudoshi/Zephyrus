#!/bin/bash
# Canonical local-to-production release script for Zephyrus.

set -Eeuo pipefail

EXPECTED_REPOSITORY_ROOT="/home/smudoshi/Github/Zephyrus"
PRODUCTION_APPLICATION_ROOT="/var/www/Zephyrus"
PRODUCTION_SSH_TARGET="smudoshi@zephyrus.acumenus.net"
DEPLOY_LOCK="/tmp/zephyrus-production-release.lock"
ORIGINAL_ARGS=("$@")
DEPLOY_ACTION="application"
FRONTEND_LABEL=0
CHECK_ONLY=0
HOST_ONLY=0
EXPECTED_COMMIT_ARG=""
CONFIRM_COMMIT=""
MIGRATION_PATHS=()

usage() {
    cat <<'USAGE'
Usage:
  ./deploy.sh [--frontend] [--confirm <full-commit-sha>]
  ./deploy.sh --migrate --path database/migrations/<file.php> [--path ...]
  ./deploy.sh --check

Run from a clean local main branch to synchronize and deploy the exact
CI-successful origin/main commit through smudoshi@zephyrus.acumenus.net.

Options:
  --frontend              Compatibility label; still publishes a full immutable
                          application snapshot and never runs migrations.
  --migrate, --db         Run only explicitly named migration paths after the
                          application commit is deployed and a verified logical
                          backup is captured.
  --path <path>           Exact database/migrations/*.php path; repeat as needed.
  --check                 Read-only local, GitHub CI, SSH, and server preflight.
  --confirm <commit>      Non-interactive confirmation; must equal the full SHA.
  --help                  Show this help.
USAGE
}

fail() {
    echo "❌ Error: $*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --frontend)
            FRONTEND_LABEL=1
            shift
            ;;
        --migrate|--db)
            DEPLOY_ACTION="migration"
            shift
            ;;
        --path)
            [[ $# -ge 2 ]] || fail "--path requires a migration file"
            MIGRATION_PATHS+=("$2")
            shift 2
            ;;
        --path=*)
            MIGRATION_PATHS+=("${1#--path=}")
            shift
            ;;
        --check)
            CHECK_ONLY=1
            shift
            ;;
        --confirm)
            [[ $# -ge 2 ]] || fail "--confirm requires the full release commit"
            CONFIRM_COMMIT="$2"
            shift 2
            ;;
        --host-only)
            HOST_ONLY=1
            shift
            ;;
        --expected-commit)
            [[ $# -ge 2 ]] || fail "--expected-commit requires a full commit SHA"
            EXPECTED_COMMIT_ARG="$2"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            usage >&2
            fail "unknown option: $1"
            ;;
    esac
done

if [[ "${DEPLOY_RUN_MIGRATIONS:-0}" != "0" ]]; then
    fail "DEPLOY_RUN_MIGRATIONS is no longer supported; use --migrate with explicit --path values"
fi

if [[ "$DEPLOY_ACTION" == "application" && ${#MIGRATION_PATHS[@]} -gt 0 ]]; then
    fail "--path is valid only with --migrate"
fi
if [[ "$DEPLOY_ACTION" == "migration" && ${#MIGRATION_PATHS[@]} -eq 0 ]]; then
    fail "--migrate requires at least one explicit --path"
fi
if [[ "$FRONTEND_LABEL" -eq 1 && "$DEPLOY_ACTION" == "migration" ]]; then
    fail "--frontend and --migrate cannot be combined"
fi

assert_main_release_source() {
    local repository_root="$1"
    local current_branch
    local upstream

    current_branch="$(git -C "$repository_root" branch --show-current)"
    upstream="$(git -C "$repository_root" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
    if [[ "$current_branch" != "main" || "$upstream" != "origin/main" ]]; then
        echo "🌿 Current branch: ${current_branch:-detached HEAD}" >&2
        echo "📡 Current upstream: ${upstream:-none}" >&2
        fail "production releases must come from main tracking origin/main"
    fi

    if [[ -n "$(git -C "$repository_root" status --porcelain=v1 --untracked-files=normal)" ]]; then
        git -C "$repository_root" status --short --branch >&2
        fail "the release worktree must be clean"
    fi

    git -C "$repository_root" fetch --quiet origin main
}

validate_migration_paths() {
    local repository_root="$1"
    local migration_path

    for migration_path in "${MIGRATION_PATHS[@]}"; do
        if [[ ! "$migration_path" =~ ^database/migrations/[A-Za-z0-9._-]+\.php$ ]]; then
            fail "migration path is not a direct database/migrations/*.php file: $migration_path"
        fi
        if [[ ! -f "$repository_root/$migration_path" ]]; then
            fail "migration file does not exist: $migration_path"
        fi
        if ! git -C "$repository_root" ls-files --error-unmatch "$migration_path" >/dev/null 2>&1; then
            fail "migration path is not tracked by Git: $migration_path"
        fi
    done
}

verify_release_ci() {
    local repository_root="$1"
    local commit="$2"
    "$repository_root/scripts/deployment/verify-github-ci.sh" "$commit"
}

confirm_release() {
    local commit="$1"
    local verb="DEPLOY"
    local expected
    local answer

    if [[ "$DEPLOY_ACTION" == "migration" ]]; then
        verb="MIGRATE"
    fi

    if [[ -n "$CONFIRM_COMMIT" ]]; then
        [[ "$CONFIRM_COMMIT" == "$commit" ]] \
            || fail "--confirm must exactly match release commit $commit"
        return
    fi

    [[ -t 0 ]] || fail "interactive confirmation unavailable; pass --confirm $commit"
    expected="$verb ${commit:0:12}"
    read -r -p "Type '$expected' to continue: " answer
    [[ "$answer" == "$expected" ]] || fail "release confirmation did not match"
}

run_remote_preflight() {
    local commit="$1"

    ssh -o BatchMode=yes -o ConnectTimeout=10 "$PRODUCTION_SSH_TARGET" \
        bash -s -- "$commit" <<'REMOTE_CHECK'
set -Eeuo pipefail
EXPECTED_COMMIT="$1"
REPOSITORY_ROOT="/home/smudoshi/Github/Zephyrus"

[[ "$(id -un)" == "smudoshi" ]] || {
    echo "Error: production SSH user is not smudoshi" >&2
    exit 1
}
[[ -d "$REPOSITORY_ROOT/.git" ]] || {
    echo "Error: canonical production checkout is missing" >&2
    exit 1
}
[[ "$(git -C "$REPOSITORY_ROOT" branch --show-current)" == "main" ]] || {
    echo "Error: production checkout is not on main" >&2
    exit 1
}
[[ "$(git -C "$REPOSITORY_ROOT" rev-parse --abbrev-ref --symbolic-full-name '@{u}')" == "origin/main" ]] || {
    echo "Error: production checkout does not track origin/main" >&2
    exit 1
}
[[ -z "$(git -C "$REPOSITORY_ROOT" status --porcelain=v1 --untracked-files=normal)" ]] || {
    echo "Error: production checkout is dirty" >&2
    exit 1
}
REMOTE_COMMIT="$(git -C "$REPOSITORY_ROOT" ls-remote --exit-code origin refs/heads/main | awk '{print $1}')"
[[ "$REMOTE_COMMIT" == "$EXPECTED_COMMIT" ]] || {
    echo "Error: origin/main changed during preflight" >&2
    exit 1
}
sudo -n true
printf 'Production preflight passed: user=%s branch=main origin/main=%s\n' \
    "$(id -un)" "$EXPECTED_COMMIT"
REMOTE_CHECK
}

run_remote_release() {
    local commit="$1"

    ssh -o BatchMode=yes "$PRODUCTION_SSH_TARGET" \
        bash -s -- "$commit" "$DEPLOY_ACTION" "$FRONTEND_LABEL" "${MIGRATION_PATHS[@]}" <<'REMOTE_RELEASE'
set -Eeuo pipefail
EXPECTED_COMMIT="$1"
DEPLOY_ACTION="$2"
FRONTEND_LABEL="$3"
shift 3
MIGRATION_PATHS=("$@")
REPOSITORY_ROOT="/home/smudoshi/Github/Zephyrus"
LOCK_FILE="/tmp/zephyrus-production-release.lock"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Error: another Zephyrus production release is already running" >&2
    exit 1
fi

cd "$REPOSITORY_ROOT"
[[ "$(id -un)" == "smudoshi" ]] || {
    echo "Error: production SSH user is not smudoshi" >&2
    exit 1
}
[[ "$(git branch --show-current)" == "main" ]] || {
    echo "Error: production checkout is not on main" >&2
    exit 1
}
[[ "$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}')" == "origin/main" ]] || {
    echo "Error: production checkout does not track origin/main" >&2
    exit 1
}
[[ -z "$(git status --porcelain=v1 --untracked-files=normal)" ]] || {
    echo "Error: production checkout is dirty" >&2
    exit 1
}

git fetch --quiet origin main
[[ "$(git rev-parse origin/main)" == "$EXPECTED_COMMIT" ]] || {
    echo "Error: origin/main no longer matches the approved commit" >&2
    exit 1
}
git merge --ff-only "$EXPECTED_COMMIT"
[[ "$(git rev-parse HEAD)" == "$EXPECTED_COMMIT" ]] || {
    echo "Error: production checkout did not reach the approved commit" >&2
    exit 1
}

DEPLOY_ARGS=(--host-only --expected-commit "$EXPECTED_COMMIT")
if [[ "$DEPLOY_ACTION" == "migration" ]]; then
    DEPLOY_ARGS+=(--migrate)
    for migration_path in "${MIGRATION_PATHS[@]}"; do
        DEPLOY_ARGS+=(--path "$migration_path")
    done
elif [[ "$FRONTEND_LABEL" == "1" ]]; then
    DEPLOY_ARGS+=(--frontend)
fi

exec env \
    ZEPHYRUS_DEPLOY_LOCK_HELD=1 \
    ZEPHYRUS_REMOTE_APPROVED_COMMIT="$EXPECTED_COMMIT" \
    ./deploy.sh "${DEPLOY_ARGS[@]}"
REMOTE_RELEASE
}

run_path_scoped_migrations() {
    local release_commit="$1"
    local deployed_commit
    local metadata
    local environment
    local driver
    local host
    local database
    local short_commit="${release_commit:0:12}"
    local timestamp
    local backup_path
    local ledger_path
    local migration_path
    local migration_arguments=()

    validate_migration_paths "$REPOSITORY_ROOT"

    [[ -f "$PRODUCTION_APPLICATION_ROOT/.release-commit" ]] \
        || fail "the production release marker is missing; deploy the application first"
    deployed_commit="$(sudo cat "$PRODUCTION_APPLICATION_ROOT/.release-commit")"
    [[ "$deployed_commit" == "$release_commit" ]] \
        || fail "deployed application commit $deployed_commit does not match $release_commit"

    metadata="$(
        cd "$PRODUCTION_APPLICATION_ROOT"
        sudo -u www-data php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make("Illuminate\\Contracts\\Console\\Kernel")->bootstrap();
            $name = (string) config("database.default");
            $config = (array) config("database.connections.".$name);
            echo app()->environment(), "\t";
            echo (string) ($config["driver"] ?? ""), "\t";
            echo (string) ($config["host"] ?? ""), "\t";
            echo (string) ($config["database"] ?? "");
        '
    )"
    IFS=$'\t' read -r environment driver host database <<< "$metadata"

    [[ "$environment" == "production" ]] || fail "deployed application is not in production mode"
    [[ "$driver" == "pgsql" ]] || fail "production database driver is not PostgreSQL"
    [[ "$host" == "127.0.0.1" || "$host" == "localhost" || "$host" == "::1" ]] \
        || fail "production database is not the expected host-local PostgreSQL service"
    [[ "$database" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "production database name is invalid"

    for migration_path in "${MIGRATION_PATHS[@]}"; do
        migration_arguments+=(--path "$migration_path")
    done

    echo "🔎 Previewing only the approved migration paths..."
    (
        cd "$PRODUCTION_APPLICATION_ROOT"
        sudo -u www-data php artisan migrate \
            --pretend --force --no-interaction "${migration_arguments[@]}"
    )

    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    backup_path="/var/backups/zephyrus/pre-migrate-${short_commit}-${timestamp}.dir"
    ledger_path="${backup_path}.migrate-status.txt"

    sudo install -d -o postgres -g postgres -m 0700 /var/backups/zephyrus
    if sudo test -e "$backup_path" || sudo test -e "$ledger_path"; then
        fail "migration backup target already exists"
    fi

    echo "💾 Capturing full PostgreSQL logical backup..."
    sudo -u postgres pg_dump \
        --format=directory \
        --jobs=2 \
        --file="$backup_path" \
        --dbname="$database"
    sudo -u postgres pg_restore --list "$backup_path" >/dev/null
    (
        cd "$PRODUCTION_APPLICATION_ROOT"
        sudo -u www-data php artisan migrate:status --no-ansi
    ) | sudo -u postgres tee "$ledger_path" >/dev/null

    echo "✅ Verified backup: $backup_path"
    echo "🧾 Migration ledger: $ledger_path"
    echo "🗄️  Running only the approved migration paths..."
    (
        cd "$PRODUCTION_APPLICATION_ROOT"
        sudo -u www-data php artisan migrate \
            --force --no-interaction "${migration_arguments[@]}"
    )

    echo "🔍 Verifying migration status..."
    (
        cd "$PRODUCTION_APPLICATION_ROOT"
        sudo -u www-data php artisan migrate:status --no-ansi
    )
    echo "✅ Path-scoped migration release completed for $release_commit"
}

REPOSITORY_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"

[[ -n "$REPOSITORY_ROOT" ]] || fail "run this script from a Zephyrus Git worktree"

if [[ "$REPOSITORY_ROOT" != "$EXPECTED_REPOSITORY_ROOT" ]]; then
    [[ "$HOST_ONLY" -eq 0 ]] || fail "--host-only is valid only in the canonical production checkout"
    cd "$REPOSITORY_ROOT"
    assert_main_release_source "$REPOSITORY_ROOT"
    validate_migration_paths "$REPOSITORY_ROOT"

    RELEASE_COMMIT="$(git rev-parse HEAD)"
    REMOTE_COMMIT="$(git rev-parse origin/main)"
    [[ "$RELEASE_COMMIT" == "$REMOTE_COMMIT" ]] \
        || fail "local main must exactly match origin/main before release"
    verify_release_ci "$REPOSITORY_ROOT" "$RELEASE_COMMIT"
    run_remote_preflight "$RELEASE_COMMIT"

    if [[ "$CHECK_ONLY" -eq 1 ]]; then
        echo "✅ Read-only release preflight passed for $RELEASE_COMMIT"
        exit 0
    fi

    confirm_release "$RELEASE_COMMIT"
    run_remote_release "$RELEASE_COMMIT"
    exit 0
fi

cd "$REPOSITORY_ROOT"

if [[ -z "${ZEPHYRUS_DEPLOY_LOCK_HELD:-}" ]]; then
    exec flock -n "$DEPLOY_LOCK" \
        env ZEPHYRUS_DEPLOY_LOCK_HELD=1 "$0" "${ORIGINAL_ARGS[@]}"
fi

echo "📡 Checking remote status..."
assert_main_release_source "$REPOSITORY_ROOT"
RELEASE_COMMIT="$(git rev-parse HEAD)"
REMOTE_COMMIT="$(git rev-parse origin/main)"

if [[ "$RELEASE_COMMIT" != "$REMOTE_COMMIT" ]]; then
    echo "❌ Error: main must exactly match origin/main before deploying"
    echo "🏠 Local main:  $RELEASE_COMMIT"
    echo "📡 Origin main: $REMOTE_COMMIT"
    exit 1
fi
echo "✅ main is current at $RELEASE_COMMIT"

if [[ -n "$EXPECTED_COMMIT_ARG" && "$EXPECTED_COMMIT_ARG" != "$RELEASE_COMMIT" ]]; then
    fail "production checkout does not match expected commit $EXPECTED_COMMIT_ARG"
fi
if [[ "$HOST_ONLY" -eq 1 ]]; then
    [[ -n "$EXPECTED_COMMIT_ARG" ]] || fail "--host-only requires an expected commit"
    [[ "${ZEPHYRUS_REMOTE_APPROVED_COMMIT:-}" == "$EXPECTED_COMMIT_ARG" ]] \
        || fail "--host-only requires the local controller's exact-commit approval"
fi

verify_release_ci "$REPOSITORY_ROOT" "$RELEASE_COMMIT"

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    echo "✅ Production-host release preflight passed for $RELEASE_COMMIT"
    exit 0
fi

if [[ "$HOST_ONLY" -eq 0 ]]; then
    confirm_release "$RELEASE_COMMIT"
fi

if [[ ! -f "$REPOSITORY_ROOT/vendor/autoload.php" ]]; then
    echo "❌ Error: Composer dependencies are missing; run composer install"
    exit 1
fi

if [[ "$DEPLOY_ACTION" == "migration" ]]; then
    run_path_scoped_migrations "$RELEASE_COMMIT"
    exit 0
fi

if [[ ! -d "$REPOSITORY_ROOT/node_modules" ]]; then
    echo "❌ Error: Node dependencies are missing; run npm install"
    exit 1
fi

# Production releases fail before publication unless the root-owned Apache
# edge policy, ModSecurity, and OWASP CRS contract are active and match this
# release. Prepare the host once with deploy/apache/install-zephyrus-edge-security.sh.
echo "🛡️  Verifying production edge prerequisites..."
if ! sudo php "$REPOSITORY_ROOT/scripts/security/verify-edge-security.php" --contract --apache; then
    echo "❌ Error: Production WAF/edge prerequisites are not ready"
    echo "💡 Review deploy/apache/install-zephyrus-edge-security.sh"
    exit 1
fi

RELEASE_TEMP="$(mktemp -d "${TMPDIR:-/tmp}/zephyrus-release.XXXXXX")"
RELEASE_ROOT="$RELEASE_TEMP/release"
IMMUTABLE_HELPER="$RELEASE_TEMP/create-release-snapshot.sh"

cleanup_release() {
    rm -rf "$RELEASE_TEMP"
}
trap cleanup_release EXIT

# Load the snapshot helper from the release commit itself, then archive that
# same commit. A concurrent writer may change the checkout after this point,
# but rsync and the asset build only read from RELEASE_ROOT.
git show "$RELEASE_COMMIT:scripts/deployment/create-release-snapshot.sh" > "$IMMUTABLE_HELPER"
chmod 0700 "$IMMUTABLE_HELPER"
"$IMMUTABLE_HELPER" "$REPOSITORY_ROOT" "$RELEASE_COMMIT" "$RELEASE_ROOT"

# Composer dependencies are not tracked. Freeze the currently installed,
# lockfile-backed dependency tree into the otherwise commit-only release tree.
echo "📦 Freezing Composer dependencies into the release snapshot..."
mkdir -p "$RELEASE_ROOT/vendor"
rsync -a --delete "$REPOSITORY_ROOT/vendor/" "$RELEASE_ROOT/vendor/"

echo "🚀 Starting deployment process..."
echo "Building assets..."

# Reuse the installed build toolchain without exposing the release payload to
# mutable application source. The symlink is removed before publication.
ln -s "$REPOSITORY_ROOT/node_modules" "$RELEASE_ROOT/node_modules"
(
    cd "$RELEASE_ROOT"
    NODE_ENV=production npm run build
)
rm "$RELEASE_ROOT/node_modules"

if [[ ! -f "$RELEASE_ROOT/public/build/manifest.json" ]]; then
    echo "❌ Error: Production asset manifest was not generated"
    exit 1
fi

# Do not publish an older snapshot if main advanced while assets were building.
echo "📡 Revalidating release commit before publication..."
git fetch --quiet origin main
REMOTE_COMMIT="$(git rev-parse origin/main)"
if [[ "$RELEASE_COMMIT" != "$REMOTE_COMMIT" ]]; then
    echo "❌ Error: origin/main advanced while the release was building"
    echo "📦 Prepared commit: $RELEASE_COMMIT"
    echo "📡 Origin main:     $REMOTE_COMMIT"
    exit 1
fi

echo "Syncing to production..."
sudo rsync -av --exclude 'node_modules' \
            --exclude '.git' \
            --exclude '.env' \
            --exclude 'storage/logs/*' \
            --exclude 'storage/framework/cache/*' \
            --exclude '.github' \
            --exclude 'tests' \
            --exclude 'deploy.sh' \
            --exclude 'arena/.venv' \
            --exclude '__pycache__' \
            --exclude '.pytest_cache' \
            "$RELEASE_ROOT/" /var/www/Zephyrus/

# Vite's ignored hot marker is intentionally absent from immutable release
# snapshots, but rsync does not delete destination-only runtime files. Remove
# any marker left by an older development-era deployment so production pages
# resolve assets from the generated manifest instead of localhost:5176.
sudo rm -f /var/www/Zephyrus/public/hot
if sudo test -e /var/www/Zephyrus/public/hot; then
    echo "❌ Error: Production Vite hot marker could not be removed"
    exit 1
fi

echo "Setting permissions..."
# rsync -a preserves dev (smudoshi) ownership, but Apache/PHP-FPM runs as www-data.
# The ENTIRE tree must be www-data-owned or vendor/autoload reads fail with a
# site-wide 500 (e.g. "Permission denied" on vendor/.../functions_include.php).
sudo chown -R www-data:www-data /var/www/Zephyrus

DEPLOYED_COMMIT="$(sudo cat /var/www/Zephyrus/.release-commit)"
if [[ "$DEPLOYED_COMMIT" != "$RELEASE_COMMIT" ]]; then
    echo "❌ Error: Deployed commit marker does not match the prepared release"
    echo "📦 Prepared commit: $RELEASE_COMMIT"
    echo "🚀 Deployed marker: $DEPLOYED_COMMIT"
    exit 1
fi

echo "Clearing Laravel caches..."
# Clear Laravel caches
cd /var/www/Zephyrus
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear

echo "Installing and restarting the supervised queue worker..."
WORKER_UNIT_SOURCE="/var/www/Zephyrus/deploy/systemd/zephyrus-queue-worker.service"
WORKER_UNIT_DEST="/etc/systemd/system/zephyrus-queue-worker.service"
if [[ ! -f "$WORKER_UNIT_SOURCE" ]]; then
    echo "❌ Error: Queue worker unit is missing from the deployment"
    exit 1
fi
if ! sudo cmp -s "$WORKER_UNIT_SOURCE" "$WORKER_UNIT_DEST"; then
    sudo install -o root -g root -m 0644 "$WORKER_UNIT_SOURCE" "$WORKER_UNIT_DEST"
    sudo systemctl daemon-reload
fi
sudo systemctl enable --now zephyrus-queue-worker.service
sudo systemctl restart zephyrus-queue-worker.service

# --- Arena OCPM sidecar (Part X) -----------------------------------------
# The rsync above ships fresh arena/ code into the deployed tree, but the
# sidecar runs from a SEPARATE uv-managed venv (ExecStart=/opt/arena-sidecar/
# venv/bin/uvicorn, WorkingDirectory=/var/www/Zephyrus/arena). New code only
# goes live on a service restart, and changed requirements.txt needs a dep
# sync — neither of which the plain code sync does. Everything here is
# best-effort: the sidecar is gated by ARENA_ENABLED and its absence must
# never fail the core web deploy. Override the venv path with ARENA_VENV.
ARENA_SERVICE="zephyrus-arena.service"
ARENA_VENV="${ARENA_VENV:-/opt/arena-sidecar/venv}"
ARENA_PORT="${ARENA_PORT:-8101}"
ARENA_REQ="/var/www/Zephyrus/arena/requirements.txt"
ARENA_REQ_MARKER="/opt/arena-sidecar/.requirements.sha256"
# LoadState is readable unprivileged (unlike `systemctl cat`, which needs to
# read the root-only unit file and would false-negative for the deploy user).
if [[ "$(systemctl show "$ARENA_SERVICE" -p LoadState --value 2>/dev/null)" == "loaded" ]] \
   && [[ -x "$ARENA_VENV/bin/python" ]]; then
    echo "Refreshing the Arena OCPM sidecar (Part X)..."
    # Sync venv deps only when requirements.txt actually changed — uv install
    # hits the network, so a checksum marker keeps no-op deploys fast.
    if [[ -f "$ARENA_REQ" ]]; then
        UV_BIN="$(command -v uv || echo "$HOME/.local/bin/uv")"
        if [[ -x "$UV_BIN" ]]; then
            NEW_SUM="$(sha256sum "$ARENA_REQ" | awk '{print $1}')"
            OLD_SUM="$(sudo cat "$ARENA_REQ_MARKER" 2>/dev/null || true)"
            if [[ "$NEW_SUM" != "$OLD_SUM" ]]; then
                echo "  requirements.txt changed — syncing venv deps via uv..."
                if VIRTUAL_ENV="$ARENA_VENV" "$UV_BIN" pip install -r "$ARENA_REQ"; then
                    echo "$NEW_SUM" | sudo tee "$ARENA_REQ_MARKER" >/dev/null
                    echo "  ✅ Arena venv deps synced"
                else
                    echo "  ⚠️  uv pip install failed — sidecar may run stale deps; check manually."
                fi
            else
                echo "  requirements.txt unchanged — skipping dep sync."
            fi
        else
            echo "  ⚠️  uv not found — skipping Arena dep sync (venv left as-is)."
        fi
    fi
    # Restart to pick up freshly rsynced code, then health-gate (non-fatal).
    if sudo systemctl restart "$ARENA_SERVICE"; then
        # Poll rather than a single sleep: uvicorn + pm4py import takes several
        # seconds, so a one-shot 2s check false-warns on a perfectly healthy start.
        arena_ok=""
        for _ in 1 2 3 4 5 6 7 8; do
            sleep 2
            if systemctl is-active --quiet "$ARENA_SERVICE" \
               && curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${ARENA_PORT}/health" | grep -q '^2'; then
                arena_ok=1
                break
            fi
        done
        if [[ -n "$arena_ok" ]]; then
            echo "  ✅ Arena sidecar healthy on :${ARENA_PORT}"
        else
            echo "  ⚠️  Arena sidecar not healthy after ~16s — check: sudo journalctl -u ${ARENA_SERVICE} -n 50"
        fi
    else
        echo "  ⚠️  Failed to restart ${ARENA_SERVICE} — check: sudo systemctl status ${ARENA_SERVICE}"
    fi
else
    echo "Arena sidecar not installed on this host — skipping."
fi
# -------------------------------------------------------------------------

echo "🔄 Restarting Apache..."
# Restart Apache
sudo systemctl restart apache2

# Verify the deployment
echo "🔍 Verifying deployment..."

# Check if Apache is running
if ! systemctl is-active --quiet apache2; then
    echo "❌ Error: Apache failed to start"
    echo "💡 Check Apache logs: sudo journalctl -u apache2.service -n 50"
    exit 1
fi

if ! systemctl is-active --quiet zephyrus-queue-worker.service; then
    echo "❌ Error: Zephyrus queue worker failed to start"
    echo "💡 Check worker logs: sudo journalctl -u zephyrus-queue-worker.service -n 50"
    exit 1
fi

# Check if the site is responding. Target the Zephyrus vhost explicitly via the
# Host header — bare http://localhost resolves to the DEFAULT vhost (Aurora), not
# Zephyrus, so it would report a false failure even on a healthy deploy.
if ! curl -s -o /dev/null -w "%{http_code}" -H "Host: zephyrus.acumenus.net" http://localhost | grep -q "^[23]"; then
    echo "❌ Error: Site is not responding correctly"
    echo "💡 Check the Laravel logs: tail -f /var/www/Zephyrus/storage/logs/laravel.log"
    exit 1
fi

# A 200 response can still be a blank application shell when a stale Vite hot
# marker sends the browser to a development server. Prove that the live login
# document references production assets before declaring the release healthy.
LOGIN_HTML="$(curl --fail --silent --show-error https://zephyrus.acumenus.net/login)"
if grep -Eq 'https?://(localhost|127\.0\.0\.1):[0-9]+/(@vite|@react-refresh|resources/)' <<< "$LOGIN_HTML"; then
    echo "❌ Error: Live production HTML references a development Vite server"
    exit 1
fi
if ! grep -Eq '/build/assets/' <<< "$LOGIN_HTML"; then
    echo "❌ Error: Live production HTML does not reference built assets"
    exit 1
fi

# The application smoke proves reachability; this second probe proves that the
# public TLS boundary actually enforces the release's headers, method policy,
# and sensitive-path denial rules.
if ! sudo php /var/www/Zephyrus/scripts/security/verify-edge-security.php \
    --contract --apache --live=https://zephyrus.acumenus.net; then
    echo "❌ Error: Live production edge verification failed"
    exit 1
fi

# Check Laravel storage permissions
if ! sudo -u www-data test -w /var/www/Zephyrus/storage; then
    echo "❌ Error: Storage directory is not writable by www-data"
    echo "💡 Fix permissions: sudo chown -R www-data:www-data /var/www/Zephyrus/storage"
    exit 1
fi

echo "✅ All checks passed!"
echo "🎉 Deployment completed successfully at commit $RELEASE_COMMIT!"

# Print helpful information
echo "
💡 Helpful commands:"
echo "  - View Laravel logs: tail -f /var/www/Zephyrus/storage/logs/laravel.log"
echo "  - View Apache logs: sudo journalctl -u apache2.service -n 50"
echo "  - Check Apache status: sudo systemctl status apache2"
echo "  - Check worker status: sudo systemctl status zephyrus-queue-worker.service"
echo "  - Clear Laravel cache: cd /var/www/Zephyrus && php artisan cache:clear"
