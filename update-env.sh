#!/bin/bash

# Script to update the .env file on the server

# Check if we're in the right directory
if [ ! -f ".env" ]; then
    echo "Error: .env file not found. Make sure you're in the project root directory."
    exit 1
fi

# Update the SESSION_DRIVER in the .env file
if grep -q "^SESSION_DRIVER=" .env; then
    # Replace the existing SESSION_DRIVER line
    sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=database/' .env
    echo "Updated SESSION_DRIVER to 'database' in .env file."
else
    # Add SESSION_DRIVER if it doesn't exist
    echo "SESSION_DRIVER=database" >> .env
    echo "Added SESSION_DRIVER=database to .env file."
fi

# Generate a new application key
php artisan key:generate --force
echo "Generated new application key."

# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
echo "Cleared all caches."

# Check if the sessions table exists
php artisan migrate --force
echo "Ran migrations to ensure sessions table exists."

echo "Environment update complete. Please restart your web server."
