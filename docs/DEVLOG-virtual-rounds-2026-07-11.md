# DEVLOG — Virtual Rounds (Phases 1–2) — 2026-07-11

**Status:** Phase 1 (rounds kernel + unit board) complete and verified. Phase 2
(4D rounds overlay) core complete. Phases 3–8 open — this document is the
handoff for whoever picks them up.

**Companion documents:**

- Plan: [docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md](./superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-implementation-plan.md) — the authoritative spec (§ references below point here)
- Working TODO: [docs/superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-TODO.md](./superpowers/plans/2026-07-11-virtual-rounds-4d-eddy-TODO.md) — per-item checkboxes + session log; **keep checking items off there as you land work**

---

## 1. What this feature is

Virtual Rounds adds asynchronous/hybrid multidisciplinary rounds as an
additive clinical-coordination domain: a shared, explainably-prioritized
patient queue per unit run, role-attributed immutable contributions, a
completion policy engine, and a 4D Navigator overlay. Q-Rounds is the product
benchmark, not a data model to copy. Three coordinated surfaces: the **Rounds
Board** (primary, dense, board-first), the **4D overlay** (peer view, never
required), and later the **Eddy tour** (draft-only, human-governed — Phase 6).

Core invariants the code enforces (do not weaken):

- A round is a durable, versioned workflow; every clinical statement keeps
  author, role, timestamp, and supersession history.
- Submitted contributions are immutable; corrections insert a superseding row.
- `structured_data` is allowlisted per `section_code` (config/rounds.php) —
  arbitrary blobs must never become a shadow medical record.
- Queue priority is deterministic and explainable; every reason is
  reproducible from the signal snapshot stored on the patient row.
- Patient access is enforced server-side; the scene projection carries opaque
  tokens only, for every lens; 403s never leak identifiers.
- Broadcast payloads are PHI-free reload pings (opaque IDs + versions).
- Flags off ⇒ 404 (invisible), and existing audit data is never deleted.

---

## 2. What shipped

### 2.1 Database — `rounds` schema

Migration: `database/migrations/2026_07_11_000100_create_virtual_rounds_tables.php`
(raw-SQL idiom + `SafeMigration` trait; destructive rollback local-only).

| Table | Notes |
| --- | --- |
| `rounds.templates` | Versioned policy: `required_roles` jsonb (`[{role_code, sections[], requirement: hard\|soft}]`), `completion_policy`, `priority_policy`, `eta_policy`, `scope_types text[]`. Unique (name, version). |
| `rounds.runs` | Run FSM + `queue_version` (optimistic-concurrency token) + `source_cutoff_at` (census snapshot moment) + `completion_exception` jsonb. |
| `rounds.patients` | One row per encounter per run (unique `(run_id, encounter_ref)`). Snapshot columns (`snapshot_unit_id/_bed/...`) freeze enrollment-time truth. `priority_reasons` jsonb + `metadata.signals` = the reproducibility record. Per-row `version`. |
| `rounds.participants` | Run-level role slots (per-patient slots supported via nullable `round_patient_id`); waivers carry `waived_by`/`waiver_reason`. |
| `rounds.contributions` | Partial uniques: ONE `submitted` and ONE `draft` per (patient, author, role, section). `supersedes_id` chain. |
| `rounds.questions` / `rounds.tasks` | Tasks carry `ops_action_uuid` — the bridge into the governed ops lifecycle (link only; ops owns state after promotion). |
| `rounds.events` | Append-only audit stream. **Partial unique on `idempotency_key` = the idempotency ledger** (see §3.2). |

Later-phase tables (attendance, contact_preferences, notifications,
interpreter_requests, consult_requests, evaluations, tours, outbox) are
deliberately NOT created yet — create them in their phases.

### 2.2 Backend domain (`app/Services/Rounds/`)

