# Deploy Authentication Updates - Execute Now

**Date**: February 28, 2026  
**Time**: 01:51 UTC  
**Status**: Ready to deploy

---

## Quick Deployment (Copy-Paste Ready)

### Step 1: Connect to Production

```bash
ssh smudoshi@ohdsi.acumenus.net
```

### Step 2: Run All Deployment Commands

**Copy and paste this entire block into your SSH session:**

```bash
#!/bin/bash
cd /var/www/Zephyrus

echo "==========================================="
echo "Zephyrus Authentication Update Deployment"
echo "==========================================="
echo ""

# Check if git repo exists, if not initialize
if [ ! -d .git ]; then
    echo "⚠️  Git not initialized. Initializing..."
    git init
    git remote add origin https://github.com/sudoshi/Zephyrus.git
fi

# Backup .env
echo "📦 Backing up .env..."
cp .env .env.backup.$(date +%Y%m%d_%H%M%S) 2>/dev/null || echo "No .env to backup"
echo "✓ Backup created"
echo ""

# Fetch and pull latest code
echo "⬇️  Pulling latest code from GitHub..."
git fetch origin main
git reset --hard origin/main
echo "✓ Code updated to latest"
echo ""

# Install/update Composer dependencies
echo "📚 Installing Composer dependencies..."
composer install --optimize-autoloader --no-dev --no-interaction 2>&1 | tail -5
echo "✓ Dependencies updated"
echo ""

# Clear all Laravel caches
echo "🧹 Clearing application caches..."
php artisan config:clear
php artisan route:clear  
php artisan cache:clear
php artisan view:clear
echo "✓ All caches cleared"
echo ""

# Run database migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force
echo "✓ Migrations complete"
echo ""

# Seed database with users
echo "👥 Seeding database with user accounts..."
php artisan db:seed --class=UserSeeder
echo "✓ Users created (admin, sanjay, acumenus, kartheek, hakan)"
echo ""

# Fix file permissions
echo "🔐 Setting file permissions..."
sudo chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache 2>/dev/null || chmod -R 775 storage bootstrap/cache
echo "✓ Permissions set"
echo ""

# Restart Apache
echo "🔄 Restarting Apache..."
sudo systemctl restart apache2 2>/dev/null || systemctl restart apache2
sleep 2
echo "✓ Apache restarted"
echo ""

# Check Apache status
echo "📊 Apache Status:"
sudo systemctl status apache2 --no-pager 2>/dev/null | head -5 || systemctl status apache2 --no-pager | head -5
echo ""

echo "==========================================="
echo "✅ DEPLOYMENT COMPLETE!"
echo "==========================================="
echo ""
echo "🎯 Next Steps:"
echo "   1. Visit: https://zephyrus.acumenus.net"
echo "   2. Verify login page appears"
echo "   3. Test login: admin / password"
echo "   4. ⚠️  CHANGE PASSWORD IMMEDIATELY (see below)"
echo ""
```

### Step 3: Test the Deployment

Open your browser:
- Visit: **https://zephyrus.acumenus.net**
- Expected: Login page should appear
- Try logging in with: **admin** / **password**
- Expected: Redirect to dashboard after successful login

### Step 4: CRITICAL - Change Default Password

**While still SSH'd into the server, run:**

```bash
cd /var/www/Zephyrus
php artisan tinker
```

**In the tinker console, paste:**

```php
echo "Changing admin password...\n";
$admin = User::where('username', 'admin')->first();
if ($admin) {
    $admin->password = bcrypt('NewSecurePassword123!');
    $admin->save();
    echo "✓ Admin password changed successfully!\n";
} else {
    echo "✗ Admin user not found!\n";
}
exit
```

---

## Troubleshooting

### If login page doesn't appear:

```bash
# Check Apache error logs
sudo tail -50 /var/log/apache2/error.log

# Check Laravel logs  
tail -50 /var/www/Zephyrus/storage/logs/laravel.log

# Clear caches again
cd /var/www/Zephyrus
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### If "User not found" when changing password:

```bash
# Check if users exist
cd /var/www/Zephyrus
php artisan tinker
```

```php
User::all(['username', 'email'])->toArray()
```

If empty, re-run seeder:
```bash
php artisan db:seed --class=UserSeeder
```

### If login fails after deployment:

```bash
# Verify database connection
cd /var/www/Zephyrus
php artisan tinker
```

```php
DB::connection()->getPdo();
```

### If redirected back to login after successful login:

```bash
# Check session configuration
cd /var/www/Zephyrus
cat .env | grep SESSION

# Ensure sessions table exists (if using database driver)
php artisan tinker
```

```php
\Schema::hasTable('sessions')
```

---

## Rollback (If Needed)

If something goes wrong:

```bash
cd /var/www/Zephyrus

# Restore .env backup
ls -la .env.backup.* | tail -1  # Find latest backup
cp .env.backup.YYYYMMDD_HHMMSS .env  # Restore it

# Rollback to previous commit (find commit hash from GitHub)
git reset --hard PREVIOUS_COMMIT_HASH

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Restart Apache
sudo systemctl restart apache2
```

---

## Verification Checklist

After deployment, verify:

- [ ] https://zephyrus.acumenus.net shows login page (not auto-login)
- [ ] Can log in with admin/password
- [ ] Redirected to dashboard after login
- [ ] Dashboard displays correctly
- [ ] Can access /analytics/block-utilization (requires auth)
- [ ] Can logout successfully
- [ ] After logout, redirected back to login
- [ ] Default password changed to secure password
- [ ] Can still login with new password

---

## Production Environment Check

Before closing, verify these settings:

```bash
cd /var/www/Zephyrus
cat .env | grep -E "(APP_ENV|APP_DEBUG|APP_URL|SESSION)"
```

Should show:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://zephyrus.acumenus.net
SESSION_DRIVER=database (or file)
```

---

## Success!

Once all checks pass:

1. ✅ Authentication is now properly secured
2. ✅ Login page required for all access
3. ✅ All routes protected with auth middleware
4. ✅ Modern login UI deployed
5. ✅ Default users created

---

## Support

If you encounter issues:
- Check `storage/logs/laravel.log` on the server
- Review `/var/log/apache2/error.log`
- Consult `AUTHENTICATION.md` for detailed troubleshooting
- Consult `DEPLOYMENT_CHECKLIST.md` for full procedures

---

**Ready to Deploy?** 

1. SSH into production
2. Copy-paste the deployment script above
3. Test the site
4. Change passwords
5. You're done!

**Deployment Time**: ~2-3 minutes  
**Downtime**: None expected (except Apache restart ~2 seconds)
