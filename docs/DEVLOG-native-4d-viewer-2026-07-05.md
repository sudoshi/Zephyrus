# DEVLOG — Native 4D Viewer + Flow Window (2026-07-05)

Handoff for the next session. This subproject turns the Hummingbird mobile
**Flow Window** (48h persona-lensed map: 24h review + 24h prediction) into a
**native 3D per-persona "duties" viewer** and eliminates the old 2.5D renderer.
Governing plan: **`docs/hummingbird/NATIVE-4D-VIEWER-PLAN.md`** (read it first).
Prior context: `docs/hummingbird/FLOW-WINDOW-PLAN.md`, memory
`hummingbird-flow-window`, `canonical-backend-db`, `env-local-shadowing-trap`.

---

## 0. TL;DR — where we are

- **Flow Window Phases 0–5: DONE, committed, pushed** (branch `codex/hummingbird-login`, `155d7e1..14f98ae`). Web 3D Navigator + mobile 2.5D map + backend, all green.
- **NATIVE-4D-VIEWER — the active track:**
  - **Phase A (backend duties layer + 3D substrate): DONE + verified, UNCOMMITTED.**
  - **Phase B (native SceneKit 3D on iOS): FOUNDATION rendering on-device, UNCOMMITTED.** Reaches geometry+camera; patient tokens / time / picking / Android still open.
  - **Phases C–E: OPEN.**
- **The 3D twin renders on iPhone 17 Pro** (1,450 exploded floor-segment anchors, bed_manager lens) — see the reproduce recipe in §6.
- **Everything runs against `pgsql.acumenus.net`** (the canonical DB). `.env` is pointed there.

---

## 1. Flow Window Phases 0–5 — DONE (committed + pushed)

Baseline the native viewer builds on. All shipped, tested, and on the branch:
- Backend data plane, `FlowLensService` + `config/hummingbird/flow_lens.php` (14 roles), `GET /api/mobile/v1/flow/{floors,window}`, `?since=` deltas, web RBAC (`EnforceFlowLens`).
- Web 3D Navigator decomposed (`NavigatorScene.ts`, three.js lazy chunk), 48h Chronobar, projection ghosts, persona lens, `?persona=&scope=&t=` handoff.
- Mobile **2.5D** map (iOS `Features/Flow/`, Android `ui/flow/`) for all 14 personas: floor plates + HouseStack + Chronobar + timeline lanes + per-persona lenses (transport arcs, EVS turn map, OR RoomLanes, aggregate curves), delta merge, offline cache, Live Activities/widgets.
- **This 2.5D spatial renderer is what the native 3D replaces (see §4 Phase D).**

---

## 2. NATIVE-4D-VIEWER Phase A — DONE + verified (UNCOMMITTED)

Backend "duties" layer + 3D substrate. Built inline (crash-resilient). All files on disk, **not committed**.

**Shipped:**
- `app/Services/Flow/DutyProjectionService.php` — per-persona, spatially-anchored, due-dated duties from the live domains (transport/EVS/placement/barrier/staffing/approval/discharge-leverage). Each duty: `{id, kind, label, space_ref, unit_id, bed_id, centroid_m{x,y,z}|null, due_at, window_status: overdue|due|upcoming, tier, patient_context_ref, provenance, action{endpoint,method,label}}`. Anchors via `facility_spaces.geometry.position_ft × 0.3048`. Carries `_patient_ref` for `FlowLensService::redactRow` (unset before serialize).
- `app/Services/Flow/Spaces3dAssetService.php` + `GET /api/mobile/v1/flow/spaces3d` (`FlowController::spaces3d`) — versioned, ETagged 3D centroid anchors (`{version, spaces:[{space_ref, floor, category, unit_id, bed_id, centroid_m}]}`). Same load/write/version pattern as `FloorPlateAssetService`.
- `config/hummingbird/flow_lens.php` — `duty_kinds` added to all 14 roles + `duties` in each `layers`.
- `app/Http/Controllers/Api/Mobile/FlowController.php` — `duties[]` in the window payload (lens-clamped, redacted, **full on `?since=`**), `scopeUnitIds()` helper, `spaces3d()` endpoint.
- `routes/api.php` — `/flow/spaces3d` route.
- OpenAPI `hummingbird-bff.v1.yaml` — `FlowDuty`, `FlowSpace3d`, `FlowSpaces3dEnvelope`, `duties` on window, `/flow/spaces3d` path.
- Tests: `tests/Feature/Mobile/FlowWindowTest.php` (duty clamp per persona, **14-role duty redaction matrix**, since-full, spaces3d 304); `SeedsFlowStory` already seeds transport/EVS/barrier/EDD duties; `MobileBffTest` drift map entry.

