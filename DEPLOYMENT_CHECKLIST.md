# Production Deployment Checklist
## Zephyrus Authentication & Security Update

**Deployment Date**: February 28, 2026  
**Changes**: Authentication fixes, modernized login page, security improvements  
**Breaking Changes**: ✅ Yes - Auto-login removed, authentication now required

---

## Pre-Deployment Checklist

### 1. Code Review
- [x] Authentication changes reviewed
- [x] Login page modernization complete
- [x] User seeder updated with default accounts
- [x] Routes properly protected with auth middleware
- [x] Changes committed and pushed to GitHub

### 2. Local Testing
- [ ] Tested login flow locally
- [ ] Verified all default credentials work
- [ ] Tested logout functionality
- [ ] Verified redirect to login when not authenticated
- [ ] Tested dashboard access after login

### 3. Database Preparation
- [ ] Database seeder ready (`UserSeeder.php`)
- [ ] Confirmed seeder creates admin user
- [ ] Migration status checked (`php artisan migrate:status`)

---

## Deployment Steps

### Step 1: Backup Production

```bash
# SSH into production
ssh smudoshi@ohdsi.acumenus.net

# Backup database
cd /var/www/Zephyrus
php artisan backup:run  # If backup package installed

# Or manual backup
mysqldump -u [user] -p [database] > backup_$(date +%Y%m%d_%H%M%S).sql
# OR for PostgreSQL:
pg_dump -U [user] [database] > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup .env file
cp .env .env.backup

# Note current git commit
git log -1 --oneline
```

**Rollback Point**: `_________________________`

---

### Step 2: Deploy Code Changes

#### Option A: Automatic Deployment (GitHub Actions)

```bash
# Check GitHub Actions status
# Visit: https://github.com/sudoshi/Zephyrus/actions

# Wait for deployment to complete (should auto-deploy on push to main)
```

Status: [ ] Deployment workflow completed successfully

#### Option B: Manual Deployment

```bash
# From local machine
./deploy.sh

# Or step-by-step:
npm run build
rsync -avz --exclude 'node_modules' --exclude '.git' ./ smudoshi@ohdsi.acumenus.net:/var/www/Zephyrus/
```

Status: [ ] Code deployed to production

---

### Step 3: Production Server Tasks

```bash
# SSH into production
ssh smudoshi@ohdsi.acumenus.net

# Navigate to application directory
cd /var/www/Zephyrus

# Pull latest code (if not already done)
git pull origin main

# Install/update dependencies
composer install --optimize-autoloader --no-dev

# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

# Run migrations (if any new)
php artisan migrate --force

# Seed database with users
php artisan db:seed --class=UserSeeder
```

**Tasks Status**:
- [ ] Code pulled from GitHub
- [ ] Composer dependencies installed
- [ ] Caches cleared
- [ ] Migrations run
- [ ] Database seeded with users

---

### Step 4: Verify Environment Configuration

```bash
# Check critical .env settings
cat .env | grep -E "(APP_ENV|APP_DEBUG|SESSION_DRIVER|SESSION_SECURE_COOKIE)"
```

**Required Settings**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://zephyrus.acumenus.net
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Status: [ ] Environment configured correctly

---

### Step 5: Restart Services

```bash
# Restart Apache
sudo systemctl restart apache2

# Check Apache status
sudo systemctl status apache2

# Check error logs if needed
sudo tail -f /var/log/apache2/error.log
```

Status: [ ] Services restarted successfully

---

## Post-Deployment Testing

### Step 6: Verify Login Flow

Test these scenarios:

1. **Unauthenticated Access**
   - [ ] Visit `https://zephyrus.acumenus.net/`
   - [ ] Redirected to login page
   - [ ] Login page displays correctly
   - [ ] Dark mode toggle works

2. **Login with Admin Account**
   - [ ] Enter username: `admin`
   - [ ] Enter password: `password`
   - [ ] Click "Sign In"
   - [ ] Successfully redirected to dashboard
   - [ ] User info displayed in header

3. **Protected Routes**
   - [ ] Try accessing `/dashboard` without login → redirects to login
   - [ ] Try accessing `/analytics/block-utilization` → requires auth
   - [ ] After login, all routes accessible

4. **Logout**
   - [ ] Click logout in user menu
   - [ ] Redirected to login page
   - [ ] Session cleared properly

5. **Other User Accounts**
   - [ ] Test login with `sanjay/sanjay`
   - [ ] Test login with `kartheek/kartheek`
   - [ ] Verify workflow preferences work

---

## Security Hardening (Critical)

### Step 7: Change Default Passwords

**⚠️ MUST BE DONE IMMEDIATELY AFTER DEPLOYMENT**

