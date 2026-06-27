# Phase 0 — Backend Foundation (shipped)

This is the **additive** Laravel groundwork for the Hummingbird mobile companion: token
auth, the assignment model, the push-device registry, the `PushNotifier` seam, and the first
slice of the mobile BFF. It implements the backend half of
[Phase 0](IMPLEMENTATION-PLAN.md#phase-0--foundation).

> ⚠️ **Not executed in the authoring environment** (no PHP runtime was available). The code
> is written to the repo's conventions but **must be verified** with the steps in §5 before
> it is trusted. Treat §5 as the acceptance gate.

---

## 1. The additive guarantee

Per [.claude/rules/auth-system.md](../../.claude/rules/auth-system.md), the web auth system is
locked. **No protected file was modified.** Token auth is a *new, parallel path*:

- **Untouched:** `AuthenticatedSessionController`, `RegisteredUserController`,
  `ChangePasswordController`, `HandleInertiaRequests`, `routes/auth.php`, `config/services.php`,
  and all Auth React pages/modal. `config/auth.php` and `config/sanctum.php` are unchanged.
- **`must_change_password` is honored**, not bypassed: a forced-change user receives only a
  narrowly-scoped `password:change` token from `/api/auth/token` and cannot reach the BFF
  until they set a new password via `/api/auth/change-password` (which mirrors — does not
  replace — the web controller's rules).
- The mobile auth lives entirely under `/api/auth/*` and `/api/mobile/v1/*`, on the `sanctum`
  guard (bearer token), with no session and no CSRF.

---

## 2. What shipped (files)

**Modified (additive only)**
- [`app/Models/User.php`](../../app/Models/User.php) — added `HasApiTokens`; `units()` (the
  `user_unit` assignment relation), `mobileDevices()`, and `mobileTokenAbilities()`.
- [`routes/api.php`](../../routes/api.php) — added the `/api/auth/*` and `/api/mobile/v1/*`
  route groups (+ imports).
- [`bootstrap/providers.php`](../../bootstrap/providers.php) — registered `HummingbirdServiceProvider`.

**New**
- Migrations:
  [`…_create_mobile_assignment_model.php`](../../database/migrations/2026_06_27_000110_create_mobile_assignment_model.php)
  (`prod.user_unit` + `owner_user_id` on `prod.barriers` & `prod.rtdc_plans`) and
  [`…_create_mobile_devices_table.php`](../../database/migrations/2026_06_27_000120_create_mobile_devices_table.php)
  (`prod.mobile_devices`).
- Model: [`app/Models/MobileDevice.php`](../../app/Models/MobileDevice.php).
- Auth: [`app/Http/Controllers/Api/Mobile/AuthController.php`](../../app/Http/Controllers/Api/Mobile/AuthController.php).
- BFF: `MeController`, `DeviceController`, `RealtimeConfigController`, `RtdcController`
  (under [`app/Http/Controllers/Api/Mobile/`](../../app/Http/Controllers/Api/Mobile/)) +
  [`app/Http/Concerns/RendersMobileEnvelope.php`](../../app/Http/Concerns/RendersMobileEnvelope.php).
- Push: [`app/Contracts/PushNotifier.php`](../../app/Contracts/PushNotifier.php) +
  [`app/Services/Push/LogPushNotifier.php`](../../app/Services/Push/LogPushNotifier.php) +
  [`app/Providers/HummingbirdServiceProvider.php`](../../app/Providers/HummingbirdServiceProvider.php).
- Config: [`config/hummingbird.php`](../../config/hummingbird.php).
- Test: [`tests/Feature/Mobile/MobileAuthTest.php`](../../tests/Feature/Mobile/MobileAuthTest.php).

---

## 3. API surface added

All paths are under the existing `api` prefix. Matches
[api-contract/hummingbird-bff.v1.yaml](api-contract/hummingbird-bff.v1.yaml).

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/api/auth/token` | public (throttle 10/min) | Exchange username/email + password for tokens; honors `must_change_password` |
| POST | `/api/auth/token/refresh` | `auth:sanctum` (refresh token) | Rotate the refresh token into a new pair |
| POST | `/api/auth/token/revoke` | `auth:sanctum` | Revoke the presented token (logout / wipe) |
| POST | `/api/auth/change-password` | `auth:sanctum` | Forced/voluntary change; mirrors web rules |
| GET | `/api/mobile/v1/me` | `auth:sanctum` | Profile, roles, workflow, unit assignments |
| PUT | `/api/mobile/v1/me/preferences` | `auth:sanctum` | Default workflow + theme (P0 subset) |
| POST | `/api/mobile/v1/devices` | `auth:sanctum` | Register/refresh an APNs/FCM push token |
| DELETE | `/api/mobile/v1/devices/{device}` | `auth:sanctum` | Revoke a device |
| GET | `/api/mobile/v1/realtime/config` | `auth:sanctum` | Reverb host/key + PHI-free channels to subscribe |
| GET | `/api/mobile/v1/rtdc/census` | `auth:sanctum` | First live BFF read: unit census + safe capacity |

**Tokens:** short-lived `mobile-access` (abilities from role/workflow) + longer-lived
`mobile-refresh` (ability `token:refresh`, rotated on use). TTLs in `config/hummingbird.php`.
Sanctum's `personal_access_tokens` table already exists (migration `2025_01_30_173302…`).

---

## 4. Deliberately deferred to Phase 1

- **Real push senders** — the `PushNotifier` binding is the `LogPushNotifier` stub; APNs
  (`.p8` HTTP/2) + FCM v1 senders replace it without touching call sites.
- **Per-resource Policies + write-ability split** — the BFF group is now gated by
  `ability:mobile:read` (Sanctum `CheckForAnyAbility`), so the `password:change` challenge
  token can't reach it and admin `*` tokens pass. Splitting read vs. write (`mobile:act`) and
  per-resource Laravel Policies land with the first write endpoints (P1).
- **Notification router + evaluators**, **`BarrierUpdated` broadcast**, and the full BFF
  (bed requests, barriers, transport, EVS) — Phase 1.
- **`user_preferences` table** (notification tiers, quiet hours) — Phase 1; P0 preferences
  cover default workflow + theme only.

---

## 5. OR backend fixes (Phase 0 continuation)

Verified against the **authoritative Laravel migrations** (which build the test/prod DB),
not only the `db/schemas/` SQL:

- **`ORCaseController@store`** now persists `status_id` (Scheduled — `case_statuses.code =
  SCHED`, fallback `1`, matching the `update_case_status_column` migration's default) instead
  of writing a **non-existent string `status` column** — the previous line broke inserts.
  > ⚠️ **Honest scope note:** `store()`/`validateCase` still carry a *broader* schema mismatch
  > (they validate `patient_name` / `mrn` / `service_id` / `case_class` / `estimated_duration`,
  > but `prod.or_cases` stores `patient_id` / `case_service_id` / `case_class_id` /
  > `scheduled_duration` and requires a `case_number`). A full, **tested** overhaul of case
  > creation is out of scope for this pass and remains flagged for before P2. This fix
  > corrects the specific `status_id` bug only.
- **Reference endpoints** — `ServiceController` / `RoomController` / `ProviderController` now
  filter **`active_status`** (the real column on `prod.services` / `rooms` / `providers` per
  `2024_01_29_163500/163600`), not `is_active`. Regression test:
  [`tests/Feature/Api/ReferenceEndpointsTest.php`](../../tests/Feature/Api/ReferenceEndpointsTest.php)
  (asserts 200 where the bad column previously raised 500).
- **Analytics `actual_start/end_time` — NO CHANGE (audit false positive).** The audit flagged
  these as non-existent, but `prod.or_cases` defines `scheduled_end_time`, `actual_start_time`,
  and `actual_end_time` (verified in `2024_01_29_163700_create_case_tables.php` /
  `db/schemas/init/004-case-tables.sql`). Changing the analytics SQL would have **broken
  correct code**; the consolidated plan's Appendix B is corrected to reflect this.

## 6. Verification (run these — the acceptance gate)

```bash
composer install                      # ensure laravel/sanctum ^4 is present

# 1) Static checks
./vendor/bin/pint --test              # code style (or `pint` to auto-format)
php -l app/Http/Controllers/Api/Mobile/AuthController.php   # lint a couple of new files

# 2) Routes register cleanly
php artisan route:list --path=api/auth
php artisan route:list --path=api/mobile

# 3) Migrate (test DB) + run the feature test
php artisan migrate --database=pgsql            # against zephyrus_test for tests
php artisan test --filter='MobileAuthTest|ReferenceEndpointsTest'

# 4) Manual smoke (dev server on :8001)
curl -s -X POST localhost:8001/api/auth/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"username":"<user>","password":"<pass>"}'
# → { token_type, access_token, refresh_token, expires_in, abilities }   (or password_change_required)

curl -s localhost:8001/api/mobile/v1/me \
  -H 'Accept: application/json' -H "Authorization: Bearer <access_token>"
# → { data:{ id, username, roles, workflow_preference, units[] }, meta:{ as_of, stale:false }, links:{ web } }
```

**Pass criteria:** `MobileAuthTest` green (10 cases: token issue, generic-401, change-password
challenge, inactive-403, BFF auth, refresh rotation, access-token-can't-refresh, revoke
invalidation, device registration); `ReferenceEndpointsTest` green (services/rooms/providers
→ 200, locking in the `active_status` fix); routes listed (incl. the `ability:mobile:read`
gate on `/api/mobile/v1/*`); web auth regression suite still green
(`php artisan test --testsuite=Feature`); `pint --test` clean.

> If `php artisan migrate` reports an FK/column type issue on `prod.users`, confirm that
> table's PK is bigint `id` (the assignment migration uses the house convention of *plain
> `unsignedBigInteger` user refs with no FK to `prod.users`*, so this should not occur — but
> verify on first migrate).