**Verified:** `php artisan test tests/Feature/Mobile tests/Feature/MobileSharedDtoFixtureTest.php tests/Feature/PatientFlow` → **62 passed** (+drift/PatientFlow), Pint clean, fixtures regenerated with `duties`. Over HTTP against `pgsql.acumenus.net`: `spaces3d`=1450, `window?persona=bed_manager`=12 duties.
**Caveat baked into tests:** the bare `zephyrus_test` DB has no CAD catalog → duty `centroid_m` resolves `null` there (anchoring only exercised against a catalog-loaded DB).

---

## 3. NATIVE-4D-VIEWER Phase B — iOS foundation rendering (UNCOMMITTED)

Native SceneKit 3D twin. **Renders on iPhone 17 Pro** (verified visually).

**Shipped (iOS only):**
- `hummingbird/iosApp/Hummingbird/Features/Flow/Flow3DView.swift` — SceneKit `UIViewRepresentable`. Exploded floor plates (segments = `SCNBox` per space, Y = floor rank × gap), bed-status colors, **free orbit/pinch** via `allowsCameraControl` + explicit `pointOfView`. **Frames on the clinical core** (bed/room categories) so campus/floor-slab CAD outliers don't blow up the camera distance.
- `FlowModels.swift` — `FlowDuty`, `FlowSpace3d`, `FlowSpaces3dDocument`, `FlowCentroid3d`, `FlowDutyAction`; `duties` added to `FlowWindowData` (decode + memberwise init + `merged(delta:)` + CodingKeys).
- `APIClient.swift` — `flowSpaces3d(bearer:)`.
- `FlowWindowStore.swift` — `@Published spaces3d`, fetched in `load()`.
- `FlowMapView.swift` — `is3DLens` (bed_manager/house_supervisor) → `flow3DLayer` renders `Flow3DView` instead of the 2.5D `houseLayer`.

**Verified:** `xcodebuild` green; on-device shows "1,450 anchors" exploded hospital twin, bed_manager lens, inside the Flow Window (Chronobar + timeline lanes intact).

**What's LEFT in Phase B (to reach web-viewer parity):**
1. **Patient tokens** — spheres at bed centroids from window snapshots/events, hidden for `patient_dots:none`.
2. **Chronobar-driven time** — the scrubber `t` should drive the scene (past replay, future ghosts). Right now the scene is static "now"; it does not yet react to `store.t`.
3. **Tap-pick → inspector** — raycast the `SCNBox` (nodes are named by `space_ref`) → selection sheet.
4. **Census / forecast heat** — per-unit pillars; forecast ghosts ahead of now.
5. **Legibility** — house scope is currently monochrome (bed colors only show at floor/unit scope where `bed_statuses` is served). Add census heat + a "focus my duties" camera. Segment sizes/scale tuning.
6. **Gesture conflict** — the 3D canvas sits in a scrolling layout; `allowsCameraControl` fights the scroll. Phase E may want a full-screen 3D mode.
7. **Android Filament/SceneView** — the SECOND renderer, not started. `ui/flow/` on Android needs the same: DTOs (duty + spaces3d), a `Flow3DView` equivalent (SceneView), wiring in `FlowMapScreen`.

---

## 4. Phases C–E — OPEN (from the plan)

