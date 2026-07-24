# Patient Flow 4D Navigator — HFE Closure Plan

**Date:** 2026-07-19
**Status:** H1, H2 (audit + all 12 dispositions + fix-now tranche + all 4 [SU] rulings **implemented**), H3.1 protocol, H4 tooling/hook, H5.1 tour, and H5.2 register all DONE and deployed (prod `e7fadb5`, incl. PR #44 F-1/F-2/F-3). Also deployed: PR #45 (4D lens-collapse fix: broad-access house default + CAD unit bridge — the "one section of one floor" prod incident; prod `5b635c8`, verified 4430/4430 events pass the default house lens across all floors, up from 79). Remaining user-gated: H3 sessions, H4 runs (wall box + soak account), the H1 vision-deficiency spot-check. Interim devlog: [DEVLOG-flow4d-hfe-closure-2026-07-19.md](../devlog/DEVLOG-flow4d-hfe-closure-2026-07-19.md)
**Predecessor:** [FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md](./FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md) (Phases 0–3 merged + deployed at `2066e5c`, incl. 20-finding deep-review remediation `a90abff`).
**Purpose:** everything shipped so far is validated by *heuristic* evaluation (plan audit, multi-angle self-review, design hook, canon tests). This plan closes the gap to a defensible human-factors conclusion: fix the known perceptual/access defects, obtain an independent audit, validate empirically with representative users, prove the long-session claims in the field, and institutionalize the invariants.

Owners: **[C]** = Claude-executable, **[SU]** = requires Dr. Udoshi (clinical network / hardware / judgment).

---

## H1 — Close the known perceptual & access gaps (≈1 day) `feature/flow4d-hfe-gaps` — **BUILT 2026-07-19**

Do these BEFORE the audit and usability test so evaluators don't burn time rediscovering known defects.

### H1.1 CVD-safe delayed cue on census disks [C]
The ok/delayed disk axis is green-vs-coral — a red-green discrimination. Legend + hover chip are compensating controls, but the disk is the at-a-glance signal.
- [x] Shape cue for `delayed` disks: warning-triangle billboard sprite above the disk (echoes the board's `AlertTriangle`; no collision with torus=rounds / diamond=barriers)
- [x] Sprite material cached once; coral fill + dark exclamation; `isSprite` dispose guard already in place
- [x] `sceneVocabulary.ts`: "Delayed marker" entry under *Occupancy & timers* (triangle shape); legend renders it automatically
- [x] CVD verification: implemented as a PINNED TEST rather than a one-off screenshot — `tests/js/patientFlow/cvdPalette.test.ts` simulates deuteranopia (Machado 2009, severity 1.0) and asserts the green/coral pair collapses (>50% discriminability loss), documenting why the shape cue is load-bearing; a manual DevTools protanopia/deuteranopia eyeball on prod remains a 5-minute [SU] spot-check
- [x] Tests: vocabulary parity + the CVD collapse + shape-compensation assertions

### H1.2 Non-pointer selection path (keyboard / AT) [C]
- [x] Search-result list: up to 8 matches as buttons under Find; labels honor the lens (display id on full dots, location otherwise)
- [x] Feed rows selectable (same path; plain rows for aggregate lenses)
- [x] Shared `patientTokenInspectorData()` builder (features/patientFlowNavigator/inspector.ts) — scene userData and list selection present identical payloads
- [x] Scene `selectEntity({kind:'patient', id})` applies the standard highlight; `F` then flies to it (flight on explicit keystroke, not on select)
- [x] Tests: toolbar match-list render + selection callback; feed row selection + aggregate-lens plain rows

### H1.3 Single selection entity [C]
- [x] `SelectionEntity {kind: patient|occupancy|barrier|round-stop, id}` — every entry point writes through one path (canvas click derives it, tour step sets it, list/feed selection sets it). *Implementation note:* the entity record lives in NavigatorScene (required for rebuild re-apply) with all writes flowing through its two APIs (`selectEntity`, pointerdown) — one store, orchestrator-driven; ghosts/base meshes remain mesh-only selections by design
- [x] Registries: `tokenByPatient` / `heatMeshByLocation` / `barrierMeshById` / `roundStopMeshByUuid`; highlight re-applies after rebuildHeat/rebuildBarriers/rebuildRounds while the entity resolves
- [x] `F` flies to the current selection (mesh or re-resolved entity); Escape clears entity + visual + inspector + action link in one code path
- [x] Tour step IS a selection (panel/highlight/F/Escape agree); the ring keeps its dedicated focus-pulse material
- [x] Tests: covered via toolbar/feed selection tests + existing suite; scene-internal re-apply is exercised by the shared-builder equivalence (GPU-free scene unit tests remain impractical — Playwright CI covers the rendered path)

### H1 exit
- [x] 584 vitest, tsc, vite build, canon all clean
- [x] PR → CI → merge → `./deploy.sh --frontend` — PR #40 merged 2026-07-19 (15/15 green, merge `6410303`), deployed at `4ce1c7d`; live chunk verified over HTTPS ("Delayed marker" + "Search matches" present). Remaining manual: [SU] 5-minute DevTools vision-deficiency spot-check on prod

---

## H2 — Independent expert audit (≈half day) — **DONE 2026-07-19** (Codex CLI available locally, run [C])

Self-review has a structural blind spot: it grades its own interpretation of the plan.
- [x] Run a Codex audit pass over `/rtdc/patient-flow-navigator` (Codex CLI 0.144.6, read-only sandbox) — 12 findings: 6 High, 5 Medium, 1 Observation; wrong-toggle lens came back clean
- [x] Prime the auditor with: PRODUCT.md personas + anti-references, DESIGN.md Two-System/earned-urgency/never-color-alone doctrines, the advancement plan §10 guardrails, and explicit prompts for: wrong-toggle risk, alarm fatigue, mode confusion (live/replay/historical/tour), interruption recovery, and the mixed-persona wall-display context
- [x] [C] Triage findings — every file:line claim independently verified before disposition; categories used: fix-now / test-in-H3 / ruling-needed / follow-up (nothing rejected — all 12 verified factual)
- [x] Record the full audit output in `docs/audits/` — [2026-07-19-flow4d-codex-hfe-audit.md](../audits/2026-07-19-flow4d-codex-hfe-audit.md) (dispositions table + verbatim output)

**Acceptance met:** every audit finding has a disposition in writing.

**Fix-now shipped:** F-4 copy (PR #42, merge `c8116d8`); F-3 census coral-counting + F-8 aria-labels + F-9 gold focus + F-10 24px/tabular-nums/faux-bold + F-11 soak listener + F-12 canon-in-CI (PR #43, merge `fd2d6e6`) — both **deployed `fd2d6e6` 2026-07-19, live-chunk verified** (intro markers, aria-labels, 24 px minimums confirmed in the served assets; the canon CI step proved itself green on both PRs' runs).
**Fed into H3:** F-4 overlay labeling (SAGAT probe), F-5 present-view recovery (task T2), F-7 non-critical color states (element naming + CVD mix).
**Ruled 2026-07-19 [SU] (all four):**
- **F-1 → server persona transition.** The role switch on the navigator page performs a server/Inertia persona transition through `EnforceFlowLens`; persona propagates to every lensed request. One canonical persona state. → **MERGED+DEPLOYED** (PR #44, prod `e7fadb5`, live-verified)
- **F-2 → centroid + server redaction.** Under `patient_dots = none` the rounds projection anchors at unit centroids and strips bed/facility-space/board deep link server-side, with a sentinel test. → **MERGED+DEPLOYED** (PR #44, prod `e7fadb5`, live-verified)
- **F-3 → inferred capped at amber.** Unverified duration-only stay risk maxes at `watch`; coral requires verification. Per-service validated targets remain the phase-2 refinement. → **MERGED+DEPLOYED** (PR #44, prod `e7fadb5`, live-verified)
- **F-9 → dark-only, full motion.** The navigator is a **dark-only wall instrument by design** and keeps **full motion**; light theme and `prefers-reduced-motion` are rejected-with-rationale (wall-display surface, operator-driven camera), recorded as a sanctioned exception in CLAUDE.md.
**Queued follow-up engineering:** F-6 demo-refresh epoch atomicity; F-8 selectable delayed/barrier/stop list; F-11 app-level context-loss recovery; F-12 rendered-scene browser test.

---

## H3 — Formative usability test (protocol [C], sessions [SU], ≈half day of sessions)

The actual HFE conclusion. The advancement plan's acceptance criteria are already testable claims — the protocol operationalizes them.

### H3.1 Protocol document [C]
- [ ] Draft `docs/audits/FLOW-4D-USABILITY-PROTOCOL.md` containing, at minimum:
  - **Participants:** 3–5 per persona — charge nurse (unit lens), house supervisor / RTDC (house lens), executive (aggregate lens via role switcher). Include ≥1 participant with corrected-to-normal color vision NOT verified (real-world mix).
  - **Setup:** prod demo environment, wall display AND desktop, screen + audio recording, think-aloud instructions.
  - **Tasks (scripted, randomized order where independent):**
    1. *Element naming (E-1 claim):* moderator points at 10 elements (token, trail, disk ok/delayed, pip, ghost, pillar, diamond ×2 severities, ring); participant names each. Threshold: ≥8/10 correct, median <5 s.
    2. *Time recovery (N-1 claim):* "scrub back to ~6 hours ago, then return to now." Threshold: return is one action, 100% success.
    3. *Wrong-toggle discrimination (B-1..B-4 claim — the critical one):* task A requires hiding logged barrier markers; task B requires viewing only delayed locations. Count wrong-control activations. Threshold: 0 wrong-toggle errors after first exposure.
    4. *Delayed cluster triage:* "find the worst delayed cluster and tell me why it's delayed" (chip → Focus → disk → inspector evidence). Threshold: reaches timer evidence unaided.
    5. *Rounds round-trip (Phase 3 claim):* board → Locate in 4D → walk two stops with Next → back to board for the same patient. Threshold: completes without moderator help; identity never visible in scene (moderator verifies).
    6. *Floor + search navigation (Phase 2):* "get to floor N framed, then find patient X." Keyboard-only variant for ≥1 participant per persona (H1.2 dependency).
  - **Situation-awareness probes (SAGAT-lite):** 3 display freezes across the session — "how many barriers have been open >24h?", "which unit is most delayed right now?", "is the scene showing now, the past, or a projection?" (mode-confusion probe). Score against ground truth.
  - **Measures:** task success, time-on-task, error counts (esp. wrong-toggle), SUS post-session, per-task confidence rating, SA probe accuracy.
  - **Pass thresholds** stated per task up front; a miss ⇒ a logged design action, not a debate.
- [ ] Pilot the protocol once (self-run) to time it (~30 min/participant target)

### H3.2 Sessions + synthesis
- [ ] [SU] Recruit + run sessions (clinical network)
- [ ] [C] Synthesize: findings table (severity × frequency), map each to a design action or accepted risk; append results to this doc
- [ ] [C] Implement resulting P0/P1 fixes as `feature/flow4d-hfe-usability-fixes`

**Acceptance:** every plan-level HFE claim has empirical pass/fail data from representative users; failures have dispositions.

---

## H4 — Long-session & urgency field verification (≈1 day, mostly unattended) [C, wall hardware SU]

Code-verified ≠ field-verified. Prod is a 6 h-refresh demo wall — test across that boundary.

> **Scripts DRAFTED 2026-07-19** (`scripts/soak-flow4d.mjs`, `scripts/urgency-census-flow4d.mjs`,
> shared `scripts/lib/flow4d-field.mjs`). Credentials via `FLOW4D_USERNAME`/`FLOW4D_PASSWORD`
> env only. Both feature-detect the in-app `window.__FLOW4D_SOAK__` debug hook (rendererInfo /
> nowDeltaMs / roundsRun) — hook MERGED + DEPLOYED 2026-07-19 (PR #41, prod `056e568`,
> live-chunk verified). Runs remain pending wall hardware + a provisioned soak account.

### H4.1 24-hour soak
- [x] Script `scripts/soak-flow4d.mjs` (Playwright, headed Chromium on the wall box or equivalent): load navigator, authenticate, then every 30 min capture — JS heap size, `renderer.info.memory` (geometries/textures) + `renderer.info.render.calls` via an exposed debug hook, now-marker wall-clock delta, rounds HUD run uuid/status, screenshot
- [ ] Assertions: heap growth <15% between hour 2 and hour 24 (post-warmup); geometry/texture counts flat (±rounds content); now-marker drift <90 s always; rounds run turns over across the demo-refresh boundary without a reload; zero uncaught exceptions in console log
- [ ] Run once across a demo-refresh cycle; file any failure as a bug with the captured evidence

### H4.2 Urgency census (earned-urgency verification on real data)
- [x] Script: poll `/api/patient-flow/occupancy` + `/barriers` every 30 min for 24 h; record distribution of ok/watch/delayed disks and barrier severities (`scripts/urgency-census-flow4d.mjs`; barriers carry category not severity — categories recorded)
- [ ] Compute time-weighted share of coral and amber elements on screen. Guideline thresholds (tune after baseline): coral <10% of visible status elements in steady state, amber <25%. Exceedance ⇒ either seed-data tuning (`zephyrus:demo-seed` pressure knobs) or threshold review — coral must stay *earned*
- [ ] Record baseline + verdict in this doc

**Acceptance:** measured evidence that the navigator neither leaks memory over a shift-plus, drifts its clock, orphans its rounds overlay, nor debases its urgency palette on ordinary data.

---

## H5 — Discoverability & institutionalization (≈1 day) `feature/flow4d-onboarding` [C]

### H5.1 First-run guided tour — **SHIPPED 2026-07-19** (PR #42, merge `c8116d8`, deployed `fd2d6e6`, live-chunk verified)
The legend solves *reference* ("what is this?"); nothing yet solves *discovery* ("what can I do?").
- [x] 5-stop tour: ① Census scope + chip, ② chronobar Now + jump ticks, ③ Key legend, ④ floor rail + `?` keymap, ⑤ Rounds HUD/tour (stop renders only when a run is loaded). *Plan correction: react-joyride was never actually a Zephyrus dependency — hand-rolled coach-mark (`NavigatorIntro.tsx` + `introTour.ts`) in the sanctioned overlay style instead; no new dependency.* Temporal copy fixed per audit F-4 ("past 24 h / projected next 24", "present" not "live")
- [x] Persona-keyed one-time dismissal: `flow4d.tour.{role}` in localStorage (savedViews guarded pattern); re-launchable from the shortcut sheet ("Replay intro"); Escape dismisses through the same one-time path
- [x] Styling within canon: overlay family, gold-tint notice ring (deliberately outside the status-color space), 24 px buttons, gold `:focus-visible`; copy in operator language
- [x] Tests (+11): renders/advances/dismisses, rounds-stop gating, shrink-clamp, and storage-blocked degrades to **never auto-start** — a kiosk wall on the 6 h demo refresh must not loop the welcome card

### H5.2 HFE decisions register + guard map
- [x] Append an **HFE Decisions** section to the advancement plan doc: one row per doctrine — earned urgency, never-color-alone, wrong-toggle separation, explicit camera actions, identity-free scene payloads, follow-mode time slide, 24px targets — each with its rationale and its *named automated guard* (hue-clamp property test, vocabulary parity test, rounds coral-ban test, toolbar census-scope tests, hover-label identity exclusion, canon script) — **§12 of the advancement plan, 2026-07-19** (+ selection-entity row; follow-mode's guard is honestly the H4 soak drift assertion, not a unit test)
- [x] Add the one missing guard: a unit test asserting the hover-label builder never emits `patient_display_id`/`patient_id`/`encounter_id` for any input (currently enforced by construction, not pinned) — `tests/js/patientFlow/hoverLabel.test.ts` (hoverLabelFor extracted to sceneVocabulary; rides PR #41 with the `__FLOW4D_SOAK__` hook)
- [ ] DEVLOG entry closing the HFE program; update project memory — *interim devlog covering all [C]-owned closure work shipped 2026-07-19 ([DEVLOG-flow4d-hfe-closure-2026-07-19.md](../devlog/DEVLOG-flow4d-hfe-closure-2026-07-19.md)); the closing entry lands once H2/H3/H4 runs/H5.1 complete*

**Acceptance:** a new contributor can read one section and know both *why* each invariant exists and *what breaks in CI* if they violate it.

---

## Appendix — supporting engineering (not HFE-blocking, schedule opportunistically)

- [ ] Consolidate the three hand-rolled polls onto TanStack Query (`useRoundScene` beside `useRoundBoard`); uniform stale-on-error + visibility gating
- [ ] Shared `useNow(intervalMs)` hook (third ticker in the codebase: CommandCenter, FrozenSectionTimer, navigator)
- [ ] React.memo + handler hoisting for NavigatorToolbar/Legend/FloorRail
- [ ] Replace the scoped toast with the app-wide react-hot-toast `<Toaster>`
- [ ] Advancement-plan Phase 4 backlog: Reverb push, Eddy-narrated tour, unit billboards w/ LOD, mobile accordion, bed-capability lens

## Sequencing

H1 → H2 (audit against the fixed surface) → fold H2 fixes → H3 protocol draft in parallel → H3 sessions → H3 fixes → H5. H4 runs unattended any time after H1 deploys; its urgency-census baseline is also useful *input* to H3 session scheduling. Each coding phase is an independently shippable PR with the standard gate: vitest + tsc + vite build + canon + CI + `./deploy.sh --frontend`.
