# Patient Flow 4D Navigator — Deep Review & Advancement Plan

**Date:** 2026-07-18
**Surface:** `/rtdc/patient-flow-navigator` (Pages/RTDC/PatientFlowNavigator.tsx → Components/PatientFlowNavigator/*)
**Scope:** Navigability, element legibility, the dual Barrier toggles, Virtual Rounds integration, long-session correctness.
**Related plans:** `docs/hummingbird/FLOW-WINDOW-PLAN.md` (shipped Phases 0–5), `docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md` (§5.2 guided camera — unbuilt).

---

## 1. Executive Summary

The 4D Navigator is architecturally sound — a thin React orchestrator, a lazy-chunked three.js scene, bucketed rebuilds, a disciplined 48h Chronobar, and correct lens/identity redaction. Its problems are **legibility and connection**, not engineering:

1. **The scene has a rich shape grammar nobody can learn.** Six shape vocabularies (sphere / line / disk / pip / diamond / ring / pillar) encode six data concepts, but there is no legend, no hover feedback, and no selection highlight. A first-time operator sees colored geometry with no key.
2. **The building itself is illegible.** The GLB carries 1,472 nodes with full semantic extras — `corridor`, `patient_room`, `bed`, `care_unit`, `emergency_department`, `imaging`, `procedure_room`, `elevator`, `helipad`, plus clinical attributes (ICU-capable, telemetry, negative-pressure) — yet the renderer paints everything one translucent gray (floors 0.56, all else 0.72). Hallways, beds, and rooms are visually identical.
3. **The two "barrier" controls are two different data concepts sharing one word** (see §3). The split is deliberate (HFE wrong-toggle fix) but the naming and grouping still invite confusion.
4. **Patient token colors violate the earned-urgency ration.** `hashColor()` assigns any hue 0–360, so a patient can randomly render coral or amber — the colors reserved for breach and warning.
5. **Virtual Rounds and the 4D scene are disconnected.** The rounds layer renders rings, but `focusRoundStop()` is dead code (zero call sites), there is no link in either direction between the Rounds board and the navigator, the overlay is fetched once and goes stale during an active round, and the promised guided tour (§5.2) does not exist.
6. **Long sessions drift.** `nowMs` is frozen at mount; after hours on a wall display the gold now-marker, ghost gating, and barrier severity are all wrong. Barriers and projections are fetched once, never refreshed.

The plan below fixes these in five phases, each independently shippable.

---

## 2. Current State — Architecture Map

| Piece | File | Role |
|---|---|---|
| Page wrapper | `resources/js/Pages/RTDC/PatientFlowNavigator.tsx` | DashboardLayout `fullBleed`; passes `flowLens` + `flowUnits` Inertia props |
| Orchestrator | `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx` | Data fetching, 48h time model, playback/live, filters, layer state, inspector redaction |
| Scene | `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts` | Only importer of `three`; lazy chunk; 7 layers (forecast, heat, trails, ghosts, patients, barriers, rounds) |
| Toolbar | `NavigatorToolbar.tsx` | Source status, chronobar slot, transport buttons, filters, layer switches, metrics, occupancy rollup |
| Chronobar | `NavigatorChronobar.tsx` | 48h window, shift detents, coverage band, barrier ticks, forecast HUD |
| Inspector / Feed | `NavigatorInspector.tsx`, `NavigatorFeed.tsx` | Click-to-inspect key/value panel; recent-event feed |
| Placement logic | `features/patientFlowNavigator/projections.ts` | Placement index, ghost selection, barrier cells + severity |
| Occupancy | `features/patientFlowNavigator/occupancyInsights.ts` + server `/occupancy` | Disk + timer-pip insight model |
| Rounds overlay | `features/virtualRounds/roundsScene.ts` | `buildRoundStopCells`, `ROUND_STOP_COLORS` |
| Backend | `PatientFlowController` (`/api/patient-flow/*`), `RoundRunController::scene()` (`/api/rounds/runs/{uuid}/scene`) | Locations from `hosp_space.facility_spaces`; barriers from `prod.barriers`; stops patient-free |
| Model | `public/facility-models/zep-500/hospital_model.glb` (771 KB, 1,472 nodes / 30 meshes) | glTF `extras` → `Object3D.userData` (already proven: the `floor` opacity branch reads it) |

**Current scene vocabulary (undocumented, in-code only):**

| Shape | Layer | Meaning | Color rule |
|---|---|---|---|
| Sphere (r 1.65) | patients | Patient token | `hashColor(patientId)` — any hue ⚠ |
| Line | trails | Patient movement history | same hash color, 0.55 α |
| Flat cylinder disk | heat | Occupancy + stay duration (radius = √stay-hours) | green ok / amber watch / coral delayed |
| Tiny pip cylinder ×4 | heat | Individual timers around a disk | same status colors |
| Translucent sphere | ghosts | Future projection (discharge / transport / EVS / OR case) | teal / sky / light-sky / blue, opacity = confidence |
| Translucent pillar | forecast | Predicted census per unit (height = census) | cyan |
| Octahedron (diamond) | barriers | Registered open barrier (`prod.barriers`) | sky <24h / amber ≥24h / coral ≥48h open-age |
| Torus ring (flat) | rounds | Virtual Rounds stop | slate/blue/amber/sky/teal by status; amber ring = pinned |

This table does not exist anywhere a user can see. That is finding E-1.

---

## 3. The Two Barrier Toggles — Investigation & Verdict

**Answer to "there are two for some reason?":** yes, deliberately — they control different things — but the execution still confuses.

| Control | Identity | What it actually does |
|---|---|---|
| **"Barriers"** layer switch (`flow-layer-barriers`, added in `layerControls`, PatientFlowNavigator.tsx:364) | A **visibility toggle** for one scene layer | Shows/hides the floating octahedron markers of *registered operational barriers* — real `prod.barriers` rows (category medical/logistical/placement/social, owner, reason code) fetched from `/api/patient-flow/barriers`. Severity is earned from open-age (projections.ts:143 — ≥48h coral, ≥24h amber, else sky). |
| **"Find barriers"** switch (`flow-barrier-finder`, NavigatorToolbar.tsx:214–225) | A **filter + camera mode**, not a layer | Filters the *census/occupancy disks* down to locations matching `isBarrierOrDelay()` (PatientFlowNavigator.tsx:228 — status ≠ ok, blockers present, or any timer late), rewrites the "Active" metric denominator (line 493), and **auto-flies the camera** to the delayed set on enable (lines 522–527). It operates on elapsed-timer *signals*, which the toolbar itself disclaims: *"Elapsed occupancy signal; not a verified operational barrier"* (NavigatorToolbar.tsx:247). |

The comment at NavigatorToolbar.tsx:222 shows the split already survived one HFE audit (two controls must never share one accessible name). The **residual defects**:

- **B-1 (High):** Near-synonym labels for different data concepts. "Barriers" = logged operational barriers; "Find barriers" = *delays*. A charge nurse cannot predict which switch does what, and the word "barrier" is used for both the verified and the unverified signal.
- **B-2 (Medium):** "Find barriers" sits **inside the Layers fieldset** though it is not a layer — it's a census filter. Wrong grouping teaches the wrong mental model.
- **B-3 (Medium):** Enabling it silently changes the meaning of the "Active" metric and hides non-delayed disks — with no visible "filtered" state beyond the switch itself.
- **B-4 (Low):** The camera auto-fly is a hidden side effect of a checkbox. Checkboxes should not move the camera.

**Remediation (Phase 0):**
1. Rename "Find barriers" → **"Delayed only"** and move it out of the Layers fieldset into the filter grid (next to Floor/Service/Event), presented as a census-scope control: `Census: All | Delayed`. Keep distinct ids/names.
2. Keep the "Barriers" layer switch as-is but retitle its tooltip: *"Logged operational barriers (diamond markers)"*.
3. When Delayed-only is active, show a dismissible **filter chip** near the metrics block: `Filtered: delayed locations only (N)` — the metric relabels from "Active" to "Delayed".
4. Replace the auto-fly with an explicit **"Focus delayed"** button (icon: ScanSearch) enabled while the filter is on; first enable may still offer one fly via the button's pulse, never via the checkbox.

---

## 4. Findings Catalog

Severity: **H**igh (blocks comprehension/decisions) / **M**edium (slows or misleads) / **L**ow (polish).

### E — Element legibility
- **E-1 (H):** No legend/key anywhere. Shape+color grammar (table §2) is unlearnable in-product. → Phase 1.
- **E-2 (H):** Base model renders all categories identically; GLB extras (`category`, `unit_code`, `service_line`, `bed_code`, acuity flags) are already in `userData` but unused except `floor`/`elevator`. Hallway vs room vs bed vs ED indistinguishable. → Phase 1.
- **E-3 (H):** `hashColor()` (NavigatorScene.ts:862) can assign coral/amber hues to patient tokens, colliding with the status ration ("when something turns coral, it means it" — DESIGN.md). → Phase 1.
- **E-4 (M):** No hover affordance — raycast only on `pointerdown`. Users cannot discover what is clickable; nothing names an element until after a click. → Phase 1.
- **E-5 (M):** No in-scene selection highlight — clicking populates the inspector but the scene shows nothing (rounds focus is the only exception). → Phase 1.
- **E-6 (M):** No in-scene labels: 16 floors, 23 care units, zero signage. Unit identity only appears after clicking a disk. → Phase 2.
- **E-7 (L):** Status is color-coded in-scene without a paired shape/label cue at the mesh level (canon: never color alone). The severity *is* shape-scaled (`BARRIER_SCALE`) and the inspector carries labels, but disks rely on color only. Legend + hover chips (E-1/E-4) are the compensating controls; note in legend copy. → Phase 1.

### N — Navigability
- **N-1 (H):** No **"Now"** button. Once scrubbed, returning to the present requires pixel-precise slider dragging. → Phase 0.
- **N-2 (M):** Chronobar shift detents and barrier ticks are `aria-hidden` decorations — not clickable, not focusable. They look like affordances and aren't. → Phase 0.
- **N-3 (M):** Camera readout is raw `x/y/z` (NavigatorScene.ts:710) — operator-hostile debug text. Should read like a place: `Floor 3 · 3W Med-Surg`. → Phase 0.
- **N-4 (M):** Floor selection is a small dropdown; no fast floor stepping, no fit-to-floor on change, no keyboard path for the primary spatial filter. → Phase 2.
- **N-5 (M):** Search filters tokens but never moves the camera; no result count; Enter does nothing. → Phase 2.
- **N-6 (L):** No double-click-to-focus, no keyboard shortcuts (Home/F/?, arrows), no shortcut help. → Phase 2.
- **N-7 (L):** No saved views (persona-relevant camera bookmarks: ED, ICU, Home). → Phase 2.
- **N-8 (L):** "Live" Radio button streams a **stored replay** (honest in the status bar, less so on the button). Retitle "Replay stream". → Phase 0.

### R — Virtual Rounds
- **R-1 (H):** `focusRoundStop()` (NavigatorScene.ts:584) has **zero call sites** — the guided-camera seam shipped and was never wired. → Phase 3.
- **R-2 (H):** No path from Rounds board → 4D (no "Locate in 4D") and no path from a 4D ring → Rounds board (inspector shows the opaque uuid and stops there). → Phase 3.
- **R-3 (M):** Rounds overlay is a one-shot fetch on mount (PatientFlowNavigator.tsx:665–692) while the Rounds board polls at 30 s (`features/virtualRounds/hooks.ts:57`). During an active run the rings show stale statuses. → Phase 3.
- **R-4 (M):** Queue order (`queue_position`) is invisible in-scene — no numbering, no route. A rounder cannot see the itinerary spatially. → Phase 3.
- **R-5 (M):** No run-progress HUD in the navigator (run status, N of M rounded, awaiting-input count). → Phase 3.
- **R-6 (L):** Guided tour (auto-stepping camera per §5.2 of the rounds plan) unbuilt; Eddy tour runner unbuilt (deferred — see Phase 4). 

### S — System / long-session correctness
- **S-1 (H):** `nowMs` frozen at mount (PatientFlowNavigator.tsx:243). Now-marker, `ghostsAt` gating, forecast gating, and barrier open-age severity all drift on long-lived sessions (prod is a 6h-refresh demo wall). → Phase 0.
- **S-2 (M):** Barriers fetched once (no polling); projections fetched once. Occupancy already refetches on time change. → Phase 0.
- **S-3 (L):** Mobile: toolbar may cover 64% of the viewport; feed/metrics/rollup hidden. Acceptable triage today; collapsible accordion later. → Phase 4.

---

## 5. Design — Element Identity System (Phase 1)

Goal: **any operator can name any element within 5 seconds**, via three reinforcing mechanisms: differentiated materials, a legend, and hover identification. All colors stay inside the operational family (slate/blue/sky/teal); amber/coral remain status-only; gold remains focus-only; crimson never enters the scene.

### 5.1 Single source of truth: `sceneVocabulary.ts`
New module `resources/js/features/patientFlowNavigator/sceneVocabulary.ts` exporting every shape/color constant currently scattered in NavigatorScene.ts (`BARRIER_COLORS`, `GHOST_COLORS`, occupancy/timer/forecast material params, `ROUND_STOP_COLORS` re-export) **plus** the new base-category palette, each entry carrying `{ key, label, shape, colorHex, description }`. NavigatorScene consumes it for materials; the legend renders from it. One edit updates both — the legend can never lie.

### 5.2 Base-model category materials
In `loadModel()` traversal, replace the two-way opacity split with a category material map (mesh.userData.category is already populated from glTF extras):

| Category | Treatment (dark scene, additive to base 0x-gray) | Rationale |
|---|---|---|
| `floor` | current beige-gray, α 0.56 | unchanged datum plane |
| `corridor` | darker, desaturated slate, α 0.35 | circulation reads as negative space |
| `patient_room` | neutral slate, α 0.60 | the default "room" |
| `bed` | slate-**blue** tint, α 0.85, subtle emissive | the care asset — most important base element |
| `care_unit` | very low-α unit shell tint | grouping, not object |
| `emergency_department` | **sky** tint, α 0.55 | high-tempo zone identity |
| `imaging` / `procedure_room` / `procedure_support` | **blue** tint, α 0.55 | interventional zones |
| `elevator` | light neutral, α 0.85 (already always-visible) | vertical circulation landmark |
| `helipad` / `support_infrastructure` | dim neutral | context only |

Implementation: category → `MeshStandardMaterial` cache (mirrors existing material caches); fall back to today's 0.72 default for unknown categories. **No backend change required.**

### 5.3 Patient token palette fix
Constrain `hashColor()` hue to **160°–280°** (teal → blue → violet): `hue = 160 + (hash % 120)`. Tokens stay individually distinguishable but can never impersonate amber/coral status. (Alternative considered — color by service line — rejected for v1: trails need per-patient identity continuity; service line already lives on the disks and inspector.)

### 5.4 Legend panel (`NavigatorLegend.tsx`)
- Collapsible overlay (default collapsed to a `Key` pill, bottom-left above the status bar; expanded ≈ 260 px panel).
- Renders from `sceneVocabulary.ts`: one row per element — shape glyph (small inline SVG: circle / line / disk / diamond / ring / pillar / pip), color chip, label, one-line description. Sections: *People & movement*, *Occupancy & timers*, *Barriers*, *Forecast*, *Rounds*, *Building*.
- Status colors always shown **with their worded meaning** (canon: never color alone).
- Styles appended to the existing `PatientFlowNavigator.css` (no new backdrop-blur file — the canon script gates NEW files; this CSS already carries the overlay treatment).
- Rows for layers currently toggled off render dimmed with "(hidden)".

### 5.5 Hover + selection
- **Hover:** throttled (≥50 ms) `pointermove` raycast against the same interactive set as `onPointerDown`. On hit: `cursor: pointer`, emissive-intensity boost via a shared hover clone (the focused-round-material pattern at NavigatorScene.ts:608 already proves the clone-and-dispose approach), and a lightweight HTML chip near the cursor: `{elementLabel} · {name}` (e.g. `Bed · 5-212`, `Open barrier · EVS delay`, `Round stop · queued`). Chip text comes from `sceneVocabulary` + userData; no identity fields ever (lens redaction applies before display).
- **Selection:** persist the highlight on the clicked object until the next selection/Escape; inspector title gains the element-type prefix so panel and scene agree.
- Perf guard: raycast skips when pointer is over an overlay panel; hover disabled while `playing` at >60×.

---

## 6. Design — Navigability (Phases 0 & 2)

### 6.1 Time navigation (Phase 0)
- **"Now" button** on the chronobar row (gold accent, `SkipForward`-style icon): `onScrub(nowMs)` + disconnect replay. Disabled in historical mode.
- **Clickable detents & barrier ticks:** convert to `<button>`s with aria-labels ("Jump to Fri 07:00 shift change", "Jump to barrier opened 14:32"); click scrubs to that instant.
- **Relabel** the Radio button: title "Stream stored replay", active label "REPLAY".

### 6.2 Spatial context (Phase 0)
Replace the `x y z` readout with **place context**: on camera settle (existing 150 ms throttle), find the nearest unit centroid to `orbit.target` within its floor → `Floor 3 · 3W Med-Surg` (from `placementIndex.unitAnchors` + `flowUnits` names). Raw xyz moves to the title attribute for debugging.

### 6.3 Floor & search (Phase 2)
- **Floor stepper:** compact vertical rail (right edge, `B2 B1 1 2 … PH | All`), current floor highlighted, click or ↑/↓ (when focused) to step; changing floor triggers **fit-to-floor** (bbox of that floor's locations via `focusOn`). The dropdown stays for form-based selection; both drive the same filter.
- **Search fly-to:** Enter in the Find field flies to the bbox of matched states; show `N matches` under the field; Escape clears. No match → shake-free inline "0 matches — check spelling or floor filter".
- **Keyboard map + help:** `H` home, `F` focus selection, `N` now, `?` opens a shortcut sheet (reuses legend panel chrome). Document OrbitControls' built-in arrow-key panning.
- **Saved views:** 3 slots in `localStorage` keyed by persona (`flow4d.views.{role}`): save current camera+floor+layers; render as small chips under the transport buttons.

---

## 7. Design — Virtual Rounds Integration (Phase 3)

Make the navigator a **first-class rounding surface**: see the itinerary, walk it, and jump between board and building in one click. All payloads stay patient-free (opaque `round_patient_uuid` only — plan §8.1 doctrine unchanged).

### 7.1 Bidirectional deep links
- **Navigator accepts `?focus_stop={uuid}`** (extend `parseHandoff()`): after stops load, call `focusRoundStop(uuid)`; if unplaceable (wrong floor/no anchor), clear the floor filter and retry once, else toast "Stop not placeable — open the board".
- **Rounds board → 4D:** "Locate in 4D" icon-button per patient row in `RoundsBoard.tsx` / `RoundPatientWorkspace.tsx` → `/rtdc/patient-flow-navigator?focus_stop={uuid}`.
- **4D ring → board:** the round-stop inspector gains an action link "Open in Rounds board" → `/rtdc/virtual-rounds?patient={uuid}` (board already deep-links patient workspaces; wire the query param if absent).

### 7.2 Rounds HUD + itinerary rendering
- **Rounds HUD chip** (top of toolbar when a run is loaded): run status, `rounded / total`, awaiting-input count, colored by run state (never coral).
- **Queue numbering:** small billboard sprite (`1`, `2`, …) above each ring from `queue_position`; skipped/deferred render dimmed without numbers.
- **Route polyline:** a low-α slate line connecting stops in queue order per floor (cross-floor legs dashed & dropped at the elevator node). This is stable-ordering visualization, **not** path-finding (the rounds plan explicitly defers routing graphs).

### 7.3 Guided tour mode (manual first, Eddy later)
- HUD gains `◀ Prev · Next ▶` plus `Auto` (10 s dwell, pauses on any user camera input — respect the operator's hand).
- `Next` advances by queue order among placeable stops, calls `focusRoundStop`, and updates the inspector with that stop's payload.
- Tour state is client-only; no `tours` table needed for v1. The Eddy-narrated tour (structured evidence per stop, checkpoint persistence) remains Phase 4 / the separate rounds plan.

### 7.4 Freshness
- Poll `GET /api/rounds/runs/{uuid}/scene` every **30 s** while a run is loaded (mirror `hooks.ts:57`), diff by `round_patient_uuid`, rebuild the layer only on change (bucket key already covers `roundStops.length`; extend with a content hash). Reverb channel upgrade deferred to Phase 4.
- Run completion → HUD announces "Run complete", rings fade to rounded/dim, polling stops.

---

## 8. Design — Long-Session Correctness (Phase 0)

- **S-1 nowMs drift:** hold `nowMs` in state, refreshed every 60 s (`setInterval`); chronobar window recomputes `[now−24h, now+24h]` in live-follow only when the user is *at* now (|current−now| < 90 s) so a deliberate scrub position is never yanked. Barrier severity and ghost gating consume the fresh value automatically once it flows from state.
- **S-2 polling:** barriers every 120 s; projections every 5 min; both keep the existing fail-soft catch (empty overlay, never a dead navigator). Add `document.visibilityState` gating so background tabs don't poll.

---

## 9. Implementation Plan & TODO

Each phase is independently shippable and PR-sized. Frontend checks per project canon: `npx tsc --noEmit` **and** `npx vite build` (stricter), `scripts/check-ui-canon.sh`, then `./deploy.sh --frontend`.

### Phase 0 — Truth & quick wins (≈1 day) `feature/flow4d-phase0-truth` — **BUILT 2026-07-18**
- [x] B-1/B-2: rename "Find barriers" → "Delayed only"; move from Layers fieldset to the filter grid as `Census: All | Delayed` segmented control (distinct ids preserved: `flow-census-all` / `flow-census-delayed`)
- [x] B-3: active-filter chip `Filtered: delayed locations only (N)`; metric label "Active" → "Delayed" while filtered
- [x] B-4: remove checkbox auto-fly; add explicit "Focus" button on the filter chip (rendered only while filtered)
- [x] N-1: "Now" button on chronobar (disabled when historical)
- [x] N-2: detents + barrier ticks become focusable jump buttons with aria-labels
- [x] N-3: camera readout → `Floor · Unit` place context (xyz demoted to title attr; wide framing reads `House view` / `Floor N · overview`)
- [x] N-8: "Live" → "Replay stream" labeling (button retitled "Stream stored replay"; chronobar shows `Replay stream ·` while connected)
- [x] S-1: `nowMs` state + 60 s refresh; gated live-follow window slide (skipped while historical, playing, or scrubbed >90 s from now); scene no longer torn down on window changes (onFrame reads refs)
- [x] S-2: poll barriers (120 s) + projections (5 min) with visibility gating
- [x] Tests: chronobar jump buttons + Now button, `barrierSeverity` with moving now, filter-chip render + census scope + replay labeling (new `NavigatorToolbar.test.tsx`)

**Acceptance:** an operator can always return to now in one click; the two barrier controls are visually and verbally distinct concepts; a 6-hour-old wall session shows correct now/severity.

### Phase 1 — Element Identity System (≈2–3 days) `feature/flow4d-element-identity` — **BUILT 2026-07-18**
- [x] E-2: `sceneVocabulary.ts` (labels, shapes, hexes, descriptions) consumed by NavigatorScene materials
- [x] E-2: category material map in `loadModel()` per §5.2 (corridor/patient_room/bed/ED/imaging/elevator/care_unit tints; floor + unknown keep the model material)
- [x] E-3: clamp `hashColor` hue to 160–280° (`patientHue` in sceneVocabulary; scene builds the color)
- [x] E-1: `NavigatorLegend.tsx` collapsible key rendered from `sceneVocabulary` (sections, worded status meanings, hidden-layer dimming); styles in existing CSS file (no new backdrop-blur file)
- [x] E-4: throttled hover raycast + cursor + emissive hover clone + element chip (identity fields never in the chip; scene-owned div, textContent only; disabled during >60× playback)
- [x] E-5: persistent selection highlight + element-type prefix in inspector title; Escape clears
- [x] Tests: sceneVocabulary completeness (layer parity, category coverage, worded statuses), hue-clamp property test, legend render & collapse, elementLabelFor mapping

**Acceptance:** with no training, a user can point at any object, hover it, and read what it is; beds/hallways/rooms/ED are visually distinct; no patient token renders in amber/coral hues.

### Phase 2 — Navigability (≈2 days) `feature/flow4d-navigability` — **BUILT 2026-07-18**
- [x] N-4: floor stepper rail with fit-to-floor, keyboard stepping (↑/↓), synced to filter; dropdown also frames via the same path
- [x] N-5: search Enter → fly-to matches; match count under the field; empty-state copy; Escape clears
- [x] N-6: keyboard shortcuts (H/F/N/?) + shortcut sheet; OrbitControls arrow-panning enabled on the focused canvas
- [x] N-7: 3 persona-keyed saved views (localStorage `flow4d.views.{role}`, Zod-validated), chips under transport buttons (restore + save per slot)
- [x] Tests: floor-rail select/step/All + top-down order, search match-count + Enter/Escape, saved-view round-trip + garbage tolerance, dropdown frame-path

**Acceptance:** floor changes are one click/keystroke and frame the floor; search lands the camera on results; power users never touch the mouse for time/floor/focus.

### Phase 3 — Virtual Rounds (≈2–3 days) `feature/flow4d-rounds-integration` — **BUILT 2026-07-18**
- [x] R-1: `?focus_stop={uuid}` handoff wiring → `focusRoundStop` (retry loop while scene/stops load, floor-clear retry, fallback toast "Stop not placeable — open the Rounds board")
- [x] R-2: "Locate in 4D" per row in RoundsBoard + workspace header; "Open in Rounds board" action in round-stop inspector; board accepts `?patient={uuid}`
- [x] R-5: Rounds HUD chip (run status + scope, rounded/total, awaiting-input; teal active / amber paused, never coral)
- [x] R-4: queue-number sprites (skipped/deferred unnumbered) + per-floor route polyline with dashed cross-floor legs (`buildRoundRoute`, non-raycastable layer)
- [x] R-6a: manual tour Prev/Next/Auto (10 s dwell; OrbitControls 'start' — operator input only — pauses Auto; stops at itinerary end)
- [x] R-3: 30 s scene polling, visibility-gated, content-hash gate so unchanged payloads never rebuild; run-complete → HUD Complete + toast + polling stops
- [x] Tests: cells carry floor, route ordering/dashed legs/skip rule, deep-link parsing, HUD render + tour controls; PHPUnit scene contract guard extended (queue_position/unit_id/bed/pinned pinned)
- [x] Prod flag posture unchanged: overlay stays empty on 404/no-run (fail-soft catch preserved in the polling loop)

**Acceptance:** a rounder can start on the board, jump to the building, walk the itinerary Next-by-Next with live statuses, and jump back — without ever seeing patient identity in the scene layer.

### Phase 4 — Deferred / ambitious (not scheduled)
- Reverb-pushed rounds + barrier updates (replace polling)
- Eddy-narrated guided tour w/ checkpoint persistence (`tours` table, per rounds plan §6)
- Unit-code billboard labels w/ LOD gating; minimap / floor-stack widget
- Mobile collapsible toolbar accordion (S-3)
- Cockpit mount-anywhere embed of a read-only navigator face
- Bed-level clinical-capability lens (ICU-capable / neg-pressure tints from GLB extras) for surge planning

---

## 10. Canon Compliance Guardrails

- **Two-System Rule:** all new tints stay in the blue/slate operational family; gold only for focus (now-marker, focus ring), crimson never in-scene.
- **Earned urgency:** amber/coral remain exclusively status (timer watch/late, barrier ≥24h/≥48h). The E-3 hue clamp *removes* an existing violation.
- **Never color alone:** every status color is paired with shape (diamond scale, ring state, disk radius) and worded meaning in legend, hover chip, and inspector.
- **No new backdrop-blur files:** legend/HUD styles live in `PatientFlowNavigator.css`; no `text-[Npx]` utilities (overlay CSS uses stylesheet font-sizes, ≥11 px matching existing).
- **Scene hex palette** remains a sanctioned data-driven exception, but every hex is now centralized in `sceneVocabulary.ts` and mirrors the `--critical/--warning/--success/--info` vocabulary.
- **Identity redaction:** hover chips and tour HUD reuse `redactSelection`; rounds layer stays opaque-uuid-only.
- **A11y (WCAG 2.2 AA pragmatic):** all new controls are real buttons/inputs with names; chronobar ticks become focusable; the toolbar/inspector remain the non-3D equivalent of scene state.

## 11. Risks

| Risk | Mitigation |
|---|---|
| Hover raycast per pointermove on low-end wall hardware | 50 ms throttle, skip during playback >60×, raycast only visible layers |
| Category materials wash out the status layers | keep base α ≤ 0.85 and emissive ≈ 0; status/barrier/rounds layers keep emissive priority |
| Window slide (S-1) fighting an operator mid-scrub | slide only when parked at now (<90 s delta) |
| Rounds polling load | 30 s interval, visibility-gated, stops when run closes |
| Sweep-style regressions | sequential small PRs on feature branches; no long-lived worktrees (Worktree Agent Protocol) |

## 12. HFE Decisions Register (closure plan H5.2)

One row per doctrine: why the invariant exists, and the *named automated guard* that
breaks in CI if a future change violates it. A new contributor should read this table
before "simplifying" anything that looks redundant — most of the redundancy is the point.

| Doctrine | Rationale | Automated guard |
|---|---|---|
| **Earned urgency** — coral only for a real breach (timer past target, barrier ≥48h); rounds work is never coral | Alarm fatigue is the #1 failure mode of hospital dashboards; a palette that cries wolf trains operators to ignore it | `tests/js/virtualRounds/roundsScene.test.ts` ("coral never appears" in round states); `tests/js/patientFlow/sceneVocabulary.test.ts` (base opacities ≤0.85 keep status priority); `scripts/check-ui-canon.sh` raw-palette ratchet. Field guard: urgency census (`scripts/urgency-census-flow4d.mjs`, coral <10% / amber <25% guidelines) |
| **Never color alone** — every status pairs color with a shape and a worded meaning | ~8% of male operators have CVD; under deuteranopia the ok-green/delayed-coral axis collapses outright | `tests/js/patientFlow/cvdPalette.test.ts` (Machado 2009 simulation proves the collapse is real, and asserts the triangle shape cue + legend wording stay); `sceneVocabulary.test.ts` ("words the status meanings — status is never color alone") |
| **Identity hue clamp** — patient-token identity color lives in 160°–280° (teal→violet) | A token must never impersonate amber/coral status; identity color is arbitrary, status color is a claim | `sceneVocabulary.test.ts` ("stays inside 160°–280° for arbitrary ids", deterministic per id) |
| **Identity-free scene payloads** — hover chips never show identity (stricter than the lens-redacted inspector); rounds payloads are opaque-uuid-only | The wall is a shared surface; a hover on a public display must not disclose who a patient is, and rounds overlays travel to personas whose lens forbids identity | `tests/js/patientFlow/hoverLabel.test.ts` (hoverLabelFor never emits patient_display_id/patient_id/encounter_id for any kind/category); `tests/Feature/Rounds/RoundProjectionTest.php` (projection contract carries no identity fields) |
| **Wrong-toggle separation** — Census All\|Delayed is a *filter* with a visible chip, not a layer toggle; "Find barriers" removed | Mode confusion: a layer toggle that silently filters is how an operator concludes a unit is empty when it isn't (the classic wrong-toggle error) | `tests/js/patientFlow/NavigatorToolbar.test.tsx` ("Census: All \| Delayed as a filter, not a layer", chip shows count, dismissing the chip clears the scope). Behavioral guard: usability protocol tasks T3/T4 (0-error threshold, automatic P1) |
| **Explicit camera actions** — toggling scope/filters never flies the camera; "Focus" is a separate deliberate act | Camera theft breaks the operator's spatial frame; surprise motion on a wall display is disorienting at distance | `NavigatorToolbar.test.tsx` census-scope tests pin the control semantics; the only flight entry points are Focus/`F`/floor-rail framing (code seam: `focusSelection`/`focusOn` callers) |
| **Follow-mode time slide** — the 48h window slides with wall-clock now ONLY when parked at now (<90 s delta); a deliberate scrub, playback sweep, or replay stream is never yanked | Stealing the timeline mid-investigation destroys the operator's working context; live-follow is a mode you *enter*, not a default that interrupts | Code seam (S-1 effect + `followNowRef`); field guard: 24h soak (`scripts/soak-flow4d.mjs`) asserts now-marker drift <90 s via `window.__FLOW4D_SOAK__.nowDeltaMs()` — which only reports in follow mode by design |
| **24px minimum targets** — chronobar detents/ticks and all toolbar controls are real ≥24×24 buttons | WCAG 2.2 AA (2.5.8) plus wall-display reality: operators hit these targets from awkward angles on touch panels | `tests/js/patientFlow/NavigatorChronobar.test.tsx` ("shift detents and barrier ticks are focusable jump buttons"); sizes pinned in `PatientFlowNavigator.css`; `check-ui-canon.sh` floors text at `text-xs` outside the cockpit exception |
| **Selection is one entity** — patient/occupancy/barrier/round-stop selections resolve through a single `SelectionEntity`; every surface (click, search, feed, tour) agrees | Two selection states that can disagree will disagree, and the inspector ends up describing something the scene isn't highlighting | Shared-builder equivalence via `patientTokenInspectorData` (`tests/js/patientFlow/NavigatorFeed.test.tsx`, `NavigatorToolbar.test.tsx` H1.2 selection tests); scene re-apply exercised by Playwright CI on the rendered path |