- **Phase C — duties overlay + one-tap actions (frontline first):** render the `duties[]` (already flowing to the client — 12 for bed_manager) as 3D markers at their `centroid_m`, colored by `window_status` (overdue coral / due amber / upcoming ghost), with a synced duties bottom-sheet and one-tap `action.endpoint` calls. Start with transport/evs/charge_nurse.
- **Phase D — all 14 personas in 3D + DELETE the 2.5D:** wire every lens' framing into the 3D scene, then remove iOS `FloorPlateView.swift`/`HouseStackView.swift`, Android `FloorPlateCanvas.kt`/`HouseStack.kt`, and the 2.5D branches in FlowMapView/FlowMapScreen. **Parity-before-delete** — do not delete until every persona renders in 3D.
- **Phase E — perf/legibility/battery + offline + screenshot matrix + the two cache fixes (§5).**

---

## 5. CRITICAL environment notes & gotchas (read before touching anything)

1. **Canonical DB = `pgsql.acumenus.net`** (user `smudoshi`, pass `Acumenus321$%`, db `zephyrus`). `.env` is pointed there. Do NOT use the wiped `192.168.1.58` or local `hb_pg`. Memory: `canonical-backend-db`.
2. **`.env.local` shadowing trap:** `APP_ENV=local` + a tracked `.env.local` makes Laravel load `.env.local` INSTEAD of `.env` (drops APP_KEY → 500). `.env.local` is currently **moved aside** to `.env.local.shadow-disabled` so the full `.env` loads. Keep it aside. Memory: `env-local-shadowing-trap`.
3. **Backend serves via Docker:** containers `hb_pg` (owns ports), `hb_serve` (mounts the repo at `/var/www/html`, runs `php artisan serve --port=8001`), `hb_reverb`. After any crash/restart: `docker start hb_pg hb_serve hb_reverb`, then `docker exec hb_serve php artisan config:clear`. The iOS sim build targets `localhost:8001`.
4. **Post-restart DB race:** right after `docker start`, `pgsql.acumenus.net` connections can TIME OUT for a few seconds, then recover. Retry before concluding the DB is broken.
5. **TWO caching bugs that hid the 3D render (Phase E must fix):**
   - **Server:** `Spaces3dAssetService.load()` (and FloorPlateAssetService) cache the built asset in the 300s **file cache**. Under the post-restart DB race, an **empty** build got cached and served as `spaces=[]`. Fix: don't cache empty builds; invalidate on empty. Workaround: `docker exec hb_serve php artisan tinker --execute="Cache::forget('flow:spaces3d:doc'); Cache::forget('flow:plates:doc');"` + delete `storage/app/private/flow/*.json`.
   - **Client:** the endpoint sends `Cache-Control: private, max-age=86400`, so iOS `URLCache` cached the transient empty response for **24h across relaunches**. Only an app **uninstall** cleared it. Fix: APIClient should revalidate versioned assets via `If-None-Match` (ETag) rather than blind-serve for 24h.
6. **No `demo` user on the canonical DB.** To run the mobile app, auto-login as the **admin**: `HB_USER=smudoshi HB_PASS='Acumenus321$%'` (broad access → any persona). Creating a `demo` account on prod was intentionally NOT done (safety: weak-cred backdoor on a production hospital DB). Goal (a) was retired; (b) — show the map via the existing admin — is done.
7. **Temp test affordance in the tree:** `HB_HOME_MODE=map` hook in `hummingbird/iosApp/Hummingbird/Features/RTDC/HouseCapacityView.swift` (`viewMode` initial value reads the env). Launch-gated, inert in production; used to screenshot the Map without tapping. Revert or keep as a sanctioned hook.
8. **Tests run against `zephyrus_test`** (phpunit.xml). NEVER `migrate:fresh` anything but the test DB; NEVER touch `pgsql.acumenus.net` data.
9. **Working tree is heavily dirty and UNCOMMITTED.** It contains (a) this native-viewer work (Phase A backend + Phase B iOS + plan/devlog), (b) the earlier toggle fix, AND (c) a large **unrelated** service-line/deployment + staffing-wizard body of work from other sessions (`app/Services/Deployment/`, `app/Models/Org/`, `database/migrations/2026_07_04_*`, etc.). **Do not commit the unrelated work.** Stage the native-viewer files explicitly (see §7). The Flow Window Phases 2–5 are already committed/pushed.

