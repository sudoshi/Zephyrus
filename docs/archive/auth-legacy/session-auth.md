# Session-Based Authentication

This document describes the session-based authentication system implemented in the Zephyrus application.

## Overview

The application has been updated to use a simplified session-based authentication system instead of CSRF tokens. This change was made to improve reliability and simplify the authentication flow.

## How It Works

1. **Session Storage**: User authentication state is stored in the database using Laravel's built-in session management.

2. **Authentication Flow**:
   - When a user logs in, their user ID is stored in the session.
   - The `SessionAuthMiddleware` validates the session on each request.
   - If the session is valid and contains a user ID, the user is automatically authenticated.
   - If the session is invalid or doesn't contain a user ID, the user is redirected to the login page.

3. **Security Considerations**:
   - Sessions are stored in the database, making them more secure than client-side tokens.
   - Session IDs are regenerated on login to prevent session fixation attacks.
   - Sessions have a configurable lifetime and can be invalidated server-side if needed.

## Implementation Details

The following components were modified or created to implement this system:

1. **SessionAuthMiddleware**: A new middleware that validates the session and automatically logs in the user if a valid session exists.

2. **AuthenticatedSessionController**: Updated to store the user ID in the session on login and invalidate the session on logout.

3. **Frontend Code**: Updated to remove CSRF token handling and rely solely on session cookies for authentication.

## Removed CSRF Components

The following CSRF-related components have been deprecated and are no longer used:

- `VerifyCsrfToken` middleware
- `BypassCsrfMiddleware` middleware
- `DisableCsrfForAllRoutes` middleware
- `AddXsrfTokenMiddleware` middleware
- `CsrfServiceProvider` service provider

## Configuration

The session configuration can be adjusted in the `.env` file or `config/session.php`:

- `SESSION_DRIVER`: Set to "database" to store sessions in the database.
- `SESSION_LIFETIME`: The number of minutes that sessions should be valid.
- `SESSION_SECURE_COOKIE`: Set to "true" for HTTPS-only cookies.
- `SESSION_SAME_SITE`: Set to "lax" to prevent CSRF attacks while allowing links to work.

## Troubleshooting

If you encounter authentication issues:

1. Check that the session table exists in the database.
2. Verify that the session driver is set to "database" in the configuration.
3. Clear the browser cookies and try logging in again.
4. Check the server logs for any session-related errors.
