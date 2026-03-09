# Authentication System — DO NOT MODIFY

## CRITICAL: Protected Auth Components

The following authentication system is production-deployed and MUST NOT be overwritten, removed, or architecturally changed without explicit user authorization:

### Backend (Laravel)
- `app/Http/Controllers/Auth/RegisteredUserController.php` — Modified registration: generates temp password, sends via Resend, no auto-login
- `app/Http/Controllers/Auth/ChangePasswordController.php` — Forced password change (show + update)
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — Login redirects to /change-password when must_change_password is true
- `app/Http/Middleware/HandleInertiaRequests.php` — Shares must_change_password with frontend
- `routes/auth.php` — Includes GET/POST /change-password routes
- `config/services.php` — Resend API key configuration

### Frontend (React/Inertia)
- `resources/js/Pages/Auth/Login.jsx` — Login form with "Create Account" CTA section
- `resources/js/Pages/Auth/Register.jsx` — Registration form (name, email, phone — no password fields)
- `resources/js/Pages/Auth/ChangePassword.jsx` — Dedicated change password page
- `resources/js/Components/ChangePasswordModal.jsx` — Non-dismissable blocking modal for authenticated pages
- `resources/js/Layouts/AuthenticatedLayout.jsx` — Renders ChangePasswordModal when must_change_password is true

### Database Schema
- `prod.users` table includes: must_change_password (boolean, default true), role (varchar, default 'user'), is_active (boolean, default true), phone (varchar)
- Username auto-generated from email prefix on registration

## Enforced Auth Flow (MediCosts Paradigm)

1. Visitor clicks "Create Account" on login page
2. Enters: name, email, phone (optional) — NO password fields
3. Backend auto-generates username from email, generates 12-char temp password
4. Temp password emailed via Resend API (from: Zephyrus <noreply@acumenus.net>)
5. Visitor logs in with username + temp password
6. Redirected to /change-password page AND ChangePasswordModal blocks authenticated pages
7. After password change: must_change_password = false, full app access

## Rules

1. **NEVER remove the "Create Account" section from Login.jsx**
2. **NEVER remove or make the ChangePasswordModal dismissable**
3. **NEVER add password fields back to the Register page** — temp passwords only
4. **NEVER bypass the must_change_password redirect in AuthenticatedSessionController**
5. **NEVER remove the ChangePasswordModal from AuthenticatedLayout**
6. **NEVER change the email sender from noreply@acumenus.net**
7. **NEVER hardcode the Resend API key in source code** (use RESEND_API_KEY env var)
8. **NEVER remove email enumeration prevention** on registration
9. **NEVER weaken password requirements** (min 8 chars, bcrypt via Laravel Hash)
10. **Superuser account** `admin@acumenus.net` must always exist with must_change_password=false
11. **If modifying auth**, preserve ALL existing endpoints and their behavior — additions only
12. **NEVER revert Register to accept user-chosen passwords** — the temp password + Resend email flow is mandatory

## Resend Configuration
- API Key: RESEND_API_KEY in .env (deployed at /var/www/Zephyrus/.env)
- Config: `config/services.php` → `resend.key`
- From: `Zephyrus <noreply@acumenus.net>`
