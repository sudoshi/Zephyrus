#!/bin/bash

# Script to deploy all changes, clear caches and restart Apache
# Run with sudo to ensure Apache can be restarted
# Example: sudo ./deploy-changes.sh

echo "===== Pulling latest changes from Git ====="
git pull origin main

echo "===== Clearing Laravel caches ====="
# Laravel cache clearing
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan event:clear
php artisan optimize:clear

echo "===== Restarting Apache ====="
# Restart Apache to apply .htaccess changes
if command -v systemctl &> /dev/null; then
    systemctl restart apache2 || service apache2 restart
else
    service apache2 restart
fi

echo "===== Deployment complete ====="
echo "The CSRF and Content Security Policy fixes have been deployed."
echo "If you experience any issues, please check the Apache error logs."
