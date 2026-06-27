# 05 — Platform Layer: Auth, Authorization, Navigation, Data, Real-Time & Design Tokens

> **Purpose.** Exhaustive map of the cross-cutting platform layer of **Zephyrus**
> (Laravel 11 + React/Inertia + PostgreSQL) that the **Hummingbird** mobile companion
> app (Kotlin/Android + Swift/iOS) must integrate with. Drives the mobile app's
> architecture: auth, RBAC, navigation/workflow model, data-fetching, real-time
> transport, and the design system.
>
> **Method.** Source read of `app/Http/Controllers/Auth/*`, `app/Services/Auth/Oidc/*`,
> `app/Auth/*`, `app/Models/*`, `routes/*`, `config/*`, `bootstrap/app.php`,
> `resources/js/Contexts/*`, `resources/js/lib/echo.ts`, `resources/js/features/rtdc/*`,
> `resources/js/services/*`, `tailwind.config.js`, `DESIGN.md`, `AUTHENTICATION.md`.
> Date: 2026-06-26. No source files were modified.

---

## 0. TL;DR for Mobile Architects

| Dimension | Reality today | Mobile implication |
|---|---|---|
| **Auth model** | **Session-cookie only** (Laravel `web` guard, `driver: session`). Login = username + password → server session; OIDC/SSO (Authentik, PKCE) also lands a **web session**. | **No mobile-usable auth exists.** Cookies + CSRF are browser-shaped. **Biggest gap → must add token auth.** |
| **Token API auth** | **None wired.** Sanctum *is installed* (`laravel/sanctum ^4.0`) but configured **SPA-only** (`'guard' => ['web']`); **zero** `auth:sanctum` routes; **no** `HasApiTokens` on the `User` model; **no** `/oauth/token` or PAT endpoints. `AUTHENTICATION.md` lists token auth as *"API Authentication (Future)"*. | Add `HasApiTokens` + a token-issue endpoint (Sanctum PAT) **or** wire Authorization-Code+PKCE/OIDC to issue bearer tokens for the mobile clients. |
| **API routes** | `routes/api.php` groups are **`['web','auth','throttle:60,1']`** — i.e. they ride the **session cookie + `X-XSRF-TOKEN`**, not bearer tokens. Some reference-data routes have *no auth at all*. | Mobile cannot call these as-is. Introduce a parallel **`auth:sanctum`** route group (or middleware that accepts both). |
| **Real-time** | **Live WebSockets via Laravel Reverb** (Pusher protocol). Web uses `laravel-echo` + `pusher-js`. Events broadcast on **public** channels `unit.{id}` & `hospital.beds` (PHI-free aggregates). No replay; client does **snapshot-on-reconnect**. | Mobile can speak the **Pusher/Reverb WS protocol** directly (public channels need no auth). For background delivery, add **APNs/FCM push** + a sync-on-foreground pattern (mirrors web's invalidate-on-connect). |
| **Roles** | Two parallel systems: **Spatie roles** (`super-admin`/`admin` → gate admin features) **and** a free-text `users.role` column (default `'user'`). **`workflow_preference`** (the persona switcher) is **independent of role** — any user can switch to any of the 6 workflows. | Mobile RBAC = `auth.is_admin` flag + `auth.roles[]` from shared props; workflow is a *preference*, not a permission. |
| **Design tokens** | `healthcare-*` blue/slate operational palette + rationed status colors **teal/amber/coral/sky** (dark) ; **Figtree** only, weights **400/500/600**; **dark-default**; `tabular-nums` metrics; gold `#C9A227` focus, crimson `#9B1B30` brand only. | Mirror the token table (§6) into iOS (Asset Catalog / SwiftUI Color) + Android (`colors.xml` / Compose). |

---

## 1. Authentication Architecture

### 1.1 Guard & session model

`config/auth.php`:

```
defaults.guard      = web         (env AUTH_GUARD)
guards.web.driver   = session
guards.web.provider = users (eloquent → App\Models\User)
```

There is **exactly one guard: `web` (session)**. No `api`/token guard is defined.
Sessions are server-side (`SESSION_DRIVER=file` in `.env.example`; production guidance
recommends `database`). Auth state is carried by the Laravel session cookie; the
frontend SPA additionally relies on the `XSRF-TOKEN` cookie + `X-XSRF-TOKEN` header.

`App\Models\User` (table **`prod.users`**, note the Postgres schema prefix):
- **fillable:** `name, email, username, password, workflow_preference, must_change_password, role, is_active, phone`
- **casts:** `must_change_password:boolean, is_active:boolean, email_verified_at:datetime`
- **traits:** `HasFactory, HasRoles (Spatie), Notifiable` — **`HasApiTokens` is NOT present** (this is the token-auth blocker).
- **login identifier:** `username()` returns `'username'` → users log in with **username, not email**.

### 1.2 Local (password) login flow

`POST /login` → `AuthenticatedSessionController@store` (`routes/auth.php`, `guest` group):
1. `LoginRequest::authenticate()` validates `username` + `password` against the `web` guard.
2. `session()->regenerate()`.
3. Stores `username`, and `user_id` in the session (a bespoke convention, see §1.6).
4. Backfills `workflow_preference = 'superuser'` if null.
5. **If `must_change_password` → redirect `password.change`**; else `redirect()->intended('dashboard')`.

`POST /logout` → `destroy`: `Auth::guard('web')->logout()` + `session()->invalidate()`.

### 1.3 Registration + temp-password flow (MediCosts paradigm — **protected**, see `.claude/rules/auth-system.md`)

`POST /register` → `RegisteredUserController@store`:
- Validates `name, email (unique, lowercase), phone (nullable)` — **no password field**.
- Auto-derives `username` from the email local-part (`preg_replace('/[^a-z0-9_-]/','')`, dedups with numeric suffix).
- Generates a **12-char temp password** (ambiguous chars `I l O 0` excluded).
- Creates user with `must_change_password = true, role = 'user', is_active = true`.
- **Emails credentials via Resend API** (`from: Zephyrus <noreply@acumenus.net>`, `services.resend.key`). Failures are logged, not surfaced (email-enumeration-safe — always returns the same status flash).
- Redirects to `/login` with a flash status.

### 1.4 Forced password change (`must_change_password`)

Two enforcement points (both **must stay**, per auth rules):
- **Page:** `GET /change-password` → `ChangePasswordController@show`; `POST` → `update` validates `current_password` + `new_password (min:8, confirmed)`, checks the new password differs, then sets `password` + `must_change_password = false`, redirects `dashboard`.
- **Blocking modal:** `AuthenticatedLayout.tsx` renders `<ChangePasswordModal />` whenever `auth.user.must_change_password` is true. The modal is **non-dismissable** (fixed full-screen overlay, no close affordance).

`must_change_password` is surfaced to **every** Inertia page via shared props (§3) as a coerced boolean.

### 1.5 OIDC / SSO (Authentik) — Authorization Code + PKCE

Full server-side OIDC is implemented (ships **disabled**: `OIDC_ENABLED=false`). It results in a **web session** — *not* a token.

**Components**
| File | Responsibility |
|---|---|
| `Auth/OidcController.php` | `redirect` (builds authorize URL w/ PKCE S256, nonce, state) & `callback` (code→token exchange, validates, logs user into `web` guard). |
| `Services/Auth/Oidc/OidcProviderConfig.php` | Merges DB `AuthProviderSetting` (provider_type `oidc`) over `config/services.php` `oidc.*`. **client_secret only from env.** `isPubliclyAvailable()` gates the SSO button + routes. |
| `Services/Auth/Oidc/OidcDiscoveryService.php` | Fetches/caches (`3600s`) the `.well-known` discovery doc + JWKS. |
| `Services/Auth/Oidc/OidcTokenValidator.php` | Validates the `id_token` JWT via `firebase/php-jwt` (`JWK::parseKeySet`, 30s leeway): signature, `exp`, `iss`, `aud`, `nonce`, required claims `sub/email/name`, optional `groups[]`. |
| `Services/Auth/Oidc/OidcHandshakeStore.php` | Cache-backed `state`→`{nonce, code_verifier}` map, 300s TTL, single-use (`Cache::pull`). |
| `Auth/Drivers/AuthentikOidcAuthDriver.php` (name `authentik-oidc`, registered in `AuthDriverRegistry`) | Bridges validated claims → `OidcReconciliationService`. |
| `Services/Auth/Oidc/OidcReconciliationService.php` | Maps the external identity to a Zephyrus `User`. |

**Reconciliation order** (`OidcReconciliationService::reconcile`, in a DB transaction):
1. Match existing `UserExternalIdentity` by `(provider='authentik', provider_subject=sub)` → `linked_by_sub`.
2. Else match `User` by `lower(email)` → link + `linked_by_email`.
3. Else match via `OidcEmailAlias` canonicalization → `linked_by_alias`.
4. Else **JIT-create** — *only if* the token's `groups[]` intersect `allowed_groups ∪ admin_groups` (default `Zephyrus Users` / `Zephyrus Admins`); role = `admin` if in an admin group else `user`. Sets `must_change_password=false`, `email_verified_at=now()` → `created_jit`. Otherwise throws `not_in_allowed_group`.
- `is_active=false` users are rejected at every branch (`account_disabled`).

**Routes** (`auth.php`, `guest` group): `GET /auth/oidc/redirect` (`auth.oidc.redirect`), `GET /auth/oidc/callback` (`auth.oidc.callback`). The callback mirrors `AuthenticatedSessionController` session setup (`Auth::guard('web')->login`, regenerate, store username/user_id). Failures redirect to `/login` with a generic SSO-failed flash.

Admin can manage the provider at `admin/auth-providers/{type}` (GET/PUT, `AuthProviderController`, behind `web,auth`).

### 1.6 Legacy/dead auth code (do **not** rely on for mobile)

- **`app/Http/Middleware/SessionAuthMiddleware.php`** — an **auto-login-as-`admin`** "demo mode" middleware (`User::firstOrCreate(['username'=>'admin'])` + `Auth::login`). **It is NOT registered** in `bootstrap/app.php` and **not referenced** by any route group → **inert**. `AUTHENTICATION.md` confirms "the auto-login bypass has been removed." Mobile must assume **real auth is enforced**.
- The session `user_id`/`username` keys exist to support that retired custom-session scheme; current code uses standard `auth()->user()`.

### 1.7 Other auth endpoints (Breeze scaffolding, all session-based)

Forgot/reset password (`password.request/email/reset/store`), email verification (`verification.notice/verify/send`), password confirmation (`password.confirm`), `PUT /password` (in-app change). All under `web` + (where relevant) `auth`. **None are token-aware.**

### 1.8 Auth GAP summary (the headline for mobile)

> **There is no mobile-consumable authentication today.** Everything terminates in a
> **browser session cookie**. Sanctum is installed but dormant (SPA guard only, no
> `HasApiTokens`, no token routes). To support Hummingbird you must add **one** of:
> 1. **Sanctum Personal Access Tokens** — add `HasApiTokens` to `User`, expose a
>    `POST /api/auth/token` (username+password → `createToken`), and add an
>    **`auth:sanctum`** route group (or dual middleware) over the existing API
>    controllers. Smallest change; reuses the password flow + `must_change_password`.
> 2. **OIDC bearer tokens** — extend the existing Authentik PKCE flow with a native
>    app redirect (custom scheme / `AppAuth` libs) and accept Authentik access
>    tokens server-side. Heavier; best if SSO becomes the primary identity source.
>
> Either path must also: honor `is_active`, propagate `must_change_password` (block
> mobile until changed), and decide CSRF strategy (bearer tokens are CSRF-exempt — a
> reason to favor option 1/2 over reusing the cookie+CSRF SPA mode on device).

---

## 2. Authorization, Roles & Personas

### 2.1 Two parallel "role" mechanisms (important nuance)

| Mechanism | Where | Used for |
|---|---|---|
| **Spatie roles** (`HasRoles`, `spatie/laravel-permission ^6.24`; `roles`/`permissions` tables) | `User->getRoleNames()`, `hasRole([...])` | **Feature gating.** `AdminMiddleware` + Inertia `is_admin` check `hasRole(['super-admin','admin'])`. |
| **`users.role`** free-text column (default `'user'`; set to `'admin'`/`'user'` by registration & OIDC JIT) | DB column | Coarse label; **not** itself enforced by middleware. (OIDC sets it but gating still goes through Spatie roles / the `is_admin` prop.) |

There is no granular `permissions` usage in routes — authorization is effectively **binary: admin vs. everyone**.

### 2.2 Authorization enforcement points

| Guard | Scope | Rule |
|---|---|---|
| `auth` middleware | All `web.php` app routes + most `api.php` groups | Must have a `web` session. |
| `App\Http\Middleware\AdminMiddleware` | `Route::resource('users', UserController)` (user management) | `abort(403)` unless `hasRole(['super-admin','admin'])`. |
| `OidcReconciliationService` group check | OIDC JIT provisioning | Must be in `allowed_groups ∪ admin_groups`. |
| `is_active` | OIDC login | Disabled accounts blocked. (Note: **local password login does not appear to check `is_active`** — a gap to confirm.) |
| `throttle:60,1` | Every API group | 60 req/min rate-limit. |
| `SecurityHeaders` (global) | All responses | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS in prod. |

### 2.3 Personas / workflows = a **preference**, not a permission

The "role switcher" sets **`users.workflow_preference`** (one of `superuser | rtdc | perioperative | emergency | improvement | transport`; `home` is a transient view). It is decoupled from authorization:
- `GET /set-preference/{workflow}` → `DashboardController@setPreference` → `dashboardService->updateWorkflowPreference(user, workflow)`; route constraint allows all 6 workflows for **any authenticated user**.
- The chosen workflow is also written to the **session** (`workflow`) and shared to every page (§3).
- The frontend `DashboardContext` swaps the nav tree purely on this string. **No role check gates which workflow a user may enter.**

### 2.4 Role → workflow matrix (seeded reality + capability)

Seeded users (`database/seeders/UserSeeder.php`) map **users → default workflow**, *not* roles → workflows:

| Username | Default `workflow_preference` | Spatie role (default) | Can switch to other workflows? |
|---|---|---|---|
| `admin` (also superuser `admin@acumenus.net` per auth rules) | `superuser` | typically `admin`/`super-admin` | Yes (all) |
| `acumenus` | `superuser` | user | Yes (all) |
| `sanjay` | `perioperative` | user | Yes (all) |
| `kartheek` | `rtdc` | user | Yes (all) |
| `hakan` | `improvement` | user | Yes (all) |

> **Persona semantics for mobile:** treat `workflow_preference` as the user's
> *landing context / default tab set*, freely switchable. The only real authorization
> bit is **`is_admin`** (Spatie). Build the mobile RBAC around `auth.is_admin` +
> `auth.roles[]`; render the workflow switcher for everyone.

The 6 workflows correspond to the product's command-center domains (PRODUCT.md): **Superuser** (cross-domain exec/ops), **RTDC** (real-time demand–capacity / bed mgmt), **Perioperative** (OR), **Emergency** (ED), **Improvement** (process improvement / PDSA), **Transport** (patient movement / EVS / staffing adjuncts).

---

## 3. Inertia Shared Props (global state on every screen)

`app/Http/Middleware/HandleInertiaRequests.php` `share()` returns, on **every** page load:

| Prop | Shape | Notes for mobile |
|---|---|---|
| `auth.user` | full `User->toArray()` **+** `must_change_password:boolean` (coerced). Includes `name, email, username, workflow_preference, role, is_active, phone, …`. `password`/`remember_token` hidden. | The canonical "me" object. Mobile needs an equivalent `/api/me`. |
| `auth.roles` | `string[]` from Spatie `getRoleNames()` | RBAC source. |
| `auth.is_admin` | `boolean` = `hasRole(['super-admin','admin'])` | The one real permission flag. |
| `workflow` | current session `workflow` string | Drives the active persona/nav. |
| `flash.message` / `flash.error` | lazy session values | Toasts. |
| `app.name`, `app.env` | config | Environment banner. |
| `ziggy` | `{ url, port, defaults, routes{} }` | Named-route table for the SPA (`tightenco/ziggy`). Not needed by native mobile. |

> Mobile equivalent: a single **`GET /api/me`** (or login response body) returning
> `{ user, roles[], is_admin, workflow }`. There is **no dedicated `/me` endpoint
> today** — this data only exists inside Inertia props.

---

## 4. Navigation / Workflow Model (the full screen map)

Source: `resources/js/Contexts/DashboardContext.tsx` → `workflowNavigationConfig`
(authoritative client-side nav tree) cross-checked against `routes/web.php`.
`ModeContext.tsx` toggles **`dev` (mock) vs `prod` (API)** data mode, persisted in
`sessionStorage['mode']`, default **`dev`**.

**Top-level switcher** (`mainNavigationItems`): SUPERUSER, RTDC, Perioperative, Emergency, Improvement, Transport (each `→ /dashboard/{workflow}`).

Per-workflow nav (`{ analytics[], operations[], predictions[] }`):

### Superuser
- **Analytics:** Primetime Utilization, OR Utilization, Block Utilization, Room Running, Turnover Times, Procedure Analysis
- **Operations:** Capacity Management, Staffing, Scheduling, Patient Flow (`/rtdc/patient-flow-navigator`)
- **Predictions:** Volume Forecasting, Capacity Planning, Resource Optimization

### RTDC (Real-Time Demand–Capacity)
- **Analytics:** Utilization & Capacity, Performance Metrics, Resource Analytics, Trends & Patterns
- **Operations:** Bed Tracking, Patient Flow 4D, Bed Placement, Ancillary Services, Global Huddle, Unit Huddle, Service Huddle
- **Predictions:** Demand Forecasting, Resource Planning, Discharge Predictions, Risk Assessment

### Perioperative
- **Analytics:** Block Utilization, OR Utilization, Primetime Utilization, Room Running, Turnover Times
- **Operations:** Block Schedule, Case Management, Room Status
- **Predictions:** Utilization Forecast, Demand Analysis, Resource Planning

### Emergency (ED)
- **Analytics:** Wait Time, Patient Flow
- **Operations:** Resource Management, Triage, Treatment
- **Predictions:** Arrival Prediction, Resource Optimization

### Improvement
- **Analytics (only):** Overview, Bottlenecks, Process Analysis, Root Cause, Active Cycles (+ PDSA pages `/improvement/pdsa/{id}`)
- Operations/Predictions: empty

### Transport
- **Analytics:** Command Center, Analytics
- **Operations:** Requests, Dispatch, Inpatient, Transfers, Discharge, EMS, Care Transitions, Resources
- **Predictions (label):** Integration Settings

> **Mobile takeaway:** this is the complete screen inventory. The web nav is a static
> client config keyed by `workflow`; mobile can hardcode the same tree (or, better,
> expose it as a small `/api/nav` config so it stays in sync). Each `href` maps to an
> Inertia page; the corresponding **data** comes from `routes/api.php` (§5) or mock
> data (§5.3) depending on `ModeContext`.

`changeWorkflow()` calls `router.get('/set-preference/{workflow}?redirect=/dashboard/{workflow}')` (full Inertia visit, `preserveState:false`). Mobile equivalent: `POST` the preference then navigate.

---

## 5. Data Fetching

There are **two coexisting data patterns**:

### 5.1 Legacy `DataService` (mock-vs-API switch)
`resources/js/services/data-service.js`: a class instantiated with the current `mode`
(from `ModeContext`). Each method returns **mock data when `mode==='dev'`**, else
`axios.get('/api/...')`. Covers perioperative/analytics surfaces
(`getPerformanceMetrics, getBlockSchedule, getCases, getRoomStatus,
getProviderPerformance, getCapacityAnalysis, getBlockTemplates, getBlockUtilization,
getServices`). Exposed via `DataService.useDataService()`.

### 5.2 Modern React Query + Reverb (the RTDC slice — the template to follow)
`resources/js/features/rtdc/hooks.ts` uses **`@tanstack/react-query`** (`useQuery`/`useMutation`)
against typed `./api` callers, with **Zod** validation of wire payloads
(`@/schemas/rtdc`) and **live invalidation via Echo** (§6). This is the newer,
cleaner architecture (server-truth, no mock branch). Mobile should mirror *this*
pattern (typed client + cache + WS invalidation), not the `dev`-mock `DataService`.

### 5.3 Mock-data domains (`resources/js/mock-data/`)
Used only in `dev` mode. Domains present:
`analytics, block-schedule, block-templates, block-utilization, case-management,
cases, dashboard, ed, generate-service-huddle-data, historical-metrics,
improvement/ (index), pdsa/ (cycles,index), primetime-capacity-review,
primetime-utilization, provider-analytics, room-running, room-status, rtdc-alerts,
rtdc-capacity, rtdc-service-huddle (+constants), rtdc-staffing, rtdc-trends, rtdc,
service-analytics, turnover-times`.

### 5.4 Transport / envelope conventions
- **Axios bootstrap** (`resources/js/bootstrap.js`): `withCredentials=true`,
  `withXSRFToken=true`, `X-Requested-With: XMLHttpRequest`, `baseURL='/'`. A response
  interceptor **redirects to `/login` on HTTP 401** (session-expiry UX).
- **CSRF** (`resources/js/services/csrf.js`): Laravel sets the `XSRF-TOKEN` cookie;
  Axios echoes it as `X-XSRF-TOKEN`. `ensureValidToken()` can `GET /sanctum/csrf-cookie`
  to refresh. RTDC API comment confirms: the `web` group provides `StartSession` so
  the SPA authenticates via the session cookie; **CSRF auto-skips only in `testing`**.
- **Response envelope:** no global wrapper — controllers return ad-hoc
  `response()->json([...])`. `GET /api/health` returns `{status, database, timestamp}`.
  Mobile clients must tolerate per-endpoint shapes (no uniform `{data,meta}` contract).

---

## 6. Real-Time Transport (the live-data reality)

### 6.1 Stack — **Laravel Reverb (WebSockets), Pusher protocol**

| Layer | Detail |
|---|---|
| Server | **`laravel/reverb ^1.10`** (`config/reverb.php`; server on `0.0.0.0:8080` by default, Redis scaling optional). |
| Broadcaster | `config/broadcasting.php` default **`null`** (safe fallback) — but **`.env.example` sets `BROADCAST_CONNECTION=reverb`** → production uses Reverb. Pusher/Ably/log/redis connections also defined. |
| Client | **`laravel-echo ^2.3` + `pusher-js ^8.5`** (`resources/js/lib/echo.ts`): `new Echo({ broadcaster:'reverb', key:VITE_REVERB_APP_KEY, wsHost:VITE_REVERB_HOST, wsPort/wssPort:VITE_REVERB_PORT(8080), forceTLS, enabledTransports:['ws','wss'] })`, exposed as `window.Echo`. |

> **There is NO polling for live ops data.** Live updates are **push over WebSockets**.
> (Some legacy screens still pull mock/REST snapshots, but the real-time path is WS.)

### 6.2 Channels & events (all **public**, PHI-free)

`routes/channels.php` is **intentionally empty** of auth callbacks — the design note
states the RTDC channels are **public** because payloads are PHI-free aggregate
operational counts. No `Broadcast::channel()` callbacks exist (would be dead code).

| Event (`app/Events/Rtdc/*`) | Channel | `broadcastAs` | Payload |
|---|---|---|---|
| `CensusUpdated` | `unit.{unit_id}` | `census.updated` | `{unit_id, captured_at, staffed_beds, occupied, available, blocked, acuity_adjusted_capacity}` |
| `HuddleUpdated` | `unit.{unit_id}` | `huddle.updated` | `{unit_id, prediction{}}` |
| `BedMeetingUpdated` | `hospital.beds` | `bedmeeting.updated` | aggregate rollup (net bed-need, weighted discharges, etc.) |

All three `implements ShouldBroadcast` on a **public `Channel`** (not `PrivateChannel`).

### 6.3 Dispatch sites (real-time **is wired & live**)

- `Api/Rtdc/HuddleController.php` → `broadcast(new BedMeetingUpdated($rollup))`
- `Api/Rtdc/PredictionController.php` → `broadcast(new HuddleUpdated($unitId, ...))` (3 call sites: capacity/demand/plan)
- `app/Rtdc/EventDispatcher.php` centralizes census broadcasts.
- A scheduled job `ReconcileRtdcPredictions` runs **daily at 02:00** (`bootstrap/app.php`).

### 6.4 Client consumption pattern (`useLiveCensus`)

For each known unit: `echo.channel('unit.{id}').listen('.census.updated', …).listen('.huddle.updated', …)` and `echo.channel('hospital.beds').listen('.bedmeeting.updated', …)`. On each event it **invalidates the relevant React Query cache** (re-fetches truth) rather than patching state. Critically: **snapshot-on-(re)connect** — it binds `pusher.connection 'connected'` to re-invalidate everything, **because Reverb/Pusher do not replay missed messages**.

### 6.5 What mobile needs

| Need | Recommendation |
|---|---|
| In-app live updates | Speak the **Pusher protocol to Reverb** directly. Android: a Pusher/Java WS client (or OkHttp + protocol) subscribing to `unit.{id}` / `hospital.beds`. iOS: PusherSwift or a Starscream-based client. Public channels → **no channel auth needed**. |
| Missed-message resilience | Reverb **does not replay** → adopt the same **snapshot-on-reconnect / on-foreground** strategy: on WS connect and on app foreground, re-fetch the REST snapshot, then apply deltas. |
| Background / killed-app delivery | WS won't run reliably in background. Add **APNs (iOS) + FCM (Android)** push for alerts (breaches, new bed requests). **No push infrastructure exists today** — net-new backend work (token registration table, a `Notification`/broadcast-to-push bridge, or a queued job per `app/Jobs/`). |
| PHI on the wire | Current channels are aggregate-only. If mobile ever needs patient-identifying real-time data, those events must move to **`PrivateChannel`** + a real `Broadcast::channel()` auth callback — and **private-channel auth requires the token model from §1.8** (Echo's `/broadcasting/auth` is session-based today). |

---

## 7. Layouts, Theme & Dark Mode

| Concern | Implementation |
|---|---|
| Authed shell | `resources/js/Layouts/AuthenticatedLayout.tsx`: mounts `TopNavbar`, the non-dismissable `ChangePasswordModal` (when `must_change_password`), a skip-to-content link, and centers `<main>` at `var(--content-max-width)` (1600px). Provides **`DarkModeContext`** (`useDarkMode`). |
| Guest shell | `GuestLayout.tsx` / `AuthLayout.tsx` (sanctioned indigo/blue auth styling — do not recolor). |
| Dark mode | **Dark-default**: state seeds from `localStorage['darkMode']`, falling back to `prefers-color-scheme` **and defaulting to dark when unset**; toggles the `dark` class on `<html>` (`darkMode:'class'` in Tailwind). |
| Gutter ownership | `Components/Common/PageContentLayout` (`p-4`) is the single gutter; layouts only center. (Per CLAUDE.md.) |

> **Inconsistency flag (not blocking):** `AuthenticatedLayout.tsx` still injects a
> legacy **Google Fonts** link (Crimson Pro / Source Serif 4 / Source Sans 3 / IBM
> Plex Mono) and inline `fontFamily: 'Figtree…'`. The **token canon is Figtree-only,
> 400/500/600**; the extra families/weights are loaded but should not be used. The
> `ChangePasswordModal` uses `backdrop-blur-sm` (normally banned glassmorphism) — this
> is an **auth-sanctioned exception**. Mobile should follow the **canon (§8)**, not
> these legacy artifacts.

---

## 8. Design Tokens (for iOS/Android mirroring)

North Star: **"The Operations Bridge."** Two-System Rule: **blue/slate `healthcare-*`
governs operations**; **crimson `#9B1B30` + gold `#C9A227` is brand/heritage + focus
only** (never an operational primary). Status color is **rationed** and **never by
color alone** (pair with arrow ▲▼▬ / icon / label).

### 8.1 Core palette (`tailwind.config.js` `colors.healthcare` + `DESIGN.md`)

| Token | Light | Dark | Role |
|---|---|---|---|
| `background` | `#F8FAFC` | `#0F172A` | App background (dark-default). |
| `surface` | `#FFFFFF` | `#1E293B` | Cards/panels (Quiet-Lift `shadow-sm`). |
| `surface.hover` | `#F8FAFC` | `#334155` | Hover. |
| `surface.secondary` | `#F1F5F9` | — | Nested panels. |
| `border` | `#E2E8F0` | `#334155` | Hairline borders. |
| `text.primary` | `#1E293B` (`#0F172A`) | `#F8FAFC` | Primary text. |
| `text.secondary` | `#475569` | `#CBD5E1` (`#94A3B8`) | Secondary text. |
| `primary` (interactive blue) | `#2563EB` | `#3B82F6` | The single interactive blue. Hover `#1D4ED8`/`#2563EB`. |
| `accent` | `#3B82F6` | `#60A5FA` | Highlights. |

### 8.2 Status colors (the rationed teal/amber/coral/sky vocabulary)

| Status token | Light | Dark (DESIGN.md vocabulary) | Meaning |
|---|---|---|---|
| `success` | `#059669` | **`#2DD4BF` teal** | OK / improving. |
| `warning` | `#D97706` | **`#E5A84B` amber** | Watch. |
| `critical` | `#DC2626` | **`#E85A6B` coral** | **Reserved for real breaches / critical acuity only.** |
| `info` | `#0284C7` | **`#60A5FA` sky** | Informational. |

### 8.3 Brand / focus layer (NOT operational chrome)

| Token | Value | Role |
|---|---|---|
| `brand-crimson` | `#9B1B30` (light `#B82D42`) | Acumenus master brand identity (wordmark, heritage, night-shift status). |
| `accent-gold` | `#C9A227` (ink `#A6791A`) | **Focus color** — every `:focus-visible` outline is gold (2px) + soft ring; also skip-link. |

### 8.4 Typography (`fontFamily.sans = Figtree`; `DESIGN.md`)

- **One family: Figtree** (fallback `ui-sans-serif, system-ui`). Weights **400 / 500 / 600 only** (`font-normal`/`font-medium`/`font-semibold`). **No 700/800** (faux-bold). No serif/mono.
- **The Tabular Rule:** every metric/count/time/% uses `tabular-nums` (no separate mono face).
- **Density-tuned scale** (`tailwind.config.js fontSize` — note these are *tighter* than Tailwind defaults; mobile should adopt these px values):

| Token | Size / line-height | Use |
|---|---|---|
| `xs` | 11px / 16 | Labels (UPPERCASE +0.05em, 600), column heads. |
| `sm` | 13px / 18 | Dense body. |
| `base` | 14px / 20 | Default body (Figtree 400). |
| `lg` | 16px / 24 | Title (panel/card, 600). |
| `xl` | 18px / 26 | — |
| `2xl` | 22px / 28 | Headline (section headers, KPI, 600). |
| `3xl` | 28px / 34 | Display (hero metrics, 600, -0.01em). |
| `4xl` | 36px / 40 | — |

### 8.5 Other tokens

- **Spacing:** 4px grid; extras `touch:44px`, `18/22/26/30`, `192:48rem`. `--content-max-width` = **1600px**.
- **Shadows (Quiet-Lift):** resting surfaces = `shadow-sm` (`0 1px 2px rgba(0,0,0,.05)`); only floating elements (modals/dropdowns/tooltips) get `shadow-lg`. No glassmorphism (`backdrop-blur`) except the sanctioned auth modal.
- **Accessibility target:** WCAG 2.2 AA (pragmatic); gold focus-visible everywhere; status never color-only.

> **Mobile mirroring:** encode §8.1–8.4 as semantic color sets (light/dark) and a type
> ramp. Keep **dark as the default theme**. Reserve coral for true breaches. Use gold
> only for focus/brand, crimson only for brand — never as an operational accent.

---

## 9. API Surface Inventory (`routes/api.php`) & auth posture

All groups carry `throttle:60,1`. **Auth column shows the gap:** most are `web,auth`
(session cookie), several are **unauthenticated**.

| Prefix | Auth | Representative endpoints |
|---|---|---|
| `/health` | none | DB-ping health. |
| `/command-center` | `web,auth` | `/drilldown`. |
| `/facility` | `web,auth` | `/model/summary` (digital twin). |
| `/patient-flow` | `web,auth` | summary/locations/events/tracks/state/ambient, `/fhir/bundle`, `/stream/adt`, `POST /ingest/hl7v2`. |
| `/rtdc` | `web,auth` | units, predictions (capacity/demand/plan), huddles, bed-meeting, barriers, reliability, **bed-requests** (+recommendations/decision). |
| `/transport` | `web,auth` | overview, regional-summary/-simulation, requests CRUD + assign/status/cancel/handoff, resources, vendors. |
| `/evs` | `web,auth` | overview, requests CRUD + assign/status/cancel, resources. |
| `/staffing` | `web,auth` | overview, plans, requests CRUD + assign/status/cancel, resources. |
| `/ops` | `web,auth` | graph snapshot/nodes, recommendations, agent-inbox, **agents** (capacity-commander/data-quality/executive-briefing runs), approvals/actions lifecycle, simulation promote. |
| `/admin/integrations` | `web,auth` | health, enterprise FHIR capability-discovery, writeback-drafts. |
| `/analytics` (two groups) | **mixed**: most `web,auth`; `service-performance`,`provider-performance`,`historical-trends` are **unauthenticated** (throttle only). | overview/live/retrospective/predictive/process-intelligence/opportunities/workbench/data-quality, metric lineage. |
| `/cases`, `/blocks` | **none** (throttle only) | OR cases CRUD + metrics/room-status; block schedule + utilization. |
| `/services`,`/rooms`,`/providers` | **none** | reference data. |
| `/improvement/api/nursing-operations` | none | process-map JSON (hardcoded sample). |

> **For mobile:** the `web,auth` endpoints are the operational core but are
> **session-bound**. A bearer-token (`auth:sanctum`) variant must be introduced. The
> currently **unauthenticated** endpoints (cases/blocks/reference/some analytics)
> should also be reviewed/secured before mobile exposure.

---

## 10. Consolidated Gaps & Mobile Work Items

| # | Gap (today) | Mobile work item | Effort |
|---|---|---|---|
| **G1** | **No token auth.** Session-cookie + CSRF only; Sanctum dormant; `User` lacks `HasApiTokens`. | Add `HasApiTokens`; build `POST /api/auth/token` (username+pw → PAT) honoring `is_active` + `must_change_password`; add `auth:sanctum` route group over API. | **High / blocker** |
| **G2** | No `/me` endpoint; identity only in Inertia props. | Expose `GET /api/me` → `{user, roles[], is_admin, workflow}`. | Low |
| **G3** | Real-time is WS-only, no replay, no push. | Native Pusher/Reverb WS client + snapshot-on-foreground; **add APNs/FCM** + device-token registration + broadcast→push bridge. | High |
| **G4** | Private/PHI channels need session-based `/broadcasting/auth`. | If PHI real-time needed: `PrivateChannel` + token-aware channel auth (depends on G1). | Medium |
| **G5** | `must_change_password` enforced via web redirect/modal only. | Mobile must block the app on this flag + provide a change-password screen hitting `POST /change-password` (or a tokenized variant). | Low |
| **G6** | Some API routes unauthenticated; no uniform response envelope. | Secure cases/blocks/reference/analytics; agree a `{data,meta,error}` contract for the mobile API. | Medium |
| **G7** | OIDC is web-redirect (PKCE) landing a session. | For SSO on device: native AppAuth + accept Authentik tokens server-side. | Medium (optional) |
| **G8** | Workflow is an unconstrained preference. | Mobile RBAC keys off `is_admin`/`roles[]`; workflow switcher open to all. | Trivial |

---

### Appendix A — Key file index

- Auth controllers: `app/Http/Controllers/Auth/{AuthenticatedSessionController,RegisteredUserController,ChangePasswordController,OidcController,NewPasswordController,PasswordResetLinkController,PasswordController,ConfirmablePasswordController,EmailVerification*,VerifyEmailController}.php`
- OIDC services: `app/Services/Auth/Oidc/{OidcProviderConfig,OidcDiscoveryService,OidcTokenValidator,OidcHandshakeStore,OidcReconciliationService,ValidatedClaims}.php` + `Exceptions/*`
- Auth drivers: `app/Auth/{AuthDriverRegistry}.php`, `app/Auth/Drivers/{AuthentikOidcAuthDriver,AuthDriverResult,AuthDriverException}.php`
- Auth models: `app/Models/User.php`, `app/Models/Auth/{UserExternalIdentity,OidcEmailAlias,AuthProviderSetting}.php`
- Middleware: `app/Http/Middleware/{HandleInertiaRequests,AdminMiddleware,SecurityHeaders,SessionAuthMiddleware(inert)}.php`
- Routes: `routes/{auth.php,api.php,web.php,channels.php}`; `bootstrap/app.php`
- Config: `config/{auth,sanctum,broadcasting,reverb,services,auth-drivers,permission,session}.php`
- Real-time: `app/Events/Rtdc/{CensusUpdated,HuddleUpdated,BedMeetingUpdated}.php`, `app/Rtdc/EventDispatcher.php`, `app/Jobs/ReconcileRtdcPredictions.php`, `resources/js/lib/echo.ts`, `resources/js/features/rtdc/hooks.ts`
- Frontend platform: `resources/js/Contexts/{DashboardContext,ModeContext}.tsx`, `resources/js/Layouts/AuthenticatedLayout.tsx`, `resources/js/services/{data-service.js,csrf.js}`, `resources/js/bootstrap.js`, `resources/js/mock-data/*`
- Design: `tailwind.config.js`, `DESIGN.md`, `CLAUDE.md` (token canon), `AUTHENTICATION.md`, `.claude/rules/auth-system.md`
