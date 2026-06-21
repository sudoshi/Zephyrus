# Authentik OIDC Login for Zephyrus — Design Spec

- **Date:** 2026-06-21
- **Status:** Approved (design); implementation pending
- **Author:** Dr. Sanjay Udoshi (with Claude Code)
- **Related rules:** `.claude/rules/auth-system.md` (protected auth — additions only)
- **Pattern source:** Aurora `App\Services\Auth\Oidc\*` (Parthenon-style hardened OIDC),
  `[[smudoshi-pg-password-rotation]]`, `project_authentik_oidc_pattern`,
  `project_medgnosis_authentik_sso`

## 1. Goal

Add a **"Sign in with Authentik"** option to the Zephyrus login page as an
**additive** authentication path alongside the existing username/password +
temp-password flow. No existing auth behavior is removed or weakened.

Decided scope (user, 2026-06-21):
- **Approach:** Full Parthenon/Aurora hardened OIDC stack (not the lightweight
  Socialite path), adapted to Zephyrus's simpler auth model.
- **Provisioning:** JIT (just-in-time) account creation on first SSO login,
  **gated by Authentik group membership**.

## 2. Context: how Zephyrus auth differs from Aurora

| Concern | Aurora | Zephyrus |
|---|---|---|
| Guard | `sanctum` | `web` |
| Roles | Spatie RBAC | `prod.users.role` varchar (default `user`) |
| Session | API token | custom session bits (`user_id`, `username`, `workflow_preference`) |
| Schema | `app`,`clinical`,… | `prod` |
| Frontend | React SPA + API | Inertia + React (JSX) |
| Forced PW change | — | `must_change_password` flow + blocking modal |

The Aurora OIDC services are guard-agnostic and port cleanly; the **driver,
controller, reconciliation, and migrations** are adapted to the table above.

## 3. Authentik configuration (provisioned via bootstrap-token script)

