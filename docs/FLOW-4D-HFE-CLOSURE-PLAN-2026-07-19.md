# Patient Flow 4D Navigator — HFE Closure Plan

**Date:** 2026-07-19
**Status:** TODO — none started
**Predecessor:** [FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md](./FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md) (Phases 0–3 merged + deployed at `2066e5c`, incl. 20-finding deep-review remediation `a90abff`).
**Purpose:** everything shipped so far is validated by *heuristic* evaluation (plan audit, multi-angle self-review, design hook, canon tests). This plan closes the gap to a defensible human-factors conclusion: fix the known perceptual/access defects, obtain an independent audit, validate empirically with representative users, prove the long-session claims in the field, and institutionalize the invariants.

Owners: **[C]** = Claude-executable, **[SU]** = requires Dr. Udoshi (clinical network / hardware / judgment).

---

## H1 — Close the known perceptual & access gaps (≈1 day) `feature/flow4d-hfe-gaps`

Do these BEFORE the audit and usability test so evaluators don't burn time rediscovering known defects.

### H1.1 CVD-safe delayed cue on census disks [C]
The ok/delayed disk axis is green-vs-coral — a red-green discrimination. Legend + hover chip are compensating controls, but the disk is the at-a-glance signal.
- [ ] Add a shape cue for `delayed` disks: small warning-triangle billboard sprite above the disk (echoes the lucide `AlertTriangle` already used in the Rounds board "Needs" column — consistent cross-surface grammar; does NOT collide with the torus=rounds or diamond=barriers shapes)
- [ ] Sprite material cached once (mirror `queueSpriteMaterialFor`); coral fill on the dark chip background, `isSprite` dispose guard already in place
- [ ] `sceneVocabulary.ts`: new entry under *Occupancy & timers* ("Delayed marker — triangle appears when any timer is past target"), legend renders it automatically
- [ ] Run a deuteranopia + protanopia simulation pass over a live screenshot (Chrome DevTools → Rendering → Emulate vision deficiencies); record the result and remaining residuals in this doc
- [ ] Tests: vocabulary parity (new key), rebuildHeat marker presence is scene-side — pin the *decision* in a pure helper if extracted

**Acceptance:** a deuteranope can separate ok / delayed disks by shape alone at overview zoom.

### H1.2 Non-pointer selection path (keyboard / AT) [C]
Today the inspector is only populated by canvas pointer clicks or tour steps — keyboard-only users can filter and fly but never select. The toolbar/inspector are canonically "the non-3D equivalent of scene state"; make that claim true.
- [ ] Search-result list: when `filters.search` is non-empty, render up to 8 matches as buttons under the Find field (label = redacted display id or location — same redaction rules as the inspector); click/Enter selects
- [ ] Feed rows become selectable (same path)
- [ ] Scene: `selectPatientToken(patientId): boolean` — resolves via the existing `tokenByPatient` registry, applies the standard selection highlight, returns false if not visible
- [ ] Orchestrator: selecting from list = populate inspector from state data (no raycast needed) + scene highlight + optional `focusSelection`-style flight on second activation
- [ ] Tests: toolbar match-list render + selection callback; redaction of list labels under `dots: none` lens

**Acceptance:** with the mouse unplugged, an operator can find, select, inspect, and fly to a patient.

### H1.3 Single selection entity [C]
Click-highlight, tour focus, and inspector state are three uncoordinated stores; "what is selected" can have three answers, and `F`/Escape act on different ones. This is a system-model consistency defect, not code cleanup.
- [ ] Orchestrator-owned `selection: { kind: 'patient'|'occupancy'|'timer'|'ghost'|'barrier'|'round-stop'|'base', id: string } | null`
- [ ] Every entry point writes through it: canvas click, tour step, deep link, H1.2 list selection
- [ ] Scene consumes it: one `setSelection(kind, id)` API resolving against mesh registries (`tokenByPatient`, `roundStopMeshByUuid`; add lightweight registries for heat/barrier meshes keyed by location/barrier_id during rebuild); highlight re-applies across rebuilds while the entity still exists
- [ ] `F` flies to the current selection; Escape clears it everywhere (scene highlight + inspector + action link) — one code path
- [ ] Round-stop focus (tour) becomes a *view* of the same selection rather than a parallel mechanism where feasible; keep the dedicated ring-pulse material
- [ ] Tests: selection survives a heavy-layer rebuild; Escape clears all three surfaces; tour step and click produce identical inspector output for the same stop

**Acceptance:** at any moment there is exactly one answer to "what is selected", and panel, highlight, `F`, and Escape all agree with it.

