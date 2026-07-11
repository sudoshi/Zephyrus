# Virtual Rounds, 4D Viewer, and Eddy — Implementation TODO

**Plan:** [2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md](./2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md)
**Started:** 2026-07-11
**Convention:** Check items off as they land. Items marked `(deferred)` need vendor
credentials, clinical sign-off, or EHR change control and cannot be completed
in-repo; they stay unchecked with a note.

Legend: `[x]` done · `[ ]` open · `[~]` partially done (note inline)

---

## Phase 0 — Discovery, safety, and contracts

Organizational, not code. Tracked here so the gate is visible; these need the
named human owners and cannot be checked off by engineering alone.

- [ ] Name clinical / operational / privacy-security / integration / pilot-unit owners `(deferred — human sign-off)`
- [ ] Observe current rounds on 2 units + 1 service-line review `(deferred)`
- [ ] Define role sections, exception policy, discharge-ready definition, priority reason codes, consent rules `(engineering default shipped in config/rounds.php + RoundTemplateSeeder; clinical approval pending)`
- [ ] Inventory EHR/FHIR/nurse-platform/interpreter/telehealth capabilities and sandboxes `(deferred)`
- [ ] Threat model, data-flow diagram, downtime workflow, clinical safety case `(deferred)`
- [ ] Record baseline metrics `(deferred)`

## Phase 1 — Rounds kernel and unit board

### 1.1 Schema + models
- [x] `rounds` PG schema migration via SafeMigration (additive, local-only destructive rollback) — `2026_07_11_000100_create_virtual_rounds_tables.php`
- [x] `rounds.templates` (versioned policy: scope_types, mode, required_roles, completion_policy, priority_policy, eta_policy)
- [x] `rounds.runs` (status FSM, scope, queue_version, source_cutoff_at)
- [x] `rounds.patients` (encounter_ref target + prod_encounter_id bridge, location/service-line snapshot, status FSM, priority + ETA fields, unique per encounter/run)
- [x] `rounds.participants` (role slots, required flag, status, waivers)
- [x] `rounds.contributions` (immutable after submit, supersedes chain, allowlisted section_code, partial uniques: one submitted + one draft per author/role/section/patient)
- [x] `rounds.questions`
- [x] `rounds.tasks` (bridge to ops action via ops_action_uuid)
- [x] `rounds.events` (append-only audit stream; partial unique on idempotency_key = the idempotency ledger)
- [x] Eloquent models `app/Models/Rounds/*` (schema-qualified tables, uuid business keys, casts, FSM transition maps on models)
- [ ] Later-phase tables (attendance, contact_preferences, notifications, interpreter_requests, consult_requests, evaluations, tours, outbox) — created in their phases, not up front

### 1.2 Config + flags
- [x] `config/rounds.php`: feature flags (VIRTUAL_ROUNDS_ENABLED, …_FAMILY/_WRITEBACK/_EDDY/_EXTERNAL_NOTIFICATIONS), roles + unit_role_map, section allowlist, priority bands/weights, ETA + completion defaults
- [x] Flags gate routes + UI (`EnsureRoundsEnabled` → 404 when off, on both /api/rounds and the web page route; no data loss)

### 1.3 Domain services
- [x] `RoundAuthorizationService` (broad-access roles × prod.user_unit assignment × pivot lead roles; assert* helpers; 403s carry no identifiers)
- [x] `RoundCohortBuilder` (active prod census at one cutoff, flow_core bridge w/ `prodenc:` fallback, dedupe, inclusion/exclusion reasons, role slots + auto-assignment, reconciliation suggestions)
- [x] `RoundCommandService` (run FSM draft→…→completed/cancelled; row locks + expected versions + Idempotency-Key replay + audit events in-transaction; broadcasts after commit; completion exception path)
- [x] `RoundContributionService` (draft→submitted→superseded/withdrawn; drafts mutable in place; submit freezes; supersession in one transaction; allowlist validation; participant satisfaction; queued→in_progress nudge)
- [x] `RoundCompletionService` (hard/soft roles, sections, waivers, freshness staleness, block_on_open_tasks; run-completion gating w/ blocking list)
- [x] `RoundQueueService` (6 deterministic bands w/ reason objects {code, band, weight, value, source, observed_at, explanation}; stable bed-label fallback; settled patients sink)
- [x] `RoundEtaService` (default + complexity + unresolved-input components, cumulative windows + uncertainty buffer, notify damping threshold)
- [x] `RoundProjectionService` (lens-clamped board/scene/patient-detail with {version, generated_at, source_cutoff_at, scope, lens} envelope; scene = opaque tokens only)
- [x] Round patient FSM (queued→in_progress→awaiting_input→ready_for_review→rounded; deferred/skipped w/ mandatory reason; rounded→in_progress reopen)

