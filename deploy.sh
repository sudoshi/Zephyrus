#!/bin/bash
# Deploy script for Zephyrus

# Exit on error
set -e

# Check if we're in the development directory
if [[ "$(pwd)" != "/home/acumenus/GitHub/Zephyrus"* ]]; then
    echo "❌ Error: This script must be run from the development directory"
    echo "📂 Current directory: $(pwd)"
    echo "📂 Expected directory: /home/acumenus/GitHub/Zephyrus"
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
            /home/acumenus/GitHub/Zephyrus/ /var/www/Zephyrus/

echo "Setting permissions..."
# Set proper permissions
sudo chown -R www-data:www-data /var/www/Zephyrus/storage
sudo chown -R www-data:www-data /var/www/Zephyrus/bootstrap/cache
sudo chown -R www-data:www-data /var/www/Zephyrus/public/build

echo "Clearing Laravel caches..."
# Clear Laravel caches
cd /var/www/Zephyrus
php artisan cache:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear

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

# Check if the site is responding
if ! curl -s -o /dev/null -w "%{http_code}" http://localhost | grep -q "^[23]"; then
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
echo "  - Clear Laravel cache: cd /var/www/Zephyrus && php artisan cache:clear"
