# Patient Flow 4D Navigator — Adversarial Review & Patient-Journey / Conformance Advancement Plan

**Date:** 2026-07-26
**Surface:** `/rtdc/patient-flow-navigator` (Pages/RTDC/PatientFlowNavigator.tsx → Components/PatientFlowNavigator/*, NavigatorScene.ts) + the Arena/OCEL data plane it has never touched
**Scope:** (1) navigability second wave, (2) understanding an *individual patient's* care pathway, movements, and history, (3) OCEL-based clinical adherence to evidence-based norms surfaced in the navigator, (4) inherited engineering debt (epoch atomicity, context loss, rendered-scene test).
**Method:** adversarial code review (frontend + backend data plane, every claim spot-verified against source), reconciliation against the four predecessor documents, and an external literature sweep (54 sourced practices across 8 topics — §6 and Appendix A).
**Predecessors (this plan builds on, and absorbs the open queue of):**
- [FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md](./FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md) — Phases 0–3 shipped; §12 HFE Decisions Register governs
- [FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md](./FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md) — H1/H2/H5 done; H3 sessions + H4 runs user-gated
- [2026-07-19-flow4d-codex-hfe-audit.md](../audits/2026-07-19-flow4d-codex-hfe-audit.md) — F-1…F-12 dispositions; queued follow-ups F-6(pt 2)/F-11/F-12 are absorbed here as DI-1/PX-2/OB-2
- [ZEPHYRUS-2.0-PART-X.md](../product/ZEPHYRUS-2.0-PART-X.md) — OCEL/Arena spec; §X.7 online conformance remains unbuilt
- `docs/superpowers/plans/2026-07-21-drg-care-pathways-…-integration-plan.md` — governed DRG catalog; **all clinical serving deferred on clinical authority**

Owners: **[C]** = Claude-executable, **[SU]** = requires Dr. Udoshi (ruling / clinical authority / hardware).

---

## 1. Executive Summary

The advancement + HFE-closure programs solved the navigator's first-generation problems: element legibility (vocabulary/legend/hover), wrong-toggle truth, time-navigation basics, rounds integration, lens/persona unification, and a defensible urgency palette. What they deliberately did not attempt — and what this review finds is now the navigator's binding constraint — is **meaning at the level of one patient**:

1. **The navigator cannot tell one patient's story.** Selecting a patient yields a flat `<dl>` of ≤32 key/value rows (`NavigatorInspector.tsx:10–29`, `flattenInspector` cap at `PatientFlowNavigator.tsx:239`). There is no journey timeline, no milestone list, no LOS/boarding framing, no "where were they, where are they going." The 2026-07-17 app-wide audit called the detail view "raw JSON"; structurally that is still true. Meanwhile the backend already holds everything needed — `flow_core.flow_events` is indexed `(patient_ref, occurred_at)` and `(encounter_ref, occurred_at)`, and `prod.care_journey_milestones`, `prod.case_timings`, `prod.transport_requests`, `prod.bed_requests`, `prod.home_episodes` all carry per-patient rows — but **no endpoint joins them into an ordered journey** (§4 PJ-1).
2. **The navigator is blind to the conformance layer running three meters away.** Arena X3 conformance (sepsis SEP-3, WHO surgical safety, Home-Hospital) is live on prod and computes **per-case verdicts** — then throws them away: `arena/app/conformance.py::_check_one` groups by case object and evaluates every case, but returns only aggregate rates plus ≤8 `sample_deviant_cases` (`sample_limit=8`). A patient mid-sepsis-bundle renders identically to every other token. The join is *already deterministic*: `EmissionMap::hashRef()` (unsalted `substr(sha256,0,12)`, `app/Domain/Ocel/EmissionMap.php:25–33`) lets Laravel compute any live patient/encounter's OCEL object id server-side, with the sidecar staying PHI-free. **Per-patient OCEL adherence is a narrow seam, not a research project** (§4 CF-1…CF-3, §5).
3. **The governed care-pathway catalog and the navigator don't know each other exist.** 250 governed DRG pathway definitions (milestone_definitions with `expected_range` timing targets, activity_definitions, evidence claims → PubMed) and the brand-new `patient_experience.pathway_instances` tables (encounter-bound, version-pinned, append-only stage/milestone status events) are served **nowhere**. Everything clinical stays behind the deferred serving flags — this plan designs the bridge but ships it dark (§4 CF-3, Phase D).
4. **Three queued engineering items from the Codex audit are still open** and get more expensive with every feature added on top: demo-refresh epoch atomicity (F-6 pt 2 — `ops.demo_refresh_runs` already exists as the ledger; no API exposes it), WebGL context-loss recovery (F-11 — an unattended wall goes permanently black), and the rendered-scene browser test (F-12).
5. **Navigability is good at the interaction level and unproven at the orientation level.** The literature is blunt: 3D displays impose a "naive realism" tax on monitoring tasks unless paired with overview+detail structure and 2D escape hatches. The navigator has no minimap/floor-stack, no orientation widget, no top-down orthographic mode, no URL-shareable viewpoint, and an unscented chronobar the operator must scrub blind (§4 WN/TN series).

The program below is seven phases, each independently shippable, ordered so the **server data spine (A) unblocks everything**, the **patient journey drawer (B)** delivers the headline capability, **operational conformance (C)** lands behind a new default-off flag using the three already-live operational pathways, and the **DRG catalog bridge (D)** stays clinically gated. Wayfinding (E), resilience/scale (F), and field validation (G) complete it.

---

## 2. Constraints — Rulings and Governance This Plan Must Not Violate

These are settled. Findings and phases below are designed inside them; none re-litigates.

| # | Constraint | Source |
|---|---|---|
| G-1 | Navigator is a **dark-only wall instrument with full motion**; no light theme, no `prefers-reduced-motion` gating, scoped overlay palette stays | [SU] ruling F-9, 2026-07-19; project CLAUDE.md sanctioned exception |
| G-2 | **Earned urgency** — coral only for a verified breach; **inferred/duration-only risk caps at amber** (`watch`) | [SU] ruling F-3; OccupancyInsightProjector + client mirror |
| G-3 | **Status never by color alone** — every new encoding pairs shape/wording; CVD collapse pinned by test | §12 register; `cvdPalette.test.ts` |
| G-4 | **Identity-free scene payloads** — hover chips never carry identity; rounds payloads opaque-uuid-only; centroid + server redaction under `patient_dots=none` | [SU] rulings F-1/F-2; `hoverLabel.test.ts`, `RoundProjectionTest` |
| G-5 | **One canonical persona state** — persona rides every lensed request/SSE; server transition via `EnforceFlowLens` | [SU] ruling F-1 (PR #44) |
| G-6 | **Explicit camera actions** — no control side-effect ever flies the camera | §12 register |
| G-7 | **Predictions are `watch`, never `crit`**; only *observed* deviations earn amber+; conformance signals flap-damped | Part X §X.7.3 |
| G-8 | **Sidecar stays PHI-free and read-only** — de-identified OCEL only; any live-patient join happens in Laravel | Part X §X.10.1 |
| G-9 | **Care-pathways clinical serving is OFF on clinical authority** — catalog/assignment/rounds/staff-mobile/patient/Eddy/writeback flags all deferred; a passing test never implies clinical approval | DRG integration plan TODO convention; [SU] |
| G-10 | Migrations **additive + reversible** (`SafeMigration`, hasTable/hasColumn guards); prod applies via targeted `--path` only; PHPUnit class syntax (no Pest); backend tests class-scoped (D3 paratest: 4 shards × 2 workers) | house discipline |
| G-11 | Canon: no new `backdrop-blur` files, no `text-[Npx]` outside cockpit, `check-ui-canon.sh` ratchet only goes down; new hexes live in `sceneVocabulary.ts` only | project CLAUDE.md |
| G-12 | F-4 (overlay temporal labeling) and F-5 (present-view reset) were dispositioned **test-in-H3** — building them early requires the explicit [SU] call recorded in §9 OQ-2, not silent pre-emption | Codex audit dispositions |

---

## 3. Current State — What the Examination Established

Condensed; every item verified against source this session. Predecessor docs carry the full architecture map.

**Frontend** (`Components/PatientFlowNavigator/*`, ~5.7k lines + `features/patientFlowNavigator/*`):
- Thin orchestrator (1,664 ln) + imperative lazy-chunked three.js scene (1,387 ln); 8 layer groups (forecast/heat/trails/ghosts/patients/barriers/rounds/roundsRoute); registries + single `SelectionEntity` survive rebuilds; vocabulary SSOT in `sceneVocabulary.ts`.
- Data: 4-fetch bootstrap (summary/locations/events/ambient — **once, no retry affordance**), occupancy 220–900 ms debounced, projections 5 min, barriers 120 s, rounds 30 s content-hashed, SSE "live" = **stored replay** (`replay=180&interval=0.65`). No dataset-version negotiation anywhere.
- Time: 48 h window (±24 h), 60 s `nowMs` tick, follow-mode slide only when parked at now; chronobar has Now + shift detents + barrier ticks; no density strip; no window presets.
- Interaction: H/F/N/? keys, floor rail, search fly-to + match list, saved views ×3 (localStorage), intro tour, rounds HUD/tour, F-8 action list (delayed/barriers/stops), deep links `?scope=`, `?t=`, `?focus_stop=` — **no `?patient=`**.
- Per-patient surface today: hover chip (identity-free), inspector `<dl>` (≤32 rows), trail polyline. No journey, milestones, pathway, conformance, or per-patient future.
- No `webglcontextlost` handling; mesh-per-entity (no instancing); bucketKey rebuilds clear whole groups; ~200–300 draw calls at demo scale (4,430 events).

**Backend flow plane** (`/api/patient-flow/*`, `App\Services\Flow\*`):
- `flow_core.flow_events` carries `patient_ref/patient_display_ref/encounter_ref`, category/type, from/to space FKs, bed, service line, FHIR class/status, `diagnosis_codes/order_codes/observation_codes/medication_codes` jsonb, `metadata.activity` — indexed for per-patient and per-encounter timeline queries. Sibling tables: `patient_identities` (merge history), `encounters`, `occupancy_snapshots`, `fhir_bundle_cache` (+ `/fhir/bundle` endpoint already exists, lens-gated).
- Journey reconstruction: **no unified endpoint** — `PatientStateProjector` (location-only), `MobilePatientContextService::timeline()` (bed/transport/EVS/ED only, no milestones/pathways). The mobile `/window` endpoint already has a `?since=` delta pattern the web bootstrap lacks.
- `ops.demo_refresh_runs` ledger exists (`DemoRefreshCoordinator` writes `refresh_id` rows at :206/:222) — the natural epoch source. No API exposes it.

**OCEL/Arena** (live on prod, `ARENA_ENABLED=true`, `ARENA_AI_ENABLED=true`):
- `OcelProjector` reads flow_events + care_journey_milestones + case_timings + transport + barriers + ancillary_milestones + home_episodes → `ocel.*`; refs hashed via `EmissionMap::hashRef` — **deterministic, no salt, same hash the flow normalizer/seeder use** → Laravel can compute `patient-<hash>` / `enc-<hash>` for any live ref. Nightly reconcile + 15-min refresh.
- Sidecar (`zephyrus-arena.service:8101`, pm4py py3.12): discover / performance / **conformance** / petrinet / capacity / copilot. `PATHWAYS` = versioned, owner-attributed rule specs (sepsis SEP-3 with 3 h abx target, WHO surgical safety, Home-Hospital SLA/waiver/response) evaluated **per case object** (`groupby OCEL_OID`), returning aggregate rate + deviation ranking + ≤8 samples. X3 loop → `arena.conformance_signals` → cockpit tiles → Eddy `flag_pathway_deviation`. **Online prefix-alignment (X.7) unbuilt; per-case surface unexposed.**
- X4 copilot: deterministic-first NL query + narrative with provenance; drafts land PENDING behind Eddy's human gate.

**Care pathways** (`care_pathways.*` + `patient_experience.*`):
- 250 governed definitions/versions (immutability triggers, digests), `milestone_definitions` with `sequence` + `expected_range` timing targets, activity/goal/education definitions, DRG codebook mappings, evidence claims → sources (PubMed), review/approval ledger. Governance API GET-only, `CARE_PATHWAYS_GOVERNANCE_ENABLED=true` on prod; **all clinical serving flags off, 0 active versions**.
- `patient_experience.pathway_instances` + stage/milestone instances + **append-only status events** (encounter-scoped via access grants, version-pinned, source-digested) — projected by nothing, served by nothing.

---

## 4. Adversarial Findings Catalog

Severity: **H** blocks the mission (per-patient understanding / trustworthy display) · **M** misleads or materially slows · **L** friction/polish. Inherited items note their Codex-audit lineage. Every finding maps to a phase.

### PJ — The individual patient's story

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| PJ-1 | **H** | **No per-patient journey exists anywhere in the product.** Inspector is a flat ≤32-row `<dl>` (`NavigatorInspector.tsx:10–29`; cap at `PatientFlowNavigator.tsx:239`); no endpoint reconstructs admit→moves→milestones→orders→discharge (verified absence; closest are `PatientStateProjector` location-only and `MobilePatientContextService::timeline()` without milestones/pathways). The 2026-07-17 audit's "raw JSON detail view" finding is structurally unremediated. | A1, B1 |
| PJ-2 | **H** | **Movement is geometry, not narrative.** Trails render position history with no time encoding, no interval framing (boarding/waiting as *segments*), no milestone markers. A boarded patient — the single most decision-relevant flow state — has no visual signature on their own token/trail; stagnation is only visible indirectly via location disks. Space-time-cube literature: a stationary entity is a *vertical segment*, i.e., dwell must be encoded, not inferred (Kraak 2003; EventFlow intervals — §6.2/6.3). | B3 |
| PJ-3 | M | **No `?patient=` deep link and no cross-surface pivot.** `parseHandoff` supports `scope/t/focus_stop` only; rounds board→4D exists per *stop*, but ED board, bed board, huddles, cockpit drills cannot say "locate this patient in 4D," and the navigator cannot hand a patient off outward. | B4 |
| PJ-4 | M | **The patient has no future.** Ghost projections are location-anchored aggregates; `next_move/next_move_at` exist on occupancy insights but never attach to the selected patient's story. Selecting a patient answers "where are they," never "what happens next for them." | A1, B2 |
| PJ-5 | M | **No normative context.** Nothing aligns a patient's elapsed intervals against expectations (pathway `expected_range`, service-line LOS bands) — the align-on-sentinel-event operation that makes clinical timelines readable (LifeLines2, §6.3) is absent. | B2, D |
| PJ-6 | L | `/api/patient-flow/fhir/bundle` (lens-gated, already built) is consumed by nothing in the navigator — free clinical context (encounter class/status trail) left on the table. | B2 |

### CF — OCEL conformance & evidence-based norms

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| CF-1 | **H** | **The 4D viewer and the conformance engine are disconnected end-to-end.** Arena X3 runs on prod; its only sinks are cockpit tiles and Eddy. In the navigator, a patient inside an active sepsis evaluation window renders identically to any other token; no layer, chip, panel, or census scope reflects pathway state. | C1–C3 |
| CF-2 | **H** | **Per-case verdicts are computed and discarded.** `conformance.py::_check_one` evaluates *every* case (groupby case oid → `evaluate(timeline, counts, ordered)`) but returns only `conformance_rate`, ranked deviation counts, and ≤8 `sample_deviant_cases` (`sample_limit=8`). `RefreshArenaConformance` persists only the aggregate to `arena.conformance_signals`. The per-patient answer exists transiently in sidecar memory on every run. | A2 |
| CF-3 | **H** | **No bridge from the governed catalog to executable reference models, and pathway assignments are served nowhere.** Sidecar `PATHWAYS` is a hand-authored dict (3 operational pathways); `care_pathways.milestone_definitions` (sequence + `expected_range`) is never compiled to a reference model; `patient_experience.pathway_instances` + status events have zero read surfaces. The "evidence-based norms" the user asks about exist in governed form and are structurally unreachable. | D |
| CF-4 | M | **Conformance is batch; in-flight honesty is impossible.** 30-min refresh over the projected log; Part X §X.7's online prefix-alignment is the acknowledged gap. Any live "bundle clock" display must either be prefix-aware or clearly labeled as last-batch state; streaming conformance literature adds the confidence dimension (C-3PA — early-trace verdicts are provisional) that the UI must carry. | C4 (label now), F+ (stream later) |
| CF-5 | M | **Deviation vocabulary is engineer-facing.** Codes like `culture_after_antibiotic`, `no_repeat_lactate` have labels, but the presentation layer must translate alignment results into clinician patterns (skipped / late / out-of-order / missing) and — per the healthcare process-mining consensus (JBI 2022, §6.4) — frame them as *variance to review with an exception path*, never a compliance verdict on a clinician. | C2 |
| CF-6 | M | **No conformance→space drill.** The proven non-expert flow (violation list → filtered view → "where it concentrates"; Celonis pattern §6.4) has no 4D analogue: an operator cannot ask "show me the beds/units holding tonight's deviant sepsis cases." | C3 |

### TN — Time & temporal truth

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| TN-1 | M | **Chronobar is unscented.** Only barrier-open ticks exist; no admission/discharge/deviation density strip, so retrospective use is blind scrubbing (scented-widgets evidence: embedded density roughly doubles discovery — §6.2). | B5 |
| TN-2 | M | **Replay is the only retrospective instrument.** Animation is the wrong tool for analysis (Robertson 2008); no trail-flatten ("last 6 h as heat-trails on the floor"), no small-multiples time slices. | E4 |
| TN-3 | M | *(Codex F-4, test-in-H3)* Present-state barrier/rounds overlays render inside historical/projected scenes without a persistent layer caption. | G / OQ-2 |
| TN-4 | M | *(Codex F-5, test-in-H3)* No single "Present view" reset (Now doesn't stop playback/tour/selection/scope). | G / OQ-2 |
| TN-5 | L | No follow-patient playback: replay is house-wide only; you cannot ride one patient's 24 h. | B3 |
| TN-6 | L | No window presets (shift / 6 h / 24 h) — the 48 h window is one-size. | B5 |

### WN — Wayfinding & orientation

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| WN-1 | M | **No overview+detail structure.** No minimap/floor-stack synchronized to the camera — the evidence-backed antidote to 3D disorientation (Cockburn CSUR 2009 §6.1); currently the only overview is flying out. | E1 |
| WN-2 | M | **No persistent orientation widget / canonical views.** Place-context readout (N-3) shipped, but there is no one-click top-down-floor / house / bed-level canonical framing (ViewCube pattern §6.1). | E2 |
| WN-3 | M | **No 2D escape hatch for monitoring questions.** Naive-realism evidence (§6.1): 3D costs precision on comparison/monitoring tasks. F-8's action list covers selection parity, but counts/comparisons still require reading the 3D scene; a top-down orthographic mode + the journey drawer give every monitoring answer a non-perspectival path. | E3 |
| WN-4 | L | Fly-to transitions should follow the van Wijk zoom-out arc; current `focusOn`/`flyTo` framing needs verification against linear lerp (disorientation between floors). | E2 |
| WN-5 | L | **Views are not shareable.** Saved views are localStorage-only; no URL state for camera/floor/layers/time/selection — "send this exact view to the charge nurse" doesn't exist, though `?scope/?t/?focus_stop` prove the pattern. | E5 |
| WN-6 | M | **Control-surface overload persists** (2026-07-17 audit: "three competing columns"; toolbar props have "ballooned" per project memory). Phases 0–3 *added* controls (census scope, saved views, rounds HUD, intro, action list). No task-mode organization (Monitor / Investigate / Rounds / Review) or progressive disclosure. | E6 |

### DI — Data integrity & epochs

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| DI-1 | **H** | *(Codex F-6 pt 2, queued)* **Mixed-epoch datasets on the 6 h demo refresh.** Bootstrap datasets (summary/locations/events/ambient/tracks) never rebase; only overlays poll. `ops.demo_refresh_runs` already records every refresh (`DemoRefreshCoordinator:206`) but no API exposes an epoch, so a wall client can render pre-refresh tokens over post-refresh disks indefinitely. | A3 |
| DI-2 | M | **Bootstrap is all-or-nothing with no retry.** Any of the 4 parallel fetches failing → dead navigator + status text; no retry affordance, no partial render (`PatientFlowNavigator.tsx:839–876`). | A3 |
| DI-3 | M | SSE reconnect dedups by `event_id` set only; no server sequence/gap signal — silent holes possible across network blips. Epoch header (A3) subsumes: stamp stream messages with epoch. | A3 |
| DI-4 | L | Mobile `/window` has `?since=` deltas; web bootstrap refetches nothing or everything. Adopt delta on the web read path when epoch lands. | A3/F3 |

### PX — Performance & resilience

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| PX-1 | M | **Mesh-per-entity rendering.** Every token/pip/ghost/diamond is a Mesh; fine at ~200–300 draw calls, but per-patient trace emphasis (B3), deviation glyphs (C3), and any census growth multiply object counts. InstancedMesh per archetype is the standard remedy (§6.7). | F2 |
| PX-2 | **H** | *(Codex F-11, queued)* **No `webglcontextlost/restored` handling** — GPU reset on an unattended wall = permanently black instrument; recovery protocol is well-defined (preventDefault → halt loop → full GPU-resource rebuild on restore; deterministically testable via `WEBGL_lose_context`). | F1 |
| PX-3 | M | BucketKey changes clear + rebuild whole groups (trails/heat/ghosts); delta updates (`needsUpdate`, pre-sized buffers) are the three.js-sanctioned pattern for long-running scenes. | F3 |
| PX-4 | L | Per-frame `patientStatesAt` filtering is O(patients × events) each minute-bucket; fine today (~4–8 ms), unbudgeted tomorrow. | F4 |
| PX-5 | L | No frame-budget instrumentation: soak hook exposes renderer counters but not frame-time percentiles; RAIL gives 10 ms/frame app budget to allocate per subsystem. | F4 |

### AT — Accessibility parity for what's new

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| AT-1 | M | F-8's action list covers delayed/barriers/stops; there is still no **navigable structure** (hospital→floor→unit→bed graph traversal, Data Navigator pattern §6.8). The journey drawer (B) must be the AT-primary patient surface: plain HTML timeline, full keyboard path, announced context. | B1, E3 |
| AT-2 | M | WCAG 2.2 SC 2.5.7: scrubbing is drag-first. Detents/ticks/Now are alternatives, but add click-to-jump on the chronobar track + ±15 min step buttons to make every temporal move single-pointer/keyed. | B5 |
| AT-3 | L | *(Codex F-7 residual)* CVD tests cover ok/delayed disks + triangle only; every glyph this plan adds (deviation marker, journey markers, minimap states) must extend `cvdPalette.test.ts` pairs and the never-color-alone wording tests. | C3, E1, G |

### OB — Observability & validation debt

| ID | Sev | Finding (evidence) | Phase |
|---|---|---|---|
| OB-1 | M | H3 usability sessions and H4 soak/urgency runs remain user-gated; every phase here adds claims that must enter that protocol (journey task, adherence probe) rather than shipping as assumed-good. | G |
| OB-2 | M | *(Codex F-12, queued)* No rendered-scene browser test (selection-across-rebuild; now also epoch-rebootstrap and context-restore paths). | F5 |
| OB-3 | L | The only independent command-center evaluation (Bradford ITS, §6.6) is null-to-negative — investor-grade claims need task-level endpoints (time-to-locate, time-to-answer, actionable-alert ratio) instrumented in-product, not vendor-style before/after anecdotes. | G3 |

---

## 5. The Per-Patient Conformance Seam — Why This Is Buildable Now

The pivotal discovery of this review, spelled out because Phases A2/C hang off it:

1. **Identity join, server-side, PHI-preserving.** `EmissionMap::hashRef()` is deterministic and shared with the flow normalizer. Laravel (which legitimately holds identified context) computes `enc-<hash12>`/`patient-<hash12>` for the selected patient and queries `ocel.*` / asks the sidecar **by hashed id**. The sidecar never learns identity; the client never sees raw hashes without lens authority. G-8 holds untouched.
2. **Per-case verdicts already exist.** `_check_one` builds a first-occurrence activity timeline per case and runs the pathway's `evaluate()` per case. Change required: a `per_case=true` / `case_ids=[…]` request mode returning `{case_id, conformant, deviations[], activity_timeline{}}` for all (or requested) cases instead of 8 samples — plus a Laravel cache table so the navigator reads cached verdicts, never a live mining run (the `/cockpit/snapshot` discipline).
3. **The activity timeline is the alignment visualization.** `timeline{activity → first_ts}` against the spec's ordered `activities` + targets is exactly the expected-vs-observed two-lane diagram (§6.4 pattern: deviations shown *on the trace*, in pattern language: late / missing / out-of-order).
4. **Governance fit.** Sepsis/surgical-safety/Home-Hospital are *operational safety* pathways already live on prod under `ARENA_ENABLED` with [SU]'s enablement; surfacing their per-case state to **full-dots operational personas** is new presentation of an existing enabled signal — still shipped behind a new default-off flag (`FLOW4D_CONFORMANCE_ENABLED`) with explicit [SU] enablement (OQ-1). The DRG catalog is a different animal: serving anything per-patient from it stays behind the deferred clinical flags (G-9), so Phase D builds the compiler + demo overlay and ships dark.

---

## 6. Research Digest — Practices Adopted (sources in Appendix A)

| # | Practice → application here |
|---|---|
| 6.1 | **3D navigation:** small fixed vocabulary (orbit + framed fly-to + named bookmarks) over free-fly; speed-coupled zoom for overview↔local; **ViewCube-style orientation + canonical views**; van Wijk arc fly-tos; **overview+detail (minimap/floor stack) as default structure**; *naive realism*: 3D for spatial questions, always a 2D/tabular path for monitoring answers → WN-1…WN-5, E-phase. |
| 6.2 | **Spatiotemporal:** space-time paths make stagnation visible (dwell = the signal) → trail time-gradient + dwell markers (B3); GSTC "cut/flatten/small-multiples" as the derived-view menu (E4); aggregate-by-default above ~50 concurrent trails (flow arrows), detail-on-demand (F2 guard); scrubbing and camera are substitute interactions — both must stay first-class (B5); **animation for briefing, statics for analysis** (E4); **scented scrubber** (B5). |
| 6.3 | **Clinical event sequences:** align-on-sentinel-event (ED arrival, recognition time-zero, incision) is the operation that makes per-patient timelines clinically legible (B2/C2); intervals (boarding, med courses) as first-class segments (B2); single-record vs cohort views are *different designs* — journey drawer (one) vs Arena/catalog (cohort), pivot between them, don't merge (B/D boundary); anchor any prediction to the visible sequence (Eddy explain, C5). |
| 6.4 | **Conformance UX:** translate alignment moves into pattern vocabulary (skipped/late/swapped/repeated) before clinicians see them (C2); taxonomy check — cover model-view (Arena), trace-view (journey drawer), KPI-view (cockpit) deliberately (C); Celonis drill (violation → filter → concentration) → deviation census scope (C3); object-centric conformance is research-grade — keep verdicts per object type, never one blended score (C2); prefix-alignments + C-3PA confidence for anything labeled "live" (C4); **healthcare PM consensus: deviation ≠ error — exception annotation, variance framing** (C2, G). |
| 6.5 | **Clinical adherence:** WHO checklist item-level completion as the periop display grammar; **ERAS "vertical compliance"** (per-patient %-of-elements-met, dose-response framing) as the adherence panel's headline number (C2/D); CPG-on-FHIR/CQL is the standards-first target representation for compiled pathway logic (D3, OQ-5); sepsis-alert evidence: bundle clocks change behavior but must show contributing data, prompts-not-verdicts (C2); Five Rights: wall = ambient/no interruptions, desk = actionable detail, bedside = EHR's job, not the navigator's (C posture); Joint Commission/AHRQ alarm-fatigue governance backs the earned-urgency canon and adds a metric: **actionable-to-displayed ratio as a product KPI** (G3). |
| 6.6 | **Command centers:** GE/Hopkins/Humber walls are *decision queues*, not floor plans — the navigator complements tiles by *spatializing* queues, entered via deep links from tiles (B4); wall mode = group reference, stable and legible at distance (already doctrine); **Bradford null result** → instrument process endpoints from day one (G3). |
| 6.7 | **WebGL scale:** InstancedMesh per archetype + per-instance color (F2); troika SDF text for in-scene labels, HTML overlay for dense readouts, distance-culled (E2/F2); zero per-frame allocation + 8 h soak (already H4; extend); delta updates over rebuilds (F3); **context-loss protocol is mandatory for unattended walls** (F1); RAIL 10 ms budget split per subsystem with `performance.measure` (F4). |
| 6.8 | **Canvas a11y:** parallel semantic layer / accessible shadow UI mirroring the scene's selection model (B1/E3); Data Navigator structure graph for keyboard traversal (E3); Chartability as the audit instrument for the new surfaces (G2); SC 2.5.7 non-drag alternatives for every drag (B5); Okabe-Ito-checked encodings + mandatory pairing (AT-3); XAUR user-preference motion intensity — **[SU] question, not a plan item** (OQ-3). |
| 6.9 | **Digital-twin hospitals:** direct literature is thin, simulation-oriented, evaluation-light; the one rigorous adjacent evaluation is null (Bradford). Treat the 4D navigator as a hypothesis to be measured (G3), and lean on the adjacent mature literatures above rather than twin-vendor claims. |

---

## 7. Design Sketches (the three connected surfaces)

### 7.1 Patient Journey Drawer (Phases A1+B)
- **Server:** `GET /api/patient-flow/journey?patient={ref}&encounter={ref?}` (new `PatientJourneyService`), lens-gated `EnforceFlowLens:scoped-patients`, persona-forwarded. Merges, ordered by time: flow events (movement/order/observation with `metadata.activity`), care_journey + ancillary milestones, transport phases, bed requests, barriers intersecting the stay, home-episode events; segments derived server-side into **intervals** (ED wait, boarding, unit stays, OR phases) with dwell durations; `projected` block (next_move, expected_discharge ghost if patient-attributable); `epoch` stamped. Redaction: same `redactSelection` policy family — under `patient_dots=none` the endpoint 403s (aggregate personas have no per-patient surface, G-4).
- **Client:** right-side drawer (replaces inspector *for patient selections*; inspector remains for disks/barriers/stops): header (display ref per lens, location, LOS, service line), **interval timeline** (vertical, newest at top, dwell bars with `tabular-nums` durations, milestone chips, barrier badges), "next" block, links (FHIR bundle context PJ-6, Rounds board when applicable, Eddy ask-with-evidence). Plain HTML = the AT-primary patient surface (AT-1). Selection stays `SelectionEntity`-driven; Escape closes.
- **Scene tie-in (B3 "Trace mode"):** while a patient is selected — dim non-selected layers (existing emphasis pattern), render their full-window trail with a **time gradient + dwell nodes** (node radius ∝ dwell, capped; sits on the existing trail layer), chronobar gains **patient event ticks**; `F` frames the trace bbox. Follow-patient playback = camera tracks token during replay (explicit toggle in the drawer — G-6).
- **Deep links (B4):** `?patient={ref}` in `parseHandoff` (persona-forwarded, retry-until-loaded like `focus_stop`); "Locate in 4D" affordances from ED board / bed board / rounds workspace reuse the rounds pattern.

### 7.2 Adherence Panel + deviation layer (Phase C; flag `FLOW4D_CONFORMANCE_ENABLED`, default off)
- **Seam (A2):** sidecar `/conformance` gains `per_case` + `case_ids`; `RefreshArenaConformance` upserts `arena.case_conformance` (`case_oid`, pathway, version, conformant, deviations jsonb, activity_timeline jsonb, computed_at, epoch) — additive migration, cache-only semantics. `ArenaService::caseConformance(encounterRef)` does the hashRef join and reads the cache. `GET /api/arena/conformance/case?encounter={ref}` — lens-gated like journey; sidecar untouched by identity (G-8).
- **Panel (inside the journey drawer):** per active pathway — headline **"N of M elements met"** (ERAS vertical-compliance framing, not a percent-grade), two-lane expected-vs-observed strip (spec activities in order vs observed first-occurrence times), deviations in **pattern language** ("Antibiotics 42 min past the 3 h target" = *late*; "Repeat lactate not yet observed" = *missing*), each with: model version + owner (provenance line, "against SEP-3 v1 · owner: quality"), batch timestamp ("as of 14:32 batch" — CF-4 honesty), and an **"open an exception note"** affordance (writes an Eddy-plane annotation draft, human-gated) — variance-to-review framing, never a clinician scoreboard (CF-5). "Explain this deviation" → X4 copilot narrative with provenance (only when `ARENA_AI_ENABLED`).
- **Scene:** deviation marker on the affected patient token — **distinct glyph** (proposed: small hollow square bracket-pip; must not collide with triangle=delayed, diamond=barrier, torus=rounds; final shape via sceneVocabulary + CVD test), **amber, never coral in v1** (G-2/G-7; coral escalation is OQ-1's ruling), worded in legend + hover chip ("Pathway deviation · sepsis · late step" — state, no identity, G-4). Layer toggle "Pathway" defaults **off**.
- **Census scope:** `Census: All | Delayed | Pathway deviations` third scope (same chip/metric-relabel/Focus discipline the wrong-toggle redesign established — reuses, not duplicates, the filter machinery). Urgency census script extended to count deviation glyphs (H4 parity).
- **Aggregate personas:** none of the above renders; executives keep cockpit conformance tiles (rate-level). The flag + lens gate compose: surface requires `FLOW4D_CONFORMANCE_ENABLED` ∧ full-dots lens.

### 7.3 Orientation & 2D structure (Phase E)
- **Minimap/floor-stack** (bottom-right, canon overlay style): stacked floor plates with camera frustum footprint + selection dot + deviation/delay concentration pips; click = fit-to-floor (existing path). Overview+detail, always visible on desk, suppressible in wall mode.
- **Orientation widget:** compass/canonical-views control (Top · House · Floor · Bed-level) — one-click framed transitions along van Wijk arcs (`focusOn` upgrade).
- **Top-down mode:** orthographic camera toggle (`O`) — the monitoring-precision escape hatch; all layers remain; disks/labels legible without perspective distortion.
- **URL state:** serialize camera/floor/layers/time/selection into the query (proven param patterns); "Copy view link" button beside saved views.
- **Task modes (WN-6):** toolbar reorganized into Monitor / Investigate / Rounds modes (progressive disclosure of the ballooned prop surface); default Monitor = today's layout minus investigation-only controls.

---

## 8. Implementation Phases & TODOs

Standard gates every phase: `npx tsc --noEmit` **and** `npx vite build`, `scripts/check-ui-canon.sh`, Vitest, targeted PHPUnit (class-scoped, PHPUnit-not-Pest), sidecar `arena/.venv/bin/python -m pytest` when touched, CI green, `./deploy.sh --frontend` (or `--db --path=` for the one migration), devlog + memory update. Sequential small PRs; no long-lived worktrees (Worktree Agent Protocol).

### Phase A — Server data spine `feature/flow4d-journey-spine` [C] — **SHIPPED (PR #93, squash `e5f39bac`; deployed prod + migration `[53] Ran` + fpm restart 2026-07-27)**
**A1 Journey endpoint**
- [x] `app/Services/Flow/PatientJourneyService.php` — merge flow_events (patient_ref + encounter_ref paths), care_journey_milestones (via or_cases), ancillary_milestones, transport_requests phases, bed_requests, barriers overlapping stay window, home_episodes; derive interval segments (ED wait / boarding [bed_request→place] / unit stays / OR phases) with dwell; attach `next` block from ForwardProjection/occupancy `next_move`
- [x] Route `GET /api/patient-flow/journey` under `EnforceFlowLens:scoped-patients` in `routes/api.php` (patient-flow group); persona chain untouched; 403 for `patient_dots=none`
- [x] Response carries `epoch` + `as_of`; Zod schema `features/patientFlowNavigator/journeySchemas.ts`
- [x] PHPUnit: `tests/Feature/PatientFlow/PatientJourneyEndpointTest.php` — ordering, interval derivation, lens redaction (aggregate 403; scoped strips identity fields), encounter scoping, empty-history shape
**A2 Per-case conformance seam**
- [x] `arena/app/conformance.py`: `per_case: bool` + `case_ids: list[str]` params; response adds `case_results: [{case_id, conformant, deviations, activity_timeline}]` (aggregates unchanged — additive contract); pytest: per_case parity with aggregate counts, case_ids filter, timeline first-occurrence semantics
- [x] `ArenaSidecarClient::conformance()` passthrough; `RefreshArenaConformance` upserts `arena.case_conformance` (new additive migration `create_arena_case_conformance` — SafeMigration, no prod.* touch; applied to prod later via `--db --path=`)
- [x] `ArenaService::caseConformance(string $encounterRef)` — `EmissionMap::hashRef` join (enc-first, patient fallback), reads cache only
- [x] Route `GET /api/arena/conformance/case` (main arena group under `EnsureArenaEnabled` + flow-lens full-dots check); PHPUnit: hash-join correctness, cache-only (no sidecar call on request path), lens gate, ARENA-off 404
**A3 Epoch & bootstrap resilience (absorbs Codex F-6 pt 2; DI-1..DI-3)**
- [x] `GET /api/patient-flow/epoch` (or fold into `/summary`): `{epoch: refresh_id, refreshed_at}` from `ops.demo_refresh_runs` latest completed row (prod-safe when table empty: epoch=null → feature inert)
- [x] All patient-flow JSON responses + SSE messages stamped with `epoch` (response envelope meta; additive)
- [x] Client: 60 s epoch check piggybacked on existing `nowMs` tick; on change → **atomic rebootstrap** (all four bootstrap datasets + overlays refetched together, "Rebuilding view — data refreshed" status card, selection cleared, scrub position preserved-if-valid else Now); rounds stops cleared per PR #48 rule
- [x] Bootstrap retry: failure card gains a Retry button; partial-failure renders what loaded + names what didn't (DI-2)
- [x] Vitest: epoch-change triggers single coordinated rebootstrap (no per-dataset tearing); soak hook exposes `epoch()` for H4 refresh-boundary assertion
**Acceptance:** journey JSON for any demo patient reconstructs admit→now ordered with intervals; per-case conformance queryable by encounter ref out of cache; wall client crosses a 6 h refresh with one visible rebuild and zero mixed-epoch frames.

### Phase B — Patient Journey Drawer + trace mode `feature/flow4d-journey-drawer` [C] — **SHIPPED (PR #94, squash `47ff12d4`)**
- [x] B1 `NavigatorJourneyDrawer.tsx` (+ `features/patientFlowNavigator/journey.ts` builders): header/intervals/milestones/next/links per §7.1; replaces inspector for patient selections; CSS appended to `PatientFlowNavigator.css` (no new blur file); ≥24 px targets; `tabular-nums` durations
- [x] B2 Sentinel alignment control: "align from" selector (arrival / admit / recognition when present) re-zeroes interval labels (LifeLines2 op); FHIR bundle context row (PJ-6)
- [x] B3 Trace mode in `NavigatorScene`: selected-patient trail w/ time gradient + dwell nodes; others dimmed; chronobar patient ticks; `F` frames trace; follow-patient toggle during replay (explicit button, G-6)
- [x] B4 `?patient={ref}` in `parseHandoff` + copy-link pivot URL. *Outward "Locate in 4D" buttons on ED/bed boards deferred to a follow-up touch (rounds already pivots via `focus_stop`; rounds payloads are opaque-uuid-only by doctrine, so a rounds→patient link is structurally excluded).*
- [x] B5 Chronobar: scented density strip + ±15 m step buttons (SC 2.5.7; the native range input already jumps on track click). *Window presets (TN-6, severity L) deferred.*
- [x] Tests: drawer render + lens variants (full vs scoped display ref), interval math, align-from re-zeroing, deep-link parse, density-strip bucketing, keyboard-only walkthrough (select → drawer → trace → close); `hoverLabel`/identity sentinels extended to trace-mode chips
- [x] Intro tour gains a journey stop; usability protocol draft task T8 (find patient → state their last 12 h → name current wait) staged for H3
**Acceptance:** from any surface, one action lands on a patient in 4D with their story open; a boarded patient's boarding interval is visible as a labeled segment with dwell; keyboard-only path complete; no identity in any scene-layer payload.

### Phase C — Operational adherence surface (~3 d) `feature/flow4d-adherence` [C, enable = SU] — **BUILT (dark); enablement = OQ-1 [SU]**
Flag: `FLOW4D_CONFORMANCE_ENABLED` (config `services.flow4d.conformance`, default **false**; Inertia-shared pre-composed as `arena.conformance_enabled` = ARENA ∧ flag).
- [x] C1 Adherence panel in journey drawer per §7.2 (elements-met headline, expected/observed lanes, pattern-language deviations w/ computed evidence, provenance + batch-time line, exception-note draft via `EddyActionService::propose(approve:false)` reusing the existing `flag_pathway_deviation` catalog entry, Explain→Eddy prefill when `ARENA_AI_ENABLED`)
- [x] C2 `features/patientFlowNavigator/adherence.ts` — registry mirror of arena/app/pathways.py (all 11 codes × 3 pathways; unknown codes degrade + can never overstate compliance); `adherence.test.ts` pins every code
- [x] C3 Scene: hollow amber **bracket** glyph (billboarded 4-seg ring; `PATHWAY_DEVIATION_COLOR` amber cap lives in sceneVocabulary), `Pathway` layer toggle default off (absent while dark), census scope `Pathway deviations` (3-way radio replacing the boolean; chip + metric relabel + explicit Focus), legend section + hover chip "Pathway deviation · sepsis · late step"; `cvdPalette.test.ts` pins the 4-shape amber-capable pair set. Bulk scene read = `GET /arena/conformance/scene` (canViewPatientRow row parity, ptok-keyed, deviant-only)
- [x] C4 Every element carries "as of {batch}" + "30-minute batch" (cadence shipped in the payload, mirrored from the scheduler); T10 probes "is this live?" — no element says live
- [x] C5 `EnsureFlow4dConformanceEnabled` inside the arena group; `Flow4dConformanceSurfaceTest` runs the 8-combo gate matrix + scene-flags identity sentinels + exception-note PENDING/422/403 (16 tests)
- [x] `scripts/urgency-census-flow4d.mjs` counts deviant patients into the amber/status shares (silent 0 while dark); soak hook `pathwayGlyphs()`
- [x] Usability protocol **T10** (the planned "T9" probe — T9 was taken by the v1.1 deep-link task) staged for H3, incl. the earned-urgency and freshness-honesty sub-probes
- Also fixed in-phase: canvas token clicks never opened the journey drawer (Phase B kind test `'patient'` vs userData `'patient-token'`); trace/follow effects no-oped when the journey resolved before the lazy scene chunk (sceneNonce re-run guard)
**Acceptance MET (dev walk 2026-07-27, 11/11 checks):** deviant seeded sepsis case → amber bracket in-scene (exactly 1 under trace, all when idle) → panel reads "2 of 4 elements met · Antibiotic beyond the 3-hour target — Antibiotics 42 min past the 3 h target · against SEP-3 v1 · owner: critical care · as of {t} batch · 30-minute batch" → census scope isolates + chips → exception note landed `ops.recommendations(draft)/actions(draft)/approvals(pending)` with an identity-free payload. Flag off: gates 404, toggle/radio/legend absent, drawer byte-identical (pinned by tests).

### Phase D — Governed DRG catalog bridge (~4–5 d) `feature/flow4d-pathway-bridge` [C build; SU/clinical to ever serve]
Everything here ships **dark** behind the existing deferred serving-flag family (G-9); the only visible artifact is the demo overlay under `CARE_PATHWAYS_DEMO_ENABLED`.
- [ ] D1 `app/Domain/CarePathways/ReferenceModelCompiler.php`: active-or-demo pathway version → sidecar reference spec (milestone_definitions.sequence + expected_range → ordered activities + timing targets + deviation labels); pure + unit-tested against fixture versions; emits the same spec shape `PATHWAYS` uses (sidecar gains a `POST /conformance/reference-models` registration or file-drop — decide in-phase; sidecar stays stateless per-request preferred)
- [ ] D2 Activity mapping table: pathway milestone stable_keys ↔ OCEL activities (starts as config map + coverage report command `care-pathways:ocel-coverage` listing unmappable milestones — the honest gap list clinical review will need)
- [ ] D3 (spike, timeboxed) Evaluate compiling via CPG-on-FHIR PlanDefinition + CQL as the canonical intermediate instead of a bespoke spec (§6.5; OQ-5) — outcome is a decision memo, not a dependency
- [ ] D4 `PathwayInstanceReadService` over `patient_experience.pathway_instances` + status events (append-only reads); journey drawer shows assigned-pathway stage/milestone progress **only when the relevant serving flag is on** — flag checks at service AND component
- [ ] D5 Demo overlay: `/care-pathways/demo`-style synthetic in-memory pathway instance attached to a demo patient in the drawer (no DB writes, no activation, labeled "Demo — not clinical guidance") — the investor-visible artifact
- [ ] Tests: compiler determinism + digest pinning (a version's compiled model is content-addressed), flag-off inertness (byte-identical responses), append-only discipline untouched
**Acceptance:** a governed pathway version compiles to a conformance-checkable reference model with a coverage report; demo overlay demonstrates the full loop on synthetic data; nothing clinical serves anywhere with flags off — proven by tests, asserted with G-9's convention (no inferring clinical approval).

### Phase E — Wayfinding & 2D structure (~4 d) `feature/flow4d-wayfinding` [C]
- [ ] E1 Minimap/floor-stack widget (camera footprint, selection dot, delay/deviation pips; click = fit-to-floor); CVD + never-color-alone treatment; suppress in wall/kiosk param
- [ ] E2 Canonical views control (Top/House/Floor/Bed) + van Wijk-arc verification/upgrade of `focusOn`/`flyTo` transitions; SDF-text unit billboards w/ LOD + distance culling (P4 backlog item, now evidence-backed)
- [ ] E3 Top-down orthographic mode (`O`) — all layers; disks/labels legibility pass; Data-Navigator-style structure traversal (floor→unit→bed arrow-key graph) layered on the existing action-list seam
- [ ] E4 Flatten + small multiples: "Trail heat (last 6 h)" scene mode (GSTC flatten) and a 6-slice hourly small-multiples export card (analysis path replacing forced replay)
- [ ] E5 URL view state + "Copy view link" (camera/floor/layers/time/selection/censusScope; extends parseHandoff; saved views gain "copy as link")
- [ ] E6 Task modes Monitor/Investigate/Rounds (progressive disclosure; `NavigatorToolbar` decomposition — the renderToolbar test fixture must gain the mode prop; WN-6)
- [ ] Tests: mode-gated control visibility, URL round-trip property test, minimap sync, ortho toggle, LOD label culling thresholds
**Acceptance:** an operator can always answer "where am I / what am I looking at / how do I get back" in one glance + one click; every monitoring answer has a non-perspectival path; any exact view is shareable as a URL.

### Phase F — Resilience & scale (~3–4 d) `feature/flow4d-resilience` [C]
- [ ] F1 Context-loss recovery (absorbs Codex F-11): `webglcontextlost` preventDefault + halt + visible degraded card; `webglcontextrestored` → full GPU-resource rebuild from state (scene state already lives outside GL); deterministic test via `WEBGL_lose_context` in the browser test; soak hook counts losses/restores
- [ ] F2 InstancedMesh migration for tokens/pips/ghosts/deviation glyphs (per-instance color; raycast via instanceId; selection/highlight adapted — SelectionEntity API unchanged); aggregate-trails guard: >50 concurrent trails → unit-to-unit flow arrows w/ counts, individual trail on selection (§6.2)
- [ ] F3 Delta updates: pre-sized instance buffers + `needsUpdate` paths replace group clear+rebuild for token/heat layers; epoch rebootstrap (A3) remains the only full-rebuild trigger; web read path adopts `?since=` deltas where the endpoint offers it (DI-4)
- [ ] F4 Frame budget: `performance.measure` marks per subsystem (tokens/trails/overlays/labels), p95 exposed via soak hook; RAIL-derived budget table recorded in this doc when measured
- [ ] F5 Rendered-scene Playwright test (absorbs Codex F-12): boot demo scene → select → force layer rebuild → assert highlight survives; epoch rebootstrap path; context-loss path; runs in the existing browser CI job
**Acceptance:** wall survives GPU reset with self-recovery; draw calls flat vs entity count (instancing proven by soak counters); browser CI covers the three long-session failure modes end-to-end.

### Phase G — Field validation & claims discipline (sessions [SU], synthesis [C])
- [ ] G1 Usability protocol v1.1: add T8 (journey), T9 (adherence SAGAT), keyboard-only journey variant; re-pilot timing (≤35 min)
- [ ] G2 Chartability audit pass over drawer/adherence/minimap surfaces; log + fix P1s
- [ ] G3 In-product task telemetry (time-to-locate-patient, time-to-journey-answer, deviation-scope usage, actionable-vs-displayed alert ratio) — the Bradford-proof metrics investor material can stand on (OB-3); aggregate-only, no per-user surveillance
- [ ] G4 [SU] run H3 sessions + H4 soak/urgency across a refresh boundary (now also asserting epoch(), glyph census, frame p95); synthesize → design actions
- [ ] G5 OQ-2 disposition executed (build F-4 caption + F-5 present-view now vs post-H3 — see §9)
- [ ] G6 Close out: devlog, §12 register additions (journey identity rules, adherence amber cap, epoch atomicity doctrine + their named guards), memory update

---

## 9. Open Questions for [SU]

| # | Question | Recommendation |
|---|---|---|
| OQ-1 | **Adherence enablement + coral eligibility.** Enable `FLOW4D_CONFORMANCE_ENABLED` on prod after Phase C? And: may any *observed* hard breach (e.g., `antibiotic_late`) ever escalate the glyph to coral, or is pathway state permanently amber-capped in-scene? | Enable on prod (it's the demo/investor env; signal already prod-live in cockpit). Keep **amber-only in v1**; revisit coral for a named short list only after H3 evidence. |
| OQ-2 | **F-4/F-5 timing.** Both were dispositioned test-in-H3; H3 has no date. Build the overlay captions + Present-view reset now (Codex + mode-confusion literature both support), or hold for sessions? | Build in the next available phase window with the disposition amended in the audit doc ("promoted: H3 unscheduled after 7 days" note) — the empirical probe still runs in H3 and can contradict us cheaply. |
| OQ-3 | **Desk-user motion-intensity preference (XAUR §6.8).** F-9 rejected the OS media query for the wall instrument. Is an in-app, per-user "camera motion: full/reduced" preference (default full; wall/kiosk pinned full) compatible with the ruling's intent, or re-litigation? | Treat as compatible (user preference ≠ ambient OS gating) but it is [SU]'s ruling to make; cost is small either way. |
| OQ-4 | **Journey anchor: Patient vs Encounter.** Part X's open question lands here concretely — the drawer defaults to the current encounter; do we ever show cross-encounter (readmission) context at this surface? | Encounter-scoped v1; longitudinal person view belongs to Arena/Study altitude, not the wall instrument. |
| OQ-5 | **Compiled pathway representation.** Bespoke sidecar spec (fast, matches `PATHWAYS` today) vs CPG-on-FHIR PlanDefinition + CQL intermediate (standards-first per global instructions, heavier)? D3 spike will bring evidence. | Spike first; if CQL tooling friction is high, ship bespoke spec with a documented FHIR-mapping commitment — the catalog's own schema already stores the governed truth. |
| OQ-6 | **Adherence persona floor.** Full-dots personas only (house supervisor, unit charge within scope) per §7.2 — confirm executives/aggregate walls stay rate-only via cockpit. | Confirm as designed. |

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| **Alarm-fatigue regression via deviation glyphs** — the exact failure the canon exists to prevent | Amber-capped, layer default-off, flag default-off, census-scope opt-in, H4 urgency census counts glyphs, Joint-Commission-style actionable-ratio telemetry (G3) |
| **Clinical-authority creep** — per-patient adherence drifting into CDS territory | Operational pathways only in C; DRG catalog dark behind G-9 flags; exception-note + prompts-not-verdicts framing; Part X scope rule ("CDS is the EHR's job") restated on the panel |
| **Re-identification on shared walls** via journey/adherence context | Drawer requires full-dots lens (403 server-side), scene payloads stay identity-free (sentinel tests extended), aggregate personas see nothing new |
| Hash-join drift (hashRef inputs diverge between normalizer and projector) | Cross-system PHPUnit fixture pinning `hashRef` parity flow_core↔ocel; nightly reconcile already alarms on count drift |
| Sidecar per-case payload growth (all cases × pathways) | `case_ids` filter for on-demand; cache table carries only latest batch per case; window already log-bounded |
| Instancing migration destabilizes selection/highlight invariants | F2 keeps `SelectionEntity` API frozen; rendered-scene browser test (F5) lands **before** F2 merges |
| Toolbar decomposition (E6) breaks the ballooned-props test fixtures | renderToolbar fixture updated in the same PR; task-mode props typed with `Pick<>` per house style |
| Two concurrent sessions on this checkout | Branch-early, stage-only-own-files (established SOP); phases are independent branches |
| Scope: seven phases is a program, not a sprint | Each phase independently shippable and valuable; A alone retires the worst debt (DI-1) and unlocks everything else |

---

## 11. Sequencing & Effort

```
A (spine: journey + per-case + epoch)   ~3–4 d   ← unblocks B, C; retires DI-1..3
B (journey drawer + trace + links)      ~3–4 d   ← the headline capability
C (adherence, flag-gated)               ~3 d     ← needs A2; [SU] enable via OQ-1
D (DRG bridge, ships dark)              ~4–5 d   ← independent of B/C; clinical gate G-9
E (wayfinding & 2D)                     ~4 d     ← independent; anytime after A
F (resilience & scale)                  ~3–4 d   ← F5 before F2; F1 anytime (do early if wall incidents)
G (validation)                          [SU]-gated sessions + ~1 d synthesis
```
Recommended order: **A → B → C → F1+F5 → E → D → F2–F4 → G** (G1/G2/G3 prepared alongside B/C). Total [C] effort ≈ 21–26 days across 7+ PRs.

---

## Appendix A — Research Sources

**3D navigation & wayfinding:** Jankowski & Hachet, *Survey of Interaction Techniques for 3D Environments*, Eurographics STAR 2013 / CGF 2015 (hal-00789413); Tan, Robertson & Czerwinski, *Speed-coupled flying with orbiting*, CHI 2001; Khan et al., *ViewCube*, ACM I3D 2008; van Wijk & Nuij, *Smooth and efficient zooming and panning*, InfoVis 2003; Cockburn, Karlson & Bederson, *Overview+detail, zooming, and focus+context*, ACM CSUR 41(1) 2009; Smallman & Cook, *Naive Realism*, Topics in Cognitive Science 2011; NN/g, *2D is Better Than 3D*.

**Spatiotemporal movement:** Kraak, *The Space-Time Cube Revisited*, ICC 2003; Bach et al., *Generalized Space-Time Cube*, CGF 36(6) 2017; Andrienko & Andrienko, *Visual analytics of movement*, Information Visualization 12(1) 2013 + Springer 2013; Amini et al., *Impact of Interactivity on 2D/3D Movement Visualization*, IEEE TVCG 21(1) 2015; Robertson et al., *Effectiveness of Animation in Trend Visualization*, InfoVis 2008; Willett, Heer & Agrawala, *Scented Widgets*, InfoVis 2007.

**Clinical event sequences:** Wang, Plaisant & Shneiderman, *LifeLines2*, UMD HCIL (CHI 2008/2010); Monroe et al., *EventFlow*, IEEE TVCG 2013; Rind et al., *Interactive InfoVis to Explore and Query EHRs*, FnT HCI 5(3) 2013; Jin et al., *CarePre*, ACM Trans. Computing for Healthcare 1(1) 2020; *Patient Path via Sankey — Proof of Concept*, MIE 2020 (PMID 32570378); *Patient Journey Mapping and the LHS: Scoping Review*, JMIR Med Inform 2023 (PMC10012009).

**Conformance visualization:** van Zelst et al., *Online conformance checking with prefix-alignments*, IJDSA 2017; *Process-Level Deviation Patterns*, BPM/ICPM workshops 2024 (Springer); Häge & Rehse, *Taxonomy for Conformance Checking Visualizations*, ICPM 2024 + Process Science 2025; *A Task Taxonomy for Conformance Checking*, arXiv 2025; Celonis, *Conformance Checker* docs; van der Aalst et al., *OCEL 2.0*; Adams & van der Aalst, *Precision and Fitness in OCPM*; *Object-Centric Conformance on Graph-Based Abstractions*, Springer 2025; *C-3PA: Streaming Conformance, Confidence and Completeness*, CAiSE 2023; Burattin, *Online Soft Conformance*, arXiv 2022; Munoz-Gama et al., *Process Mining for Healthcare: Characteristics and Challenges*, J Biomed Inform 127:103994, 2022.

**Clinical adherence:** Haynes et al., *WHO Surgical Safety Checklist*, NEJM 360:491, 2009; *ERAS Interactive Audit System 10 Years*, World J Surg 2019 (PMID 30647549) + ERAS Society EIAS + *Vertical Compliance*, IJMI 2020; HL7 *CPG-on-FHIR IG v2.0.0* + CQL; *Sepsis Alert Systems Meta-Analysis*, JAMA Netw Open 2024 (PMC11265133); *Sepsis Digital Alerts in NHS Trusts*, JMIR Human Factors 2024; Osheroff/AHRQ, *Five Rights of CDS* (AHRQ 09-0069-EF); Joint Commission *Sentinel Event Alert 50* (2013); AHRQ *Making Healthcare Safer III — Alarm Fatigue* (2020).

**Command centers:** Johns Hopkins *Judy Reitz Capacity Command Center* (2016; 5-year review 2021); Humber River *Command Centre Gen 1–3* (hrh.ca 2022; GE one-year review); GE HealthCare *Command Center Tiles*; Tampa General *CareComm* (Becker's); Mebrahtu et al., *Hospital command centre impact on patient flow and data quality*, IJQHC 35(4) 2023 (PMC10566538) + *Command centre and patient safety ITS*, BMJ HCI 2023 (PMC9884873).

**WebGL performance:** three.js *InstancedMesh* docs + InstancedMesh2 (three.js forum); *Troika Text* (protectwise); *Discover three.js — Tips and Tricks*; three.js manual *How to update things*; Khronos wiki *HandlingContextLost* + MDN *WEBGL_lose_context*; Google web.dev *RAIL model*.

**Canvas/3D accessibility:** W3C HTML WG *AddedElementCanvas*; Quorum *Accessible WebGL* tutorial; Elavsky et al., *Data Navigator*, IEEE VIS 2023 + *Chartability* (EuroVis/CGF 2022); W3C WAI *Understanding SC 2.5.7 Dragging Movements* (WCAG 2.2); Wong, *Color blindness*, Nature Methods 8:441 2011 + Okabe & Ito CUD; W3C *XAUR* Working Group Note 2021.

**Digital-twin hospitals (thin, calibrate claims):** *AI-driven digital twin for patient flow*, Frontiers in Digital Health 2026; AnyLogic hospital digital-twin case study (vendor); *Digital twins in healthcare IoT: systematic review*, 2025; *DITTO: Visual Digital Twin for Head & Neck Cancer*, IEEE VIS 2024. One candidate (PMC12053090) untriaged — retrieval blocked.