| Service | Responsibility |
| --- | --- |
| `RoundAuthorizationService` | Broad-access roles (`config rounds.broad_access_roles` + Spatie admin) × `prod.user_unit` assignment × pivot lead roles (`charge`/`manager`) × run creator. `assert*` throw `AuthorizationException` → framework 403. `contributorRoleFor()` maps pivot role → contributor role via `rounds.unit_role_map`. |
| `RoundCohortBuilder` | Active `prod.encounters` on the unit at one cutoff → `rounds.patients` with snapshots, signals, priority, duration; role slots + auto-assignment; `suggestReconciliation()` computes add/remove deltas (suggestions only — never silent rewrites). |
| `RoundCommandService` | THE transactional mutation hub: run lifecycle, patient transitions, pin/unpin, queue reorder, reconciliation apply. Row lock on run → idempotency replay check → expected-version check → mutate → `RoundEvent::record` in-transaction → broadcast after commit. |
| `RoundContributionService` | draft(mutable, updated in place) → submit(freezes; supersedes prior inside one transaction) → withdraw. Section/field/enum allowlist validation. Marks participant slot `contributed`; nudges patient `queued → in_progress` on first submission. |
| `RoundCompletionService` | `evaluatePatient` → `{satisfied, missing[{role,section,requirement}], stale, waived, open_task_count}` — always says WHICH requirement is missing. `evaluateRun` → blocking patient list. |
| `RoundQueueService` | Six deterministic bands (1 pinned / 2 time-critical / 3 discharge-ready / 4 coordination / 5 missing-input / 6 routine). Each reason: `{code, band, weight, value, source, observed_at, explanation}`. Stable bed-label fallback ordering; settled patients sink. |
| `RoundEtaService` | duration = default + acuity complexity + unresolved-input (+ coordination); cumulative windows + uncertainty buffer; `shouldNotify()` damping (for Phase 4). |
| `RoundProjectionService` | Lens-clamped `board` / `scene` / `patientDetail`, all in the `{data, meta:{version, generated_at, source_cutoff_at, scope, lens}}` envelope. `lens: 'detail'\|'aggregate'`; aggregate rows have null identifiers + counts only. Scene = opaque tokens for everyone. |

Exceptions → HTTP mapping (in `Api/Rounds/RoundsController::guard`):
`RoundConflictException`/`RoundTransitionException` → **409** (+ `current`
board projection for recovery), `RoundPolicyException` → **422**,
`AuthorizationException` → framework 403.

### 2.3 API — `/api/rounds` (routes/api.php, above the RTDC group)

Middleware: `['web','auth','throttle:60,1', EnsureRoundsEnabled::class]`.
Routes are exactly the plan §10.1 set (minus family/interpreter/consult/Eddy —
their phases), plus `defer`/`skip`/`pin` on patients and
`questions/{q}/resolve`, `tasks/{t}/transition`. URLs use opaque UUIDs.
Mutations accept an `Idempotency-Key` header; queue ops require
`expected_queue_version`; patient transitions accept `expected_version`.
`GET /templates` additionally returns `meta.sections` + `meta.roles` — the
config-owned allowlist the client renders from (never hardcode it client-side).

### 2.4 Frontend

- Feature module `resources/js/features/virtualRounds/`: `api.ts` (axios,
  auto-UUID Idempotency-Key), `schemas.ts` (Zod, incl. `conflictResponseSchema`
  and `sceneResponseSchema`), `types.ts`, `hooks.ts` (TanStack Query;
  everything invalidates the `['rounds']` tree), `roundsScene.ts` (pure
  placement: bed-anchor first, unit-centroid fallback, floor filter).
- Page `resources/js/Pages/RTDC/VirtualRounds.tsx` (route
  `/rtdc/virtual-rounds`, controller `RTDCDashboardController::virtualRounds`,
  nav entry in `navigationConfig.ts` RTDC domain). Boundary `safeParse`
  everywhere; malformed payloads degrade to inline cards. On 409 the server's
  `current` board is installed into the query cache and the user is told to
  retry.
- Components `resources/js/Components/VirtualRounds/`: `format.ts` (status/band
  token maps — single source), `RoundsCommandBar`, `RoundsBoard`,
  `RoundPatientWorkspace`, `ContributionComposer`, `ParticipantRail`.
