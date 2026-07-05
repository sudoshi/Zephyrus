# Native 4D Viewer — Per-Persona Duties in Space & Time

**Status:** Proposed (not started) — supersedes the mobile 2.5D Flow Window renderer.
**Date:** 2026-07-04
**Decisions locked (user):** (1) **Native 3D on both platforms** — SceneKit (iOS) + Filament/SceneView (Android), not WebView, not 2.5D. (2) **The 2.5D floor‑plate / axonometric‑stack view is eliminated** — the native 3D twin replaces it (built to full‑persona parity *before* deletion; see §9). (3) Write this plan before code.
**Mandate:** the 4D viewer is a **critically important frontline surface for every persona** — its job is to show *what assignments and duties are due for their actions*, in the real building, across the 48‑hour window.
**Builds on:** `FLOW-WINDOW-PLAN.md` (Phases 0–5, shipped), `ALTITUDE-PERSONA-OPERATING-PLAN.md`, `ADR-2026-07-01-altitude-patient-lens.md`.

---

## 1. The reframe: the 4D viewer is a **duties** instrument

The current viewers answer *"where are patients moving?"* The mandate is different and sharper:

> For **this persona**, show **the duties due to me** — what, **where** in the building, **when** on the timeline (overdue / due / upcoming), and let me **act** — rendered in a real 3D twin I can read and trust at a glance.

So the 3D twin is not the point; it is the **canvas**. The point is the persona's **duties, in space and time**. Every design decision serves legibility of "my work, where and when." A 3D building that doesn't make a transporter's next three runs obvious in two seconds is a failure regardless of how good it looks.

Design non‑negotiables (PRODUCT.md / DESIGN.md):
- **Duties‑first, twin‑second.** The 3D exists to place and time the duties. A persona must be able to answer "what's due for me next, and where?" in ~2s.
- **Earned urgency.** Overdue = coral; due‑soon = amber; upcoming = calm/ghost. Never an alarm field.
- **Defensible.** Every projected/predicted duty cites its source + reliability (reuse the Flow Window provenance model).
- **One‑handed glance‑and‑act.** Frontline devices, gloves, motion. Big tap targets, a duties bottom‑sheet synced to the scene, Reduce‑Motion honored.

---

## 2. What exists today (build on it — do not restart)

### 2.1 Mobile 2.5D Flow Window (to be replaced)
- iOS `Features/Flow/`, Android `ui/flow/`. Per‑persona, live `prod.*`, 48h Chronobar, entry via List⇄Map on all 14 persona homes.
- **2.5D spatial renderers (RETIRE):** iOS `FloorPlateView.swift`, `HouseStackView.swift`; Android `FloorPlateCanvas.kt`, `HouseStack.kt`.
- **Reusable, keep:** `Chronobar(View)`, `FlowModels`, `FlowWindowStore` / `FlowViewModel`, `FlowWindowCache`, `TimelineLanes`, `FlowCurve(View)`, `FlowLensPanels` / `FlowAggregateLenses`, `RoomLanes(View)`, `TransportRoutesLayer`, `EvsTurnLayer` (their *data/logic*; the drawing moves into the 3D scene where spatial).

### 2.2 Web 3D viewer (reference implementation for the scene)
- three.js `NavigatorScene.ts` + the GLB shell (`public/vendor/zephyrus-facility-models/zep-500/hospital_model.glb`, **753 KB**). Persona lens (Phase 4), projection ghosts, Chronobar, patient tokens/trails/census/forecast. Runs on synthetic `flow_core` (no live tasks, no duties overlay). This is the **scene spec reference** the native renderers mirror.

