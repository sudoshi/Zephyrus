# DEVLOG — 4D Navigator HFE Closure Program (2026-07-19)

**Branches/PRs:** #40 `feature/flow4d-hfe-gaps` (H1 perceptual/access gap fixes, merge `6410303`), #41 `feature/flow4d-soak-hook` (soak diagnostics hook + hover-label identity guard, merge `056e568`). Both merged to main with full CI green (15/15 checks each) and deployed to prod via `./deploy.sh --frontend` — first at `4ce1c7d`, currently at `056e568`.
**Plan:** [FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md](../plans/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md) — checklists annotated in place per item.
**Predecessor:** [DEVLOG-flow4d-advancement-2026-07-18.md](./DEVLOG-flow4d-advancement-2026-07-18.md) (Phases 0–3 + 20-finding deep-review remediation, deployed at `2066e5c`).

## Why this program exists

Everything shipped in the advancement phases was validated *heuristically* — plan audits, multi-angle self-review, the impeccable design hook, canon tests. Heuristic evaluation grades its own interpretation of the plan; it cannot produce a defensible human-factors conclusion. The closure plan structures the remaining distance in five phases:

- **H1** — fix the *known* perceptual/access defects first, so downstream evaluators don't burn time rediscovering them
- **H2** — independent expert audit (Codex, primed with PRODUCT.md/DESIGN.md doctrine)
- **H3** — formative usability test with representative users against the plan's own acceptance claims
- **H4** — long-session and urgency field verification on the real prod wall
- **H5** — discoverability (guided tour) + institutionalization (decisions register with named CI guards)

Ownership is split **[C]** (Claude-executable) / **[SU]** (clinical network, wall hardware, judgment). This devlog closes out every [C]-owned item that could run without user input. What shipped:

## H1 — Perceptual & access gap fixes (PR #40, `5265c1c`)

Three workstreams, 543 insertions across 12 files, +7 vitest (577→584).

### H1.1 CVD-safe delayed cue
The census-disk ok/delayed axis was green-vs-coral — a red-green discrimination that deuteranopic/protanopic users largely cannot make. The legend and hover chip were compensating controls, but the disk is the at-a-glance signal.

- Delayed disks now carry a **warning-triangle billboard sprite** above the disk (coral fill, dark exclamation) — echoing the board's `AlertTriangle`, colliding with neither torus=rounds nor diamond=barriers. Sprite material cached once; the existing `isSprite` dispose guard covers it.
- `sceneVocabulary.ts` gained a "Delayed marker" entry under *Occupancy & timers*; the legend renders it automatically (SSOT holds).
- CVD verification is a **pinned test, not a one-off screenshot**: `tests/js/patientFlow/cvdPalette.test.ts` simulates deuteranopia (Machado 2009 matrices, severity 1.0) and asserts the green/coral pair *collapses* (>50% discriminability loss) — documenting in CI *why* the shape cue is load-bearing. If a future palette change makes the pair CVD-safe, the test flags that the assumption changed; if someone removes the triangle, the vocabulary parity test fails. A 5-minute manual DevTools vision-deficiency eyeball on prod remains as an [SU] spot-check.

### H1.2 Non-pointer selection path (keyboard / AT)
Selection previously required a canvas raycast — pointer-only, invisible to assistive tech.

- The toolbar's Find now renders up to **8 search matches as real buttons**; labels honor the lens (display id on full dots, location otherwise).
- **Feed rows are selectable** through the same path (plain rows under aggregate lenses).
- A shared `patientTokenInspectorData()` builder (`features/patientFlowNavigator/inspector.ts`) guarantees scene userData and list selection present *identical* inspector payloads — no second code path to drift.
- Scene-side `selectEntity({kind:'patient', id})` applies the standard highlight; `F` then flies to it. Flight happens on the explicit keystroke, never on select — consistent with the "explicit camera actions" doctrine.

### H1.3 Single selection entity
Click, tour step, deep link, search list, and feed row previously each half-owned selection state.