- 4D overlay: `NavigatorScene.rebuildRounds()` (torus rings; state → cool
  tones, coral never; pinned = amber + 1.25× scale; stacks per anchor) +
  `focusRoundStop(uuid|null)` (highlight + fly; re-applies without flying
  across rebuilds). Orchestrator (`PatientFlowNavigator.tsx`) fetches the most
  recent open run's scene in its own effect — flag off / no run / failure ⇒
  empty overlay, navigator untouched; the `Rounds` layer toggle only appears
  when stops exist.

### 2.5 Seeds, demo, flags

- `RoundTemplateSeeder` (in `DatabaseSeeder` chain): “Unit Multidisciplinary
  Round” + “Discharge Focus Round”. Idempotent by (name, version);
  **template_uuid is minted once and never rotated on reseed**.
- `php artisan rounds:seed-demo {unit?}` — creates + starts today's run on the
  busiest unit (or given unit), submits demo nursing notes; reuses an existing
  open run rather than duplicating.
- Flags in `config/rounds.php`, documented in `.env.example`:
  `VIRTUAL_ROUNDS_ENABLED` (Phase 1–2), `_FAMILY_` (P5), `_WRITEBACK_` (P7),
  `_EDDY_` (P6), `_EXTERNAL_NOTIFICATIONS_` (P4–5). All default false.

### 2.6 Tests

- `tests/Feature/Rounds/` — 5 files, 39 tests: schema + partial uniques,
  lifecycle FSM + exception paths, cohort/reconcile, contributions
  (allowlist, immutability, supersession, concurrent roles), queue (bands,
  pin, 409-with-projection, idempotent replay), projections (envelope,
  aggregate redaction, scene opacity, auth matrix incl. outsider-403-leaks-
  nothing and flag-off-404).
- `tests/Unit/Rounds/RoundEtaServiceTest.php` — 4 tests.
- `tests/Support/SeedsRoundsStory.php` — the shared story trait (unit, beds,
  3 encounters incl. discharge-ready + high-acuity, charge/bedside/attending/
  outsider/admin users, template). It sets `config(['rounds.enabled'=>true])`.
- `tests/js/virtualRounds/` — Zod boundary tests + pure scene-placement tests.
- `tests/e2e/virtual-rounds.spec.ts` — Playwright; **skips (not fails)** when
  the flag is off.

---

## 3. Decisions and deviations from the plan (read before extending)

1. **Round target ref is soft.** Plan §6.1 implied an FK to
   `flow_core.encounters`. Reality: the operational census lives in
   `prod.encounters` (unit_id/bed_id/acuity/EDD) and the flow spine is not
   guaranteed to be populated. `rounds.patients.encounter_ref` is soft text —
   the bridged flow ref when `flow_core.encounters.prod_encounter_id` matches,
   else deterministic `prodenc:{encounter_id}` — plus a `prod_encounter_id`
   bigint for operational joins. Cohort building works on any deployment.
2. **Idempotency lives in `rounds.events`,** not an HTTP middleware. No
   header-based idempotency existed in the repo to reuse. Commands check for
   an event with the key inside the transaction (replay ⇒ return current state
   without re-executing); the partial unique index converts a concurrent race
   into a `QueryException` that `RoundCommandService::execute()` maps to a
   409. If you add new commands, follow this exact shape.
3. **No Policies/Gates.** The repo has zero model policies and only two
   camelCase gates; authorization idiom here is service-based
   (`RoundAuthorizationService`), mirroring the FlowLens approach. The plan's
   `rounds:*` colon abilities were deliberately not introduced.
4. **Public broadcast channels.** Per the doctrine comment in
   `routes/channels.php`, PHI-free payload events use public `Channel`s. The
   rounds events carry only UUIDs + versions + status strings. **If any
   payload ever grows beyond that, switch to `PrivateChannel` + add the app's
   first `Broadcast::channel` auth callback.**
5. **`reopened` is a transition, not a status.** Plan §6.3 shows
   `rounded → reopened → in_progress`; implemented as `rounded → in_progress`
   with a `patient.reopened` audit event (keeps the status CHECK tight).
6. **Phase 2 shipped as a layer toggle,** not the full `Flow | Rounds`
   segmented mode (§8.2). Rationale: consistent with the Navigator's toolbar
   architecture, and the board page is the primary operational surface (§14).
   The segmented mode + run/scope selectors on the Navigator, and a tour
   stepper UI, are the remaining Phase 2 UX (see §6 below).
