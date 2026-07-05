# The 48-Hour Flow Window — 4D for Every Persona

**Status update 2026-07-04 (later) — Phases 3–4 IMPLEMENTED.**
Phase 3 (aggregate lenses, both platforms): shared FlowCurve (48h census
curve: solid past from snapshots, dashed predicted_census + band ribbon,
staffed-capacity line, shift detents); executive 15s auto-replay time-lapse
(Reduce Motion honored) settling into the forecast strip; capacity_lead
curve-first with client-side floor/unit focus; staffing coverage-vs-curve
with per-floor worst-gap tints (never color alone); hospitalist/intensivist
discharge-leverage lane (ranked expected_discharge → A2P when ref present;
iOS census-home map had to be enabled for them, Android already had it);
pi_lead 4h/s replay + clip-to-share (plain-text summary + links.web
?from=&to= — v1, no PDSA write). No backend changes needed.
Phase 4 (web Navigator parity): monolith decomposed (NavigatorScene.ts is
React-free and the only three.js importer — lazy chunk 642 kB, navigator UI
chunk 21.7 kB); trails/heat rebuild now bucketed instead of per-frame;
48h Chronobar (past coverage band, dashed future, detents, graceful sparse
data); projection ghost layer from /api/patient-flow/projections (entity
ghosts at unit/room anchors, aggregate forecast pillars + HUD, provenance
in inspector); persona lens via shared ResolvesFlowLens trait (patient_dots
none/unit/task redaction in scene + inspector; lens-less web users keep the
full house view); ?persona=&scope=&t= handoff. Verified: vite build green,
vitest 180 passed, tsc no new errors, full backend suite 376 passed / 1
pre-existing env failure (EddyKnowledgeRagTest needs pgvector, absent from
the local hb_pg postgres image — fails identically on HEAD).
**Status update 2026-07-04 (final) — Phase 5 IMPLEMENTED. Phases 0–5 all
shipped.** Backend: `?since=` delta on GET /flow/window — events/snapshots
filtered to t > since, projections/spaces/bed_statuses always full,
`window.since` echo, 422 `invalid_since` on out-of-range/malformed;
OpenAPI + 4 delta tests (FlowWindowTest 20 green, MobileBff drift green).
iOS: delta merge + per-user offline FlowCache (LRU 20, staleness caption,
cleared on logout) + Reverb/foreground delta refresh; the widget extension,
Live Activities, and App-Group house-glance widget were already shipped in
commit 9ac1b6a, so Phase 5 EXTENDED them — added SLA countdowns
(Text(timerInterval:) on lock screen + Dynamic Island, calm not coral) and
the enriched glance widget (occupancy %, net bed need, For You count,
next-4h ghost count, updated-at); clean xcodebuild green, 0 warnings,
.appex embedded — no fallback rung taken. Android: same delta merge +
per-user filesDir/flow-cache (atomic, LRU 20) + Glance house widget
(app-driven updateAll, no background networking) + T1–T4 urgency
notification channels registered at start (FCM send still blocked on
server credentials); 17 new pure-logic unit tests + assembleDebug green.
Whole feature verified: backend mobile/flow suites green, both mobile
builds green. REMAINING (tracked, not blocking): persona screenshot matrix
(14 roles × surfaces × 2 platforms), plate-LOD/dot-batching perf pass,
real APNs/FCM push credentials, EDD write-back + service-scope patient
access (§11 open questions), and the pre-existing pgvector env gap in
EddyKnowledgeRagTest (unrelated to this feature).

**Status update 2026-07-04 — Phase 2 IMPLEMENTED.** Frontline task lenses +
periop shipped on both platforms: transport (house/floor trip arcs with
unit-abbr → unit-name → bed-label endpoint resolution, off-map gutter for
free-text destinations, derived-ghost trips with provenance chips), EVS (turn
map: bed-level `bed_statuses` tints at floor/unit scope — now-only, fading on
scrub — plus past turn ticks and dashed `evs_due` bed ghosts with time/ISO
chips), OR (RoomLanes: per-room case bars t→ends_at with cascade drift "+Xm"
chips, milestone ticks, or_nurse room picker, periop floor auto-resolved via
procedure_room plates). Contract additions (additive): projection `room`
field, window `bed_statuses` (floor/unit scope × bed_status-lens gate),
or_milestone `to_space` = real room name; new fixture
`mobile-flow-window-evs.json`; story seeds now cover transport/EVS/OR.
Bug fixed en route: or_milestone read the legacy `prod.orlog` table that no
migration creates — now resolves `prod.orlog` → `prod.or_logs` at query time.
Verified: backend 64 passed (redaction matrix green), Android
`assembleDebug` + unit tests green, iOS `xcodebuild` green + 7 fixtures
decoded. Phases 3–5 remain open.

