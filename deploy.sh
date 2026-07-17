#!/bin/bash
# Deploy script for Zephyrus

set -Eeuo pipefail

EXPECTED_REPOSITORY_ROOT="/home/smudoshi/Github/Zephyrus"
REPOSITORY_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"

# Production releases must originate from the canonical main checkout. Other
# worktrees are useful for development, but are not release sources.
if [[ "$REPOSITORY_ROOT" != "$EXPECTED_REPOSITORY_ROOT" ]]; then
    echo "❌ Error: This script must be run from the canonical development checkout"
    echo "📂 Current repository: ${REPOSITORY_ROOT:-not a Git worktree}"
    echo "📂 Expected repository: $EXPECTED_REPOSITORY_ROOT"
    exit 1
fi
cd "$REPOSITORY_ROOT"

CURRENT_BRANCH="$(git branch --show-current)"
UPSTREAM="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || true)"
if [[ "$CURRENT_BRANCH" != "main" || "$UPSTREAM" != "origin/main" ]]; then
    echo "❌ Error: Production deployments must come from main tracking origin/main"
    echo "🌿 Current branch: ${CURRENT_BRANCH:-detached HEAD}"
    echo "📡 Current upstream: ${UPSTREAM:-none}"
    exit 1
fi

# Check for uncommitted or untracked source before resolving the release.
if [[ -n "$(git status --porcelain=v1 --untracked-files=normal)" ]]; then
    echo "❌ Error: You have uncommitted changes"
    echo "💡 Please commit or stash your changes before deploying"
    git status
    exit 1
fi

echo "📡 Checking remote status..."
git fetch --quiet origin main
RELEASE_COMMIT="$(git rev-parse HEAD)"
REMOTE_COMMIT="$(git rev-parse origin/main)"

if [[ "$RELEASE_COMMIT" != "$REMOTE_COMMIT" ]]; then
    echo "❌ Error: main must exactly match origin/main before deploying"
    echo "🏠 Local main:  $RELEASE_COMMIT"
    echo "📡 Origin main: $REMOTE_COMMIT"
    exit 1
fi
echo "✅ main is current at $RELEASE_COMMIT"

if [[ ! -f "$REPOSITORY_ROOT/vendor/autoload.php" ]]; then
    echo "❌ Error: Composer dependencies are missing; run composer install"
    exit 1
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
if [[ "${DEPLOY_RUN_MIGRATIONS:-0}" == "1" ]]; then
    echo "Running database migrations..."
    sudo -u www-data php artisan migrate --force
fi
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