7. **Envelope meta uses `version` = run `queue_version`** for board/scene/
   patient-detail alike; patient rows carry their own per-row `version` for
   transition concurrency.
8. **`meta.lens` is `detail`/`aggregate`** — a rounds-local clamp, not (yet)
   the full flow-lens integration. Phase 3's service-line scope should extend
   `FlowLensService` (`config/hummingbird/flow_lens.php` + `MobilePersonaCatalog`)
   rather than growing a parallel lens system.
9. **Known perf debt:** `RoundProjectionService::board()` runs per-patient
   queries (completion eval, question/task counts) — N+1 against the p95 < 2s
   SLO (§17.3). Fine at unit scale (≤40 patients); batch it before
   service-line cohorts land in Phase 3.
10. **Demo command uses `rounds:seed-demo`** naming (repo convention like
    `rtdc:demo-reset`), not the TODO's earlier `rounds:demo-run` placeholder.

## 4. Traps for the next agent

- **`NavigatorScene.clearGroup()` disposes any geometry not in its exclusion
  list.** If you add a shared geometry to a new layer, add it to the exclusion
  in `clearGroup` AND dispose it in `dispose()` — otherwise the first rebuild
  destroys the shared geometry (this bit me; `roundGeometry` is already
  handled).
- **Tests hit a real Postgres** (`zephyrus_test`, user `claude_dev`,
  localhost:5432 per `phpunit.xml`); `RefreshDatabase` runs the full migration
  set per class. `assertDatabaseHas` needs schema-qualified names.
- **Feature flag in tests:** anything touching `/api/rounds` must set
  `config(['rounds.enabled' => true])` (SeedsRoundsStory does).
- **Full-suite failures that are NOT yours:** `EddyKnowledgeRagTest` (pgvector
  in the test DB) and `RouteSmokeTest` (`/up/demo → 503`, demo-ledger
  freshness). Verified pre-existing by stashing everything (`git stash -u`)
  and re-running.
- **`attending` has no pivot-role mapping** in `rounds.unit_role_map` — an
  attending passes `author_role: 'attending'` explicitly and authorization
  falls back to unit-share/participant/broad checks. Don't “fix” this by
  mapping it; physicians aren't assigned via `prod.user_unit` roles.
- **`mark-ready` is not reachable from `queued`** — a patient enters
  `in_progress` via first contribution (or explicit transition). That's the
  plan's FSM, not a bug.
- **`defer`/`skip`/`pin`/`unpin` require reasons** (422 otherwise); prior
  queue order is preserved in the pin audit event.
- **Seeder UUID stability:** never switch `RoundTemplateSeeder` to
  `updateOrCreate` with `template_uuid` in the payload — it must mint once.
- **`scope_types` is a PG `text[]`** — read it via
  `RoundTemplate::scopeTypes()`, which handles both array and `{a,b}` string.
- **Run Pint** after backend edits (it reformats `continue`-after-statement
  spacing etc.); run `./scripts/check-ui-canon.sh` after UI edits.
- **`.env.local` shadowing trap** (see memory/repo docs): don't resurrect
  `.env.local`; it makes Laravel drop `.env`.

## 5. How to run it locally

```bash
# .env
VIRTUAL_ROUNDS_ENABLED=true

php artisan migrate                # creates the rounds schema
php artisan db:seed --class=Database\\Seeders\\RoundTemplateSeeder
php artisan rounds:seed-demo      # demo run on the busiest seeded unit
# visit /rtdc/virtual-rounds  (RTDC ▸ Virtual Rounds)
# 4D overlay: /rtdc/patient-flow-navigator → "Rounds" layer toggle appears
#             while that run is open

php artisan test --filter=Rounds  # 43 tests
npx vitest run tests/js/virtualRounds
npm run test:e2e -- virtual-rounds   # needs a running server + flag on
```

Validation state at handoff: rounds backend 43/43 (219 assertions); full
backend suite 731 passed / 2 pre-existing failures (see §4); vitest 316/316;
`npm run build` clean; Pint clean; `check-ui-canon.sh` passes.

