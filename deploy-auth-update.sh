#!/bin/bash

# Zephyrus Authentication Update Deployment Script
# Run this script to deploy authentication changes to production

set -e  # Exit on any error

echo "========================================="
echo "Zephyrus Authentication Update Deployment"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Production server details
PROD_HOST="ohdsi.acumenus.net"
PROD_USER="smudoshi"
PROD_PATH="/var/www/Zephyrus"

echo -e "${YELLOW}Step 1: Checking GitHub Actions status...${NC}"
gh run list --limit 1
echo ""

echo -e "${YELLOW}Step 2: SSH into production server and deploy...${NC}"
echo "Connecting to ${PROD_USER}@${PROD_HOST}..."
echo ""

ssh ${PROD_USER}@${PROD_HOST} << 'ENDSSH'
set -e

echo "========================================="
echo "Production Server Deployment"
echo "========================================="
echo ""

cd /var/www/Zephyrus

# Note current commit for rollback
echo "Current commit (for rollback):"
git log -1 --oneline
echo ""

# Backup .env file
echo "Backing up .env file..."
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
echo "✓ Backup created"
echo ""

# Pull latest code
echo "Pulling latest code from GitHub..."
git fetch origin main
git pull origin main
echo "✓ Code updated"
echo ""

# Install dependencies
echo "Installing/updating Composer dependencies..."
composer install --optimize-autoloader --no-dev --no-interaction
echo "✓ Dependencies updated"
echo ""

# Clear caches
echo "Clearing application caches..."
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
echo "✓ Caches cleared"
echo ""

# Run migrations
echo "Running database migrations..."
php artisan migrate --force
echo "✓ Migrations complete"
echo ""

# Seed database
echo "Seeding database with user accounts..."
php artisan db:seed --class=UserSeeder
echo "✓ Database seeded"
echo ""

# Set proper permissions
echo "Setting file permissions..."
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
echo "✓ Permissions set"
echo ""

# Restart Apache
echo "Restarting Apache..."
sudo systemctl restart apache2
sleep 2
echo "✓ Apache restarted"
echo ""

# Check Apache status
echo "Checking Apache status..."
sudo systemctl status apache2 --no-pager | head -10
echo ""

echo "========================================="
echo "Deployment Complete!"
echo "========================================="
echo ""
echo "Next steps:"
echo "1. Visit https://zephyrus.acumenus.net"
echo "2. Verify login page appears"
echo "3. Test login with admin/password"
echo "4. CHANGE DEFAULT PASSWORDS IMMEDIATELY"
echo ""

ENDSSH

echo ""
echo -e "${GREEN}Deployment script completed!${NC}"
echo ""
echo -e "${YELLOW}CRITICAL: Change default passwords now!${NC}"
echo "Run this command on production server:"
echo ""
echo "  ssh ${PROD_USER}@${PROD_HOST}"
echo "  cd ${PROD_PATH}"
echo "  php artisan tinker"
echo ""
echo "Then run:"
echo "  \$admin = User::where('username', 'admin')->first();"
echo "  \$admin->password = bcrypt('YOUR_NEW_PASSWORD');"
echo "  \$admin->save();"
echo ""
echo -e "${GREEN}Testing URLs:${NC}"
echo "  Main: https://zephyrus.acumenus.net"
echo "  Login: https://zephyrus.acumenus.net/login"
echo ""