**Status:** Phases 0–1 IMPLEMENTED (2026-07-03) — data plane + contract + lens
RBAC shipped and verified (`FlowWindowTest`, `PatientFlowApiTest`,
`MobileBffTest` green; live-verified against the seeded local `hb_pg` demo);
iOS `Features/Flow/` + Android `ui/flow/` shipped with the bed_manager /
house_supervisor and charge_nurse lenses (List ⇄ Map segment; both apps
build). Phases 2–5 (remaining lenses, web Navigator parity, stickiness)
remain open. Deviations from the letter of this plan: (a) Android P1.4
precondition satisfied via the reconciliation plan's sanctioned interim path
(manually maintained DTOs + shared-fixture drift tests), not codegen;
(b) ops.operational_events is not merged into the timeline — every ledger
entry mirrors a domain-table transition already normalized, and
double-sourcing would render each mobile write twice (documented in
`OperationalTimelineService`); (c) tomorrow's predictions are BOTH seeded
(CommandCenterDemoSeeder) and extrapolated as a `possible`-confidence
fallback in `ForwardProjectionService` (open question 5 resolved as
"do both").
**Date:** 2026-07-03
**Depends on:** `ALTITUDE-PERSONA-OPERATING-PLAN.md` (A0–A3/A2P ladder), `ADR-2026-07-01-altitude-patient-lens.md`, `PLATFORM-RECONCILIATION-TODO.md` (P1.4, P5.x), `PERSONA-SCREENS-PLAN.md`
**Supersedes nothing** — this extends the shipped persona baseline with a shared spatiotemporal layer.

---

## 1. Vision

Every Hummingbird persona gets the same underlying instrument — the hospital as a
**place** (floors → units → rooms → beds → patients) crossed with **time**
(24 hours of review behind, 24 hours of prediction ahead) — seen through a
**role-specific lens** that controls scope, layers, patient depth, and actions.

The web Patient Flow 4D Navigator stays the deep A3 surface (full 3D digital
twin). Mobile gets a native, persona-scoped projection of the same data: a
**Floor View** (2.5D floor plates with live room/bed/patient state) driven by a
**Chronobar** (a 48h scrubber centered on now). The past half replays what
actually happened; the future half renders honest projections with confidence
and provenance.

Design gut-checks (from PRODUCT.md / DESIGN.md):
- **Defensible:** every projected item cites its source service and the
  reconciliation-derived reliability score. No unlabeled speculation.
- **Earned urgency:** the future is rendered as ghosts (dashed, translucent),
  never as alarm color. Coral only for actual breaches in the past/present.
- **Altitude is architecture, not copy:** the map is not a fifth altitude. It is
  A1/A2 projected into space and time. No new user-facing vocabulary tier.

---

## 2. What exists today (grounding)

### 2.1 The 4D Navigator (web)
- `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx` (720-line
  monolith) + `resources/js/features/patientFlowNavigator/{types,api,stateProjection}.ts`.
  Raw three.js (not R3F): GLB building (`public/vendor/zephyrus-facility-models/zep-500/hospital_model.glb`,
  771 KB), patient sphere tokens, trail polylines, census heat cylinders,
  scrubber + playback (15min/s–12h/s), SSE "live" that is actually a paced replay.
- Data universe: `flow_core.flow_events` — a **fixed synthetic HL7 window**
  (2026-06-25 → 2026-06-28, not rebased to wall clock), imported by
  `patient-flow:import-synthetic`. **It never reads live `prod.*` state.**
- Point-in-time reconstruction exists both client-side (`patientStatesAt`) and
  server-side (`app/Services/PatientFlow/PatientStateProjector.php` with `asOf`).
- **Gaps:** no forecast layer of any kind; no RBAC on the API or the patient
  inspector; unused endpoints (`/state`, `/tracks`, `/fhir/bundle`) and unused
  tables (`flow_core.occupancy_snapshots`, `flow_realtime.*` cursors); trails/heat
  rebuilt every animation frame; three.js not code-split; zero mobile presence.

### 2.2 Spatial model
- **Operational spine** (`prod.units` → `prod.beds` → `prod.encounters`): no
  floors, no inpatient rooms. Floor lives in the hospital manifest
  (`config/hospital/hospital-1.php`, `HospitalManifest`) — every unit has
  `floor`, `cad_code`, `service_line`, `acuity`.
- **CAD/facility model** (`hosp_space.facility_spaces`): explicit
  campus/building/floor/zone/unit/room/bay/bed hierarchy with `floor_number`,
  positions (ft/m) and geometry jsonb — bridged to the operational spine via
  `hosp_space.operational_space_maps` and `facility_space_id` columns on
  `prod.units`/`prod.beds`. Populated by `facility:import-catalog` (1,472 spaces,
  500 beds). **A bed's floor and room polygon are already derivable.**
- **Periop rooms** (`prod.locations` → `prod.rooms`): OR/pre-op/PACU only.

### 2.3 Temporal spine (the past 24h)
- `prod.operational_events` (EncounterStarted/Transferred/Discharged,
  BedStatusChanged, AcuityChanged) with a **pure, replayable projector**
  (`app/Rtdc/CensusProjector.php`, `CensusRebuilder.php`).
