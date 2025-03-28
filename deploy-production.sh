#!/bin/bash
# Deployment script for Zephyrus production environment

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

# Ensure bootstrap/cache directory exists with correct permissions
log "Setting up bootstrap/cache directory..."
mkdir -p /var/www/Zephyrus/bootstrap/cache
sudo_cmd chown -R www-data:www-data /var/www/Zephyrus/bootstrap/cache
sudo_cmd chmod -R 775 /var/www/Zephyrus/bootstrap/cache

# Install/update PHP dependencies
log "Installing PHP dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Install/update Node dependencies
log "Installing Node dependencies..."
npm install

# Build assets with proper permissions
log "Setting up build directory..."
# Create build directory if it doesn't exist
mkdir -p /var/www/Zephyrus/public/build
# Set permissions before building
sudo_cmd chown -R $(whoami):$(whoami) /var/www/Zephyrus/public/build

log "Building assets..."
npm run build

# Reset permissions after build
sudo_cmd chown -R www-data:www-data /var/www/Zephyrus/public/build

# Update .env file to use database sessions
log "Updating .env file to use database sessions..."
if grep -q "^SESSION_DRIVER=" .env; then
    # Replace the existing SESSION_DRIVER line
    sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=database/' .env
    log "Updated SESSION_DRIVER to 'database' in .env file."
else
    # Add SESSION_DRIVER if it doesn't exist
    echo "SESSION_DRIVER=database" >> .env
    log "Added SESSION_DRIVER=database to .env file."
fi

# Run migrations to ensure sessions table exists
log "Running migrations to ensure sessions table exists..."
php artisan migrate --force

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