---

## 6. Remaining work map (in recommended order)

### 6.1 Phase 2 remainder (small, self-contained)

- **Tour stepper UI** on the Navigator: an ordered-stop mini-panel (queue
  order already comes from `scene.stops[].queue_position`) with
  next/prev/pause; drive the camera via `NavigatorScene.focusRoundStop(uuid)`
  (highlight + fly is done; a `null` clears). Label it **guided tour** —
  never “route”/“shortest path” (§8.3; no walkable graph exists).
- **`Flow | Rounds` segmented mode + run/scope selectors** on the Navigator
  page, if the UX is wanted beyond the layer toggle (§8.2). Selecting a stop
  should hand off to the board workspace (deep link
  `/rtdc/virtual-rounds` — consider `?run={uuid}&patient={uuid}` params; the
  page currently auto-selects, so add param handling in
  `Pages/RTDC/VirtualRounds.tsx`).
- **Realtime:** the events exist (`rounds.run.{uuid}` public channel,
  `round-run.updated` / `round-patient.updated` / `round-queue.updated`);
  wire an Echo listener that invalidates `['rounds','board',uuid]` when
  `queue_version` on the wire exceeds the cached one. Reverb config exists;
  the board currently polls every 30s as fallback.

### 6.2 Phase 3 — async multidisciplinary workflow (§ plan Phase 3)

Entry points:

- **Consultant invites:** new `rounds.consult_requests` table (plan §6.1 row
  exists) + endpoints (`POST /patients/{p}/consult-requests`,
  `POST /invites/{invite}/respond`). Bounded access = extend
  `RoundAuthorizationService::canViewRun/canContribute` to honor a
  patient-scoped participant row (`round_patient_id` set) WITHOUT granting
  board access — there's already a test pinning that participants without
  unit share get 403 on the board (`RoundProjectionTest::
  test_participant_without_unit_share_gets_aggregate_lens_via_detail_redaction`)
  — evolve it: consultant sees ONLY `GET /patients/{their patient}` and can
  submit only their invited section (`clinical_plan` as `consultant` role is
  already allowlisted).
- **Service-line scope:** extend `FlowLensService::resolveScope` grammar with
  `service_line:{code}` (plan §3.2), resolve cohorts via
  `hosp_ref.service_lines` + the unit/service-line bridge
  (`hosp_space`/`hosp_org`; see `app/Services/Deployment/ServiceLineRegistrar`).
  Cohort dedupe across units is already guaranteed by the
  `(run_id, encounter_ref)` unique — the canonical-record rule (§3.2: one
  patient-round record projected into unit AND service-line views) needs a
  cross-run projection: when the same `encounter_ref` is active in a unit run
  and a service-line run, the service-line board should PROJECT the unit run's
  contributions rather than allow duplicate documentation. Suggested seam: a
  `RoundProjectionService::canonicalContributionsFor(encounterRef)` that
  queries across open runs.
- **Department scope:** design-doc only until a governed department master
  exists (plan §3.2, §18). Do not enable.
- **`awaiting_input` automation:** currently only manual; consider flipping
  patients to `awaiting_input` when a leader marks in-progress but hard
  requirements are missing (service-level, evented).

### 6.3 Phase 4 — staff notifications (§ plan Phase 4)

`rounds.notifications` ledger + notification FSM + `RoundNotificationService`
with the damping already built (`RoundEtaService::shouldNotify`). Reuse
`app/Services/Push/PushNotifier` (APNs, staff devices) routed via
`prod.user_unit` — do NOT generalize it into arbitrary-recipient sending.
Android FCM and vendor adapters (Ascom/Vocera/TigerConnect) are blocked on
credentials — build behind the `RoundNotificationChannel` connector interface
(plan §11.1) with a capability/health surface.

### 6.4 Phase 5 — family/interpreter/telehealth

Blocked on consent policy + vendors. The guest surface
(`/rounds/guest/{token}`) must be a separate minimal route group (plan §10.2)
— single-use tokens, no board access, complete audit. Nothing in the current
code assumes its existence.

