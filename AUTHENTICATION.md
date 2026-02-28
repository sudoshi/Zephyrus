# Authentication Setup Guide

## Overview

Zephyrus now requires proper authentication. The auto-login bypass has been removed to ensure proper security in production.

## Changes Made

### Root Route Behavior
- **Before**: Automatically logged in as admin and redirected to dashboard
- **After**: Redirects to login page if not authenticated, or to dashboard if already logged in

### Protected Routes
All application routes now require authentication via the `auth` middleware:
- Dashboard routes (`/dashboard`, `/dashboard/rtdc`, etc.)
- Analytics routes (`/analytics/*`)
- Operations routes (`/operations/*`)
- Predictions routes (`/predictions/*`)
- RTDC routes (`/rtdc/*`)
- ED routes (`/ed/*`)
- Improvement routes (`/improvement/*`)

### Login Page
A modernized login page is available at `/login` with:
- Clean, professional UI using HeroUI components
- Password visibility toggle
- Remember me functionality
- Dark mode support
- Demo credentials display

## Default User Accounts

Run the seeder to create default users:

```bash
php artisan db:seed --class=UserSeeder
```

### Available Accounts

| Username | Password | Name | Workflow Preference | Description |
|----------|----------|------|---------------------|-------------|
| `admin` | `password` | Administrator | Superuser | System administrator |
| `sanjay` | `sanjay` | Sanjay | Perioperative | Perioperative workflow user |
| `acumenus` | `acumenus` | Acumenus | Superuser | Superuser access |
| `kartheek` | `kartheek` | Kartheek | RTDC | RTDC workflow user |
| `hakan` | `hakan` | Hakan | Improvement | Improvement workflow user |

## Local Development Setup

### First Time Setup

1. **Run migrations** (if not already done):
   ```bash
   php artisan migrate
   ```

2. **Seed the database** with default users:
   ```bash
   php artisan db:seed --class=UserSeeder
   ```

3. **Access the application**:
   - Visit http://localhost:8001
   - You'll be redirected to the login page
   - Use any of the default credentials above

### Creating Additional Users

You can create additional users via:

1. **Laravel Tinker**:
   ```bash
   php artisan tinker
   ```
   ```php
   User::create([
       'name' => 'John Doe',
       'email' => 'john@example.com',
       'username' => 'johndoe',
       'password' => bcrypt('yourpassword'),
       'workflow_preference' => 'perioperative'
   ]);
   ```

2. **Database Seeder**: Add to `database/seeders/UserSeeder.php`

3. **Registration Page**: Visit `/register` (if enabled)

## Production Deployment

### Important Security Notes

⚠️ **Before deploying to production:**

1. **Change default passwords** for all seeded accounts
2. **Re-enable CSRF protection** on routes (currently disabled for development)
3. **Review user seeder** - consider disabling auto-seeding in production
4. **Set strong APP_KEY** in `.env`
5. **Use HTTPS** for all production traffic
6. **Enable session security** settings in `config/session.php`

### Production Environment Variables

Ensure these are properly set in production `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_PRODUCTION_KEY_HERE

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
```

### Re-enabling CSRF Protection

In `routes/web.php`, remove the `withoutMiddleware` call:

```php
// Development (current):
Route::middleware(['auth'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->group(function () {
        // routes...
    });

// Production (recommended):
Route::middleware(['auth'])->group(function () {
    // routes...
});
```

## Troubleshooting

### "Session expired" errors
- Clear your browser cookies and cache
- Run `php artisan config:clear`
- Check that `SESSION_DRIVER` in `.env` matches your setup

### Can't log in with default credentials
- Ensure you've run the seeder: `php artisan db:seed --class=UserSeeder`
- Check the database to verify users exist: `php artisan tinker` → `User::all()`
- Try clearing the application cache: `php artisan cache:clear`

### Redirected to login after logging in
- Check that the user exists in the database
- Verify session is working: check `storage/framework/sessions`
- Ensure cookies are enabled in your browser

### Auto-redirect to dashboard not working
- Clear route cache: `php artisan route:clear`
- Restart the development server
- Check that HandleInertiaRequests middleware is sharing auth data

## API Authentication (Future)

For API endpoints, consider implementing:
- Laravel Sanctum token authentication
- OAuth2 via Laravel Passport
- JWT tokens

## Related Files

- `routes/web.php` - Route definitions with auth middleware
- `routes/auth.php` - Authentication routes (login, register, password reset)
- `app/Http/Middleware/HandleInertiaRequests.php` - Shares auth data with frontend
- `database/seeders/UserSeeder.php` - Creates default users
- `resources/js/Pages/Auth/Login.jsx` - Login page component
- `resources/js/Layouts/GuestLayout.jsx` - Layout for authentication pages

## Support

For issues or questions:
- Check the DEVLOG.md for technical details
- Review Laravel Breeze documentation
- Consult the Inertia.js authentication guide

---

**Last Updated**: February 28, 2026  
**Version**: 1.0