### H1 exit
- [ ] `npx tsc --noEmit`, `npx vite build`, `scripts/check-ui-canon.sh`, full vitest
- [ ] PR → CI → merge → `./deploy.sh --frontend`

---

## H2 — Independent expert audit (≈half day) [SU trigger, C triage]

Self-review has a structural blind spot: it grades its own interpretation of the plan.
- [ ] Run a Codex audit pass over `/rtdc/patient-flow-navigator` (workflow precedent: the 2026-07-17 UX/HFE audit)
- [ ] Prime the auditor with: PRODUCT.md personas + anti-references, DESIGN.md Two-System/earned-urgency/never-color-alone doctrines, the advancement plan §10 guardrails, and explicit prompts for: wrong-toggle risk, alarm fatigue, mode confusion (live/replay/historical/tour), interruption recovery, and the mixed-persona wall-display context
- [ ] [C] Triage findings into: fix-now (fold into H1 branch or a follow-up), test-in-H3 (ambiguous — let users decide), rejected-with-rationale (documented here)
- [ ] Record the full audit output in `docs/audits/`

**Acceptance:** every audit finding has a disposition in writing.

---

## H3 — Formative usability test (protocol [C], sessions [SU], ≈half day of sessions)

The actual HFE conclusion. The advancement plan's acceptance criteria are already testable claims — the protocol operationalizes them.

### H3.1 Protocol document [C]
- [ ] Draft `docs/FLOW-4D-USABILITY-PROTOCOL.md` containing, at minimum:
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

### H4.1 24-hour soak
- [ ] Script `scripts/soak-flow4d.mjs` (Playwright, headed Chromium on the wall box or equivalent): load navigator, authenticate, then every 30 min capture — JS heap size, `renderer.info.memory` (geometries/textures) + `renderer.info.render.calls` via an exposed debug hook, now-marker wall-clock delta, rounds HUD run uuid/status, screenshot
- [ ] Assertions: heap growth <15% between hour 2 and hour 24 (post-warmup); geometry/texture counts flat (±rounds content); now-marker drift <90 s always; rounds run turns over across the demo-refresh boundary without a reload; zero uncaught exceptions in console log
- [ ] Run once across a demo-refresh cycle; file any failure as a bug with the captured evidence

### H4.2 Urgency census (earned-urgency verification on real data)
- [ ] Script: poll `/api/patient-flow/occupancy` + `/barriers` every 30 min for 24 h; record distribution of ok/watch/delayed disks and barrier severities
- [ ] Compute time-weighted share of coral and amber elements on screen. Guideline thresholds (tune after baseline): coral <10% of visible status elements in steady state, amber <25%. Exceedance ⇒ either seed-data tuning (`zephyrus:demo-seed` pressure knobs) or threshold review — coral must stay *earned*
- [ ] Record baseline + verdict in this doc

**Acceptance:** measured evidence that the navigator neither leaks memory over a shift-plus, drifts its clock, orphans its rounds overlay, nor debases its urgency palette on ordinary data.

---

## H5 — Discoverability & institutionalization (≈1 day) `feature/flow4d-onboarding` [C]

### H5.1 First-run guided tour
The legend solves *reference* ("what is this?"); nothing yet solves *discovery* ("what can I do?").
- [ ] 5-stop react-joyride tour (already a dependency; note `--legacy-peer-deps`): ① Census scope + chip, ② chronobar Now + jump ticks, ③ Key legend, ④ floor rail + `?` keymap, ⑤ Rounds HUD/tour (step renders only when a run is loaded)
- [ ] Persona-keyed one-time dismissal: `flow4d.tour.{role}` in localStorage (same guarded-read pattern as saved views); re-launchable from the shortcut sheet ("Replay intro")
- [ ] Tour styling within canon (surface tokens, gold focus); copy in operator language, no jargon
- [ ] Tests: renders once, dismissal persists, re-launch works, storage-blocked degrades silently

### H5.2 HFE decisions register + guard map
- [ ] Append an **HFE Decisions** section to the advancement plan doc: one row per doctrine — earned urgency, never-color-alone, wrong-toggle separation, explicit camera actions, identity-free scene payloads, follow-mode time slide, 24px targets — each with its rationale and its *named automated guard* (hue-clamp property test, vocabulary parity test, rounds coral-ban test, toolbar census-scope tests, hover-label identity exclusion, canon script)
- [ ] Add the one missing guard: a unit test asserting the hover-label builder never emits `patient_display_id`/`patient_id`/`encounter_id` for any input (currently enforced by construction, not pinned)
- [ ] DEVLOG entry closing the HFE program; update project memory

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