### 6.5 Phase 6 — Eddy rounds tour (shadow)

- Tables: `rounds.tours` + `rounds.evaluations` (partial unique: one active
  evaluation per patient/cutoff/policy).
- `EddyRoundTourService` + a queued `EddyRoundTourJob` — NOTE: there are
  currently **zero Eddy queue jobs** in the repo (Eddy is synchronous HTTP to
  the external service; `config/services.php` `eddy.url`). The checkpointed
  cursor lives on `rounds.tours`; a retry must resume after the last committed
  cursor (mirror `CorrectiveActionExecutor`'s domain-state idempotency).
- Extend `EddyActionService::CATALOG` with `propose_round_task`,
  `request_round_input`, `flag_round_conflict` (T1/T2, draft-only) — they then
  flow through the existing Recommendation → OperationalAction → Approval FSM
  and agent inbox for free. `rounds.tasks.ops_action_uuid` is the landing
  bridge.
- Scene sync: broadcast `EddyRoundTourProgressed` (PHI-free
  `{tour_uuid, version, cursor, status}`) and drive
  `NavigatorScene.focusRoundStop` from it — backend authoritative (§9.5).
- Gate `VIRTUAL_ROUNDS_EDDY_ENABLED`.

### 6.6 Phase 7 — EHR writeback

Blocked on a deployment EHR sandbox + change control. Transactional outbox
(`integration.outbox_messages`) + connector contracts (plan §11.1–11.2).
Nothing shipped yet on purpose.

### 6.7 Cross-cutting follow-ups

- Batch the board projection queries (see §3 item 9) before service-line
  scale.
- Observability (§17): no metrics/counters were added; queue-version-conflict
  and denied-access counters are the highest-value first additions.
- The plan's §19 decisions (pilot unit, role sections, who may mark rounded /
  waive / reopen, discharge-ready definition, retention) are currently
  engineering defaults in `config/rounds.php` + the seeder — they need human
  owners and should become versioned config/ADRs before production pilot
  (plan Phase 0 gate; TODO Phase 0 items remain unchecked deliberately).

---

## 7. File inventory (this change set)

**Backend (new):** `database/migrations/2026_07_11_000100_create_virtual_rounds_tables.php`,
`config/rounds.php`, `app/Http/Middleware/EnsureRoundsEnabled.php`,
`app/Exceptions/Rounds/{RoundConflictException,RoundTransitionException,RoundPolicyException}.php`,
`app/Models/Rounds/*` (8), `app/Services/Rounds/*` (8), `app/Events/Rounds/*` (3),
`app/Http/Controllers/Api/Rounds/*` (8), `app/Http/Requests/Rounds/*` (9),
`app/Console/Commands/RoundsSeedDemoCommand.php`,
`database/seeders/RoundTemplateSeeder.php`.

**Backend (modified):** `routes/api.php` (rounds group),
`routes/web.php` (+ `/rtdc/virtual-rounds`),
`app/Http/Controllers/RTDCDashboardController.php` (+`virtualRounds()`),
`database/seeders/DatabaseSeeder.php` (+RoundTemplateSeeder), `.env.example`.

**Frontend (new):** `resources/js/features/virtualRounds/{api,schemas,types,hooks,roundsScene}.ts`,
`resources/js/Pages/RTDC/VirtualRounds.tsx`,
`resources/js/Components/VirtualRounds/{format.ts,RoundsCommandBar,RoundsBoard,RoundPatientWorkspace,ContributionComposer,ParticipantRail}.tsx`.

**Frontend (modified):** `resources/js/Components/PatientFlowNavigator/NavigatorScene.ts`
(rounds layer + focus API), `.../PatientFlowNavigator.tsx` (overlay wiring),
`resources/js/features/patientFlowNavigator/types.ts` (+`rounds` layer flag),
`resources/js/config/navigationConfig.ts` (nav entry).

**Tests (new):** `tests/Feature/Rounds/*` (5), `tests/Unit/Rounds/RoundEtaServiceTest.php`,
`tests/Support/SeedsRoundsStory.php`, `tests/js/virtualRounds/*` (2),
`tests/e2e/virtual-rounds.spec.ts`.
