#!/bin/bash
# Deploy script for Zephyrus

# Exit on error
set -e

echo "Starting deployment process..."

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

echo "Restarting Apache..."
# Restart Apache
sudo systemctl restart apache2

echo "Deployment completed successfully!"