- Per-domain event history: `prod.transport_events`, `prod.evs_events`,
  `prod.staffing_events`; mobile ledger `ops.operational_events` (+ targets,
  entities, acks) via `OperationalActivityLedger`.
- `prod.census_snapshots` exists but demo-seeds only one sentinel row/unit —
  **no hourly series today**. `flow_core.occupancy_snapshots` exists, unused.

### 2.4 Prediction inventory (the future 24h — all deterministic, no ML)
| Source | Horizon | Granularity | File |
|---|---|---|---|
| `prod.rtdc_predictions` (+ plans) | today `by_2pm` / `by_midnight` | per unit: weighted discharges (definite/probable/possible), demand by source, bed_need | `2026_06_20_000040` migration; `Api/Rtdc/PredictionController` |
| Reconciliation loop | daily backward | reliability_score per unit (predicted vs actual) | `app/Jobs/ReconcileRtdcPredictions.php`, `prod.rtdc_reconciliations` |
| ED arrival forecast | **next 24h, hourly**, with confidence band | house/ED | `app/Services/Ed/ArrivalPredictionService.php` |
| Command Center forecast band + occupancy curve | 24h/48h; curve at 2h steps ±3pp | house | `app/Services/CommandCenterDataService.php` (`computeForecastMetrics`, `forecastDetail`) |
| Demand forecast (census walk) | intraday | per unit + house | `app/Services/Rtdc/DemandForecastService.php` |
| ED acuity mix | next 4h | ED | `app/Services/Ed/AcuityPredictionService.php` |
| Discharge/readmit risk composite | per active encounter | patient | `app/Services/Rtdc/RiskAssessmentService.php` |
| OR schedule + room status clock | rest of day | room/case | `Operations\RoomStatusService` |
| Deadlines | hours | transport `needed_at`, EVS `needed_at`, staffing `needed_by` | domain tables |

**Only `net_bed_need` (by-2pm sum) reaches mobile today.**

### 2.5 Persona gating (already solved — reuse, don't reinvent)
`app/Services/Mobile/MobilePatientContextService.php`: opaque `ptok_` HMAC
context refs everywhere; role matrix — bed_manager/house_supervisor/capacity_lead
see any operational patient; transport/EVS only task-linked patients; nurses/
hospitalist/intensivist only shared-unit patients (`prod.user_unit`); executive,
pi_lead, staffing, OR roles get **403** on patient detail. This matrix becomes
the **patient-dot policy** for every map payload.

---

## 3. Gap analysis — what stands between today and the vision

| # | Gap | Bridge |
|---|---|---|
| G1 | 4D data universe (`flow_core` fixture) is disconnected from live `prod.*` and not rebased to wall clock | Build the mobile Flow Window on **`prod.*` events/projections**; keep `flow_core` for the web twin and add a rebase/bridge (§6.5) |
| G2 | No floor dimension on the operational spine | `FloorRollupService` joining manifest floors + `facility_spaces` plates + unit census (§6.1) |
| G3 | No census time series (snapshots are sentinel-only) | Hourly snapshot job + 24h backfill via `CensusRebuilder` (§6.2) |
| G4 | No unified event timeline (5 separate event tables) | `OperationalTimelineService` normalizing all sources into one event shape (§6.2) |
| G5 | No forward event stream — predictions are scattered aggregates | `ForwardProjectionService` synthesizing a +24h projection stream with confidence + provenance (§6.3) |
| G6 | No mobile spatial rendering; web viewer is desktop three.js | Native 2.5D Floor Plates (SwiftUI Canvas / Compose Canvas) + simplified geometry endpoint (§7.2) |
| G7 | 4D API has no RBAC; inspector leaks patient detail to any authed user | Lens config + persona middleware on both web and mobile flow APIs (§6.4) |
| G8 | Android still hand-parses JSON (`ApiClient.kt`, 1,027 lines org.json) | Land reconciliation **P1.4 (generated DTOs) before** adding these payloads |

---

## 4. Architecture decisions

