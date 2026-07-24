# DEVLOG — Hummingbird: service-line 4D coloring, Eddy chat, persistent carousel, chrome (2026-07-05)

Branch: `feat/hummingbird-4d-service-line-eddy` → merged to `main`. Commit `15f9940`.
Continues [DEVLOG-native-4d-viewer-2026-07-05](./DEVLOG-native-4d-viewer-2026-07-05.md).

## What shipped (all validated live on iPhone 17 Pro simulator unless noted)

### 1. 4D map colored by service line + service-line integration
- **Backend** `app/Services/Flow/Spaces3dAssetService.php`: each space in `GET /api/mobile/v1/flow/spaces3d` now carries a canonical `service_line`, and the envelope carries a colored `service_lines` legend `{code: {name, domain, color, sort}}` (+ an `unassigned` swatch). Legacy catalog codes on `facility_spaces.service_line_code` (`cardiology`, `medicine`, `trauma_surgery`) are folded to canonical via `ServiceLineNormalizer` so the map colors by one vocabulary regardless of import vintage.
- **Palette** lives with the definitions in `config/hospital/service-lines.php` as `service_line_colors` (keyed by canonical code) + `service_line_unassigned_color`. It is a **sanctioned categorical data-viz palette** (see CLAUDE.md Token Canon) that deliberately avoids the reserved status hues (teal/amber/coral/sky). The `ServiceLineRegistrar` ignores these keys (only reads named columns), so seeding is unaffected.
- **Self-healing cache:** added `Spaces3dAssetService::SCHEMA_VERSION = 2`. `load()` rebuilds the on-disk asset when its `schema` differs, so a deploy that adds a field doesn't serve the stale `storage/app/private/flow/spaces3d.json` forever (the pre-existing file had no service lines and would have been served indefinitely). Version hash now covers spaces + legend.
- **Contract/tests:** OpenAPI `FlowSpace3d.service_line` + `FlowServiceLineStyle` + envelope legend; `FlowWindowTest` asserts the legend shape + per-space `service_line`; MobileBff drift green.
- **Verified on canonical (pgsql.acumenus.net):** 1023/1450 clinical-core spaces coded → **13 service lines** in the legend.
- **iOS:** `FlowModels` gained `FlowSpace3d.serviceLine`, `FlowSpaces3dDocument.serviceLines`, `FlowServiceLineStyle`. `Theme.swift` gained `Color(flowHex:)` / `UIColor(flowHex:)`. `Flow3DView` colors segments from the server legend with a **color-mode toggle (Service line ⇄ Bed status, default Service line)** and in-place recolor that preserves the camera orbit (only a dataset/floor change reframes). `FlowMapView.flow3DLayer` hosts the toggle Picker + `Flow3DLegend` strip + a "Pinch to zoom · drag to orbit" caption. Pinch/zoom is real (SCNView `allowsCameraControl`).

### 2. Eddy — full chat, per-screen + per-persona context (not generic)
- Removed the old "Eddy context" panels (`EddyContextButton` / `EddyContextSheet` and their 5 usages).
- New `EddyChatView` (in `Features/Altitude/AltitudeComponents.swift`) → `POST /api/mobile/v1/eddy/chat` via `APIClient.eddyChat` (60s timeout for the model turn; 503-tolerant — the "Eddy is unavailable" envelope still decodes and renders). Multi-turn via `conversation_id`.
- **Context-aware:** `EddyContextStore` (injected app-wide in `HummingbirdApp`) + a `.eddyContext(key, title, summary, scopeRef)` View modifier set on **12 screens** (all persona homes + map + For You + Activity). `EddyChatView` sends `page_context` / `page_component` / `page_data` (+ persona), which `EddyChatService.php` (lines ~233–235) consumes. The chat header shows "viewing {screen}" and the first suggestion is screen-specific.
- Top-left `EddyAccessButton` on every signed-in screen (`Eddy.png` → `Assets.xcassets/Eddy.imageset`).