Authentik runs in Acropolis at `auth.acumenus.net`. No `zephyrus` app exists yet
(verified 2026-06-21). Following the **dual-app rule** (never repoint an existing
forward-auth app's provider), we create a *new* OIDC application.

- **OAuth2/OpenID provider** + **application** `zephyrus-oidc`.
- **Redirect URI:** `https://zephyrus.acumenus.net/auth/oidc/callback`
- **Scopes:** `openid profile email groups`
- **Issuer / discovery:**
  `https://auth.acumenus.net/application/o/zephyrus-oidc/.well-known/openid-configuration`
- **Groups:**
  - `Zephyrus Users` — JIT gate (membership required to auto-provision).
  - `Zephyrus Admins` — optional; maps to Zephyrus `role = admin`.
- Provisioning script adapted from `scripts/authentik/provision_*_oidc.py`
  (token from `docker exec acropolis-authentik-server printenv
  AUTHENTIK_BOOTSTRAP_TOKEN`).

## 4. Backend components

Port of Aurora's stack into `app/` (namespaces under `App\`):

### 4.1 Driver registry
- `config/auth-drivers.php` — registers `local` + `authentik_oidc`.
- `App\Auth\AuthDriverRegistry` — resolves enabled drivers.
- `App\Auth\Drivers\AuthentikOidcAuthDriver` — wraps the OIDC services.

### 4.2 OIDC services (`App\Services\Auth\Oidc\`)
- `OidcProviderConfig` — reads config **DB-first, env-fallback**. Guards against
  the Medgnosis empty-string-override bug: treat `""` settings values as *unset*
  and fall back to env (do NOT return `""`). `client_secret` is **never** stored
  in DB — referenced from env (`OIDC_CLIENT_SECRET`).
- `OidcDiscoveryService` — fetches + caches discovery document and JWKS.
- `OidcTokenValidator` — validates `id_token`: signature (JWKS, `firebase/php-jwt`
  with small leeway), `iss`, `aud`, `exp` (required), `nonce`. Rejects on any
  failure.
- `OidcHandshakeStore` — PKCE **S256** verifier, single-use `state` (TTL 300s),
  `nonce`; stored server-side (cache), bound to session.
- `OidcReconciliationService` — find-or-create + email-link (see §5).
- `ValidatedClaims` — immutable DTO of validated claims.
- `Exceptions\` — `OidcException`, `OidcTokenInvalidException`,
  `OidcAccessDeniedException`.

### 4.3 Controllers
- `App\Http\Controllers\Auth\OidcController`
  - `GET /auth/oidc/redirect` — builds authorize URL (PKCE + state + nonce), 302.
  - `GET /auth/oidc/callback` — exchanges code, validates token, reconciles user,
    then **replicates `AuthenticatedSessionController::store` session setup**:
    `Auth::guard('web')->login($user)`, `session()->regenerate()`,
    `session->put('username', …)`, default `workflow_preference`,
    `session->put('user_id', …)`. OIDC users have `must_change_password=false`,
    so they bypass the change-password redirect and land on `dashboard`.
  - Both routes are no-ops (404) when OIDC is disabled.
- `App\Http\Controllers\Admin\AuthProviderController` — superuser-only; read/update
  `auth_provider_settings`; **secrets masked on read**.

### 4.4 Routes (`routes/auth.php`, additive)
Under the existing `guest` group:
```
GET  /auth/oidc/redirect  -> OidcController@redirect   name: auth.oidc.redirect
GET  /auth/oidc/callback  -> OidcController@callback    name: auth.oidc.callback
```
Admin routes under an `auth` + superuser gate.

## 5. Provisioning / reconciliation policy

On validated callback with claims `{sub, email, groups[]}`:

1. **Existing external identity** (`user_external_identities.sub` match) → log in
   that user.
2. **Email matches `prod.users.email`** (incl. `oidc_email_aliases` mapping) →
   link (`user_external_identities` row) + log in.
3. **No match:**
   - In `Zephyrus Users` (or `Zephyrus Admins`) group → **JIT create**:
     `email`, `name` from claims, `username` = email prefix (collision-suffixed),
     `role` = `admin` if in `Zephyrus Admins` else `user`, `must_change_password
     = false`, `is_active = true`, random unusable local password.
   - Not in any allowed group → `OidcAccessDeniedException` → redirect to login
     with an error message.
4. **Never** grants superuser via OIDC. `admin@acumenus.net` is never modified.
5. `oidc_email_aliases` lets a personal Authentik email resolve to an existing
   privileged account (e.g. `sudoshi@… → admin@acumenus.net`), mirroring Medgnosis.

## 6. Database (migrations, `prod` schema)

`--path`-scoped migrations creating:
- `prod.auth_provider_settings` — `provider_type`, `enabled` (bool),
  `settings` (encrypted text JSON), timestamps. DB is source of truth, overrides
  env (except `client_secret`).
- `prod.user_external_identities` — `user_id` FK, `provider` (`authentik_oidc`),
  `sub`, unique(`provider`,`sub`), timestamps.
- `prod.oidc_email_aliases` — `alias_email` → `canonical_email`, timestamps.

Models under `App\Models\Auth\`: `AuthProviderSetting`, `UserExternalIdentity`,
`OidcEmailAlias`.

## 7. Frontend (additive — `auth-system.md` compliant)

- `resources/js/Pages/Auth/Login.jsx`:
  - Below the existing username/password form, add an **"or" divider** and a
    **"Sign in with Authentik"** button → `GET /auth/oidc/redirect`.
  - Renders **only** when `oidc_enabled === true` (Inertia prop).
  - **Unchanged:** "Create Account" CTA, password fields, forgot-password,
    ChangePasswordModal, temp-password flow. None removed or reordered out.
- `AuthenticatedSessionController::create` (and Inertia shared data) exposes
  `oidc_enabled` from `auth_provider_settings`.

## 8. Safety, config, rollout

- **Ships disabled.** `OIDC_ENABLED=false` and DB `enabled=false` → routes 404,
  button hidden. Enable only after the Authentik app + prod secrets exist.
- **New dependency:** `firebase/php-jwt`.
- **Env (added to `.env.example` with safe defaults):**
  `OIDC_ENABLED`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`,
  `OIDC_DISCOVERY_URL` (or issuer), `OIDC_REDIRECT_URI`,
  `OIDC_ALLOWED_GROUPS="Zephyrus Users"`, `OIDC_ADMIN_GROUPS="Zephyrus Admins"`.
  Quote any value containing spaces (phpdotenv whitespace bug — Aurora lesson).
- **Deploy** (`/var/www/Zephyrus`): `composer install`, migrate `--path` (prod
  schema), frontend build, `config:clear && config:cache`, then **`chown
  www-data:www-data bootstrap/cache/config.php .env`** (artisan-as-smudoshi cache
  ownership gotcha from the 2026-06-21 outage fix).

## 9. Security checklist (verify in implementation)

- [ ] `id_token` signature verified against JWKS; `iss`/`aud`/`exp`/`nonce` checked.
- [ ] PKCE S256; `state` single-use TTL 300s; `nonce` bound to session.
- [ ] No tokens placed in redirect URLs or logs.
- [ ] Secrets masked on admin read; `client_secret` only in env.
- [ ] JIT is group-gated, additive, max role `admin`, never superuser.
- [ ] OIDC failures fail closed (deny + generic error), no enumeration.
- [ ] Routes/button inert when disabled.

## 10. Out of scope (YAGNI)

- SSO logout / back-channel logout.
- Multiple OIDC providers (single Authentik provider only).
- Migrating existing local users to SSO-only.
- Account-linking UI in user settings (email-match linking is automatic).

## 11. Testing

- **Unit (Pest):** `OidcTokenValidator` (good/tampered/expired/wrong-aud/wrong-iss/
  bad-nonce), `OidcHandshakeStore` (state reuse, TTL), `OidcReconciliationService`
  (link existing, JIT gated, denied ungated, alias resolution, never-superuser),
  `OidcProviderConfig` (empty-string fallback).
- **Feature (Pest):** redirect 302 shape; callback happy path; callback denied;
  routes 404 when disabled; admin endpoint authz + secret masking.
- **Frontend (Vitest):** button hidden when `oidc_enabled` false, visible + correct
  href when true; existing login form untouched.
- Target 80%+ on new code.
