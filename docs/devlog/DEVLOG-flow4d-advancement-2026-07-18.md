# DEVLOG — 4D Navigator Advancement, all phases (2026-07-18)

**Branches/PRs:** #38 `feature/flow4d-phase0-truth` (Phase 0), #39 `feature/flow4d-rounds-integration` (Phases 1–3 stacked + review remediation). Both merged to main; deployed to prod at `2066e5c` via `./deploy.sh --frontend`.
**Plan:** [FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md](../plans/FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md) — per-phase checklists annotated BUILT in place.

## What shipped

- **Phase 0 — truth & quick wins:** "Find barriers" → `Census: All | Delayed` scope control with filter chip + explicit Focus (checkbox auto-fly removed); chronobar Now button + clickable shift detents/barrier ticks; place-context camera readout; "Stream stored replay" labeling; `nowMs` 60 s refresh with follow-mode window slide; barriers/projections polling (120 s / 5 min, visibility-gated).
- **Phase 1 — element identity:** `sceneVocabulary.ts` SSOT (materials + legend render from one module); GLB category materials from glTF extras (bed/corridor/ED/imaging/elevator legible); `patientHue` clamp 160–280°; collapsible Key legend; hover chip + persistent selection highlight, Escape clears.
- **Phase 2 — navigability:** floor stepper rail with fit-to-floor; search Enter fly-to + match count; H/F/N/? keymap + shortcut sheet; 3 persona-keyed saved views (`flow4d.views.{role}`).
- **Phase 3 — Virtual Rounds:** `?focus_stop` deep link wires the formerly dead `focusRoundStop()`; Locate-in-4D on board rows/workspace and Open-in-Rounds-board from the ring inspector; Rounds HUD with Prev/Next/Auto tour (uuid-anchored); queue-number sprites + itinerary polyline (dashed cross-floor); 30 s scene polling with content-hash gating. All payloads stay opaque-uuid-only.

## Deep-review remediation (`a90abff`)

Max-effort multi-angle self-review before merge; 20 confirmed findings fixed. Highest-impact: rounds polling now survives run completion (wall displays pick up the next run; 5 consecutive failures bound staleness); transient poll failures no longer blank live barrier/forecast overlays; shared `THREE.Sprite` singleton geometry no longer disposed per rebuild; tour anchored by uuid; hover/selection/focus material clones can no longer stomp each other; Escape gained the typing-context guard; localStorage hardened for kiosk mode; chronobar jump targets enlarged to 24×24 (WCAG 2.2); rounds layer rebuilds off the minute bucket; hover raycast allocation-free.

## Deferred (candidates for a follow-up / Phase 4)

- Consolidate the three hand-rolled polls onto TanStack Query (`useRoundScene` beside `useRoundBoard`); shared `useNow()` hook (third ticker in the codebase).
- React.memo pass on toolbar/legend/rail + handler hoisting; single selection-entity model (click/tour/deep-link all writing one store); react-hot-toast reuse instead of the scoped toast.
- Plan Phase 4 items proper: Reverb push, Eddy tour, unit billboards, mobile accordion, capability lens.

## Verification

577→578 vitest, PHPUnit rounds contract guard extended (queue_position/unit_id/bed/pinned), tsc + vite build + check-ui-canon clean, both PRs full CI green (15 checks each), deployed chunk served over HTTPS with feature markers confirmed.