```bash
# On production server
cd /var/www/Zephyrus
php artisan tinker
```

```php
// Change admin password
$admin = User::where('username', 'admin')->first();
$admin->password = bcrypt('NEW_STRONG_PASSWORD_HERE');
$admin->save();

// Change other default passwords
$users = ['sanjay', 'acumenus', 'kartheek', 'hakan'];
foreach ($users as $username) {
    $user = User::where('username', $username)->first();
    if ($user) {
        $user->password = bcrypt('NEW_PASSWORD_' . strtoupper($username));
        $user->save();
    }
}

exit;
```

**New Passwords** (store securely):
- Admin: `_________________________`
- Sanjay: `_________________________`
- Acumenus: `_________________________`
- Kartheek: `_________________________`
- Hakan: `_________________________`

Status: [ ] All default passwords changed

---

### Step 8: Review CSRF Protection

**Current State**: CSRF protection disabled for development

**Action Required**: Re-enable for production

Edit `routes/web.php`:

```php
// Remove this line:
->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])

// Keep only:
Route::middleware(['auth'])->group(function () {
    // routes...
});
```

Status: [ ] CSRF protection reviewed (re-enable recommended)

---

### Step 9: Monitor Logs

```bash
# Watch Laravel logs
tail -f /var/www/Zephyrus/storage/logs/laravel.log

# Watch Apache error logs
sudo tail -f /var/log/apache2/error.log

# Watch Apache access logs
sudo tail -f /var/log/apache2/access.log
```

**Look for**:
- Authentication errors
- Session issues
- 500 errors
- CSRF token mismatch errors (if re-enabled)

Status: [ ] Logs monitored for 15 minutes post-deployment

---

## Rollback Procedure (If Needed)

If critical issues occur:

```bash
# SSH into production
ssh smudoshi@ohdsi.acumenus.net
cd /var/www/Zephyrus

# Rollback to previous commit
git reset --hard [ROLLBACK_COMMIT_HASH]

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Restart Apache
sudo systemctl restart apache2

# Restore database if needed
mysql -u [user] -p [database] < backup_[timestamp].sql
# OR for PostgreSQL:
psql -U [user] [database] < backup_[timestamp].sql
```

---

## Success Criteria

Deployment is successful when:

- [x] All code changes deployed to production
- [ ] Login page accessible at root URL
- [ ] Admin can log in with default credentials
- [ ] All protected routes require authentication
- [ ] Dashboard accessible after login
- [ ] Logout works correctly
- [ ] No critical errors in logs
- [ ] Default passwords changed
- [ ] Session/cookies working properly
- [ ] All 5 workflow dashboards accessible

---

## Known Issues & Workarounds

### Issue: "Session expired" after login
**Workaround**: Clear browser cookies, verify `SESSION_DOMAIN` not set incorrectly

### Issue: Redirected to login in a loop
**Workaround**: Check session driver, verify database sessions table exists

### Issue: CSRF token mismatch (if CSRF re-enabled)
**Workaround**: Ensure `meta name="csrf-token"` in `app.blade.php`

---

## Communication

### Notify Stakeholders

- [ ] Development team notified of deployment
- [ ] Users informed of authentication requirement
- [ ] New login credentials distributed securely
- [ ] Support team briefed on troubleshooting

### Update Documentation

- [x] AUTHENTICATION.md created
- [x] DEVLOG.md updated
- [x] BUSINESS_PLAN.md available
- [ ] Internal wiki updated (if applicable)

---

## Sign-Off

**Deployed By**: _________________________  
**Deployment Time**: _________________________  
**Deployment Status**: [ ] Success [ ] Failed [ ] Rolled Back  
**Issues Encountered**: _________________________  
**Resolution Time**: _________________________  

---

## Post-Deployment Tasks (Next 24-48 Hours)

- [ ] Monitor error rates
- [ ] Check user login success rates
- [ ] Review session duration metrics
- [ ] Gather user feedback on new login experience
- [ ] Schedule security audit
- [ ] Plan CSRF re-enablement (if not done)
- [ ] Document any production-specific issues

---

**Deployment Completed**: _______________ (Date/Time)  
**Next Review**: _______________ (Date/Time)

---

## Quick Reference Commands

```bash
# Check application status
curl -I https://zephyrus.acumenus.net

# Check database connections
php artisan tinker
>>> DB::connection()->getPdo();

# List all users
php artisan tinker
>>> User::select('username', 'email', 'workflow_preference')->get();

# Clear specific cache
php artisan cache:forget [key]

# Check queue workers (if using)
php artisan queue:work --once

# View last 50 lines of log
tail -50 storage/logs/laravel.log
```

---

**Document Version**: 1.0  
**Last Updated**: February 28, 2026
