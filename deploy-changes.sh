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

echo "===== Verifying database connection ====="
# Ensure the database is properly connected
php artisan db:monitor

echo "===== Creating admin user if needed ====="
# Run a small PHP script to ensure the admin user exists
php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::firstOrCreate(
    ["username" => "admin"],
    [
        "name" => "Administrator",
        "email" => "admin@example.com",
        "password" => \Illuminate\Support\Facades\Hash::make("password"),
        "workflow_preference" => "superuser"
    ]
);

echo "Admin user created or verified: " . $user->username . "\n";
'

echo "===== Deployment complete ====="
echo "The auto-login solution has been deployed."
echo "The application now bypasses login and uses an admin account automatically."
echo "Visit the site at: https://demo.zephyrus.care"
