# Claude Code Build Prompt — Zephyrus **Home Hospital** Module

> **Source of truth:** `ACUM-PRD-HAH-001` — *"Extending Zephyrus to Hospital at Home:
> Acute Care at Home, Remote Patient Monitoring & Transitions of Care — Strategy and
> Module Design"* (Sanjay M. Udoshi, MD; 2026-07-17), available in-repo as
> [`Zephyrus_Hospital_at_Home_Strategy_and_Design.md`](./Zephyrus_Hospital_at_Home_Strategy_and_Design.md).
> This file operationalizes that strategy into an executable engineering brief. Where this
> file and the strategy doc disagree, this file wins — it has been reconciled against the
> actual codebase (see [§14 Reconciliation notes](#14-reconciliation-notes--deltas-from-the-strategy-doc)).

---

## 0. How to use this prompt

You are a Claude Code agent building the **Home Hospital (HOME)** module inside the
Zephyrus repository (`/Users/sudoshi/Github/Zephyrus`). This is a large, multi-phase
build. Do **not** attempt it in one pass.

**Before writing any code, read, in order:**
1. `PRODUCT.md` — who the users are, the "rigorous / composed / defensible" personality, the anti-references.
2. `DESIGN.md` (+ `.impeccable/design.json`) — the visual system, the North Star "Operations Bridge."
3. `CLAUDE.md` — the **Token Canon** (design non-negotiables) and the Two-System Rule.
4. `AGENTS.md` — build/test/deploy commands, backend/frontend architecture, key patterns.
5. `.claude/rules/auth-system.md` — the protected auth system you must **not** touch.
6. This file, in full, plus the referenced roadmap section for the phase you are on.

**Working rules:**
- Work **one phase at a time** (§9). Each phase is independently demonstrable. Do not
  start Phase N+1 until Phase N passes its Definition of Done.
- Build against the **synthetic Summit Regional demo hospital** — never against a
  production or canonical DB. Use a local disposable database (e.g. `zephyrus_test`)
  for schema work; never run `migrate:fresh` against a shared DB.
- **Reuse before you build.** Almost every capability this module needs already exists
  (§6). A new provider, a new connector, a new set of tables, and a new nav domain —
  the machinery around them is inherited. If you find yourself writing a new snapshot
  engine, a new alert lifecycle, or a new realtime channel auth layer, stop: you have
  drifted off the intended path.
- Keep the **impeccable design hook** on. Run `scripts/check-ui-canon.sh` before
  considering any frontend work done.
- When you hit one of the **open product/clinical questions** (§13), do not silently
  pick — surface the decision, state your recommended default, and proceed on the
  default only if unblocked.

---

## 1. Mission & the one-instrument thesis

Build a **Home Hospital** workspace that runs an acute **Hospital-at-Home (HaH)** virtual
ward as *one more altitude of the same instrument* — sharing Zephyrus's census spine,
cockpit, alerting, governance (Eddy), and process mining (Arena).

**The differentiator is not a monitoring app.** Every competitor (Medically
Home/DispatchHealth, Biofourmis, Inbound Health, Contessa) orchestrates home care.
None of them unify it with the hospital's **live house-wide demand and capacity.**
Zephyrus already holds the live ED boarding count, floor census vs. staffed capacity,
and expected discharges. The winning feature is the sentence no HaH vendor can say:

> *"We have 14 ED boarders, 9 of them home-eligible, 6 home slots free tonight — enroll
> these and boarding hours fall."*

Every design and data decision serves that **capacity-unification (decant-valve)**
thesis. Every active home episode-day is an **avoided occupied bed-day**, attributable
against the very boarding pressure the cockpit already displays. If a feature does not
eventually feed that story, it is lower priority than one that does.

---

## 2. Strategic context (the "why", condensed)

Enough context to make correct product judgments. Full citations live in the strategy doc §12.

- **The waiver is durable now.** The CMS Acute Hospital Care at Home (AHCAH) waiver was
  extended through **September 30, 2030** (Consolidated Appropriations Act, 2026;
  42 U.S.C. §1395cc-7). HaH is a fundable, permanent service line, not a pilot. Design
  for a five-year horizon.
- **A federal study is coming.** HHS must report by **2028-09-30** comparing home vs.
  brick-and-mortar inpatient on readmissions, mortality, infections, staffing,
  escalations/transfers, patient experience, conditions/DRGs, cost, and equity
  (addressing selection bias). **Capture these as first-class operational data** — it
  turns a compliance burden into a reporting asset.
- **RPM billing widened in 2026.** The CY2026 PFS final rule added shorter-duration RPM
  codes (99445 for 2–15 days of device data; 99470 for 10–19 min management) and RTM
  parallels. Lower-acuity, shorter post-discharge/chronic monitoring is now billable —
  so the module's enrollment/eligibility logic must be **payer-aware from day one**
  (only ~half of state Medicaid programs reimburse RPM; commercial coverage is uneven).
- **The population is comorbid and escalation-prone.** Most common HaH diagnoses: heart
  failure, respiratory infection (incl. COVID-19), sepsis, kidney/UTI, cellulitis; mean
  HCC ≈ 3.15, CMI ≈ 1.31, ~42.5% HF, ~43.3% COPD. Build for this cohort, not the worried well.
- **The evidence supports a CMO defending the program** (comparable/lower mortality,
  fewer ICU escalations, lower total cost). But a 2026 meta-analysis warns heterogeneity
  is high — **a program that instruments its own escalation and readmission rates in
  real time is more defensible than one leaning on literature.** That is Zephyrus's job.

**Commercial positioning:** à-la-carte module in the **$85–95K/yr** band, between ED
($65K) and Perioperative ($85K); included in **Enterprise Plus**. This pulls the
BUSINESS_PLAN's Year-3 "telehealth operations" line into the current window.

---

## 3. National benchmark KPIs — the numbers the dashboard tracks against

Wire these as the **targets/comparators** in `ops.metric_definitions` and Program Analytics.
The program measures itself against national benchmarks, not aspiration.

| KPI | National benchmark | Source |
|---|---|---|
| Escalation (return-to-hospital) rate | ≈ **6.2%** | Levine et al. 2024 |
| In-episode mortality | ≈ **0.5%** | Levine et al. 2024 |
| 30-day readmission | ≈ **15.6%** | Levine et al. 2024 |
| Mean length of stay | ≈ **6.3 days** | Levine et al. 2024 |
| Emergency in-person response | within **30 minutes** | CMS waiver / MedPAC 2024 |
| In-person visit cadence | **≥ 2 per day + daily MD** eval | CMS waiver / MedPAC 2024 |

These last two are **waiver operating-floor requirements** — treat them as compliance
telemetry (§8), not soft goals.

---

## 4. Non-negotiable guardrails

Violating any of these is a defect, regardless of feature completeness.

### 4.1 Design canon (from `CLAUDE.md` + `DESIGN.md`)
- **Two-System Rule.** Blue/slate `healthcare-*` palette governs all operational surfaces
  and interaction. Crimson `#9B1B30` + gold `#C9A227` is the Acumenus brand/heritage +
  focus layer **only**. Never promote crimson to a HOME dashboard primary.
- **Earned urgency / Earned-Red.** Grey is the resting baseline. Coral-red appears **only**
  for a genuine breach — an unacknowledged critical vital, a blown response SLA, or a
  missed waiver-required visit. This is *clinical* monitoring: alarm fatigue here is a
  patient-safety failure, not just a UX one. A metric enters the cockpit alert ticker
  **only** if its seeded definition carries an `alert_template` — ration them (§7).
- **Status never by color alone.** Every teal/amber/coral/sky state travels with an arrow
  (▲ ▼ ▬), an icon, or a worded label.
- **Typography:** Figtree via `font-sans` only; weights 400/500/600 (`font-normal` /
  `font-medium` / `font-semibold`) — **no `font-bold`/`font-extrabold`**. Tailwind size
  scale only — **no `text-[Npx]`** (the `text-[11px]` cockpit micro-caption exception
  applies **only** inside `Components/cockpit/`; HOME pages hold the `text-xs` floor).
  Metrics/IDs use `tabular-nums`, never `font-mono`.
- **Surfaces:** exactly one primitive — `Components/ui/Surface.tsx` via `<Card>`/`<Panel>`.
  Never `bg-white`/`bg-gray-*` surfaces, never glassmorphism (`backdrop-blur`). Resting
  panels = `shadow-sm`; only modals/dropdowns/tooltips get `shadow-lg`.
- **Color:** `healthcare-*` tokens with a `dark:` pair, always. No raw Tailwind palette
  (`bg-gray/red/blue/green/amber/...`) in `resources/js`. Status = `healthcare-critical/
  warning/success/info`. Interactive blue = `healthcare-primary`.
- **Spacing:** 4px grid. `PageContentLayout` owns the gutter (`p-4`); don't double-gutter.
- **Do not reintroduce the KPI "left status stripe."** It was deliberately removed and
  replaced by a status **dot** (`KpiTile.tsx` renders it). Use the existing `KpiTile` /
  `metric()` factory as-is; do not hand-build tiles.
- Run `/impeccable <command>` for design work; run `scripts/check-ui-canon.sh` before
  declaring frontend done (it hard-fails on faux-bold, `text-[Npx]`, `oklch(`, new
  `backdrop-blur`; the raw-palette count is a ratchet that may only go **down**).

### 4.2 PHI, privacy, and safety
- **PHI-free-wire rule.** Only aggregate pings travel on public Reverb channels
  (`home.census`, cockpit ping). Patient-level vitals are fetched over authenticated APIs
  with TanStack cache invalidation on ping — exactly like the cockpit stream
  (`routes/channels.php` documents this doctrine). If per-patient **push** is ever
  required, it must move to a `PrivateChannel` with a real auth callback — a deliberate,
  documented contract change, never incidental.
- **Pseudonymity preserved.** Operational and analytic paths use `patient_ref` and
  service **zones**, never MRNs or street addresses. Physical address is confined to a
  restricted logistics context only. Never put PHI in URL params, query strings, or
  public channel payloads.
- **HEWS is operational triage, not a medical device.** The Home Early Warning Score is
  decision support for operations, **not diagnosis.** Ship clear labeling; stay inside
  FDA clinical-decision-support boundaries; escalation authority always rests with the
  clinical team — exactly as Eddy actions always rest with a human approver.
- **Eddy governance is inviolable.** Every new Home action is **draft-only**; a human
  approves. The agent proposes, a human disposes. No direct domain mutation from an
  agent token.

### 4.3 Protected systems — do NOT modify
- The **authentication system** enumerated in `.claude/rules/auth-system.md` (temp-password
  + Resend flow, ChangePasswordModal, etc.). Additions only, never architectural change.
- The **nine existing cockpit metric providers**, `SnapshotBuilder`, `StatusEngine`,
  `AlertEngine`, the integration control plane, and the census `CensusProjector` — you
  **extend** these by adding to their registration points, you do not rewrite them.
- Deployment: production is **manual-only** (`./deploy.sh`). Never add a GitHub Actions
  deploy job or push to prod.

---

## 5. Concept & placement

- **New nav domain:** working name **Home Hospital (HOME)**, added as a new **Workspace**
  in the Altitude model (Cockpit → Workspaces → Study), gated behind a feature flag and
  route middleware (§6.11). It sits in the `workspaces` section of `navigationConfig.ts`
  next to RTDC, EMERGENCY, PERIOPERATIVE, etc.
- **The virtual ward is modeled as a unit.** Seed one or more `prod.units` rows with a
  `virtual_home` type and `prod.beds` rows as program **slots**. Because the program is a
  unit, census, occupancy, huddles, and the entire cockpit machinery work **unmodified**.
- **Three capability rings ship progressively** (map to phases):
  1. **Acute HaH at waiver grade** — virtual-ward census & command, RPM observability,
     twice-daily visit logistics, escalation with response-time telemetry, CMS reporting.
  2. **Extended observability** — post-discharge 30-day monitoring cohorts,
     ED-diversion / observation-at-home, chronic RPM lines (HF, COPD) on the same pipes
     at lower acuity.
  3. **Transitions of care** — admission pathways in, governed handoffs out.

---

## 6. Architecture — what you inherit (verified file map)

This is the load-bearing section. Every path below was verified against the current
codebase. **Reuse these; extend at the named registration points.**

### 6.1 Census spine (event-sourced occupancy)
- Tables: `prod.units`, `prod.beds` (`database/migrations/2026_06_20_000010_create_rtdc_units_beds_tables.php`);
  `prod.encounters`, `prod.census_snapshots` (`..._000020_...`); `prod.operational_events` (`..._000030_...`).
- Models: `app/Models/Unit.php`, `Bed.php`, `Encounter.php`, `CensusSnapshot.php`, `OperationalEvent.php`.
- Projector: `app/Rtdc/CensusProjector.php` — `apply(CanonicalEvent $event)` dispatches via
  `match($event->type)` onto idempotent `updateOrCreate` read-model updates; `snapshot(int $unitId)`
  recomputes occupancy and writes a `census_snapshots` row.
- **`prod.units.type` is a free-form string with no CHECK constraint** — adding `virtual_home`
  needs **no constraint migration on `prod.units`.** ⚠️ But `prod.beds.status` has a CHECK
  (`available|occupied|blocked|dirty`) — model slot states within it (map "pending-setup"/"blocked"
  onto existing states or extend the CHECK deliberately). ⚠️ `prod.gmlos_references.unit_type` has a
  CHECK (`med_surg|icu|step_down|ed`) — extend it only if a home unit needs a GMLOS reference row.

### 6.2 Cockpit snapshot & metric providers
- Assembler: `app/Services/Cockpit/SnapshotBuilder.php`. **Registration point:** the
  `providers()` method returns the nine providers in order (`OkrMetrics` last). **Add
  `HomeMetrics` here.** `DOMAIN_GAUGES` const maps a domain → its headline gauge key
  (add `'home' => 'home.<gauge>'`).
- Base class: `app/Domain/Cockpit/Metrics/BaseMetrics.php` (abstract) — inject `StatusEngine`;
  implement `domain(): string` and `metrics(SnapshotContext $ctx): array` (returns `MetricValue[]`).
  Helpers: `fromKey($ctx,$key,$value,$overrides)`, `fromLegacy(...)`, `compact()`.
- Closest analog to copy: `app/Domain/Cockpit/Metrics/RtdcMetrics.php` (census-based).
- DTO: `app/Support/Cockpit/MetricValue.php`; context: `app/Domain/Cockpit/SnapshotContext.php`;
  refresh job: `app/Jobs/RefreshCockpitSnapshot.php`.

### 6.3 StatusEngine + metric definitions (admin-editable bands)
- Engine: `app/Services/Cockpit/StatusEngine.php` — `resolveStatus(float $value, MetricDefinition $def)`
  reads `direction` (`up`/`down`), `ok`/`warn`/`crit` edges, `watch_band_pct`; resolves crit→warn→ok→watch→normal.
- Enum: `app/Enums/CockpitStatus.php` — `NORMAL/OK/WATCH/WARN/CRIT`; `canon()` → `neutral/success/info/warning/critical`.
  **Backend emits logical names only;** the teal/amber/coral/sky tokens are client-side
  (`resources/js/Components/cockpit/statusStyle.ts`).
- Table/model: `ops.metric_definitions` / `app/Models/Ops/MetricDefinition.php`.
- **Seed new `home.*` keys here:** `database/seeders/CockpitKpiDefinitionSeeder.php` — add rows
  via the `kpi(label, def, unit, direction, target, warn, crit, refreshSecs, alertTemplate=null, ...)`
  helper; `run()` upserts by `metric_key` (idempotent), auto-derives `domain` from the key prefix.
  Admin editing UI: `resources/js/Pages/Admin/CockpitThresholds.tsx` + `app/Http/Controllers/Admin/CockpitPolicyController.php`.

### 6.4 Alerting → Eddy Action Inbox
- Engine: `app/Services/Cockpit/AlertEngine.php` — `reconcile($facilityKey, $candidates)` runs the
  flap-damping lifecycle (`pending → open → cleared`, `hold_count`) against `prod.cockpit_alerts`;
  thresholds in `config/cockpit.php`.
- **Candidates are derived in `SnapshotBuilder::deriveAlerts()` from every visible warn/crit
  `MetricValue` whose definition has a non-empty `alert_template`.** This is the Earned-Red ration.
- Fan-out: `app/Services/Cockpit/AlertFanout.php` + `Channels/{PushAlertChannel,TeamsAlertChannel}.php`;
  model `app/Models/Cockpit/CockpitAlert.php`.
- Alert → Eddy: `app/Services/Eddy/EddyActionService.php` — `CATALOG` maps action types →
  `{tier, risk, label, recommendation_type, alert_key}`; `actionForAlert($alertKey, $status)` routes
  an opened alert onto a catalog action by `alert_key` **prefix**. **Add a `'home.'`-prefixed entry**
  so home alerts auto-route.

### 6.5 Integration control plane (device & FHIR ingestion)
Message flow: `source → raw.inbound_messages → integration.canonical_events → ProjectionHandler → prod.*`.
- Foundation migration (creates `integration.sources`, `raw.inbound_messages`, `integration.canonical_events`,
  `raw.dead_letters`, `integration.connector_watermarks`, `fhir.*`, `integration.provenance_records`):
  `database/migrations/2026_06_25_000030_create_healthcare_integration_foundation_tables.php`.
- Connector contract: `app/Integrations/Healthcare/Contracts/HealthcareConnector.php`
  (`sourceKey`, `capabilities`, `healthCheck`, `backfill`, `poll` [FHIR-poll], `handleWebhook` [webhook], `replay`).
- **Reference connector to copy (full lifecycle, webhook + poll):**
  `app/Integrations/Healthcare/Synthetic/SyntheticHealthcareConnector.php`.
- Projection contract: `app/Integrations/Healthcare/Contracts/ProjectionHandler.php`
  (`key()`, `eventTypes()`, `supports()`, `project()`); router `Services/ProjectionDispatcher.php`
  (one owner per eventType). Canonical writer: `Services/CanonicalEventWriter.php`.
- **Registration seam:** `app/Providers/AppServiceProvider.php` (~L95–126) — `ProjectionDispatcher`
  is constructed with an explicit array of handlers; **add your `RpmProjectionHandler` here.**
- Working HL7v2 exemplar (production ADT): `app/Services/PatientFlow/PatientFlowHl7IngestPipeline.php`.
  ⚠️ Wire the **escalation-close loop** through this: when an escalated home patient is registered in
  the ED via ADT, resolve the open `prod.home_escalations` row with an `ed_return` outcome.
- Dead-letter/replay: `raw.dead_letters` + `app/Jobs/ReplayPendingIntegrationEvents.php`.
- Per-feed freshness SLAs: `config/integrations.php` (defaults) + `Services/SourceSloDefinitionService.php`
  / `SourceObservabilityService.php` (per-source SLOs).

### 6.6 FHIR R4
- Poll client (SMART backend-services, `_history`, watermarking, tombstones):
  `app/Integrations/Healthcare/Services/SmartBackendFhirClient.php` (Epic variant: `EpicSmartFhirClient.php`).
- Storage/versioning: every resource persists as **both** a `raw.inbound_messages` row and a
  `fhir.resource_versions` row (append-only, uniquely keyed `source_id, resource_type, fhir_id, version_id`).
  `fhir.resource_links` maps FHIR ids → internal projected rows. Models: `app/Models/Fhir/ResourceVersion.php`,
  `ResourceLink.php`. Resource JSON is encrypted out-of-row.
- Store RPM as FHIR **Observation / Device / ServiceRequest** (US Core profiles; IEEE 11073 PHD via
  vendor gateways). **LOINC vital codes:** heart rate `8867-4`, SpO₂ `59408-5`, systolic `8480-6`,
  diastolic `8462-4`, respiratory rate `9279-1`, body temperature `8310-5`, weight `29463-7`.

### 6.7 Canonical event vocabulary + OCEL projection
- RTDC operational vocabulary (PascalCase constants): `app/Rtdc/Events/CanonicalEvent.php`. **Add**
  `HomeReferralCreated`, `HomeEpisodeActivated`, `RpmObservationBreached`, `HomeEscalationOpened`,
  `HomeEscalationResolved`, `HomeVisitCompleted`, `HomeEpisodeDischarged`, `TransitionHandoffCompleted`.
- OCEL emission map (pure, DB-free transformers): `app/Domain/Ocel/EmissionMap.php` — add `forHomeEpisode()`
  etc.; PHI hashed via `hashRef()`. Projector: `app/Domain/Ocel/OcelProjector.php` — add a `collectHomeEpisodes()`
  method + call it in `project()`. Catalog: `app/Domain/Ocel/OcelCatalog.php` — add object types
  (`Home Episode`, `RPM Kit`, `Home Visit`, `Escalation`) and activity verbs (additive; uncatalogued verbs still project).

### 6.8 Eddy (governed action agent)
- Catalog: `app/Services/Eddy/EddyActionService.php::CATALOG` — **adding a new action is a pure
  additive row** (`tier`, `risk`, `label`, `recommendation_type`, `alert_key`). Everything else
  (FormRequest validation via `Rule::in(array_keys(CATALOG))`, controller, tiering, role-gating)
  reads from the catalog. Add: `propose_hah_enrollment`, `propose_stepdown_cohort`,
  `propose_escalation_response`, `propose_visit_reschedule`, `flag_rpm_gap`, `propose_home_discharge`,
  `flag_transition_barrier`.
- Governance write path: `propose()` creates `Recommendation(draft) → OperationalAction(draft) →
  Approval(pending)` via `app/Services/Ops/OperationalActionLifecycleService.php`. Human-only approve
  (`app/Http/Controllers/Api/Eddy/EddyActionController.php`, gated on `actsAsHuman()`). Config: `config/eddy.php`.
- Inbox surfaces: web `/ops/agent-inbox` (`routes/web.php`); frontend `resources/js/Components/cockpit/ActionInboxModal.tsx`.

### 6.9 Arena (process mining, OCEL 2.0) & the 48-Hour Flow Review
- `app/Domain/Arena/FlowReviewService.php` (`WINDOW_HOURS = 48`) — folds the home program in once
  home events project into `ocel.*`. Conformance: `ArenaService::conformance()` / `ArenaSidecarClient::conformance()`
  against reference pathways seeded in `database/seeders/ClinicalPathwaySeeder.php` (add home pathways:
  time-to-activation SLA, visit cadence, escalation-protocol adherence).

### 6.10 Predictions layer (deterministic) & avoided bed-days
- Store: `prod.rtdc_predictions` (`database/migrations/2026_06_20_000040_...`), upsert on unique
  `(unit_id, service_date, horizon)`; model `app/Models/RtdcPrediction.php`. Deterministic write pattern:
  `app/Services/RtdcService.php::prediction()`. The +24h composition substrate is
  `app/Services/Flow/ForwardProjectionService.php` (every projected item tagged confidence
  ∈ {definite, probable, possible} + provenance) — **add a home free-slot stream here.**
- Economics ledger: `prod.discharge_facts` (`database/migrations/2026_07_04_100040_...`) feeds
  materialized views (`ops.mv_*`). Roll **avoided bed-days** up here / alongside; surface in the RTDC
  huddle and executive brief.

### 6.11 Feature-flag + route gating (copy Virtual Rounds)
- Config precedent: `config/rounds.php` returns `['enabled' => filter_var(env('VIRTUAL_ROUNDS_ENABLED', false), FILTER_VALIDATE_BOOL), ...]`.
  **Create `config/home_hospital.php`** (mirroring it) with `enabled` ← `HOME_HOSPITAL_ENABLED` plus sub-flags.
- Middleware precedent: `app/Http/Middleware/EnsureRoundsEnabled.php` → `abort_unless((bool) config('rounds.enabled'), 404)`.
  **Create `app/Http/Middleware/EnsureHomeHospitalEnabled.php`** reading `config('home_hospital.enabled')`.
- ⚠️ Laravel 11 style: **no `$routeMiddleware` alias array** — apply the middleware **inline by FQCN**
  in `routes/web.php` and `routes/api.php` (exactly as Virtual Rounds does at `routes/web.php:158-159`
  and `routes/api.php:218-219`).

### 6.12 Ambient telemetry hooks (wearable/RPM substrate)
- `flow_realtime.ambient_signal_adapters` + `flow_realtime.ambient_signal_events`
  (`database/migrations/2026_06_26_000070_...`). Register a wearable/RPM feed as an adapter row; RPM
  readings can land as `ambient_signal_events` (idempotent on `(adapter_id, external_event_id)`,
  `subject_ref_hash` PHI-safe). Service: `app/Services/PatientFlow/AmbientSignalService.php`.
  Decide (§13) whether RPM rides the ambient-signal path or a dedicated `rpm_observations` ledger — the
  strategy doc favors the dedicated ledger for volume; ambient signals remain the lower-fidelity hook.

### 6.13 Realtime (Reverb)
- Doctrine: `routes/channels.php` (public `Channel`, PHI-free aggregate pings; no auth callbacks).
- Cockpit ping event: `app/Events/Cockpit/CockpitSnapshotUpdated.php` (public `hospital.cockpit`,
  `broadcastAs('.cockpit.updated')`). Bed ping: `app/Events/Rtdc/BedsChanged.php`.
- Frontend invalidation-on-ping: `resources/js/features/cockpit/live.ts` (`useLiveCockpit()` →
  `echo.channel('hospital.cockpit').listen('.cockpit.updated', () => qc.invalidateQueries(...))`);
  SSE fallback `useCockpitStream.ts`; RTDC analog `resources/js/features/rtdc/hooks.ts::useLiveCensus`.
  **Pattern: the ping carries no data; it only triggers `invalidateQueries`, and the authenticated refetch does the work.**

### 6.14 Frontend design-system primitives & page pattern
- Surface: `resources/js/Components/ui/Surface.tsx`; `Panel` = `resources/js/Components/CommandCenter/Panel.tsx`
  (re-exports Surface) and the titled wrapper `resources/js/Components/ui/Panel.jsx`.
- KPI tile: `resources/js/Components/CommandCenter/KpiTile.tsx` (status **dot**, arrow, gauge/number,
  sparkline when `detailed`, source-trust badge). Gauge: `Components/CommandCenter/Gauge.tsx`. Sparkline:
  `Components/cockpit/Sparkline.tsx`.
- **Build HOME pages on the design-system barrel** `resources/js/Components/system/index.ts` —
  `Section`, `MetricGrid`, and the `metric(input)` factory (`Components/system/metric.ts`); the metric
  contract is `resources/js/types/commandCenter.ts` (`KpiMetric`, incl. `sourceTrust`, `trajectory`,
  `status: critical|warning|success|info|neutral`).
- **Template to copy:** `resources/js/Pages/RTDC/BedTracking.tsx` (page) + `resources/js/Components/RTDC/RTDCPageLayout.tsx`
  (workspace layout shell wrapping `DashboardLayout` + `PageContentLayout` + `<Head>`). Create a
  `HomePageLayout.tsx` copy and `resources/js/Pages/Home/*` pages.

### 6.15 Navigation registration
- SSOT: `resources/js/config/navigationConfig.ts`. Define a `HOME: NavDomain` (mirror the `RTDC` const)
  with `key:'home'`, `label:'Home Hospital'`, a Lucide icon (e.g. `HeartPulse`/`House`),
  `dashboardHref:'/home/command'`, `matchPrefixes:['/home']`, and `groups` for the six surfaces.
  Register it in `NAV_SECTIONS` → `workspaces` section `domains` array. That single registration feeds
  desktop nav, mobile drawer, command palette, and route-ownership. Ownership must be exactly 1 —
  `matchPrefixes:['/home']` is collision-free. (If `/home/analytics` is later re-homed to Study,
  add it to `ANALYTICS.matchPrefixes` and `HOME.excludePrefixes`, the pattern RTDC already uses.)

### 6.16 Dev/live mode & demo seed
- Data service: `resources/js/services/data-service.js` (`dev` → mock, `live` → `/api/...`);
  `resources/js/Contexts/ModeContext.tsx` (defaults `'dev'`). Mock files: `resources/js/mock-data/*`.
  Add `resources/js/mock-data/home.js` + getters, or fetch `/api/home/*` with a demo-provenance backend.
- Demo hospital: `database/seeders/SummitDeploymentSeeder.php` (Summit Regional, code `ZEPHYRUS-500`).
  Commands: `zephyrus:demo-seed` (`app/Console/Commands/DemoSeedCommand.php`), `zephyrus:demo-refresh`
  (`DemoRefreshCommand.php` → `App\Services\Demo\DemoRefreshCoordinator`, guarded by `config('demo.enabled')`,
  scheduled every 15 min). Deterministic generators: `app/Services/Demo/DistributionSampler.php`,
  `OperationalDemoDataService.php` (writes tagged with an `OWNER` marker so live deployments are never
  overwritten). Registration order: `database/seeders/DatabaseSeeder.php` (`DemoTuningSeeder` last).
  **Pattern:** create `app/Services/Demo/HomeHospitalDemoGenerator.php` (mirror `Ancillary/PharmacyDemoGenerator.php`),
  wrap in `HomeHospitalDemoSeeder`, register before `DemoTuningSeeder`, hook into `DemoRefreshCoordinator`.
  Synthetic tiles show a `ProvenanceBadge` when `metadata.provenance === 'demo'`.

---

## 7. Data model — new tables (prod schema)

Follow the established conventions **exactly** (verified in `database/migrations/`):

- **PK:** `$table->id('{table}_id')` (bigint identity, named `{table}_id`).
- **Public/idempotency key:** a **separate** `$table->uuid('{thing}_uuid')->unique()` column (the `_id`
  and `_uuid` are two different columns — the `_id` is the numeric PK, the `_uuid`/`idempotency_key`
  are dedupe/public keys).
- **`patient_ref`** (pseudonymous, `string(190)`, never an MRN) + `encounter_ref` where relevant.
- **`metadata`** `jsonb` default `'{}'::jsonb` (CHECK `jsonb_typeof(metadata) = 'object'`).
- **`is_deleted`** boolean default false (soft delete). `timestampsTz()`.
- **CHECK constraints via raw `DB::statement(...)`** after an existence probe (see the private
  `addCheckConstraint()` helper pattern in `2026_06_25_000030_...`). Use the `SafeMigration` trait
  (`app/Traits/SafeMigration.php`).
- Keep the **RPM observation ledger separate** from `prod.encounters` — mirror how `prod.discharge_facts`
  stays a distinct ledger.

| Table | Purpose |
|---|---|
| `prod.home_programs` | Program lines (AHCAH acute, observation-at-home, post-discharge RPM, chronic RPM, SNF-at-home) with slot capacity by service zone |
| `prod.home_referrals` | Funnel spine: source, status, decline reason, screening JSON (zone, payer, home-safety, connectivity) |
| `prod.home_episodes` | Episode spine, linked to an `encounter` on the virtual unit: program, admission source, condition + DRG, acuity tier, target vs. actual LOS, disposition |
| `prod.rpm_kits` / `prod.rpm_devices` | Kit and device inventory, lifecycle, battery & connectivity telemetry |
| `prod.rpm_enrollments` | Kit-to-episode assignment + per-patient monitoring plan (per-vital cadence, personalized thresholds, baseline window) |
| `prod.rpm_observations` | High-volume vitals ledger, LOINC-coded, with device & transmission provenance and a quality flag |
| `prod.rpm_alerts` | Patient-level clinical alerts (rule, severity, opened/acked/resolved, escalation link) |
| `prod.home_visits` | Scheduled/completed visits (RN, paramedic, MD/NP tele, labs, delivery), waiver-required flag, on-time telemetry |
| `prod.home_escalations` | Trigger, response mode, full timing chain (initiated → dispatched → arrived → resolved), outcome (managed at home / ED return / readmit) |
| `prod.home_transitions` | Inbound activation & outbound handoff milestones, receiving-entity FK to `regional.facilities`, readiness checklist |

**Virtual-unit seeding:** seed `prod.units` rows with `type = 'virtual_home'` and `prod.beds` rows as
slots, so census/occupancy/huddles/cockpit work unmodified.

> ⚠️ **`prod.rpm_observations` is high-volume.** The strategy doc calls for **monthly
> range-partitioning**, but **no declarative table-partitioning exists anywhere in the repo today** —
> this would be a *new* convention. Do it via raw `DB::statement` in the migration `up()` (consistent
> with how schemas and CHECK constraints are already created), and **flag it explicitly** in the PR
> description and DEVLOG as a new pattern for review. Alternatively, ship Phase 0/1 unpartitioned with
> a documented retention/rollup policy and add partitioning as a follow-up. Do not silently introduce it.

**Transitions substrate to reuse (do not rebuild):** `prod.transport_requests.request_type` already
includes `care_transition` and `discharge` (CHECK-constrained); `regional.facilities` +
`regional.facility_capabilities` + `regional.transfer_decisions.opportunity_cost_payload` model
external facilities and destination selection with opportunity-cost scoring.

---

## 8. The six surfaces (`resources/js/Pages/Home/*`)

Each reuses an existing Zephyrus component pattern; each route is under the `home.` group and gated by
`EnsureHomeHospitalEnabled`. Build on `Section`/`MetricGrid`/`metric` and `HomePageLayout`.

1. **Virtual Ward Command · `/home/command`** *(flagship)* — a grid of **episode tiles**: pseudonymous
   identity, condition + program, day-of-stay vs. expected LOS, live vitals sparklines, a **Home Early
   Warning Score (HEWS)** chip, open alerts, the **next required visit with a countdown**, and
   device/connectivity status. Grey resting baseline; coral only for a true breach (unacknowledged
   critical vital, blown response SLA, missed waiver-required visit). Tiles **drill in place** to the
   patient lens.
2. **Virtual Bed Board · `/home/census`** — RTDC-pattern board of program slots
   (occupied / available / pending-setup / blocked), an enrollment-pipeline column, projected discharges
   at 24 h and 48 h. Shares one census engine with the house-wide huddle (because the program is a unit).
3. **Eligibility & Referral Funnel · `/home/referrals`** — two live worklists: **ED candidates**
   (screened over the live ED census: qualifying conditions, service-zone address, payer class, clinical
   stability) and **inpatient step-down candidates** (at/near expected LOS with home-eligible profiles).
   Funnel states: referred → screened → eligible → consented → activated / declined, **with decline
   reasons** (real programs convert only a minority — design for ~22% conversion).
4. **Field Operations & Logistics · `/home/logistics`** — visit scheduling with a **two-visits-per-day
   compliance rail**, a route/assignment view for field nurses & community paramedics (reuse **Transport
   dispatch** patterns), kit inventory & lifecycle, and delivery tracking (meds, meals, DME, labs).
   ⚠️ This is the **only** context where physical address is permitted; keep it out of everything else.
5. **Transitions of Care Board · `/home/transitions`** — inbound activation checklists (consent,
   home-safety check, kit delivery, first visit) and outbound handoffs (discharge readiness, handoff
   owner, receiving entity via `regional.facilities`, barrier tracking) plus a **30-day post-discharge
   monitoring cohort** with a step-down cadence (billable under the 2026 RPM codes).
6. **Program Analytics · `/home/analytics`** *(Study)* — outcomes vs. matched inpatient comparators (LOS,
   escalation, mortality, 30-day readmission — see §3), program economics & **avoided bed-days**, funnel
   conversion, alert burden per patient-day, visit on-time compliance, RPM adherence.

**Wiring per page** (mirror RTDC): React page in `Pages/Home/`, `Inertia::render('Home/<Page>', $service->build())`
method on a new `HomeDashboardController`, `Route::prefix('home')->name('home.')->group(...)` in
`routes/web.php` with `EnsureHomeHospitalEnabled` on the group, live data via `app/Http/Controllers/Api/Home/*`
under a `->prefix('home')` group in `routes/api.php`.

---

## 9. Cockpit integration

- **New provider:** `app/Domain/Cockpit/Metrics/HomeMetrics.php` (`domain(): 'home'`), registered in
  `SnapshotBuilder::providers()`. Seed thresholds into `ops.metric_definitions` via
  `CockpitKpiDefinitionSeeder` so they stay admin-editable and audited.
- **Metric keys** (attach an `alert_template` **only** to the alerting rows — this is the Earned-Red ration):

  | Metric key | Cockpit tile | Alerting |
  |---|---|---|
  | `home.census_occupancy` | Virtual ward occupancy vs. slots | — |
  | `home.unacked_critical_vitals` | Unacked critical vitals (count · max age) | **Critical → Eddy** |
  | `home.escalation_response_p90` | Escalation response p90 (minutes) | Warn / Crit |
  | `home.visit_compliance_today` | Waiver visit compliance % | **Critical** |
  | `home.device_offline_pct` | Kits offline / transmission gaps | Warn |
  | `home.rpm_adherence` | Patient monitoring adherence % | Watch |
  | `home.escalation_rate_7d` | Escalations per 100 episode-days | Watch |
  | `home.referral_conversion_7d` | Referral → enrollment conversion % | — |
  | `home.avoided_bed_days_mtd` | Avoided bed-days MTD (executive) | — |

- **Drill:** add `'home'` to `DrillBuilder::DOMAINS` + `TITLES` (`app/Services/Cockpit/DrillBuilder.php`)
  and mirror it in `cockpitDrillDomains` (`resources/js/types/cockpit.ts`); wire the detail source in
  `DrillBuilder::build()`. A `?drill=home` modal exposes the census strip, alert list, funnel snapshot,
  and response-time trend; **wall-display mode inherits automatically.**
- **The decant line (the whole thesis, made literal):** the RTDC global huddle gains a **"home decant"
  line** — home-eligible counts and free slots surfaced next to boarding metrics.

---

## 10. Intelligence layer

### 10.1 Home Early Warning Score (HEWS) & escalation risk
- Compute **deterministically first** (transparent SQL/PHP over opaque ML, matching the Predictions
  philosophy). Blend a modified **NEWS2** with patient-specific baselines calibrated over the first 24 h,
  vital-sign trend slopes, and a monitoring-adherence signal. Bands live in metric-definition-style
  config; leave an ML upgrade path via a sidecar later.
- A daily per-episode **escalation-risk tier** (condition, day-of-stay, HEWS trajectory, adherence, visit
  findings) drives visit intensity and the command-grid **sort order**.
- **Label it clearly as operational triage, not diagnosis** (§4.2).

### 10.2 Capacity forecasting & avoided bed-days
- Enrollment-pipeline forecast (home-eligible ED census + step-down candidates near expected LOS) +
  per-episode discharge projection → **free-slot forecast at 24 h / 48 h / 7 d**, surfaced in the RTDC
  global huddle and written alongside physical capacity via `prod.rtdc_predictions` /
  `ForwardProjectionService`.
- Every active home episode-day rolls up as an **avoided occupied bed-day** into the ops materialized
  views and the executive brief — the ROI accounting that makes the program legible to a COO.

### 10.3 Eddy actions & Arena
- Add the seven draft-only Home actions to `EddyActionService::CATALOG` (§6.8). This is where the
  June-2025 plan's **Post-Acute and Care Transition Agent** lands.
- Arena gains object types (`HomeEpisode`, `RpmKit`, `HomeVisit`, `Escalation`) + conformance checks
  (time-to-activation SLA, visit cadence, escalation-protocol adherence); the **48-Hour Flow Review**
  folds in the home program (§6.7, §6.9).

---

## 11. Compliance, safety & privacy (build these in, not on)
- **Waiver conditions as first-class telemetry.** Visit compliance, response times, and CMS-required
  reporting are **generated from the `prod.home_*` tables**; extend the append-only user-audit ledger to
  home-episode access. Capturing the 2028 study variables (readmission, mortality, escalations, equity)
  as operational data makes the federal report a byproduct.
- **Clinical alarm governance.** Alert burden per patient-day is itself a tracked KPI; thresholds are
  personalized to each patient's baseline; clinical alerts are flap-damped exactly as `AlertEngine`
  damps operational tiles.
- **Equity & selection-bias guardrails.** Connectivity screening (prefer cellular-backhauled kits),
  language/caregiver requirements, and **decline-reason analytics** surface selection bias before it
  becomes a finding in the federal study.

---

## 12. Phased roadmap — build order, deliverables & Definition of Done

Each phase is independently demonstrable against the Summit Regional demo hospital. **Do not proceed to
the next phase until the current DoD passes.** Estimates are from the strategy doc.

### Phase 0 · Foundation *(4–6 wks)*
**Scope:** schemas & migrations (§7); virtual-unit seeding; feature flag (`config/home_hospital.php`) +
`EnsureHomeHospitalEnabled` + HOME nav domain; a **synthetic RPM connector** + demo cohort in the demo
seed; census board via RTDC reuse.
**Deliverables:** all `prod.home_*` / `prod.rpm_*` migrations (with the partitioning decision documented);
`virtual_home` unit + slots seeded; `HomeHospitalDemoGenerator` + `HomeHospitalDemoSeeder` wired into
`zephyrus:demo-refresh`; `SyntheticRpmConnector` (copy of `SyntheticHealthcareConnector`); `/home/census`
rendering real seeded slots; nav domain visible only when the flag is on.
**DoD:** `php artisan migrate` clean on a fresh `zephyrus_test`; `php artisan test` green; `./vendor/bin/pint`
clean; flag **off** → `/home/*` returns 404 and the nav domain is hidden; flag **on** → `/home/census`
shows the seeded virtual ward; `zephyrus:demo-refresh --validate` passes (0 critical, idempotent).

### Phase 1 · Observability MVP *(6–8 wks)*
**Scope:** vitals ingestion pipeline (connector → `raw.inbound_messages` → canonical events →
`RpmProjectionHandler` → `prod.rpm_observations` + FHIR `Observation`); HEWS + patient alerts with an
**acknowledgement workflow**; **Virtual Ward Command** page (`/home/command`); cockpit home tiles +
`?drill=home`; escalation workflow with **response timers**.
**Deliverables:** `RpmProjectionHandler` registered in `AppServiceProvider`; new canonical event
constants; `HomeMetrics` provider + seeded `home.*` definitions (alert rows only where §9 says);
`'home'` in `DrillBuilder::DOMAINS` + `cockpitDrillDomains`; `home.` Eddy `CATALOG` entry so
`home.unacked_critical_vitals` routes to `propose_escalation_response`; the ADT escalation-close loop.
**DoD:** a synthetic breached vital flows end-to-end to an acked `prod.rpm_alerts` row and a cockpit
tile; a critical vital raises exactly one flap-damped alert that seeds a **draft** Eddy action requiring
human approval (never auto-executes); `?drill=home` + `?display=wall` render; `scripts/check-ui-canon.sh`
passes; the `verify` skill drives the command page and observes a live escalation timer.

### Phase 2 · Transitions *(6–8 wks)*
**Scope:** referral funnel + eligibility worklists (ED + step-down); transitions board; care-transition &
regional-facility handoffs (reuse `transport_requests` + `regional.facilities`); 30-day post-discharge
cohort; logistics/visit board.
**Deliverables:** `/home/referrals`, `/home/transitions`, `/home/logistics`; ED-diversion worklist wired
to the live ED census; step-down worklist wired to encounters at/near expected LOS; decline-reason capture
+ analytics; two-visits-per-day compliance rail; handoffs writing `transport_requests` with
`request_type = care_transition`.
**DoD:** a referral traverses referred → activated and creates a `home_episodes` row on the virtual unit;
an outbound handoff produces a `transport_request` + a `regional.transfer_decisions` row with
opportunity-cost scoring; the post-discharge cohort tracks a step-down cadence; physical address appears
**only** in the logistics context (grep-verify it is absent from other props/payloads/URLs).

### Phase 3 · Intelligence & compliance *(8+ wks)*
**Scope:** capacity/discharge forecasting into the RTDC huddle; Eddy catalog actions (all seven); OCEL
projection + conformance; executive ROI tiles; CMS waiver reporting exports.
**Deliverables:** home free-slot forecast in `ForwardProjectionService` + `prod.rtdc_predictions`; the
huddle **"home decant" line**; avoided-bed-days rollup into `ops.mv_*` + the executive brief; OCEL
`EmissionMap::forHomeEpisode()` + `OcelProjector::collectHomeEpisodes()` + catalog rows; home reference
pathways in `ClinicalPathwaySeeder` + conformance checks; CMS/2028-study export capturing the mandated
variables.
**DoD:** the huddle shows home-eligible counts + free slots next to boarding metrics; the 48-Hour Flow
Review includes home objects; a CMS reporting export produces the waiver + 2028-study variables from the
`prod.home_*` tables; all Eddy home actions are draft-only and human-approved.

### Later *(not scheduled)*
Chronic RPM lines (HF, COPD), SNF-at-home, multi-facility program operations, payer-facing reporting.

---

## 13. Open questions — surface, don't silently decide

Raise each with product/clinical leadership; state your recommended default and proceed on it only if unblocked:
1. **Which conditions first?** Evidence points to **heart failure, COPD, pneumonia/respiratory infection,
   cellulitis, UTI**. *(Default: seed exactly these as the initial `home_programs` condition set.)*
2. **Field staffing model:** employed nurses vs. contracted community paramedics. *(Affects the logistics
   board's assignment model — build it staffing-model-agnostic.)*
3. **Target daily census & service radius** for the pilot. *(Affects slot capacity per service zone and
   the demo cohort size; Kaiser matured ~7→13 ADC, Mount Sinai ~30/mo → 50–60.)*
4. **Which single RPM vendor** to integrate first for the Phase 1 MVP. *(Until decided, Phase 1 rides the
   `SyntheticRpmConnector`; the `HealthcareConnector` abstraction keeps you vendor-agnostic.)*
5. **`rpm_observations` partitioning** (§7) — introduce a new range-partitioning convention now, or ship
   unpartitioned with a retention/rollup policy and add it later? *(Default: ship unpartitioned in
   Phase 0/1 with a documented rollup; propose partitioning as a reviewed follow-up.)*
6. **Payer-aware eligibility** rules per program line — confirm the payer matrix (RPM Medicaid/commercial
   coverage varies) before hardening the referral screen.

---

## 14. Reconciliation notes — deltas from the strategy doc

Discovered while grounding this prompt against the live codebase. These **correct or sharpen** the
strategy doc; follow the code truth.

1. **No KPI "left status stripe."** The doc/DESIGN describe a 3px left status stripe on KPI tiles; the
   actual `KpiTile.tsx` **removed it** in favor of a status **dot** (with a "replaces the banned
   side-stripe" comment). Use the existing tile as-is; do not add a stripe.
2. **No table-partitioning exists in the repo.** The doc's "monthly range-partitioned `rpm_observations`"
   would be a *new* convention. Treat it as §13 Q5 — decide explicitly; don't silently introduce it.
3. **`{table}_id` PK and `_uuid` are separate columns**, not one. The `_id` is the bigint PK; the
   `_uuid`/`idempotency_key` are dedupe/public keys.
4. **Feature-gating middleware is applied inline by FQCN** (Laravel 11 `bootstrap/app.php` style) — there
   is no `$routeMiddleware` alias array to edit. Copy the Virtual Rounds wiring exactly.
5. **`prod.units.type` needs no constraint migration** for `virtual_home` (free-form string), but
   `prod.beds.status` and `prod.gmlos_references.unit_type` **are** CHECK-constrained — extend those
   deliberately if home slot states or GMLOS references require it.
6. **`ProjectionHandler`s are hand-registered** in `app/Providers/AppServiceProvider.php` (~L95) — a new
   `RpmProjectionHandler` must be added there; there is no auto-tagging.
7. **Adding an Eddy action / OCEL object type is purely additive** — a `CATALOG` row (Eddy) or an
   `OcelCatalog` entry + one `EmissionMap::forX()` transformer + one `OcelProjector::collectX()` call.
   Don't over-engineer these.

---

## 15. Testing, verification & done

- **Backend:** `php artisan test` (add Feature tests for the ingestion pipeline, HEWS computation,
  escalation timers, the decant forecast, and gating-off 404s; Unit tests for `HomeMetrics` and the
  deterministic HEWS/forecast math). `./vendor/bin/pint` clean.
- **Frontend:** `scripts/check-ui-canon.sh` passes (raw-palette ratchet may only go down); `npm run build`
  clean. Verify dark **and** light themes, `prefers-reduced-motion`, and wall-display (`?display=wall`).
- **Demo integrity:** `zephyrus:demo-refresh --validate` stays at 0 critical / 0 warnings and remains
  idempotent; synthetic tiles carry the demo `ProvenanceBadge`.
- **End-to-end:** use the `verify` skill to drive each new surface in the running app (`./start-dev.sh`,
  Laravel :8001 / Vite :5176) and observe real behavior — not just green tests. Confirm the one-instrument
  story renders: from the cockpit/huddle you can see home-eligible boarders and free home slots together.
- **Per-PR:** small, reviewable PRs per phase-slice; DEVLOG entry under `docs/` (e.g.
  `docs/devlog/DEVLOG-home-hospital-YYYY-MM-DD.md`) following the existing DEVLOG convention; note any new
  convention (esp. partitioning) for review. Never deploy to production — that is manual-only.

---

## 16. The one-sentence definition of success

> Zephyrus becomes the **only command center that connects home capacity to ED boarding, floor occupancy,
> and discharge planning in a single, defensible view** — running the virtual ward as a lever on the whole
> hospital's throughput, with clinical-grade monitoring that never cries wolf and governance that keeps a
> human in every consequential loop.