- One `SelectionEntity {kind: patient|occupancy|barrier|round-stop, id}` record, living in NavigatorScene (required so highlights re-apply across bucket rebuilds), with **all writes flowing through two APIs** (`selectEntity`, pointerdown). Orchestrator-driven; ghosts/base meshes remain mesh-only selections by design.
- Registries (`tokenByPatient` / `heatMeshByLocation` / `barrierMeshById` / `roundStopMeshByUuid`) let the highlight re-resolve after `rebuildHeat`/`rebuildBarriers`/`rebuildRounds`.
- `F` flies to the current selection; Escape clears entity + visual + inspector + action link in one code path. A tour step **is** a selection — panel, highlight, `F`, and Escape all agree; the ring keeps its dedicated focus-pulse material.

### H1 exit
584 vitest, `tsc --noEmit`, `vite build`, `check-ui-canon.sh` all clean → PR #40 → 15/15 CI → merge `6410303` → deploy `4ce1c7d` → live chunk verified over HTTPS ("Delayed marker" + "Search matches" present in the served navigator chunk).

## H3.1 — Formative usability protocol (`b426258`)

`docs/audits/FLOW-4D-USABILITY-PROTOCOL.md` (95 lines) operationalizes the advancement plan's acceptance criteria as testable claims: 3–5 participants per persona (charge nurse / house supervisor / executive via role switcher), 7 scripted tasks — element naming (≥8/10, median <5 s), time recovery (one action), the **wrong-toggle discrimination pair** (hide barriers vs. delayed-only census; 0 wrong-toggle errors after first exposure — the critical task), delayed-cluster triage to timer evidence, rounds round-trip with identity-leak verification, floor + search navigation with a keyboard-only variant — plus SAGAT-lite situation-awareness freezes (including the live/replay/projection mode-confusion probe), SUS, per-task confidence, and pre-stated pass thresholds where a miss is a *logged design action, not a debate*. Sessions themselves are [SU]-gated on the clinical network.

## H4 — Field-verification tooling (`4ce1c7d` scripts, PR #41 hook)

Code-verified ≠ field-verified: prod is a 6-hour-refresh demo wall, and the long-session claims (no leak, no clock drift, rounds overlay survives the refresh boundary) had never been measured across it.

### The scripts (main, not yet run — runs need wall hardware + a provisioned soak account)
- **`scripts/soak-flow4d.mjs`** — 24 h Playwright soak: loads the navigator once, *never reloads*, and every 30 min captures JS heap, renderer memory/draw counters, now-marker wall-clock delta, rounds HUD run uuid/status, and a screenshot; logs page errors and WebGL context loss. `evaluateRun` asserts: heap growth <15% post-warmup (hour 2 → hour 24), now-drift <90 s always, zero uncaught exceptions, no context loss. A session bounce → re-login is recorded as evidence, and rounds-run turnover across the demo-refresh boundary is captured in the samples. Exit 1 on failure.
- **`scripts/urgency-census-flow4d.mjs`** — earned-urgency verification: polls `/api/patient-flow/occupancy` + `/barriers` every 30 min for 24 h, records status and barrier distributions, and computes the time-weighted share of coral and amber elements (uniform sampling ⇒ mean of per-sample shares). Guideline thresholds: coral <10%, amber <25% in steady state. First run is a baseline (verdict advisory; `FLOW4D_STRICT=1` makes exceedance fatal). One spec correction discovered while wiring it: **barriers carry `category`, not severity** — categories are what get recorded.
- **`scripts/lib/flow4d-field.mjs`** — shared config/login. Credentials come **only** from `FLOW4D_USERNAME`/`FLOW4D_PASSWORD` env vars (the module throws if unset — never hardcoded), and a `must_change_password` redirect aborts with an instruction to use a fully provisioned soak account. `/soak-output/` is gitignored.

### The in-app hook (PR #41, `2f3164e`)
The soak assertions need renderer internals no external script can reach. `resources/js/features/patientFlowNavigator/soakHook.ts` exposes `window.__FLOW4D_SOAK__` with three pull-based getters reading refs — `rendererInfo()` (geometries/textures/draw calls/triangles via a new `NavigatorScene.debugInfo()`), `nowDeltaMs()` (wall clock vs. scene now-marker; `null` outside follow mode), `roundsRun()` (`{uuid, status}` of the loaded run). `installSoakHook()` returns an uninstaller that removes only its own installation, so a stale React cleanup can't clobber a newer mount. Zero behavior change for users; the scripts feature-detect the hook and degrade gracefully — which is also why the scripts could land *before* the hook without forcing a CI restart on the then-open PR #40.

