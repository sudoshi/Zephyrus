# Patient Flow 4D Navigator — Independent HFE Audit (Codex), 2026-07-19

**Program:** HFE closure plan H2 ([FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md](../FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md))
**Auditor:** OpenAI Codex CLI 0.144.6, read-only sandbox over the repository at main `63b874a` (+ PR #42 intro tour sources), zero involvement in the audited implementation.
**Priming:** PRODUCT.md personas/anti-references, DESIGN.md doctrines (Two-System, earned urgency, never-color-alone), advancement plan §10 guardrails + §12 register, and seven explicit lenses: wrong-toggle, alarm fatigue, mode confusion, interruption recovery, mixed-persona wall display, perception/AT, long-session correctness.
**Verification:** every finding's file:line evidence was independently spot-checked before triage (see dispositions). All 12 findings verified factually accurate.

Triage categories per the closure plan: **fix-now** (shipped or on a branch immediately), **test-in-H3** (ambiguous — the usability sessions decide), **ruling-needed** (changes clinical semantics or a deliberate prior decision — Dr. Udoshi disposition required), **follow-up** (real engineering, queued).

## Dispositions

| # | Sev | Finding | Disposition |
|---|-----|---------|-------------|
| F-1 | High | Role switcher and enforced flow lens are two independent systems (DashboardLayout renders RoleSwitcher above a scene bound to the server lens) | **Ruling-needed.** Verified real. Recommended: the switch performs a server persona transition on this page (or the switcher is disabled/annotated here). Architectural — touches CommandCenter store, Inertia flow, and every lensed request; not implemented unilaterally. |
| F-2 | High | Rounds stops disclose bed-level context under aggregate lenses (`rounds: true` for every dots policy; redactor spares `bed`) | **Ruling-needed.** Verified. The "opaque-uuid-only" doctrine is honored by the letter, but bed+queue+status is re-identifiable at ward level. Recommended: `patient_dots = none` → unit-centroid rounds, no bed hover/inspector deep link. Changes the executive demo surface — CMIO sign-off first. |
| F-3 | High | Ordinary 18h stays are classified `delayed` (unverified/inferred) and can cascade up to six coral objects per location | **Split.** Census-script half **fixed now** (urgency census counts rendered coral objects, not disks alone — PR #43). Classification half is **ruling-needed** (inferred duration-only breach: delayed vs. watch is clinical semantics), informed by the H4 baseline. |
| F-4 | High | Temporal copy wrong in the new intro; present-state barrier/rounds overlays render inside historical/projected scenes unlabeled | **Split.** Copy **fixed now** (PR #42 `f76b03f`: "past 24 hours / projected next 24", "present" not "live"). Overlay labeling is **test-in-H3** — the SAGAT "is this now?" probe measures exactly this; the sketched design action is a persistent layer-specific caption ("Current barriers over historical scene"). |
| F-5 | High | Now does not stop playback/tour or restore an operational baseline | **Test-in-H3.** Protocol task T2 (time recovery) measures this directly; a miss converts to the logged design action (single "Present view" reset: stop playback/replay/tour, follow now, clear selection+scope, house camera). Deliberately not pre-empted — the protocol's own philosophy is empirical pass/fail, not debate. |
| F-6 | High | 6h demo refresh creates mixed temporal epochs (bootstrap-only datasets never rebase; stale rounds rings can persist) | **Follow-up (queued).** Verified. Recommended: refresh-version identifier exposed by the API, atomic client rebootstrap on change, clear `roundStops` when the server reports no open run. Also sharpens H4 soak interpretation across the refresh boundary. |
| F-7 | Medium | Watch/timer/rounds states still color-only at glance distance (triangle covers only ok-vs-delayed disks) | **Test-in-H3.** The element-naming task with a real-world CVD mix decides whether glance-distance discrimination of *non-critical* states carries operational weight; the counter-risk (glyph clutter) is itself an HFE cost. CVD test extension to further pairs rides the outcome. |
| F-8 | Medium | Non-pointer parity covers patients only; icon buttons rely on `title` | **Split.** `aria-label`s **fixed now** (PR #43). The HTML list of delayed locations/barriers/stops routed through `SelectionEntity` is **follow-up** (queued — medium effort, high AT value). |
| F-9 | Medium | Hard-coded dark island, cyan focus ring in the toolbar, no reduced-motion branch | **Split → RULED 2026-07-19 [SU].** Focus ring cyan→gold **fixed now** (PR #43 — canon alignment inside the same file that already uses gold everywhere else). Dr. Udoshi ruling: the navigator is a **dark-only wall instrument by design** (sanctioned exception to dual-theme) and keeps **full motion** — light theme and `prefers-reduced-motion` are **rejected-with-rationale**, not queued. |
| F-10 | Medium | Now button/layer switches/floor buttons miss the 24px floor; headline metrics lack `tabular-nums` | **Fixed now** (PR #43): 24px minimums, metrics `tabular-nums`, plus a bonus caught during verification — `.patient-flow-metrics span` used `font-weight: 700` (faux-bold; 700 is not loaded) → 600. |
| F-11 | Medium | WebGL context loss detectable by the soak but unrecoverable by the app; soak listener lost after re-login | **Split.** Soak listener reattach after re-login **fixed now** (PR #43). App-level `webglcontextlost` degraded state + reconstruction is **follow-up** (queued). |
| F-12 | Obs | Decisions register overstates CI guards: `check-ui-canon.sh` was never wired into CI; no rendered-scene browser test | **Fixed now** (PR #43): canon script added to the CI frontend job; register §12 wording corrected (field guards labeled as field guards). The Navigator browser test for selection-across-rebuild is **follow-up**. |

**Lens verdict on the audit itself:** wrong-toggle came back clean (the Phase 0 separation held under independent review), and the audit explicitly verified that the named §12 guards (CVD collapse, identity sentinels, coral ban, census-scope tests) really do run in CI. The audit's headline — "not yet safe to treat as an unambiguous, unattended shared-wall picture" — stands until F-1/F-2 are ruled, F-5's empirical answer arrives, and F-6 ships.

---

# Verbatim audit output

## Summary

The Navigator is not yet safe to treat as an unambiguous, unattended shared-wall picture: four High-severity findings affect persona enforcement, identity minimization, alarm salience, and temporal truth. The authors successfully separated Census `All | Delayed` from the Barriers layer, and the chronobar labels stored replay, history, and projection more honestly than many comparable surfaces. However, the visible role control is disconnected from the server lens, bed-specific rounds data survives aggregate redaction, inferred long stays can multiply coral signals, and present-state overlays appear inside historical and projected scenes. The documented H3/H4 field work remains necessary; several claimed guards are either incomplete or not actually wired into CI.

## Findings

### F-1 — The visible role switcher and the enforced patient-flow lens are two independent systems

**Severity:** High  
**Lens:** 5 — Mixed-persona wall-display context

**Evidence:** The shared chrome offers Command and Executive through `resources/js/Components/CommandCenter/RoleSwitcher.tsx:11-18`, and changing it only updates a Zustand value plus client-side `?role=` through `resources/js/Components/CommandCenter/RoleSwitcher.tsx:63-70` and `resources/js/stores/commandCenterStore.ts:18-40`. Patient Flow instead resolves `?persona=` or `X-Hummingbird-Role` in `app/Services/Mobile/MobilePersonaCatalog.php:49-61` and passes that result at `app/Http/Controllers/RTDCDashboardController.php:47-56`. The toolbar does display the resolved lens at `resources/js/Components/PatientFlowNavigator/NavigatorToolbar.tsx:131-136`, but Patient Flow never subscribes to the role store. Moreover, events, occupancy, and SSE calls omit the page persona at `resources/js/features/patientFlowNavigator/api.ts:27-65`, `:305-317`, and `:342-348`; only projections propagate it at `:320-332`. This matters because Executive requires `patient_dots = none` at `config/hummingbird/flow_lens.php:222-232`.

**Risk:** A shared-screen operator can select Executive while the scene continues using a house-supervisor or charge-nurse lens, leaving patient dots, feed identity, and actionable rounds links visible under an apparently aggregate view. A `?persona=` handoff can also render one lens while subsequent APIs resolve the user’s default persona.

**Recommendation:** Establish one canonical persona state. Make the role switch perform a server/Inertia lens transition using `persona`, propagate persona and scope to every lensed request and SSE connection, and clear scene/feed/inspector state before displaying the new role. If the switch is intentionally Cockpit-only, remove it from the Navigator’s shared context or label that limitation explicitly.

### F-2 — “Opaque” rounds stops disclose bed-level identity under aggregate lenses

**Severity:** High  
**Lens:** 5 — Mixed-persona wall-display context

**Evidence:** The rounds scene contract includes `round_patient_uuid`, `facility_space_id`, and exact `bed` at `app/Services/Rounds/RoundProjectionService.php:129-150`; the contract test explicitly requires `bed` at `tests/Feature/Rounds/RoundProjectionTest.php:47-58`. Placement prefers that bed anchor at `resources/js/features/virtualRounds/roundsScene.ts:37-60`. The scene then copies the bed into raycast data at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:923-933`, while hover text selects `data.bed` at `resources/js/features/patientFlowNavigator/sceneVocabulary.ts:147-153`. The inspector also retains bed and exposes a patient-specific board link at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx:1330-1347`; its redactor removes only named identity fields, not bed or the rounds UUID, at `:181-198`. Rounds are enabled by default for every patient-dots policy at `:160-177`, and the rounds routes use run authorization but no Flow Lens middleware at `routes/api.php:217-236`.

**Risk:** Exact bed plus queue position, round status, discharge readiness, and unit context is readily re-identifiable to staff viewing a shared wall. For a broadly authorized wall account, switching to an aggregate Executive presentation does not reduce that disclosure.

**Recommendation:** For `patient_dots = none`, project rounds only to unit centroids and omit bed, facility-space ID, UUID-based deep links, and bed hover text. Add a server-side aggregate rounds representation and a sentinel test that rejects bed and location identifiers, not only `patient_ref` and `patient_label`.

### F-3 — Ordinary long stays can produce a cascade of unearned coral

**Severity:** High  
**Lens:** 2 — Alarm fatigue / earned urgency

**Evidence:** There are four coral-capable in-scene grammars: delayed census disks, delayed timer pips, delayed triangle sprites, and critical barrier diamonds (`resources/js/features/patientFlowNavigator/sceneVocabulary.ts:24-47`). Including chrome, missing/degraded-source and fatal-load treatments add two more at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.css:79-83` and `:1276-1285`. A delayed location renders a coral disk, triangle, and as many as four coral pips at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:662-760`. Critically, a generic 18-hour stay is classified `delayed` despite having no due time and being explicitly unverified/inferred at `app/Services/PatientFlow/OccupancyInsightProjector.php:452-489`; the feature test pins this as both `primary_status = delayed` and `verified = false` at `tests/Feature/PatientFlow/PatientFlowOperationalBarrierProjectionTest.php:178-206`. The field census records timers and barriers but calculates coral share from disk status alone at `scripts/urgency-census-flow4d.mjs:52-73` and `:110-130`.

**Risk:** One routine inpatient stay over 18 hours produces at least three coral objects—disk, triangle, and stay pip—and can produce six when four timers are delayed. A normal demo census can therefore normalize coral without a verified breach, while the proposed census materially understates what operators actually see.

**Recommendation:** Keep inferred duration risk amber/watch until a validated service-specific target or verified overdue event establishes a breach. Count rendered coral objects—disk, cue, each pip, and barrier severity—in the field census, and make threshold exceedance a mandatory acceptance failure rather than optional `FLOW4D_STRICT`.

### F-4 — Historical and projected scenes silently include present-state barriers and rounds

**Severity:** High  
**Lens:** 3 — Mode confusion

**Evidence:** The orchestrator deliberately renders current open barriers at every scrub position at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx:617-622` and describes rounds as present-state at every scrub position at `:624-635`. The chronobar itself correctly labels Historical replay, Projected, Replay stream, and relative time at `resources/js/Components/PatientFlowNavigator/NavigatorChronobar.tsx:91-103`, but it does not identify those two overlays as temporally different from the rest of the scene. The new introduction compounds the problem by describing the window as “the last 48 hours” and claiming Now returns to “live” at `resources/js/features/patientFlowNavigator/introTour.ts:48-51`, while the implemented fresh window is 24 hours past plus 24 hours future at `resources/js/features/patientFlowNavigator/replayTimeline.ts:72-85`.

**Risk:** During retrospective review, a supervisor can attribute a currently open barrier or current rounds itinerary to a six-hour-old patient state. In the future half, current rings and diamonds can be mistaken for projected work or barriers, corrupting the SAGAT answer to “is this now?”

**Recommendation:** Hide present-only overlays outside a narrow now tolerance, or add a persistent, layer-specific label such as “Current barriers over historical scene.” Correct the tour to say “24h review / 24h projection” and “return to present,” not “last 48 hours” or “live.”

### F-5 — Now does not end playback or restore an operational baseline

**Severity:** High  
**Lens:** 3 — Mode confusion; 4 — Interruption recovery

**Evidence:** The Now button only calls the scrub callback at `resources/js/Components/PatientFlowNavigator/NavigatorChronobar.tsx:105-113`. That callback disconnects stored SSE and changes time, but it does not stop ordinary playback, stop the rounds tour, clear selection, clear filters, restore layers, or reset the camera (`resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx:1135-1145`). If playback is active, the frame loop immediately advances away from Now and eventually wraps to the start of the window at `:1058-1069`. Playback, stored replay, Home, filters, floor, layers, and tour are all separate actions at `:1467-1525`; Auto tour stops at the itinerary end but leaves the final camera and selected bed in place at `:1386-1401`.

**Risk:** An operator returning after 20 minutes can encounter a looping past scene or the final rounds stop. Even clicking Now does not hold the display at the present while playback remains active, making rapid interruption recovery unreliable.

**Recommendation:** Provide one prominent “Present view” action that stops playback, stored replay, and tour; returns to now/follow; clears selection and delayed-only filtering; selects the widest scope allowed by the current lens; and restores the house/default camera and layer set.

### F-6 — The six-hour demo refresh creates mixed temporal epochs without reloading

**Severity:** High  
**Lens:** 4 — Interruption recovery; 7 — Long-session correctness

**Evidence:** Summary, locations, events, ambient data, feed, tracks, and timeline bootstrap only on mount at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx:823-864`. Occupancy, projections, barriers, and rounds subsequently refresh on independent schedules at `:725-760` and `:866-996`. The six-hour refresh shifts all event and encounter timestamps in place at `app/Console/Commands/PatientFlowRebaseSyntheticCommand.php:74-93` and then retires/recreates rounds runs at `app/Services/Demo/DemoRefreshCoordinator.php:71-83`. When no open run is observed, the client marks the prior run completed but does not clear `roundStops` at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx:941-950`.

**Risk:** After refresh, disks, barriers, projections, and rounds can describe the new demo epoch while patient tokens, trails, event counts, source status, and feed retain pre-refresh timestamps. If rounds recreation fails or has a gap, the canceled run’s rings remain displayed as completed work.

**Recommendation:** Expose a refresh/version identifier and atomically rebootstrap all Patient Flow datasets when it changes. Clear rounds stops immediately when the server reports no open run, and display a brief rebuilding state instead of mixing epochs.

### F-7 — Watch, timer, and rounds states still depend on color at scene-glance distance

**Severity:** Medium  
**Lens:** 6 — Perception and accessibility

**Evidence:** The delayed triangle fixes only the delayed disk condition. Ok and watch remain the same disk geometry; disk radius represents stay duration rather than status at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:654-727`. All timer states use the same cylinder geometry and differ through material color at `:729-760` and `:1303-1317`. Rounds statuses all use the same torus, with state carried by color except for the unrelated pinned scale at `resources/js/features/virtualRounds/roundsScene.ts:20-34` and `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:919-944`. The CVD guard tests only the ok-versus-delayed disk pair and triangle at `tests/js/patientFlow/cvdPalette.test.ts:47-76`.

**Risk:** A color-deficient operator—or anyone reading a distant or desaturated wall display—cannot distinguish on-track from watch timers or queued/in-progress/awaiting-input/rounded stops without individually hovering or selecting them.

**Recommendation:** Give watch disks, timer pips, and rounds states a compact non-color encoding such as glyph, notch, stroke pattern, or always-visible abbreviation. Extend CVD/grayscale tests across every status pair, not only ok versus delayed.

### F-8 — Non-pointer action parity covers patients, not the rest of the actionable scene

**Severity:** Medium  
**Lens:** 6 — Perception and accessibility

**Evidence:** Pointer raycasting includes rounds, barriers, patients, ghosts, occupancy, and base-model objects at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:235-275`. The canvas keyboard setup only provides OrbitControls camera movement at `:333-342`. HTML alternatives were added for patient search results and feed rows at `resources/js/Components/PatientFlowNavigator/NavigatorToolbar.tsx:321-330` and `resources/js/Components/PatientFlowNavigator/NavigatorFeed.tsx:43-56`; there is no equivalent list for occupancy disks/timers, barriers, forecasts, or model locations. Play, Home, Focus, and Eddy also rely on `title` rather than explicit accessible labels, unlike the Radio control, at `resources/js/Components/PatientFlowNavigator/NavigatorToolbar.tsx:183-221`.

**Risk:** A keyboard or assistive-technology user cannot complete the clinically central path “select delayed location or barrier and read its evidence,” even though a pointer user can. The H1.2 claim therefore closes patient selection only, not pointer-action equivalence.

**Recommendation:** Add a compact HTML list of visible delayed locations, barriers, and round stops, with buttons routed through the existing `SelectionEntity` API. Give every icon control an explicit `aria-label` and state semantics.

### F-9 — Theme, focus, and motion doctrines are not honored by the Navigator

**Severity:** Medium  
**Lens:** 6 — Perception and accessibility

**Evidence:** The application toggles the root dark class at `resources/js/Layouts/AuthenticatedLayout.tsx:34-47`, but the Navigator shell and overlays are hard-coded dark at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.css:1-27`, and the WebGL background/fog are fixed dark at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:326-328`. Field and icon focus is cyan rather than mandated gold at `PatientFlowNavigator.css:417-422`, while gold is consumed as an ordinary active-button and checked-switch fill at `:375-378` and `:570-573`. No reduced-motion branch exists in the audited surface despite Orbit damping, automatic tour changes, and animated switch transitions at `NavigatorScene.ts:333-356`, `PatientFlowNavigator.tsx:1386-1401`, and `PatientFlowNavigator.css:537-567`. These contradict `DESIGN.md:121-126`, `:143-145`, and `:276-284`.

**Risk:** Light-mode users receive a dark island rather than a supported theme; focus is less distinguishable because gold also means ordinary on/active state; and motion-sensitive users cannot suppress automatic scene changes.

**Recommendation:** Introduce scoped light/dark variables for both DOM and scene background, reserve gold for focus/Now, use operational blue for active controls, and disable damping, Auto tour, and CSS movement under `prefers-reduced-motion`.

### F-10 — Several controls miss the claimed 24px floor, and headline metrics can jitter

**Severity:** Medium  
**Lens:** 6 — Perception and accessibility

**Evidence:** The HFE register claims all toolbar controls are at least 24×24 at `docs/FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md:306`. Chronobar ticks meet that requirement at `resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.css:215-227`, but Now has approximately 19px intrinsic height at `:138-147`, layer switches are 20px high at `:537-545`, and floor buttons have approximately 21px intrinsic height with no minimum at `:1051-1061`. The main four headline metrics omit `font-variant-numeric: tabular-nums` at `:584-603`, although the doctrine requires it for all metrics.

**Risk:** Touch use on a wall display is unnecessarily error-prone, particularly for Now and the dense floor rail. Changing metric widths can visually jitter during live updates and weaken across-room readability.

**Recommendation:** Apply a real `min-width`/`min-height: 24px` to every interactive target and `font-variant-numeric: tabular-nums` to the metric, time, count, and percentage containers.

### F-11 — WebGL context loss is detectable by the soak harness but not recoverable by the application

**Severity:** Medium  
**Lens:** 7 — Long-session correctness

**Evidence:** `NavigatorScene` registers resize and pointer listeners but no `webglcontextlost` or `webglcontextrestored` handling at `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts:378-382`; disposal likewise has no restoration path at `:1146-1195`. The soak harness adds an external loss listener once at `scripts/soak-flow4d.mjs:134-142`, but after reauthentication and navigation at `:150-158` it does not reinstall that listener. Geometry/texture changes and rounds-run turnover are only recorded as notes, never failures, at `:91-101`.

**Risk:** A GPU reset can leave an unattended wall display blank or frozen with no degraded-state message. The soak can also report a passing result despite geometry growth or failure to cross a rounds-run boundary.

**Recommendation:** Handle context loss in the application with a visible degraded state and deterministic scene reconstruction or controlled reload. Reattach soak instrumentation after navigation, and make renderer flatness and expected run turnover actual assertions.

### F-12 — The decisions register overstates which guards break CI

**Severity:** Observation  
**Lens:** 6 — Perception and accessibility; 7 — Long-session correctness

**Evidence:** The register says each named guard “breaks in CI” at `docs/FLOW-4D-NAVIGATOR-ADVANCEMENT-PLAN-2026-07-18.md:291-307`. CI does execute the entire Vitest suite and Vite build at `.github/workflows/ci.yml:242-249`, and all feature tests are sharded through `scripts/ci/run-backend-test-shard.sh:19-47`. However, `scripts/check-ui-canon.sh:1-8` still says “Wire into CI,” and the frontend workflow does not invoke it. Browser CI exists at `.github/workflows/ci.yml:348-429`, but the only Patient Flow references in `tests/e2e` verify a navigation link at `tests/e2e/navigation.spec.ts:46-51` and `:186-195`; no rendered scene or selection-reapply behavior is exercised.

**Risk:** Maintainers may accept changes believing theme/canon and rendered-scene invariants are protected when they are not.

**Recommendation:** Invoke `scripts/check-ui-canon.sh` in CI, add one real Navigator browser test covering selection across a layer rebuild, and label the soak and urgency census explicitly as pending field guards rather than CI guards.

## Lens verdicts

| Lens | Verdict |
|---|---|
| 1. Wrong-toggle risk | **No finding.** Census is a labeled radiogroup at `NavigatorToolbar.tsx:334-365`; Barriers is a separate Layers switch at `:368-382`; delayed filtering produces a visible count, Focus, and clear action at `:384-405`. The behavior is guarded by `NavigatorToolbar.test.tsx:93-149`, which is included in the CI Vitest run. |
| 2. Alarm fatigue / earned urgency | **F-3.** Four in-scene and six total coral-capable treatments exist; inferred 18-hour stays can multiply coral before a verified breach. |
| 3. Mode confusion | **F-4, F-5.** Core chronobar wording is good, but present overlays contaminate past/future scenes and Now does not stop playback. |
| 4. Interruption recovery | **F-5, F-6.** There is no single baseline-restoration action, and demo refresh can leave mixed epochs or old rounds rings. |
| 5. Mixed-persona wall-display context | **F-1, F-2.** Role and server lens can diverge; rounds preserve exact bed-level context under aggregate presentation. |
| 6. Perception and accessibility | **F-7, F-8, F-9, F-10, F-12.** Remaining color-only states, incomplete non-pointer parity, missing reduced-motion/light theme, small targets, and guard gaps remain. |
| 7. Long-session correctness | **F-6, F-11, F-12.** Dataset refresh is non-atomic, WebGL loss is unrecoverable, and the soak does not enforce all claimed assertions. |

## What the authors got right

- The Census-versus-Barriers separation is materially better than the prior wrong-toggle pattern: placement, wording, control type, tooltip, feedback chip, and explicit camera action are all distinct.
- The chronobar consistently says “stored replay,” “historical replay,” and “projected”; it does not falsely call the SSE endpoint live (`NavigatorChronobar.tsx:97-103`, `PatientFlowNavigator.tsx:1124-1132`).
- The delayed triangle is a real non-color correction, barrier severity also changes diamond scale, patient identity hues are clamped away from alarm colors, and the scene vocabulary/legend is centralized.
- Server-side `EnforceFlowLens` genuinely applies lens, scope, depth, and unit/task grants at `app/Http/Middleware/EnforceFlowLens.php:35-80`; F-1 concerns inconsistent client propagation, not absence of server enforcement.
- The wrong-toggle, CVD triangle, identity-hue, hover sentinel, and rounds coral-ban tests are genuinely included in CI through the full Vitest run; the rounds projection contract is included through the sharded PHPUnit workflow.
- Poll timers and visibility listeners generally have clean teardown, and rounds polling intentionally tolerates transient failures before blanking the layer.
- The closure plan correctly states that representative-user sessions and the 24-hour field runs remain pending at `docs/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md:76-105`; the code should not be treated as human-factors validated until those runs are completed.

