# Hummingbird iOS — Physical Device + TestFlight — Devlog 2026-06-28

## Summary

Took the native Hummingbird iOS app from **simulator-only** to **running on a physical
iPhone** and **uploaded to TestFlight** — the entire path driven from the command line via
`xcrun` / `xcodebuild` / `devicectl`, no Xcode GUI build. The backend URL is now build-aware
(simulator / device-debug / release), Release points at the production BFF over HTTPS/WSS,
and the whole archive → signed App Store IPA → upload pipeline is captured in two committed
scripts. First build accepted by App Store Connect with no errors.

## Starting state
- App ran only in the iOS Simulator. Code signing was **disabled** in `project.yml`
  (`CODE_SIGNING_ALLOWED/REQUIRED = NO`).
- `AppConfig.baseURL` hardcoded to `http://localhost:8001` — works on the Simulator (shares
  host loopback) but unreachable from a real device.
- No Apple account configured in Xcode; no signing identities on the machine.

## Delivered

**1. Build-aware backend (`Networking/APIClient.swift`)**
One `AppConfig` resolved per build so the same source runs against the right host:
- Simulator → `http://localhost:8001`, `ws://localhost:8080`
- Debug on device → `http://<Mac LAN IP>:8001` (overridable via `HB_HOST` scheme env var)
- Release → `https://zephyrus.acumenus.net` + `wss` on 443
- Threaded a `ws`/`wss` scheme through `RealtimeClient` so Release uses TLS.

**2. Signing (`project.yml`, automatic, team `TKXPY255A2`)**
- Replaced the `NO` signing overrides with `CODE_SIGN_STYLE: Automatic` + `DEVELOPMENT_TEAM`.
- Generated dev + distribution certs and provisioning profiles automatically via
  `-allowProvisioningUpdates`; registered the device via `-allowProvisioningDeviceRegistration`.

**3. On-device install (`deploy-device.sh`)**
`xcodebuild` (device destination) → `xcrun devicectl device install app` →
`xcrun devicectl device process launch`. One command to rebuild/install/launch.

**4. TestFlight pipeline (`archive-testflight.sh` + `ExportOptions.plist`)**
- Release archive → `xcodebuild -exportArchive` (method `app-store-connect`) → signed IPA →
  optional headless upload via an App Store Connect API key (App Manager role).
- **Per-config APNs entitlements**: Debug → `Hummingbird.entitlements` (`development`),
  Release → `Hummingbird.production.entitlements` (`production`). TestFlight needs production.
- `ITSAppUsesNonExemptEncryption = false` — app uses only standard HTTPS, so no per-upload
  export-compliance prompt.
- `iPhone-only` (`TARGETED_DEVICE_FAMILY = 1`) to satisfy the App Store iPad-orientation rule.

## Gotchas hit (and the fixes)
- **`devicectl` "available" ≠ paired for development.** The phone showed as available to
  `devicectl` but `xcodebuild` reported it *unpaired*; fix was Xcode → Devices & Simulators
  pairing + Trust on the device.
- **New device not auto-registered.** Plain `-allowProvisioningUpdates` failed with "Device
  isn't registered"; adding **`-allowProvisioningDeviceRegistration`** registered it.
- **Upload rejected (409) for iPad multitasking.** A portrait-only *universal* build is
  invalid for the App Store (iPad requires all four orientations). Fix: ship **iPhone-only**.
- **Production push environment.** Distribution archives must declare `aps-environment:
  production`, but dev builds need `development` — solved with the per-config entitlements split.

## Production BFF verification (`zephyrus.acumenus.net`)
- Resolves **publicly** (local + `8.8.8.8`) to a public Apache edge fronting the LAN server;
  **valid TLS** (`ssl_verify_result = 0`).
- `GET /api/mobile/v1/me` (Accept: application/json) → `401 {"message":"Unauthenticated."}`;
  `POST /api/auth/token` (empty) → `422` with JSON validation errors — routes live and correct.
- **Realtime caveat:** `wss://.../app/<key>` returns Apache **404** — the Reverb websocket
  (LAN `:8080`) is **not proxied** at the edge. Release builds fall back to **polling**
  (the app's designed fallback); live "badge" stays off until the proxy + `REVERB_APP_KEY`
  are reconciled.

## Validation
- **Device:** Hummingbird installed + launched on a physical iPhone 15 (iPhone16,2).
- **Distribution IPA inspected:** signed by `Apple Distribution: Sanjay Udoshi (TKXPY255A2)`;
  *iOS Team Store* profile with **no** provisioned devices; `get-task-allow: false`;
  `aps-environment: production` — i.e. genuinely App Store-signed, not a dev build.
- **Upload:** `UPLOAD SUCCEEDED with no errors` to App Store Connect app
  *Zephyrus-Hummingbird* (`net.acumenus.hummingbird`, App Store ID `6785290384`).

## Follow-ups
- Proxy the Reverb websocket at the edge (Apache `mod_proxy_wstunnel`, `/app/` → `:8080`)
  and reconcile `REVERB_APP_KEY` to light up live updates in Release/TestFlight.
- Add internal TestFlight testers (instant, no Beta App Review).
- Optionally align `MARKETING_VERSION` (`0.1.0`) with the App Store version record (`1.0`).

See also: [`hummingbird/iosApp/TESTFLIGHT.md`](../hummingbird/iosApp/TESTFLIGHT.md),
[`docs/hummingbird/DEV-STACK.md`](./hummingbird/DEV-STACK.md).