### 3. Chrome
- **Persistent Hummingbird carousel** ("prominent behind content" — user's explicit choice over subtle/chrome-only). `HummingbirdBackdrop` (self-timed crossfade, Reduce-Motion aware, in `RootView.swift`) is set as **each screen's** `.background { HummingbirdBackdrop(dim: 0.4) }` (21 screens) — a per-screen background is required because a single RootView-level backdrop is covered by the opaque TabView/NavigationStack system background. `Panel` primitive is **frosted** (`.ultraThinMaterial` + translucent tint) so cards read over the photography while staying legible. This intentionally departs from the opaque-operational canon.
- **Eddy (left) + profile (right) avatars** are matched, aligned overlays on `MainTabView` in `RootView` (both `Z.topAvatar = 40`, same `.top` padding → aligned top-and-bottom, neither clipped). The 11 per-screen toolbar `person.crop.circle` buttons were hollowed to `EmptyView()`; a global `ProfileAccessButton` (top-right) mirrors `EddyAccessButton` and opens `ProfileView`.

## App Store Connect deploy — STATE: signed .ipa in hand, BLOCKED on Issuer ID
- `hummingbird/iosApp/archive-testflight.sh` + `ExportOptions.plist` (method `app-store-connect`, team `TKXPY255A2`, automatic signing) is the intended path.
- **Done:** archived Release (`arm64`, `-allowProvisioningUpdates`) and exported a signed App Store `.ipa` → `hummingbird/iosApp/.build-archive/export/Hummingbird.ipa` (15 MB). Automatic signing created the **missing widget App Store profile** during export; both app + `HummingbirdWidgets` are signed with `iOS Team Store Provisioning Profile` under `TKXPY255A2`. Release host is `https://zephyrus.acumenus.net`.
- **Blocked:** upload needs the **App Store Connect API Issuer ID** (a UUID; App Store Connect → Users and Access → Integrations → API → Issuer ID). The `.p8` keys are on disk (`~/.appstoreconnect/private_keys/AuthKey_44R26SV98W.p8`, `AuthKey_PK47HGA24C.p8` → key ids `44R26SV98W`, `PK47HGA24C`) but the issuer id is not stored anywhere.
- **To finish:** `xcrun altool --upload-app -f Hummingbird.ipa -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>` (key needs App Manager/Admin role; app record for `net.acumenus.hummingbird` must exist).
- **Heads-up:** the `.ipa` is **version 1.0 / build 1** (`Info.plist` reports `1.0`, not the project `MARKETING_VERSION` 0.1.0 — reconcile). If build 1 was ever uploaded, re-export with a timestamp build number (the script does this via `CURRENT_PROJECT_VERSION`).

## Gotchas / environment (important for the next session)
- **`.env.local` shadowing trap RECURRED (2026-07-05).** A `.env.local` with only two `SESSION_*` lines reappeared; because the `hb_serve` container exports `APP_ENV=local`, Laravel loaded `.env.local` **instead of** `.env` → every var (DB_*, APP_KEY) defaulted → sqlite → iOS login "Server Error" (log fingerprint `Connection: sqlite … database.sqlite does not exist`). Fix: `mv .env.local .env.local.disabled-<ts>` then **`docker restart hb_serve`** (the running php process caches the bad env until restart). A fresh `php -r`/tinker in the container resolves pgsql fine (APP_ENV not exported in that shell) — don't be fooled by a green tinker. Something keeps recreating the file.
- **This is an INTEL Mac** → the simulator + app binaries are **x86_64** (not arm64). `strings` cannot see Swift string literals (HB_TAB/HB_AUTOLOGIN return 0 yet work) — don't use it to check whether a hook compiled.
- **Reaching the 3D map:** `SIMCTL_CHILD_HB_ROLE=bed_manager … HB_HOME_MODE=map`. `house_supervisor`'s home defaults to `.census` → `HomeView`, which has **no** `HB_HOME_MODE` hook; `bed_manager`'s home is `HouseCapacityView`, which **does**. Both are 3D lenses.
- **demo / `Password123!`** is `role=admin` (broad-access, all personas) on canonical; `RootView` auto-confirms superusers to `house_supervisor`.
- **Xcode project uses EXPLICIT file references (no synchronized groups)** → a NEW `.swift` file must be registered in `project.pbxproj`. That's why `EddyChatView`, `ProfileAccessButton`, and `EddyContextStore` all live in the existing `AltitudeComponents.swift`, and `HummingbirdBackdrop` lives in `RootView.swift`. A new **imageset** in `Assets.xcassets` needs no pbxproj edit (asset catalogs auto-compile).
- **No `simctl` tap / no AppleScript assistive access** in this environment — drive screens via `SIMCTL_CHILD_HB_*` launch env, not taps.
- **Warm the spaces3d asset after schema/palette changes:** `php artisan tinker --execute='app(App\Services\Flow\Spaces3dAssetService::class)->write();'` + `docker exec hb_serve php artisan cache:clear`, and **reinstall the app** (client `URLCache` holds the 24h `max-age` spaces3d until uninstall).

## Reproduce the colored 3D map in the sim
```
# server: ensure hb_serve is on pgsql (no .env.local), asset warm
docker restart hb_serve   # if login 500s (.env.local shadow)
php artisan tinker --execute='app(App\Services\Flow\Spaces3dAssetService::class)->write();'
docker exec hb_serve php artisan cache:clear
# app: reinstall (clears URLCache) + launch bed_manager into map
SIM=0A7FAE8C-8902-462D-BB4D-1E216D5BFDC1
xcrun simctl uninstall $SIM net.acumenus.hummingbird
xcrun simctl install  $SIM <DerivedData>/Build/Products/Debug-iphonesimulator/Hummingbird.app
SIMCTL_CHILD_HB_AUTOLOGIN=1 SIMCTL_CHILD_HB_USER=demo SIMCTL_CHILD_HB_PASS='Password123!' \
SIMCTL_CHILD_HB_ROLE=bed_manager SIMCTL_CHILD_HB_ONBOARD_UNIT_NAME=House-wide SIMCTL_CHILD_HB_HOME_MODE=map \
xcrun simctl launch $SIM net.acumenus.hummingbird
# wait ~15–20s for spaces3d(1450) fetch + SceneKit build, then screenshot
```

## Open / next
- **Finish the App Store Connect upload** once the Issuer ID is provided (or the user runs `archive-testflight.sh` with `ASC_*` exported). Reconcile the 1.0-vs-0.1.0 version and bump the build number if build 1 was used.
- Eddy replies "unavailable" until an LLM provider (Ollama/cloud) is reachable from `hb_serve` — the UI degrades gracefully; wiring the provider is a separate backend/config task.
- Android has no native 3D renderer yet (Phase B-rest / Phase D per the native-viewer plan) — the service-line legend contract is ready for it.
- A concurrent **patient-flow-4d-barrier-eddy** workstream is uncommitted in the working tree (web Navigator + `app/Services/PatientFlow` + `routes/*` + a `2026_07_05_000400` migration) — **not mine, left untouched.** Its plan: `docs/superpowers/plans/2026-07-05-patient-flow-4d-barrier-eddy-implementation-plan.md`.