---

## 6. How to build / verify / reproduce the 3D render

**Backend tests:** `php artisan test tests/Feature/Mobile` (host; uses `zephyrus_test`). Must stay green.

**iOS build:** `cd hummingbird/iosApp && xcodegen generate && xcodebuild -project Hummingbird.xcodeproj -scheme Hummingbird -destination 'generic/platform=iOS Simulator' build`.

**Reproduce the on-device 3D twin (the exact dance that works):**
1. `docker start hb_pg hb_serve hb_reverb && docker exec hb_serve php artisan config:clear`
2. Warm the server cache correctly: `docker exec hb_serve php artisan tinker --execute="\Illuminate\Support\Facades\Cache::forget('flow:spaces3d:doc'); echo count(app(\App\Services\Flow\Spaces3dAssetService::class)->load()['spaces']);"` → must print **1450**, not 0.
3. Build for the booted sim (`-destination 'platform=iOS Simulator,name=iPhone 17 Pro' -derivedDataPath build/DD`).
4. **Uninstall first** to clear the client URLCache: `xcrun simctl uninstall booted net.acumenus.hummingbird`, then `install`.
5. Launch: `SIMCTL_CHILD_HB_AUTOLOGIN=1 SIMCTL_CHILD_HB_USER=smudoshi SIMCTL_CHILD_HB_PASS='Acumenus321$%' SIMCTL_CHILD_HB_ROLE=bed_manager SIMCTL_CHILD_HB_TAB=home SIMCTL_CHILD_HB_HOME_MODE=map xcrun simctl launch booted net.acumenus.hummingbird`
6. Screenshot: `xcrun simctl io booted screenshot out.png`. Expect the exploded 3D hospital (1,450 anchors).

**Web 4D Navigator (three.js, live movement):** log in at `http://localhost:8001/login` as `smudoshi` / `Acumenus321$%`, go to `/rtdc/patient-flow-navigator`. Requires the Vite dev server (`npm run dev`, :5176) because `app.blade.php` hardcodes a `.jsx` per-page entry and this page is `.tsx` — **pre-existing bug worth fixing** (make the blade resolve `.tsx`). Run `patient-flow:rebase-synthetic --anchor=now` so the synthetic movements fall in the 48h window.

---

## 7. Suggested next steps (in order)

1. **Commit the native-viewer work** (explicit pathspec — do NOT sweep the unrelated deployment work): `app/Services/Flow/DutyProjectionService.php`, `Spaces3dAssetService.php`, `app/Http/Controllers/Api/Mobile/FlowController.php`, `config/hummingbird/flow_lens.php`, `routes/api.php`, OpenAPI + fixtures, `tests/Feature/Mobile/FlowWindowTest.php`, `tests/Feature/MobileBffTest.php`, `hummingbird/iosApp/Hummingbird/Features/Flow/*`, `Networking/APIClient.swift`, `docs/hummingbird/NATIVE-4D-VIEWER-PLAN.md`, this devlog. (Also the earlier toggle fix in `resources/js/Components/PatientFlowNavigator/`.)
2. **Finish Phase B iOS parity:** patient tokens → Chronobar-driven time → tap-pick inspector → census heat.
3. **Android Filament renderer** (the second engine).
4. **Phase C duties overlay + actions** (duties already reach the client).
5. **Phase E:** fix the two caching bugs (§5.5), then perf + the persona screenshot matrix.
6. **Phase D:** delete the 2.5D renderers once all personas render in 3D.

Open product questions (plan §11): service-scope patient access for hospitalist/intensivist; EDD write-back as a first-class duty action; whether to reuse the 753 KB GLB shell or keep the procedural box scene.