### 1.4 API
- [x] Authorization: RoundAuthorizationService (idiomatic here — no Policy/Gate precedent in repo; colon gates skipped deliberately)
- [x] Routes under `/api/rounds` (['web','auth','throttle:60,1'] + EnsureRoundsEnabled): templates, scopes, runs CRUD+lifecycle, board, scene, queue PATCH, patient detail, contributions (+submit/withdraw), questions (+resolve), tasks (+transition), mark-ready/complete/reopen/defer/skip/pin, cohort/reconcile
- [x] FormRequests for every mutation (`app/Http/Requests/Rounds/*`)
- [x] `Idempotency-Key` header + expected-version checks; 409 carries `error.code=rounds_conflict` + current board projection
- [x] Consistent `{data, meta:{version, generated_at, source_cutoff_at, scope, lens}}` envelope

### 1.5 Events + realtime
- [x] `RoundRunUpdated`, `RoundPatientUpdated`, `RoundQueueUpdated` reload-ping events (opaque IDs + version only, PHI-free, public Channel per channels.php doctrine)
- [x] Channel payloads PHI-free by construction; patient-level private channels deferred until a payload ever carries more than IDs

### 1.6 Seed + demo
- [x] `RoundTemplateSeeder` (Unit Multidisciplinary Round + Discharge Focus Round; idempotent, uuid preserved on reseed; wired into DatabaseSeeder)
- [x] Synthetic round generator: `php artisan rounds:seed-demo {unit?}` (busiest-unit default, reuses today's open run, seeds nursing notes)

### 1.7 Unit board frontend
- [x] `resources/js/features/virtualRounds/{api,schemas,types,hooks}.ts` (axios + Zod boundary parse + TanStack Query; Idempotency-Key on every mutation)
- [x] `Pages/RTDC/VirtualRounds.tsx` + `/rtdc/virtual-rounds` route (flag-gated) + RTDC nav entry (Stethoscope icon)
- [x] `RoundsCommandBar` (unit/template/run selects, start-today's-run, lifecycle buttons w/ complete confirm, census freshness)
- [x] `RoundsBoard` (dense queue table: position, pin, status chip w/ icon+label, priority band + reason tooltip, ETA window, needs column)
- [x] Queue mutations: pin/unpin w/ mandatory reason + expected_queue_version; 409 recovery installs the server's `current` projection into the cache and explains (reorder DnD UI deferred — API + hook shipped)
- [x] `RoundPatientWorkspace` (status transitions incl. exception-reason prompts, priority "Why this position", requirements checklist, contributions w/ supersession history)
- [x] `ContributionComposer` (server-driven section/field allowlist rendering, enum selects, draft vs submit)
- [x] `ParticipantRail` (role slots + status)
- [x] Design-canon compliance (healthcare-* tokens + dark pairs, Surface treatment, tabular-nums, icons+labels never color alone, coral unused — no round state is a breach; `check-ui-canon.sh` passes)

### 1.8 Phase 1 gate tests
- [x] Feature: run/patient/contribution FSMs incl. invalid transitions + exception paths (RoundRunLifecycleTest, RoundCompletionTest, RoundContributionTest)
- [x] Feature: cohort build from active census, dedupe, reconcile-suggestion-not-rewrite (RoundRunLifecycleTest)
- [x] Feature: priority bands deterministic ordering, pin w/ mandatory reason, reason serialization (RoundQueueTest)
- [x] Unit: ETA components/sums/overrides + damping threshold (RoundEtaServiceTest)
- [x] Feature: completion policy — exactly-which-requirement missing, hard vs soft, exception reasons (RoundCompletionTest)
- [x] Feature: auth on all endpoints; outsider 403 leaks no patient identifiers; flag off = 404; unauthenticated 401
- [x] Feature: two roles contribute w/o lost updates; duplicate Idempotency-Key replay (create + reorder); stale-version 409 w/ current projection
- [x] Feature: schema + partial unique indexes enforced at the DB layer (RoundsSchemaTest)
- [x] Frontend: Zod rejects malformed projections (missing version, bad status, bad lens), accepts aggregate-redacted rows, parses 409 body (tests/js/virtualRounds/schemas.test.ts)
- [x] Validation: rounds backend 39 passed/209 assertions + 4 ETA unit tests; full backend suite 735 passed (only 2 pre-existing failures — EddyKnowledgeRagTest pgvector + RouteSmokeTest /up/demo 503, both reproduce with all changes stashed); vitest 303/303; `npm run build` clean; pint clean; check-ui-canon.sh passes

## Phase 2 — 4D rounds overlay

- [x] Scene projection endpoint (`GET /runs/{run}/scene`, opaque tokens only for every lens, same queue_version envelope as the board — backend-tested)
- [x] Rounds layer in `NavigatorScene` (`rebuildRounds`): flat torus ring per stop — shape distinct from every other layer; state → cool-tone color (coral never used); pinned = amber + scale; discharge/missing flags in userData for the inspector; stops stack per anchor like barriers; shared geometry/material caches + disposal
- [x] Pure placement module `features/virtualRounds/roundsScene.ts` (bed-anchor first, unit-centroid fallback, floor filter, unplaceable drop) — unit-tested GPU-free (6 tests)
- [x] Overlay wiring in the Navigator orchestrator: separate fetch effect (most recent open run → scene stops; flag off/no run/failure = empty overlay, navigator never degrades); `Rounds` layer toggle appears only when stops exist; bucketed rebuilds preserve camera + filters; ring click → inspector via existing raycast/redaction path
- [x] Guided-focus API: `focusRoundStop(uuid|null)` — highlight + fly-to, non-flying re-apply across rebuilds (the hook for the Phase 6 Eddy tour runner)
- [~] `Flow | Rounds` segmented mode + run/scope selectors on the Navigator page `(shipped as a layer toggle consistent with the toolbar architecture; a full segmented mode is UX follow-up — the board page is the primary operational surface per plan §14)`
- [ ] Guided itinerary auto-advance UI (tour stepper; labeled "guided tour", never "route") — `focusRoundStop` + ordered stops are ready; stepper UI pending
- [x] WebGL-unavailable fallback = the full board page exists independently of the 3D view
- [x] Gate: scene/board version parity + no patient identifier in scene payload (RoundProjectionTest); canvas nonblank pixel checks remain covered by existing navigator e2e; rounds board e2e added (`tests/e2e/virtual-rounds.spec.ts`, skips when flag off)

## Phase 3 — Async multidisciplinary workflow

- [ ] Role-specific contribution schema allowlist per section_code (config-driven)
- [ ] Required-role resolution from care team + staffing assignments
- [ ] Consultant question/invite workflow (`consult_requests` table, bounded access grant, respond endpoint)
- [ ] Cross-shift handoff: reopen/supersede, stale-input warnings, conflict display
- [ ] Service-line scope in FlowLensService + service-line cohort (dedupe by encounter across units, aggregate progress)
- [ ] Canonical patient-round record projected into unit + service-line views (no duplicate documentation)
- [ ] Department scope design doc only; enabled only behind governed master `(deferred — no department master exists)`
- [ ] Gate tests: no double documentation across scopes; waivers audited; consultant sees only invited patient/question

## Phase 4 — Staff notifications and hybrid timing

- [ ] `rounds.notifications` delivery ledger + notification/invite FSM (pending→queued→sent→delivered→acknowledged; failed→retrying→dead_letter)
- [ ] `RoundNotificationService` + outbox pattern; dedupe by event/recipient/channel/queue-version; damping thresholds; quiet periods
- [ ] `RoundNotificationChannel` connector interface + capability/health reporting
- [ ] Hummingbird APNs routing via active assignments (reuse PushNotifier); Android FCM `(deferred — needs FCM credentials)`
- [ ] `rounds.attendance` live/hybrid segments + timer state
- [ ] Connector capability/health admin view
- [ ] Gate tests: PHI-free payloads, dedupe under repeated queue updates, revoked device receives nothing, sub-threshold ETA shift doesn't notify

## Phase 5 — Family, interpreter, telehealth pilot

- [ ] `rounds.contact_preferences` (consent snapshot, tokenized recipient, no plaintext contact in logs)
- [ ] Guest surface: `GET/POST /rounds/guest/{single_use_token}` (RSVP, questions; opaque rotating token, expiry, rate limit, revocation, access audit)
- [ ] `rounds.interpreter_requests` lifecycle + `InterpreterServiceConnector` interface (pilot vendor `(deferred)`)
- [ ] `TelehealthConnector` interface + join-link lifecycle (pilot vendor `(deferred)`)
- [ ] Family timing notifications (PHI-free SMS copy + secure link) `(deferred — needs SMS vendor + consent policy)`
- [ ] Gate tests: expired/revoked token reveals nothing; guest cannot reach unit queue or other patients; consent withdrawal stops messages

## Phase 6 — Eddy rounds tour (shadow mode)

- [ ] `rounds.tours` (actor, scope, lens snapshot, manifest hash, cursor, cutoff, policy/model versions, stop reason) + `rounds.evaluations` (partial unique: one active per patient/cutoff/policy)
- [ ] `EddyRoundTourService` + `EddyRoundTourJob` (idempotent, checkpointed cursor, resume-after-retry without duplicate evaluations, cancellation token)
- [ ] Per-patient bounded context tool (one patient at a time, reauthorize each step, no cross-patient carryover)
- [ ] Structured evaluation output validator (evidence refs required, ungrounded output rejected/marked, abstention support)
- [ ] Tour API: start (privileged, visible scope+count), get, pause/resume/stop; `POST /evaluations/{id}/decision`
- [ ] `EddyRoundTourProgressed` PHI-free progress event; 4D camera sync (backend authoritative)
- [ ] `EddyActionService::CATALOG` + `propose_round_task`, `request_round_input`, `flag_round_conflict` (draft-only, existing approval FSM)
- [ ] `EddyEvaluationReview` + `EddyTourControls` frontend; bulk reject allowed, bulk accept restricted
- [ ] Auto-pause on access revocation / discharge / stale cutoff / tool failure / validation failure
- [ ] Evaluation harness: synthetic cases w/ missing, conflicting, stale data + access changes
- [ ] Gate tests: no cross-patient/scope leakage under adversarial tests; retry never duplicates; every assertion has evidence or abstains; no state change without human action

## Phase 7 — EHR writeback and production pilot

- [ ] `integration.outbox_messages` transactional outbox + reconciliation job + replay tooling
- [ ] Connector contracts: `RoundCensusSource`, `RoundCareTeamSource`, `RoundReadinessSource`, `RoundEhrWriteback`, `RelatedPersonConsentSource`
- [ ] One deployment writeback adapter `(deferred — needs EHR sandbox + change control)`
- [ ] Kill switch stops outbound immediately; mismatch dashboard
- [ ] Gate tests: duplicate/timeout/partial-failure/replay in sandbox

## Phase 8 — Scale and optimization (ongoing, all deferred)

- [ ] Learned ETA model (shadow first) `(deferred — needs local observations)`
- [ ] Learned priority suggestions `(deferred — fairness/safety review first)`
- [ ] CAD-derived walkable routing `(deferred)`
- [ ] Materialized aggregates + retention partitioning `(deferred — scale-dependent)`

---

## Session log

- 2026-07-11 — Plan examined; TODO created; substrate exploration and Phase 1 implementation begun.
- 2026-07-11 — **Phase 1 complete**: `rounds` schema (8 tables, partial-unique idempotency ledger), 8 models w/ FSM maps, `config/rounds.php` + 5 env flags (`.env.example` documented), `EnsureRoundsEnabled` (404-when-off), 8 domain services (authorization / cohort / command / contribution / completion / queue / ETA / projection), 3 PHI-free reload-ping events, 7 controllers + 9 FormRequests under `/api/rounds`, `RoundTemplateSeeder` + `rounds:seed-demo`, `/rtdc/virtual-rounds` board page (feature module + 5 components) + nav entry. 43 backend tests (219 asserts) + 13 vitest; full suite green except 2 pre-existing failures (verified by stashing all changes: EddyKnowledgeRagTest pgvector, RouteSmokeTest /up/demo 503).
- 2026-07-11 — **Phase 2 core complete**: scene stops schema + pure placement module (bed→unit anchor fallback), `rebuildRounds` torus-ring layer + `focusRoundStop` guided-focus API in NavigatorScene, orchestrator overlay wiring (auto-selected open run, degradation-safe fetch, conditional layer toggle). Remaining in Phase 2: tour stepper UI + full `Flow | Rounds` segmented mode (UX follow-up). Build, vitest (303+13), pint, check-ui-canon all pass.