## H5.2 — HFE Decisions Register + the last missing guard

### The register (`b133aba`, §12 of the advancement plan)
Nine doctrine rows, each **doctrine → rationale → named automated guard**: earned urgency (roundsScene coral-ban test), never color alone (sceneVocabulary parity + cvdPalette collapse tests), identity hue clamp 160°–280° (property test), identity-free scene payloads (`hoverLabel.test.ts` + `RoundProjectionTest.php`), wrong-toggle separation (NavigatorToolbar census-scope tests), explicit camera actions (toolbar/chronobar tests), follow-mode time slide, 24 px touch targets (chronobar tests + `check-ui-canon.sh`), selection-is-one-entity. The register is deliberately honest where automation runs out: follow-mode's guard is the H4 soak drift assertion, *not* a unit test — stated in the row rather than papered over. Acceptance met: a new contributor can read one section and know both why each invariant exists and what breaks in CI if they violate it.

### The identity guard (rode PR #41)
The hover chip was identity-free *by construction* (an inline closure that only ever read location-ish fields) but not *by guard* — nothing would fail if a future edit added `patient_display_id`. Fix: the closure was extracted to `hoverLabelFor()` in `sceneVocabulary.ts`, and `tests/js/patientFlow/hoverLabel.test.ts` sweeps identity-field sentinels (`patient_display_id`/`patient_id`/`encounter_id`) across **every** element kind and GLB category in the vocabulary, asserting none ever surfaces — plus positive-shape cases and the name-equals-element collapse. The hover chip is now identity-free by construction *and* by guard, which is what the register row cites.

## Verification

- **PR #40:** 584 vitest (577→584), tsc + vite build + canon clean, 15/15 CI, merged `6410303`, deployed `4ce1c7d`, served chunk verified over HTTPS with the H1 feature markers.
- **PR #41:** 593 vitest (+9: hoverLabel + soakHook suites), tsc + vite build + canon clean (raw-palette ratchet ≤76 held), 15/15 CI, merged `056e568`, deployed `056e568`, `__FLOW4D_SOAK__` confirmed ×3 in the live-served navigator chunk.
- Docs commits (`10507f5` plan, `b426258` protocol, `06913d0`/`6b13011` exit annotations, `b133aba` register) all on main; prod == `056e568`.

## Process notes

- `./deploy.sh --frontend` enforces a fully **clean working tree**, not just `main == origin/main` — the H4 scripts had to be committed before the H1 deploy could run. Correct behavior for an immutable-snapshot deploy; worth remembering when docs annotations are pending.
- Ordering discipline held: exit checkboxes were ticked only *after* the deploy was live-verified (served-chunk grep over HTTPS), never on merge.
- The feature-detect pattern (scripts tolerate a missing hook) decoupled two PRs that would otherwise have serialized on a 75-minute CI backend shard.
- The impeccable design hook repeatedly flagged ~549 pre-existing sanctioned overlay palette values in `PatientFlowNavigator.css`; deliberately left untouched — they are the documented overlay exception, and recoloring them to silence a hook would itself violate canon.

## Program state & what remains

| Phase | State | Gate |
|-------|-------|------|
| H1 gap fixes | **DONE** — merged, deployed, live-verified | [SU] 5-min DevTools vision-deficiency spot-check on prod |
| H2 independent audit | Not started | [SU] triggers Codex; [C] then triages fix-now / test-in-H3 / rejected-with-rationale into `docs/audits/` |
| H3 usability test | Protocol on main | [SU] recruits + runs sessions; [C] synthesis + `feature/flow4d-hfe-usability-fixes` |
| H4 soak + urgency census | Scripts + hook **shipped & deployed**; runs pending | [SU] wall box session + provisioned soak account (`FLOW4D_USERNAME`/`FLOW4D_PASSWORD`) |
| H5.1 guided tour | Not started — deliberately waits for H3 findings | blocked by H3 |
| H5.2 register + guards | **DONE** — §12 + identity guard deployed | — |

Every [C]-owned item that can run without user input is complete. The program closes fully once H2's findings are dispositioned, H3's sessions produce empirical pass/fail data per claim, H4's 24-hour runs record their baseline + verdict in the closure plan, and the H5.1 tour ships against what H3 actually reveals about discoverability.