### 2.3 Backend & data (the substrate the duties layer plugs into)
- `GET /api/mobile/v1/flow/{window,floors}`, `FlowLensService`, `flow_lens.php` (14 roles with **scope / event_kinds / projection_kinds / patient_dots / actions**), `ForwardProjectionService` (due‑dated projections: `transport_due`, `evs_due`, `staffing_shift_gap`, `expected_discharge`, …), `OperationalTimelineService`, `bed_statuses`, `since`‑deltas.
- **Task/duty domains already live:** `TransportOperationsService`, `EvsOperationsService`, `BedPlacementService`, `BarrierService`, `StaffingOperationsService`, `RoomStatusService`, `OperationalActivityLedger`, `MobileForYouService` (already ranks each persona's duties!).
- **3D geometry substrate exists:** `hosp_space.facility_spaces` carries `floor_number`, `position_ft`, and **`centroid_x_ft / centroid_y_ft / centroid_z_ft`** per space (room/bed/unit) — exact 3D anchors for tokens and duty markers. Bridged to `prod.units`/`prod.beds` (Phase 0 `facility:link-operational`).

**Key insight:** ~70% of "duties due per persona" already exists as data (For‑You + projections + domain queues + actions). The missing pieces are (a) a **unified, spatially‑anchored, due‑dated duties stream**, and (b) a **native 3D renderer** that places it. We are not starting from zero.

---

## 3. Architecture decisions

- **D1 — Native 3D, two engines.** iOS **SceneKit** (OS‑native, SwiftUI `UIViewRepresentable`, node picking, instancing; GLB→USDZ via Model I/O/`GLTFKit` at build time). Android **Filament via SceneView (`io.github.sceneview:sceneview`)** (Compose‑friendly, glTF/PBR, hit‑testing). Rejected: WebView embed (battery/offline/feel), RealityKit (overhead), raw OpenGL (cost).
- **D2 — Eliminate the 2.5D, but parity‑before‑delete.** The 3D twin is the *only* spatial view at the end. The 2.5D renderers are deleted in **Phase D**, *after* the 3D reaches all‑14‑persona parity — never leave a persona without a spatial view mid‑flight.
- **D3 — Duties are a first‑class layer, not a footnote.** New `duties[]` in the window payload: `{id, kind, persona_action, label, space_ref, unit_id, bed_id, centroid_3d, due_at, window_status: overdue|due|upcoming, tier, provenance, action{endpoint,method}}`. Sourced from the domain services above, per‑persona via the lens.
- **D4 — Reuse the GLB shell; anchor by centroid, not mesh nodes.** The GLB is the building *shell*. Tokens/duty‑markers are placed by `facility_spaces` **3D centroids** (ft→m), exactly as the web viewer maps `position_ft`. No fragile GLB‑internal node mapping required. Ship the GLB + a compact **space‑anchor asset** (`GET /flow/spaces3d`: `{space_ref, floor, unit_id, bed_id, centroid_m, category}`), versioned + ETagged like the 2D plates.
- **D5 — One scene *spec*, three renderers.** Layout math (exploded‑floor offsets, token sizing, color/urgency mapping, ghost grammar, camera framings) is defined **once** in shared design tokens + the API contract + shared fixtures, and implemented per renderer (three.js / SceneKit / Filament). Prevents divergence across the three viewers.
- **D6 — Keep the temporal + lens spine.** Chronobar (48h), TimelineLanes, `FlowWindowStore`/`FlowViewModel`, lens RBAC, `since`‑deltas, offline cache — all reused. The 3D scene is a new *render target* for the same store.

---

## 4. Data plane (backend workstreams)

### W1 — Duties service (the core new capability)
`app/Services/Flow/DutyProjectionService.php`: assemble each persona's due duties into one spatially‑anchored, due‑dated stream, composing the existing domain services + `MobileForYouService` + `ForwardProjectionService`. Each duty carries a `space_ref` + `centroid_3d` (via `facility_spaces`), a `due_at`, a `window_status`, and its governed `action`. Kinds by persona (see §8): trips, turns, placements, barriers, discharges‑to‑confirm, staffing fills, OR milestones, approvals, PDSA steps.

### W2 — Per‑persona duty lens
Extend `config/hummingbird/flow_lens.php`: add `duty_kinds` + confirm `actions` per role. Enforce server‑side in `FlowLensService` (duties clamped to the role, patient identity still ptok‑gated by `patient_dots`).

### W3 — 3D space‑anchor asset
`FloorPlateAssetService` → add `spaces3d` export (`facility:export-spaces3d`): per‑space 3D centroid (m), floor, category, unit/bed bridges; versioned JSON, ETag, `GET /api/mobile/v1/flow/spaces3d`. Ship alongside the GLB (or a mobile‑decimated glTF if profiling demands).

### W4 — Window payload + contract
Add `duties[]` to `GET /flow/window` (lens‑clamped, `since`‑aware — duties are "always full" like projections). OpenAPI + shared fixtures (`mobile-flow-window*.json`) + drift test + the 14‑role **redaction matrix extended to duties** (zero raw refs; zero patient identity for `patient_dots:none`; each persona sees only *their* duty kinds).

### W5 — Live per‑space state for the twin
Ensure room/segment‑level occupancy/status (extend `bed_statuses` → `space_states` at floor/unit scope) so segments light by real state at time `t` (occupied/available/blocked/dirty/in‑use). Reuse the checkpoint+replay model for the past half.

---

## 5. The native 3D scene (per platform — mirror the web scene spec)

Shared scene graph (both engines):
1. **Building shell** — GLB, semi‑transparent, floors separable.
2. **Exploded‑floor layout** — floors lifted apart on Y by a shared offset (the axonometric idea, now true 3D), so interior segments are visible; collapse‑to‑solid‑tower toggle.
3. **Segment layer** — room/bed segments colored by live state at `t` (from W5), placed by centroid.
4. **Patient tokens** *(lens `patient_dots` ≠ none)* — instanced markers at bed centroids; hidden entirely for aggregate personas.
5. **Trails** — polylines through a patient's visited centroids up to `t` (past half only).
6. **Census / Forecast heat** — per‑unit pillars; forecast pillars as translucent ghosts ahead of `now`.
7. **Duties layer (the headline)** — per‑persona duty markers at their `space_ref` centroid: shape by kind, color by `window_status` (coral/amber/calm), badge with `due_at` countdown, ghost styling when future. Routes (transport) as arcs between origin/destination centroids across floors.
8. **Camera** — orbit/pinch; **per‑persona default framing** (§8); "Focus my duties" fit‑to‑bounds; Reset.
9. **Picking** — tap → raycast → segment/patient/duty → inspector bottom‑sheet with the governed action.
10. **Time** — the Chronobar drives `t`; scrubbing/playback updates layers (past = replay, future = ghosts), symmetric with today's Flow Window.

Engine specifics: SceneKit `SCNView` in a `UIViewRepresentable` bridged to `FlowWindowStore`; Filament `SceneView` composable bridged to `FlowViewModel`. Instanced token geometry, LOD, frustum culling, `preferredFramesPerSecond`/`renderCallback` gating, pause on background.

---

## 6. Duties overlay & actionability (per persona)

- **Duty markers** placed by centroid, sized for touch, badged with countdown; overdue floats/pulses (subtle), upcoming is ghosted on the future half of the Chronobar.
- **Synced duties sheet** — a bottom sheet listing the persona's duties (ranked overdue→due→upcoming), each row selectable → highlights/frames its marker in 3D; the reverse also holds (tap marker → scroll sheet). This is the "what's due for me" answer.
- **One‑tap actions** — each duty's governed endpoint (already on the BFF): `claim/advance/handoff` trip, `claim/start/complete` turn, `resolve` barrier, `place/reject` bed, `fill` request, `decide` approval, `confirm EDD`. Optimistic UI + `since`‑delta refresh + 409 conflict handling (reuse Phase 5).
- **"Focus my duties"** camera preset frames only the spaces with the persona's open duties.

---

## 7. Per‑persona 3D lens specs (all 14 — duties‑first)

Format — **Default framing** · **Duties shown** · **Patient depth** · **Signature "what's due" moment**.

- **Transporter** — camera follows own trips across floors (route arcs) · trips (claim/advance/handoff) + derived discharge‑runs · task · *"3 runs due next hour; the STAT from 4W is overdue — here's the route."*
- **EVS** — floors colored by dirty/turn state · turns (claim/start/complete), isolation‑flagged · task · *"Floor 4 lights up amber — 5 turns due, 1 isolation, ordered by due‑time."*
- **Charge / Bedside Nurse** — own unit exploded, bed grid in 3D · discharges‑to‑confirm, barriers‑to‑resolve, inbound placements, EVS/transport touching my beds · unit · *"My unit at shift‑start: 3 probable discharges, 2 barriers due, 2 boarders inbound."*
- **Hospitalist / Intensivist** — their patients lit across floors · discharge‑leverage duties (confirm EDD), downgrades · unit(service‑scope = §12 open Q) · *"Rounding order by what my discharge unblocks."*
- **Bed Manager / House Supervisor** — whole house, exploded · placements (place/reject with scored beds as arcs), barriers, surge · full · *"Scrub +6h: MICU saturates unless 2 step‑downs move — place them here."*
- **OR Nurse / Periop Manager** — periop floor, rooms in 3D · case milestones, turnovers, PACU handoffs (delay cascade) · none · *"OR‑4 turnover drag threatens the 13:00 slot."*
- **Capacity Lead** — house, curve‑primary + 3D heat · approvals plotted at projected impact time · full · *"This approval moves the 18:00 peak −2.1 beds."*
- **Executive** — house heat only, **no tokens/duties markers** (aggregate; `patient_dots:none`) · none · *15s time‑lapse settling into tomorrow's forecast band.*
- **Staffing Coordinator** — floors tinted by gap vs predicted census · fills due at shift boundaries · none · *"TEL7A below‑safe at 19:00 if 4 admits land — fill here."*
- **PI / Quality Lead** — de‑identified house replay · pattern clips (no patient markers) · none · *"Boarding builds 15:00–19:00 every day — clip it."*

(Executive/PI/Staffing/OR remain aggregate/no‑patient per the A2P matrix; their "duties" are approvals/fills/clips, not patient‑identified.)

---

## 8. Retiring the 2.5D (§ "eliminate it")

- **Delete (Phase D, after 3D parity):** iOS `FloorPlateView.swift`, `HouseStackView.swift`; Android `FloorPlateCanvas.kt`, `HouseStack.kt`; and the 2.5D branches inside `FlowMapView`/`FlowMapScreen`.
- **Absorb into 3D:** `RoomLanes` (OR) and `TransportRoutesLayer`/`EvsTurnLayer` spatial drawing become 3D layers; their *logic* is retained.
- **Keep as supporting 2D chrome (not the retired "2.5D map"):** Chronobar, TimelineLanes, `FlowCurve` (census curve panel), the duties bottom‑sheet, `FlowLensPanels`.
- **Entry point:** the home "Map" segment becomes the 3D viewer (relabel "3D" / "Twin"); the "List" worklist stays.

---

## 9. Phasing

- **Phase A — Backend duties + 3D substrate:** W1 DutyProjectionService · W2 duty lens · W3 `spaces3d` asset · W4 `duties[]` contract + fixtures + 14‑role redaction matrix · W5 space_states. Ship behind the existing lens; verify with tests before any UI.
- **Phase B — Native 3D foundation (both platforms), one persona:** SceneKit + SceneView scenes to **parity with the web viewer** (shell, exploded floors, segments, patients, trails, census, forecast, camera, picking, Chronobar‑driven time) for **bed_manager/house_supervisor** (house scope). Flagged; 2.5D still present.
- **Phase C — Duties overlay + actions (frontline first):** the duties layer + synced sheet + one‑tap actions for **transport, evs, charge_nurse** end‑to‑end.
- **Phase D — All personas + eliminate 2.5D:** wire all 14 lens framings into the 3D scene; **delete the 2.5D renderers** (§8); 3D becomes the sole spatial view.
- **Phase E — Perf, legibility, stickiness:** LOD/instancing/battery pass, per‑persona camera tuning, offline model+asset cache, Live Activity/widget ties to due‑duties, the full **persona × surface × platform screenshot matrix** as the regression gate.

Each phase ends demo‑able against the canonical DB (`pgsql.acumenus.net`); test writes only against `zephyrus_test`.

---

## 10. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Three renderers of one scene** (three.js + SceneKit + Filament) diverge | D5: one scene *spec* in tokens + contract + shared fixtures; parity checklist per phase |
| Native 3D is a large, two‑engine lift | Phase to parity on one persona first; reuse GLB + centroid anchoring (no node mapping); keep the whole temporal/lens/store spine |
| **Deleting the only spatial view before 3D is ready** | D2 parity‑before‑delete: 2.5D removed only in Phase D |
| 3D on a phone becomes an unreadable gimmick | Duties‑first framing, per‑persona default cameras, "Focus my duties", bottom‑sheet sync, 2s‑glance success criterion |
| Battery/thermal on frontline devices | LOD, instancing, culling, fps gating, pause‑on‑background, decimated model if needed |
| GLB→USDZ / SceneView maturity | Build‑time conversion validated in Phase B spike; SceneView is production‑used; fall back to a decimated glTF |
| PHI via a richer surface | Duties + tokens are ptok‑gated; `patient_dots:none` personas get no markers; redaction matrix extended to duties as a Phase‑A gate |

---

## 11. Open questions

1. **Service‑scope patient access** for hospitalist/intensivist (unit‑based today) — needed for their "my patients across the house" duties. (Same P5.4‑class change flagged in FLOW‑WINDOW‑PLAN §11.)
2. **EDD write‑back** (confirm/adjust discharge) as a first‑class duty+action — new endpoint + ledger event.
3. **Model fidelity** — is the 753 KB shell enough per‑floor detail, or do we need a higher‑detail per‑floor model for unit/bed legibility when zoomed?
4. **Offline** — cache the model + `spaces3d` + last window per (persona, scope); how much history to keep for the replay half.

---

## 12. Success criteria

- Every one of the 14 personas opens a **native 3D** twin and, within ~2 seconds, can see **what is due to them, where, and when** — verified by the screenshot matrix + task‑time checks.
- A frontline duty can be **acted on** from the 3D scene (claim/start/complete/place/resolve/fill) end‑to‑end.
- Zero raw patient refs in any payload; zero patient markers for `patient_dots:none` personas (automated).
- The **2.5D renderers are deleted**; the 3D twin is the sole spatial view, at parity with the web viewer plus the duties layer.
- Web (three.js) and mobile (SceneKit/Filament) render the same state for the same `t` and persona.