- **D1 — One window API, persona-lensed.** A single
  `GET /api/mobile/v1/flow/window` serves snapshots + events + projections +
  spaces for a scope (house / floor / unit / patient), clipped to a ≤48h window.
  The lens (role) decides which layers, kinds, and patient depth come back.
  Server-side filtering from day one (this is the spatial sibling of
  reconciliation P5.2 — don't repeat the client-side-filtering mistake).
- **D2 — Past = checkpoint + replay.** Hourly census/occupancy snapshots as
  checkpoints; seek to time *t* = nearest snapshot ≤ *t* + replay of remaining
  normalized events. Same event-sourcing pattern `PatientStateProjector`
  already proves; now applied to `prod.*`.
- **D3 — Future = projection events, not a second UI.** The +24h half of the
  timeline is a synthesized stream of typed projection items (expected
  discharge, predicted arrivals, predicted census step, scheduled OR case,
  transport/EVS due, staffing shift gap) each carrying
  `confidence ∈ {definite, probable, possible}`, a numeric band where
  applicable, and `provenance {service, reliability}`. The renderer treats them
  as ghosts. **Never fabricate a future patient identity** — only known
  entities (scheduled OR cases, pending placements with a target, EDD-flagged
  encounters) may appear as per-bed ghosts; everything else is aggregate heat.
- **D4 — Mobile renders plates, not the GLB.** v1 mobile is 2.5D: per-floor
  2D plates (simplified room/bed polygons precomputed from
  `facility_spaces.geometry`) drawn in SwiftUI `Canvas` / Compose `Canvas`,
  with an exploded-axonometric stacked-floor mode for house scope. No 3D
  engine, no 771 KB GLB, battery-sane, theme-native. The full 3D twin remains
  a web A3 deep link (`links.web`), later optionally embedded via WKWebView
  with a `?persona=&t=` handshake.
- **D5 — The Chronobar is one shared component per platform.** 48h scrubber:
  solid past, `now` marker, dashed future; detents at shift boundaries
  (07:00/19:00) with haptics; play/pause replay for the past half; pinch to
  zoom window. Every persona surface embeds the same control.
- **D6 — Web and mobile share the contract, not the renderer.** The window
  payload shape is defined once in the OpenAPI contract + shared fixtures
  (extending `docs/hummingbird/api-contract/`), decoded by generated DTOs on
  Android (post-P1.4) and hand-DTOs on iOS. The web Navigator adopts the same
  projections endpoint so ghosts render identically in 3D.

---

## 5. The 48-hour time model

```
past (review)                     now                    future (prediction)
├────────────────────────────────┤▲├────────────────────────────────────┤
T−24h                             ││                                 T+24h
  hourly checkpoints (snapshots)  ││   projection events (typed, confident)
  + normalized event replay       ││   + occupancy curve (2h steps, band)
  solid rendering                 ││   ghost rendering (dashed/translucent)
                          shift detents (07:00 / 19:00)
```

- **Window:** `[now−24h, now+24h]`, server-capped at a 48h span. Default
  center = now; personas may default the *view* elsewhere (e.g. OR manager
  centers on today's schedule) but the data window is constant.
- **Seek semantics (past):** state at *t* = snapshot(≤*t*) ⊕ replay(events in
  (snapshot, *t*]). Bed-level state replays `BedStatusChanged` + encounter
  moves; unit rollups come straight from checkpoints for cheap scrubbing.
- **Future semantics:** projections are bucketed (hourly for counts, 2h for
  census curve) or point-events (scheduled case at 14:30, EDD discharge
  "probable by 15:00"). Scrubbing forward accumulates ghosts up to *t* the
  same way the past accumulates events — symmetric interaction grammar.
- **Confidence rendering:** `definite` ghost at 0.8 opacity, `probable` 0.5,
  `possible` 0.3; bands as soft ribbons on curves. Colors stay in the
  operational palette; urgency tier only for *current* breaches.
- **Provenance:** tapping any projection shows "Source: Demand forecast ·
  unit reliability 0.86 (14-day)" — reliability from `prod.rtdc_reconciliations`.
- **Live edge:** Reverb `hospital.beds` events (and existing re-snapshot
  contract) advance `now`; window refreshes shift the frame, they don't reset
  the user's scrub position.

---

## 6. Data plane (backend workstreams)

### 6.1 W1 — Spatial join hardening (S)
- `app/Services/Flow/FloorRollupService.php`: floors from `HospitalManifest`
  (+ `facility_spaces.floor_number` as cross-check), each floor → units →
  census/staffing/EVS rollups.
- `php artisan facility:export-plates`: precompute **simplified 2D plate
  polygons** per floor (rooms, bays, beds, corridors, elevators) from
  `hosp_space` geometry into a versioned JSON asset served at
  `GET /api/mobile/v1/flow/floors` (cacheable, ETag). Target < 60 KB gzipped
  per floor. Include `unit_id`/`bed_id` bridges so plates join to live state.
- Backfill any `prod.beds.facility_space_id` gaps; add a data-plausibility
  check to `DATA-PLAUSIBILITY.md` gates (every staffed bed must map to a space).

### 6.2 W2 — Temporal spine: review half (M)
- `app/Services/Flow/TimelineSnapshotService.php` + scheduled command
  `flow:snapshot` (hourly): write per-unit rows to `prod.census_snapshots`
  and per-space occupancy to `flow_core.occupancy_snapshots` (finally used).
  Include `--backfill=24h` using `CensusRebuilder` replay so the feature works
  the moment it ships (and against freshly seeded demo DBs).
- `app/Services/Flow/OperationalTimelineService.php`: merge
  `prod.operational_events`, `transport_events`, `evs_events`,
  `staffing_events`, `ops.operational_events`, ED visit milestones, and
  barrier open/resolve into one normalized shape:
  `{t, kind, entity{type, ref|ptok}, from_space, to_space, unit_id, label, tier, provenance}`.
  Kinds: `admit, transfer, discharge, bed_status, ed_arrival, ed_admit_decision,
  bed_request, placement, transport_status, evs_status, barrier_opened,
  barrier_resolved, staffing_fill, or_milestone`.
- Patient refs are **always** re-tokenized through `MobilePatientContextService`
  before serialization (same rule the ledger already follows).

### 6.3 W3 — Temporal spine: prediction half (M)
- `app/Services/Flow/ForwardProjectionService.php` composing the existing
  services into one +24h stream, computed on demand with a 5-min cache
  (skip materialization until profiling says otherwise):
  - `expected_discharge` per encounter — `prod.encounters.expected_discharge_date`
    × `rtdc_predictions` definite/probable/possible vocabulary × time-of-day
    bell (discharge trough logic already in `CommandCenterDataService`).
  - `predicted_census` per unit at 2h steps — extend `DemandForecastService`
    beyond by_2pm/by_midnight to a walked curve; house curve already exists.
  - `predicted_arrivals` hourly — `ArrivalPredictionService` (band included);
    admission split via existing admit-rate; feeds ED + downstream unit demand.
  - `scheduled_or_case` — OR schedule with room, expected start/end, PACU
    handoff estimate (`RoomStatusService` clock).
  - `transport_due` / `evs_due` / `staffing_shift_gap` — deadline projections
    from `needed_at`/`needed_by`; plus **derived ghosts**: each probable
    discharge spawns a "likely discharge transport ~t" and a "bed turn ~t+20m"
    ghost (this is what makes the future half genuinely useful to P1/P2).
  - `surge_probability` — house-level, from the Command Center heuristic.
- Every item: `{t, kind, confidence, space|unit_id, value?, band?, entity?,
  provenance{service, reliability}}`.

### 6.4 W4 — Lens, RBAC, and the window endpoint (M)
- `config/hummingbird/flow_lens.php` — per role id (all 14):
  `scope_default, scopes_allowed, event_kinds, projection_kinds,
  patient_dots ∈ {full, unit, task, none}, actions[]`. `patient_dots` maps 1:1
  onto the `MobilePatientContextService` matrix (exec/PI/staffing/OR ⇒ `none`
  — they get aggregate heat only).
- `GET /api/mobile/v1/flow/window?scope=house|floor:{n}|unit:{id}|patient:{ptok}
  &from&to&layers=snapshots,events,projections` → envelope with
  `{window{from,to,now}, spaces?, snapshots[], events[], projections[]}`.
  Server clamps scope + layers + kinds to the caller's lens; unauthorized
  scope ⇒ 403 with the explicit unauthorized state (reconciliation P4.2's
  pattern). Add to OpenAPI + shared fixtures + drift test.
- **Web parity & the RBAC fix:** add the same lens middleware to
  `/api/patient-flow/*` (closing the "any authed user sees patient inspector"
  hole), add `?from&to` windowing to `/events`, and a new
  `/api/patient-flow/projections` reusing `ForwardProjectionService`.

### 6.5 W5 — Demo-data honesty (S)
- `patient-flow:rebase-synthetic --anchor=now`: shift the `flow_core` fixture
  window to end at wall-clock now, so the web twin and the mobile window agree
  during demos.
- Extend `rtdc:simulate` to emit a *plausible trailing 24h* of
  `prod.operational_events` on demand (it already does 24 hourly ticks from
  06:00) and verify `DemoTuningSeeder` ordering keeps boarding/SLA tuning intact.
- Seed `rtdc_predictions` for **tomorrow** as well as today (the +24h window
  crosses midnight; currently only today is seeded).

---

## 7. Experience plane

### 7.1 Shared components (both platforms)
- **Chronobar** — the 48h scrubber (D5). One implementation per platform in
  the design system (`DesignSystem/` / `ui/components/`), used by every lens.
- **FloorPlateView** — renders one floor's plates + state: room/bed fills by
  status (occupied/available/blocked/dirty via existing status vocabulary),
  patient dots per lens policy, ghost overlays for projections, tap targets ≥
  44 pt with an overflow list for dense zones. SwiftUI `Canvas` / Compose
  `Canvas`; target ≤ 500 drawn shapes per floor (plates are pre-simplified).
- **HouseStackView** — exploded axonometric stack of floor plates (house
  scope), heat-only; tapping a floor descends into FloorPlateView.
- **TimelineLanes** — persona-filtered horizontal lanes under the map
  (e.g. discharges / admits / barriers for a nurse; trips for transport),
  aligned to the Chronobar. Rows are the same For-You/drill row components.
- **Ghost grammar** — dashed 1.5 pt outlines, confidence-mapped opacity,
  provenance chip on tap. Never a solid fill, never coral.

Navigation: the map is a **presentation mode of A1**, entered via a "Map"
segment on the workspace (list ⇄ map), and a **spatial context block inside
A2/A2P** ("where is this, what's around it"). Deep links:
`hummingbird://flow?scope=unit:MICU&t=-4h`.

### 7.2 Mobile integration points
- iOS: new `Features/Flow/` (FlowWindowStore, ChronobarView, FloorPlateView,
  HouseStackView, TimelineLanes, GhostLayer). Android: `ui/flow/` package +
  `FlowViewModel`, models via generated DTOs (**after P1.4**).
- Realtime: existing `RealtimeClient` re-snapshot contract — on
  `hospital.beds` event, refetch the window head (delta param
  `?since=` to avoid full 48h reloads).
- Offline: cache last window per scope (ties into reconciliation P6.3
  cache-last-read); scrubbing the past works fully offline once cached.

### 7.3 Web 4D Navigator upgrade (parity, not port)
- Do the long-planned decomposition of `PatientFlowNavigator.tsx`
  (Scene/Toolbar/Inspector/Feed/Chronobar), fix per-frame trail/heat rebuild,
  code-split three.js.
- Add the **projection ghost layer** (translucent tokens at target beds for
  scheduled/known entities, dashed predicted trails, forecast heat pillars)
  fed by `/api/patient-flow/projections`.
- Replace the fixture-bounded scrubber with the Chronobar model
  (now−24h → now+24h) once W5 rebasing lands.
- Persona lens prop: page reads the user's role → same lens config → layer
  defaults + inspector redaction (closes G7 on web).
- Accept `?persona=&scope=&t=` for the mobile→web A3 handoff.

---

## 8. Per-persona lens specifications

Format — **Scope** (spatial), **Frame** (default time focus), **Review −24h**
(what the past half shows), **Predict +24h** (what the future half shows),
**Depth** (patient-dot policy), **Actions** (from the map), **Signature moment**.

### P1 · Transporter (`transport`) — "My day in the building"
- **Scope:** house corridors + origin/destination spaces of own jobs (plates
  include `corridor`/`vertical_transport` categories — routes render).
- **Frame:** now, ±4h zoom default.
- **Review:** own completed trips replayed as routes; per-trip delay vs SLA;
  floors-walked summary.
- **Predict:** queued/scheduled trips plotted at `needed_at`; **derived ghost
  trips** from probable discharges ("~3 likely discharge runs from floor 4
  between 14:00–16:00"); elevator/route congestion hints from overlapping ghosts.
- **Depth:** `task` — dots only for patients on own active jobs.
- **Actions:** claim next job from the map; open A2P for active job's patient.
- **Signature:** end-of-shift replay card — "11 trips, 4 floors, 2 SLA saves."

### P2 · EVS Technician (`evs`) — "The turn map"
- **Scope:** house, colored by bed status (dirty/cleaning/clean); floor descent.
- **Frame:** now.
- **Review:** completed turns; turnaround-time heat per floor; isolation turns flagged.
- **Predict:** ghost turns from projected discharges (bed, ~time, turn type),
  letting techs pre-position; due-time countdowns on assigned turns.
- **Depth:** `task` — isolation context only, via active request.
- **Actions:** claim/start/complete from bed tap (existing EVS transitions).
- **Signature:** "Floor 4 will need ~5 turns between 14:00–17:00 — 3 are yours."

### P3 · Charge / Bedside Nurse (`charge_nurse`, `bedside_nurse`) — "My unit, my shift"
- **Scope:** own unit's floor plate, room/bed grid (this **is** the pending
  bespoke unit board from PERSONA-SCREENS-PLAN, now spatial). Charge sees unit;
  bedside defaults to assigned-room subset when assignment data exists.
- **Frame:** shift-aware — opens at last shift boundary (07:00/19:00 detent).
- **Review:** the overnight story — admissions/transfers/discharges into and
  out of the unit, barrier open/resolve history, acuity changes, EVS/transport
  events touching unit beds. **This is the "start-of-shift moment"
  (DESIGN-ELEVATION Wave 3) rendered spatially** — a 20-second replay of the
  unit since last shift change.
- **Predict:** expected discharges per bed (definite/probable/possible ghosts
  with EDD timing), inbound placements targeting the unit (arcs from ED),
  predicted unit census walk vs staffed beds, staffing gap steps at 19:00.
- **Depth:** `unit` — full A2P for shared-unit patients (existing rule).
- **Actions:** resolve barrier, acknowledge inbound, open A2P from bed tap.
- **Signature:** shift-start replay + "3 probable discharges before 15:00;
  2 ED boarders headed your way."

### Hospitalist / Intensivist (`hospitalist`, `intensivist`) — "My patients across the house"
- **Scope:** service-line / critical-care filtered beds across all floors
  (multi-floor dot map, HouseStack with only their beds lit).
- **Frame:** now.
- **Review:** their patients' moves, acuity changes, barrier history.
- **Predict:** discharge-readiness lane ranked by `RiskAssessmentService`
  composite; EDD timeline per patient; ICU downgrade ghosts (intensivist)
  showing target step-down beds.
- **Depth:** `unit` (shared-unit rule; service-scope expansion is a P5.4-class
  backend change — flagged in §11 open questions).
- **Actions:** open A2P; flag/confirm EDD (write — new endpoint, Phase 3+).
- **Signature:** "Rounding order by discharge leverage" — patients sorted by
  what their discharge unblocks downstream.

### P5 · Bed Manager / House Supervisor (`bed_manager`, `house_supervisor`) — "The whole board, in time"
- **Scope:** house — the fullest lens; HouseStack default, floor/unit descent.
- **Frame:** now, full ±24h visible.
- **Review:** placement decisions replay (chosen bed vs runner-up), boarding
  accumulation curve, where demand actually landed vs predicted (yesterday's
  reconciliation, spatially).
- **Predict:** the headline layer — per-unit predicted census heat at 2h steps,
  pending placements as **arcs from ED to top-3 recommended beds**
  (`BedPlacementService` scores rendered spatially with safety chips),
  predicted ED admits flowing in hourly, surge probability ribbon.
- **Depth:** `full` — any operational patient.
- **Actions:** place/reject directly from a recommended-bed tap (existing
  decision endpoint); open A2P anywhere.
- **Signature:** scrub to T+6h → "MICU saturates ~18:00 unless 2 step-down
  transfers happen — here are the two" (ghost transfers with provenance).

### P4 · OR Circulating Nurse (`or_nurse`) — "My room's day"
- **Scope:** periop floor plate (ORs, pre-op, PACU); own room highlighted.
- **Frame:** today's schedule (frame shifts to schedule span, window unchanged).
- **Review:** completed cases + milestones + turnover durations for own room.
- **Predict:** remaining cases as a room lane (expected start/end from
  `RoomStatusService`), delay cascade rendering (case B slides when case A
  runs long), PACU bed pressure ghost.
- **Depth:** `none` today (OR roles are 403 on A2P). Case-context drill (P4.1)
  still applies; patient-lens expansion for OR is P5.4.
- **Actions:** none in v1 (case-status writes are a deferred backend).
- **Signature:** the delay cascade — "Case 3 now projected 15:40, PACU tight
  at 16:00."

### P7 · Periop Manager (`periop_manager`) — "All rooms, the cascade"
- Same surface as P4 across **all** rooms: review = utilization/turnover heat
  by room; predict = full-schedule Gantt against the Chronobar + PACU-to-floor
  handoff ghosts feeding the house prediction layer (their cases become
  bed-demand ghosts downstream — visible continuity into P5's world).
- **Depth:** `none`. **Actions:** none v1. **Signature:** "Turnover drag in
  OR-4 is about to cost the 13:00 slot."

### P6 · Capacity Lead (`capacity_lead`) — "Strain over time"
- **Scope:** house; primary visual is the **48h strain/occupancy curve** with
  the map as supporting heat (curve-first, map-second — inverse of P5).
- **Review:** strain trajectory with cause markers (boarding spikes, closures);
  approval decisions plotted at decision time with observed impact after.
- **Predict:** occupancy curve ±band per floor/unit, surge probability,
  pending approvals plotted at *projected impact time*; (stretch) overlay a
  `OperationsSimulationService` scenario as a second ghost curve.
- **Depth:** `full` (existing rule). **Actions:** approve/reject from timeline.
- **Signature:** "This approval moves the 18:00 peak down 2.1 beds — reliability 0.83."

### P9 · Executive (`executive`) — "Is the hospital OK — and will it be?"
- **Scope:** house only. **No patient dots, ever** (matrix says 403; the lens
  enforces `none` so the payload never contains ptoks).
- **Review:** a **15-second auto-playing time-lapse** of the last 24h — floor
  heat breathing, strain gauge moving, the "one thing" breach localized
  ("4 West is why"). This is the morning brief, cinematic but composed.
- **Predict:** forecast band (predicted discharges/arrivals/surge) — finally
  exposing `computeForecastMetrics` to mobile — as a ribbon ahead of the
  strain gauge; tomorrow-morning projected posture.
- **Depth:** `none`. **Actions:** none — glance + relay to ops leaders.
- **Signature:** open app → the house breathes through yesterday in 15s →
  settles on now → shows tomorrow's band. Done in 30 seconds.

### P10 · Staffing Coordinator (`staffing_coordinator`) — "Coverage vs the curve"
- **Scope:** house by floor, colored by staffing gap (scheduled vs minimum-safe).
- **Review:** fills/callouts replay; where gaps opened vs where census actually went.
- **Predict:** the money view — **predicted census per unit overlaid on
  scheduled staffing steps at shift boundaries**: "TEL7A goes below-safe at
  19:00 if the 4 probable admits land." Open requests plotted at `needed_by`.
- **Depth:** `none` (patient detail only when it blocks movement — via drill,
  not map dots). **Actions:** fill request from unit tap.
- **Signature:** the 19:00 detent — one glance shows tonight's exposure.

### P8 · PI / Quality Lead (`pi_lead`) — "The pattern, not the patient"
- **Scope:** house, fully de-identified (aggregate heat only, no dots, no ptoks).
- **Review:** process replay at 4h/s — boarding accumulation, EVS turnaround
  heat, transport delay hotspots; **"clip" a time window** (e.g. yesterday
  14:00–18:00) as evidence attached to a PDSA cycle or opportunity.
- **Predict:** recurring-pattern callouts ("boarding builds every day 15:00–19:00
  — 12 of last 14 days") from snapshot history once it accumulates.
- **Depth:** `none`. **Actions:** clip-to-PDSA (needs the deferred
  `pdsa/{id}/advance`-family write; v1 = clip to note/web link).
- **Signature:** a flow bottleneck replayed as a 10-second loop, attached to
  an improvement opportunity.

---

## 9. Phasing

**Phase 0 — Data plane + contract (backend, ~1 wave)**
W1 spatial (plates export, floor rollup) · W2 snapshots + timeline service +
backfill · W3 projection service · W4 lens config + window endpoint + RBAC on
web flow API · W5 demo rebase/seeding · OpenAPI + fixtures + drift tests +
redaction matrix tests (14 roles × window). **Precondition: land P1.4
(Android generated DTOs) for the new payloads.**

**Phase 1 — Shared surfaces + the two highest-value lenses (both platforms)**
Chronobar, FloorPlateView, HouseStackView, TimelineLanes, ghost grammar ·
wire into **bed_manager/house_supervisor** (house lens, placement arcs) and
**charge_nurse** (unit board + start-of-shift replay — pays down the pending
bespoke unit board AND the Wave-3 start-of-shift moment in one build).

**Phase 2 — Frontline task lenses + periop**
transport (routes + ghost trips), EVS (turn map + ghost turns), OR nurse /
periop manager (room lanes + cascade). Derived-ghost logic (discharge →
transport → EVS chains) hardens here.

**Phase 3 — Aggregate lenses**
executive (time-lapse brief + forecast band on mobile), capacity lead
(curve-first + approval impact), staffing (coverage vs curve),
hospitalist/intensivist (discharge-leverage lane), PI (replay + clip v1).

**Phase 4 — Web Navigator parity**
Decomposition + perf fixes + code-split · projection ghost layer · Chronobar
model · persona lens + inspector redaction · mobile→web `?persona=&scope=&t=`
handoff.

**Phase 5 — Stickiness + hardening**
Live Activities fed by window data (trip/turn ghosts → lock screen) · widgets
showing "next 4h" slice · delta refresh (`?since=`) · offline window cache ·
persona screenshot matrix rows for every lens (14 roles × map/chronobar states)
· performance passes (plate LOD, dot batching).

Each phase ends demo-able against the seeded Summit demo DB (local `hb_pg` —
never `migrate:fresh` the shared remote).

---

## 10. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Payload size (48h events + geometry) | Plates as cached versioned asset; per-scope windowing; `?since=` deltas; server caps (the web API's 20k-event cap stays) |
| Mobile render cost | 2D Canvas, pre-simplified polygons, ≤500 shapes/floor, dots batched; no 3D engine in v1 |
| PHI leakage via a new surface | Lens is server-side; ptok-only refs; `patient_dots: none` roles get payloads with **no** patient entities at all; redaction matrix tests are a Phase-0 gate |
| Prediction over-trust | Confidence + provenance + reliability mandatory on every ghost; ghost grammar never uses status colors; copy says "probable/possible", never "will" |
| Fabricated-feeling demo | W5 rebase + tomorrow-seeding + backfill make the window real against seeded data; extend `DATA-PLAUSIBILITY.md` checks |
| Android JSON fragility | Hard dependency on P1.4 before Phase 1 Android work |
| Scope creep into an EHR | The lens shows *operational* state only — same A2P boundary; no clinical data enters the window payload |
| Timeline UX complexity for frontline | Frontline lenses default to ±4h zoom with detents; the 48h span is capability, not default posture |

---

## 11. Open questions

1. **Service-scope patient access** for hospitalist/intensivist (matrix is
   unit-based today; service-line scoping is a P5.4-class authorization
   change) — needed for the "my patients across the house" lens depth.
2. **EDD write-back** (confirm/adjust expected discharge from mobile) — new
   write endpoint + ledger event; Phase 3 candidate, needs product sign-off.
3. **WKWebView 3D embed** — worth it after Phase 4, or does the axonometric
   stack cover the "wow" need? Decide on real usage.
4. **Snapshot retention** — hourly × units is tiny, but define retention
   (90 days?) before PI trend features rely on it.
5. **Tomorrow's `rtdc_predictions`** — seed-only fix, or teach
   `DemandForecastService` to extrapolate past midnight from the arrival
   forecast? (Recommend the latter; seeding alone won't work in production.)

---

## 12. Success criteria

- Every one of the 14 role ids opens a Map mode and sees a *different*,
  role-correct view (verified by the screenshot matrix + redaction tests).
- Charge nurse start-of-shift replay renders in < 3s on device from cold cache.
- A placement decision can be made entirely from the bed manager's map.
- Zero raw patient refs in any window payload (automated contract test).
- Every projected item on every surface carries tappable provenance.
- Web Navigator and mobile window show the same state for the same `t` during
  a demo (rebase verified).
