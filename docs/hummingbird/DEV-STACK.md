# Hummingbird — Dev Stack & Android Handoff

Status: iOS + Android at **feature parity** on branch `feat/hummingbird-mobile-v1`
(v1 census → live auto-refresh → unit detail → launcher icon → Reverb websockets →
For You queue). This doc is the handoff for continuing the **Android** build on another
machine (e.g. Linux + KVM, where the Android Studio embedded emulator renders fine —
on an Intel Mac it black-screens regardless of GPU mode, a known AS limitation; the
emulator framebuffer itself always renders correctly).

## Backend dev stack

Pure-Docker (no host PHP needed). Three containers; Postgres owns the published ports
and the others share its network namespace:

- **postgres:16** — db `zephyrus_dev`, role `claude_dev` (trust auth), named volume.
  Publishes host **8001** (API) and **8080** (Reverb) + 5432.
- **API** — `php artisan serve --host=0.0.0.0 --port=8001` (PHP 8.3 + `pdo_pgsql`).
- **Reverb** — `php artisan reverb:start --host=0.0.0.0 --port=8080`. Needs the **`pcntl`**
  PHP extension (SIGINT/SIGTERM/SIGTSTP) — install it in the image or Reverb crashes on boot.

`php artisan serve` doesn't reliably propagate env to its worker; run **`php artisan
config:cache`** after any `.env` change. On Linux you can equally run PHP/Postgres/Reverb
natively — only the ports + reachability matter.

## Mobile BFF surface (all token-gated under `auth:sanctum` + `ability:mobile:read`)

- `POST /api/auth/token` · `…/token/refresh` · `…/token/revoke` · `…/change-password`
- `GET  /api/mobile/v1/me` · `PUT …/me/preferences`
- `GET  /api/mobile/v1/rtdc/census`
- `GET  /api/mobile/v1/for-you`  — pending bed requests + open barriers + at-capacity units, PHI-minimized, tier-ranked
- `GET  /api/mobile/v1/realtime/config` · `POST/DELETE …/devices`

Realtime: Laravel **Reverb** (Pusher protocol). Public PHI-free channel **`hospital.beds`**,
event **`beds.changed`** (fired by a Bed status observer in `HummingbirdServiceProvider`).
Clients subscribe and re-snapshot `/rtdc/census` on the event; poll is a 15s fallback.

## Reachability (the per-platform gotcha)

- **iOS Simulator** → host loopback: API `http://localhost:8001`, WS `ws://localhost:8080`.
- **Android emulator** → host via **`10.0.2.2`**: API `http://10.0.2.2:8001`, WS
  `ws://10.0.2.2:8080`; needs `android:usesCleartextTraffic="true"`.
- Reverb app key `zephyrus-key` (from `.env` REVERB_APP_KEY).

## Seed / demo data

`php artisan db:seed --class=RtdcSeeder` (6 units / 180 beds) + a demo user
`demo` / `Password123!` (workflow `rtdc`). For occupancy + For-You demo rows see the
scratchpad scripts in the original session (bed_requests: `source` ∈ ed|transfer|direct|or,
`isolation_required` ∈ none|contact|droplet|airborne, `acuity_tier` 1-4 — CHECK constraints).

## Android app (`hummingbird/androidApp/`)

- Kotlin 2.1.20, AGP 8.7.3, Gradle wrapper 8.11.1, Compose BOM 2025.02, Material3,
  minSdk 26 / target+compile 35, OkHttp for the websocket. JDK 17+ (Android Studio JBR works).
- Build: `JAVA_HOME=<jdk> ANDROID_HOME=<sdk> ./gradlew :app:assembleDebug`
- Run: create an AVD, boot the emulator, `adb install -r`, then
  `adb shell am start -n net.acumenus.hummingbird/.MainActivity -e HB_AUTOLOGIN 1`
  (test hooks: `HB_AUTOLOGIN`, `HB_USER`, `HB_PASS`).

## Test gate

`php artisan config:clear` first (so phpunit's `zephyrus_test` wins over cached config),
then `php artisan test --testsuite=Feature` against a `zephyrus_test` DB. Currently
**214 passing**. iOS test hooks: `HB_AUTOLOGIN`, `HB_OPEN_UNIT=<id>`, `HB_TAB=foryou`.
