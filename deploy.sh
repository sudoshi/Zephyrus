#!/bin/bash
# Deploy script for Zephyrus

# Exit on error
set -e

# Check if we're in the development directory
if [[ "$(pwd)" != "/home/smudoshi/Github/Zephyrus"* ]]; then
    echo "❌ Error: This script must be run from the development directory"
    echo "📂 Current directory: $(pwd)"
    echo "📂 Expected directory: /home/smudoshi/Github/Zephyrus"
    exit 1
fi

# Check for uncommitted changes
if [[ -n $(git status -s) ]]; then
    echo "❌ Error: You have uncommitted changes"
    echo "💡 Please commit or stash your changes before deploying"
    git status
    exit 1
fi

# Check if we're behind the remote
echo "📡 Checking remote status..."
git fetch origin
LOCAL=$(git rev-parse @)
REMOTE=$(git rev-parse @{u})
BASE=$(git merge-base @ @{u})

if [ $LOCAL = $REMOTE ]; then
    echo "✅ Local branch is up to date"
elif [ $LOCAL = $BASE ]; then
    echo "❌ Error: Your local branch is behind the remote"
    echo "💡 Please pull the latest changes before deploying"
    exit 1
fi

echo "🚀 Starting deployment process..."

# Switch to the project directory
cd "$(dirname "$0")"

echo "Building assets..."
# Build assets
NODE_ENV=production npm run build

echo "Syncing to production..."
# Sync to production (excluding node_modules, .git, etc)
sudo rsync -av --exclude 'node_modules' \
            --exclude '.git' \
            --exclude '.env' \
            --exclude 'storage/logs/*' \
            --exclude 'storage/framework/cache/*' \
            --exclude '.github' \
            --exclude 'tests' \
            --exclude 'deploy.sh' \
            /home/smudoshi/Github/Zephyrus/ /var/www/Zephyrus/

echo "Setting permissions..."
# rsync -a preserves dev (smudoshi) ownership, but Apache/PHP-FPM runs as www-data.
# The ENTIRE tree must be www-data-owned or vendor/autoload reads fail with a
# site-wide 500 (e.g. "Permission denied" on vendor/.../functions_include.php).
sudo chown -R www-data:www-data /var/www/Zephyrus

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

# Check Laravel storage permissions
if ! sudo -u www-data test -w /var/www/Zephyrus/storage; then
    echo "❌ Error: Storage directory is not writable by www-data"
    echo "💡 Fix permissions: sudo chown -R www-data:www-data /var/www/Zephyrus/storage"
    exit 1
fi

echo "✅ All checks passed!"
echo "🎉 Deployment completed successfully!"

# Print helpful information
echo "
💡 Helpful commands:"
echo "  - View Laravel logs: tail -f /var/www/Zephyrus/storage/logs/laravel.log"
echo "  - View Apache logs: sudo journalctl -u apache2.service -n 50"
echo "  - Check Apache status: sudo systemctl status apache2"
echo "  - Check worker status: sudo systemctl status zephyrus-queue-worker.service"
echo "  - Clear Laravel cache: cd /var/www/Zephyrus && php artisan cache:clear"
