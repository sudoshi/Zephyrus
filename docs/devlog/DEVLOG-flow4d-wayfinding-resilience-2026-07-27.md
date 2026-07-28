# DEVLOG — Flow-4D Phases E (wayfinding) + F (resilience)

**Date:** 2026-07-27
**PRs:** #107 (Phase E), #108 (Phase F) — both squash-merged
**Deployed:** prod at `17dd51c6` via `./deploy.sh --frontend` (login 200 + fresh
`NavigatorScene`/`PatientFlowNavigator` chunks verified serving)
**Plan:** `docs/plans/FLOW-4D-PATIENT-JOURNEY-AND-CONFORMANCE-PLAN-2026-07-26.md`

Sixth and seventh phases of the journey/conformance program. Both are
navigator-side only (the dark-only wall instrument, HFE F-9) — no backend,
schema, or flag changes. Built on A/B/C already on prod.

## Phase E — Wayfinding & 2D structure (PR #107)

- **E5 URL view state + TN-6 presets.** `viewState.ts` serializes
  camera/floor/layers/time/census/window/selection to URL params, pinned by a
  seeded 200-view round-trip. A link can *request* but the lens/flags still
  decide what renders. A patient travels only as the opaque `patient=ptok`;
  `sel=` carries aggregate kinds only. Copy-view-link in the toolbar +
  copy-as-link on saved-view slots. Chronobar window presets (48h/24h/6h/Shift);
  historical sources keep their extent window.
- **E2 van Wijk flight + canonical views + SDF labels.** `cameraFlight.ts` is
  the pure van Wijk & Nuij optimal pan/zoom path (ρ*=1.42, 8 property tests);
  `focusOn`/`flyTo`/`flyToHome`/`focusTopDown` fly the arc and operator input
  cancels it. Top/House/Floor/Bed canonical framings. SDF unit-name billboards
  via `troika-three-text` (new dep, local ambient `.d.ts`) — per-frame billboard
  + LOD scale + distance cull, tied to the Model layer.
- **E3 ortho mode (`O`) + structure traversal.** Perspective/ortho camera swap
  beneath OrbitControls — raycast/flight/billboards all read the active camera;
  ortho is locked plan view (pan+zoom); Home/bookmarks/links restore the iso
  perspective. `structureTree.ts` (pure floor→unit→bed) + `NavigatorStructureNav`
  (WAI-ARIA tree, roving tabindex, arrow-key graph) selecting through the shared
  `selectEntity` seam.
- **E1 minimap.** `minimapProjection.ts` (pure world-XZ ↔ minimap round-trip) +
  `NavigatorMinimap` SVG plate — location dots, delay/deviation pips
  (shape-coded, CVD-safe), camera footprint, selection ring; click flies to the
  point. Polls camera+selection from the scene so the ~7 Hz camera stream never
  re-renders the orchestrator. Suppressed on `?wall=1`.
- **E4 flatten + small multiples.** `trailHeat.ts` (density grids + hourly
  slices) + `NavigatorSmallMultiples` — six hourly density plates (the analysis
  path replacing forced replay) + SVG export + an on-floor GSTC flatten (transient
  scene layer). Density is opacity on one cool ink, never a status color.
- **E6 task modes.** Monitor / Investigate / Rounds progressive disclosure of the
  ballooned toolbar (WN-6). Monitor (default) is the resting layout minus the
  investigation kit; Investigate adds Speed/Find/saved-views + the small-multiples
  card; Rounds foregrounds the walk and steps back the census rollup.

## Phase F — Resilience & scale (PR #108)

- **F5 rendered-scene browser test (landed before F2).** jsdom can't run WebGL,
  so the scene lifecycle is only testable in a real browser:
  `tests/e2e/patient-flow-navigator.spec.ts` covers boot, **GPU context-loss
  self-heal (F1)**, frame-budget accumulation (F4), and a layer rebuild keeping
  the renderer alive. Graceful `test.skip` when CI lacks WebGL or flow data (the
  E2E seed carries no patient-flow data — the model boots into an empty data
  layer); auto-strengthens when an E2E flow seed lands.
- **F1 GPU context-loss recovery.** `webglcontextlost` preventDefault + halt the
  loop + amber "Graphics paused" card; `webglcontextrestored` → resume + a full
  layer rebuild from state. Soak hook counts losses/restores.
- **F4 frame-budget instrumentation.** Per-subsystem p95 ring buffers
  (`performance.now` deltas) exposed on the soak hook for the H4 RAIL budget.
- **F2(b) aggregate-trails guard.** Above 50 concurrent trails the per-patient
  spaghetti collapses to unit→unit flow arrows with counts; the selected patient
  keeps their own trail (§6.2). Pure `flowAggregation.ts` tally (5 tests).

### Deferred — F2(a) instancing + F3 deltas

The InstancedMesh migration is a perf refactor for a token scale the demo
(~40 patients) doesn't reach — deferring aligns with "no premature optimization."
Critically, F5's **selection-survival** check can't execute in CI without an E2E
flow seed, so merging a large token-rendering refactor whose one real safety net
can't run would be an unguarded change to a wall instrument. Recommendation:
land F2(a)+F3 as a focused follow-up **together with an E2E flow seed** so F5
verifies instanced selection/highlight through rebuilds in CI. F3's `?since=`
web-delta path is coupled to the instance-buffer rebuild strategy and rides along.

## Verification

- `tsc --noEmit` clean; `vite build` clean (troika bundles into the lazy
  `NavigatorScene` chunk).
- patientFlow vitest: **221 passed** across both phases.
- `scripts/check-ui-canon.sh` passed (raw-palette ratchet ≤ 76).
- `playwright --list` resolves the 4 rendered-scene tests.
- Prod deploy verified: login 200 + new build chunks serving.

## Notes / gotchas

- **Concurrent-session shared-checkout hazard** recurred: a second session kept
  flipping the deploy-staging checkout between `main` and a feature branch
  mid-edit. All of E-later + F was done in an isolated worktree
  (`~/Github/Zephyrus-wf`, `ln -s ../Zephyrus/node_modules`), committing each
  sub-step. Because E was squash-merged, F was rebased with
  `git rebase --onto origin/main <E-tip>` to carry only its own commits.
- The deploy staging checkout (`/home/smudoshi/Github/Zephyrus`, the ssh target's
  canonical checkout) had been left on the merged feature branch; `deploy.sh`
  requires it on `main`/clean, so it was switched back before the release.
- beastmode grep treats the arrow-heavy `NavigatorScene.ts` as binary (locale) —
  use `grep -a`.

## Remaining

Phase D (DRG catalog bridge, clinical-gated), Phase G (H3/H4 field validation),
and the F2(a)/F3 instancing follow-up (with the E2E flow seed).
