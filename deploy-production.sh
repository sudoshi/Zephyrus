#!/bin/bash

# Check if SUDO_PASSWORD is set
if [ -z "$SUDO_PASSWORD" ]; then
    echo "Error: SUDO_PASSWORD environment variable is not set"
    exit 1
fi

# Function to run sudo commands with password
sudo_cmd() {
    echo "$SUDO_PASSWORD" | sudo -S $@
}

# Exit on error
set -e

# Function to log messages
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Check if we're in the correct directory
if [ ! -d "/var/www/Zephyrus" ]; then
    log "Error: /var/www/Zephyrus directory not found"
    exit 1
fi

cd /var/www/Zephyrus

# Stash any local changes
log "Stashing local changes..."
git stash --include-untracked

# Pull latest changes
log "Pulling latest changes..."
git pull origin main

# Install/update PHP dependencies
log "Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Install/update Node dependencies
log "Installing Node dependencies..."
npm install

# Build assets with proper permissions
log "Building assets..."
sudo_cmd chown -R www-data:www-data /var/www/Zephyrus/public/build
npm run build

# Clear caches
log "Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Optimize
log "Optimizing..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Update permissions
log "Updating permissions..."
sudo_cmd chown -R www-data:www-data /var/www/Zephyrus/storage
sudo_cmd chown -R www-data:www-data /var/www/Zephyrus/bootstrap/cache

# Restart PHP-FPM
log "Restarting PHP-FPM..."
sudo_cmd systemctl restart php8.2-fpm

log "Deployment completed successfully!"
