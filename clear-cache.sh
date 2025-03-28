#!/bin/bash

# Script to clear all Laravel caches after deployment
# This helps ensure our CSRF changes take effect

echo "Clearing all Laravel caches..."

# Navigate to the project directory
cd "$(dirname "$0")"

# Clear various Laravel caches
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan event:clear

# More aggressive cache clearing
php artisan optimize:clear

echo "All caches cleared."

# Also clear Apache cache if mod_cache is installed
if command -v apachectl &> /dev/null; then
    echo "Attempting to restart Apache to clear mod_cache..."
    sudo service apache2 restart || echo "Could not restart Apache (may need sudo privileges)"
fi

echo "Cache clearing completed"
