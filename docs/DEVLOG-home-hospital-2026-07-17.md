# DEVLOG — Home Hospital Module · Phases 0–3 (2026-07-17)

**Branch:** `feature/home-hospital-phase-0`
**Source of truth:** [ACUM-PRD-HAH-001](./home-hospital/Zephyrus_Hospital_at_Home_Strategy_and_Design.md) · [Build brief](./home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md)

---

## Phase 3 · Intelligence & compliance (same day, late night)

### What shipped (Phase 3)

- **The decant line, made literal** (`HomeCapacityService` + `/api/home/decant`
  + the RTDC Global Huddle "Home decant" section): home-eligible counts (ED +
  step-down off the live census) and the free-slot forecast (now / 24h / 48h)
  rendered next to boarding metrics — mounted only when the flag is on, the
  huddle is byte-identical without it. `writePredictions()` upserts the home
  forecast into `prod.rtdc_predictions` (by_2pm / by_midnight) alongside
  physical capacity, same upsert contract as RtdcService.
- **Forward projection**: new `home_slot_free` stream in
  `ForwardProjectionService` (one probable item per expected home discharge,
  provenance `home_hospital.expected_discharge`; absent when the flag is off).
- **Avoided bed-days to the executive brief**: no new code — the brief
  composes from the shared cockpit snapshot (`capacity.snapshot` /
  `executive_brief.compose` tools), so the home domain incl.
  `home.avoided_bed_days_mtd` flows in automatically. Also on the huddle
  decant line + cockpit tile + `?drill=home`.
- **OCEL / Arena**: `EmissionMap::forHomeEpisode/forHomeVisit/forHomeEscalation`
  + `OcelProjector::collectHomeEpisodes()` (flag-gated) + catalog object types
  (Home Episode, RPM Kit, Home Visit, Escalation) and `home` activity verbs —
  the corpus for time-to-activation / visit-cadence / escalation-protocol
  conformance; the 48-Hour Flow Review folds home in via ocel.* as designed.
  Dev smoke: 36 home events projected (activations, visits, escalation chains).
- **Eddy catalog complete**: the remaining six draft-only actions
  (propose_hah_enrollment, propose_stepdown_cohort, propose_visit_reschedule,
  flag_rpm_gap, propose_home_discharge, flag_transition_barrier) — alert_key
  null on all six so crit `home.*` alerts keep routing to
  propose_escalation_response; every one requires human approval.
- **CMS AHCAH waiver / 2028-study export** (`php artisan home:cms-export`):
  episode volume + mean LOS + in-episode mortality + return-to-hospital,
  escalation p90 + 30-minute-floor compliance, waiver-visit compliance,
  monitoring/alert stats, and the equity block the study explicitly demands
  (decline reasons, payer distribution, activation rate BY payer, zone
  distribution) — all from prod.home_* tables, pseudonymous, with national
  benchmarks attached. Refuses politely when the module is off.

### Phase 3 verification

- 34 Home Feature tests green (260 assertions): decant payload + predictions
  rows, home_slot_free stream on/off, all seven Eddy actions draft-only +
  routing invariant, OCEL home pathway on/off (events + object types), CMS
  export variables + pseudonymity + disabled-refusal.
- Fixed in passing: the demo seeder now seeds yesterday's completed waiver
  visits too — the UTC-day-boundary trap (just past midnight UTC, "today's"
  visits are all future) had zeroed OCEL visit events and CMS compliance.
- Full vitest 531 green; Pint / tsc / vite build / check-ui-canon clean.
- Dev smoke: decant 2 free slots / 20 ED + 20 step-down eligible; CMS export
  8 episodes, p90 27.4 min, 100% within-floor, 100% visit compliance.

### Remaining (deliberately out of Phase 3)

- Home reference pathway rows in ClinicalPathwaySeeder + Arena sidecar
  conformance checks (needs the pm4py sidecar running; the OCEL corpus they
  read is now live) — small follow-up.
- Browser walkthrough of all six surfaces + the huddle decant line (kernel-
  level rendering and payloads are test-verified).
- "Later" ring per the brief: chronic RPM lines, SNF-at-home, multi-facility,
  payer-facing reporting.

---

## Phase 2 · Transitions (same day, night)

### What shipped (Phase 2)

- **Referral funnel + eligibility worklists** (`HomeReferralService`,
  `/home/referrals`): ED candidates off the LIVE ED census (stable ESI 3–5,
  boarders first — the decant valve reading real boarding), step-down
  candidates off encounters at/near expected LOS on physical wards; strictly
  ordered funnel (referred → screened → eligible → consented → activated)
  with coded declines at every stage. **activate()** claims a free slot
  (locked), opens the census-spine encounter + episode, assigns an available
  kit + enrollment, and opens the inbound checklist — fails loudly (422) when
  the ward is full.
- **Transitions board** (`HomeTransitionService`, `/home/transitions`):
  inbound activation checklists (consent / home-safety / kit delivery / first
  visit); outbound governed handoffs writing `prod.transport_requests`
  (`request_type = care_transition`) — SNF destinations ride
  `RegionalTransferService::decide()` verbatim (guard widened additively to
  accept care_transition) producing a `regional.transfer_decisions` row with
  candidate scoring + opportunity-cost payload; one open handoff per episode.
  **discharge()** closes the loop: encounter discharged, slot freed, kit
  returned, and routine discharges auto-enroll the **30-day post-discharge
  cohort** (no slot — not an avoided bed-day; step-down cadence BP/SpO2 q12h
  + daily weight, billable under the 2026 RPM codes).
- **Field Ops & Logistics** (`HomeLogisticsService`, `/home/logistics`): the
  2-visits/day waiver compliance rail, per-clinician route assignments, kit
  inventory + low-battery count, deliveries. **The ONE address surface** —
  street addresses live in episode `metadata.logistics_address` and are read
  ONLY here; a test greps every other Home endpoint for leakage.
- Three new pages on the token canon + nav leaves; API endpoints for the
  full referral/transition/logistics workflows.

### Phase 2 verification

- 28 Home Feature tests green (196 assertions). Phase 2 DoD proven: referral
  traverses referred → activated creating the episode on the virtual unit
  (encounter + occupied slot + kit + inbound checklist); SNF handoff produces
  a care_transition transport request + scored transfer decision with
  non-empty opportunity-cost payload; routine discharge frees slot/kit and
  enrolls the 30-day cohort at step-down cadence; **address confinement
  grep-verified across all six non-logistics endpoints**; worklists surface
  live-census candidates (ESI-2 excluded, boarders flagged).
- Cockpit + Eddy regression: 165/166 in the background run; the single
  failure was a worktree env artifact (missing `.env.testing` → flag-on
  domain roster) — green after copying the testing env; the pinned 8-domain
  test remains the flag-off contract.
- `tsc` / `vite build` / `check-ui-canon.sh` / Pint / nav vitest (31) clean.
- Dev smoke: 8-row compliance rail with confined addresses, 4 field
  assignments, live worklists (20 ED + 20 step-down candidates off the real
  dev census), 2 free slots.

---

## Phase 1 · Observability MVP (same day, evening)

User decisions taken before Phase 1: continue on ONE branch (single PR when
demo-ready); HEWS as specified in the brief; §13 defaults confirmed as-is;
prod demo enablement AFTER Phase 1 ships.

### What shipped (Phase 1)

- **HEWS** (`HewsService`): deterministic modified NEWS2 — absolute banding
  over the RPM-observable set (RR, SpO2, SBP, HR, temp; consciousness and
  supplemental-O2 deliberately omitted, documented), + baseline-deviation vs
  the enrollment's first-24h calibration (>15% drift, cap +2), + short-window
  trend (SpO2 falling ≥3 abs / HR rising ≥15%, cap +2), + adherence (+1 when
  <half the expected cadence arrived). Bands in `config/home_hospital.php`.
  Labeled operational triage, not diagnosis, everywhere it surfaces.
- **Patient alerting** (`RpmAlertEvaluator` → `prod.rpm_alerts`): personalized
  thresholds (`monitoring_plan.thresholds` over `vital_thresholds` defaults);
  ONE open alert per (episode, rule) — repeat breaches refresh metadata and
  may escalate warning→critical in place, never downgrade; evaluation runs
  only on first projection (replays never inflate). Ack/resolve API records
  the acting human.
- **Escalation workflow** (`HomeEscalationService` + API): open → dispatch →
  arrive → resolve timing chain; `response_minutes` stamped at arrival (the
  p90 tile input); one open escalation per episode. **ADT close-loop** hooked
  into `PatientFlowHl7IngestPipeline`: an admit/register for a patient with an
  open escalation resolves it `ed_return` (flag-gated, guarded — can never
  fail an ADT ingest).
- **Cockpit**: `HomeMetrics` provider (9 `home.*` keys seeded; Earned-Red
  ration = alert_template only on unacked-criticals / response-p90 /
  visit-compliance / kits-offline). The home domain is ABSENT from the
  snapshot when the flag is off (SnapshotBuilder skips the empty flag-gated
  provider — deployments without the module stay byte-identical).
  `?drill=home` (ward board + referral funnel tables); crit `home.*` alerts
  route to the new draft-only Eddy `propose_escalation_response`.
- **Virtual Ward Command** (`/home/command`): episode tiles sorted by
  escalation risk (breach → HEWS → acuity); HEWS chip, SpO2/HR sparklines,
  next-visit countdown, device status; coral ONLY for an unacked critical.
- **FHIR**: each first-projection observation persists as a US Core
  vital-signs Observation in `fhir.resource_versions` (fhir_id =
  observation_uuid, version 1, encrypted out-of-row) + `resource_links` to
  `prod.rpm_observations`. Skips gracefully where the payload store is off.
- **Canonical vocabulary**: the 8 home event constants added to
  `App\Rtdc\Events\CanonicalEvent` (consumed by Phase 3 OCEL).
- **Demo seed**: trailing-12h deterministic vitals + baselines per enrollment;
  HOME-DEMO-001 deliberately declines to SpO2 87% → exactly one open critical
  alert (the DoD path); resolved escalation history (22/28 min); time-aware
  waiver visits (past slots complete, tomorrow's RN visit keeps a countdown).

### Phase 1 verification

- 21 Home Feature tests green (134 assertions) incl.: one-critical-alert
  invariant, HEWS determinism/transparency, breach dedupe via the real
  connector, human-recorded ack/resolve, escalation chain + response_minutes,
  ADT ed_return close (+ stranger no-op), snapshot home domain crit tile +
  `actionForAlert('home.unacked_critical_vitals','crit') =
  propose_escalation_response`, flag-off domain absence, drill tables,
  command-page breach-first ordering, FHIR version+link rows.
- Nav vitest 31 green; Pint / `tsc --noEmit` / `vite build` /
  `check-ui-canon.sh` (ratchet unchanged) all clean.
- Cockpit tiles verified live on `zephyrus_dev`: 9 tiles, honest values
  (occupancy 66.7%, 1 crit unacked vital, p90 27min warn, compliance 100%,
  kits online, adherence 100%).

### Session/environment notes

- **Concurrent-session clobber (evening):** another agent session switched the
  main checkout to `feature/ux-hfe-remediation` mid-build; it checkpointed the
  uncommitted Phase-1 work as `a514138 wip:` on this branch first (nothing
  lost). Work continues in the dedicated worktree
  `~/Github/Zephyrus-home-hospital` (hardlinked vendor/node_modules; isolated
  test DB `zephyrus_test_home` — the tests/bootstrap guard requires the
  `zephyrus_test*` name prefix).
- **`php artisan serve` quirk:** the ServeCommand child intermittently boots
  without APP_KEY on this host; raw `php -S 127.0.0.1:<port> -t public` from
  the worktree serves correctly (root 200). AGENTS.md's "auto-auth on /" note
  is stale — the login gate is real; browser walkthrough needs a login.
- **Remaining manual step:** browser walkthrough of `/home/command`,
  `/home/census`, and `?drill=home` (kernel-level rendering + payloads are
  test-verified; the visual pass needs an authenticated browser session).

---

Phase 0 of the Home Hospital (HOME) workspace: schema, models, feature gating,
virtual-ward seeding, the Virtual Bed Board, and a synthetic RPM ingestion
pipeline — all against the Summit Regional demo hospital.

## What shipped

- **Schema (11 tables, `prod`):** `home_programs`, `home_referrals`,
  `home_episodes`, `rpm_kits`, `rpm_devices`, `rpm_enrollments`,
  `rpm_observations`, `rpm_alerts`, `home_visits`, `home_escalations`,
  `home_transitions`. All follow the `{table}_id` PK + `_uuid` + `patient_ref`
  + `metadata` jsonb + `is_deleted` + CHECK-via-`DB::statement` conventions
  (35 CHECK constraints). Three migrations, validated on `zephyrus_dev`
  (`--path`) and on fresh `zephyrus_test` (RefreshDatabase suite).
- **Feature gating:** `config/home_hospital.php` (`HOME_HOSPITAL_ENABLED`,
  default off) + `EnsureHomeHospitalEnabled` (404 when off) applied inline by
  FQCN on the `home.` web group and `/api/home` group; Inertia
  `features.home_hospital` prop; the HOME nav domain (workspaces section) is
  hidden via a new `NavDomain.requiredFeature` field honored in
  `isDomainVisible` — nav and route gate can never disagree.
- **Virtual ward:** seeded by `HomeHospitalDemoSeeder` (flag-gated, idempotent
  on natural keys): one `prod.units` row `type = virtual_home` (`HOME`, 12
  slots), 8 active episodes on census-spine encounters, referral funnel with
  coded declines, 10 kits / 40 devices / 8 enrollments, waiver-floor visits
  (2/day + tele). Wired into `DatabaseSeeder` (before `DemoTuningSeeder`) and
  `DemoRefreshCoordinator` (flag-gated step).
- **Virtual Bed Board (`/home/census`):** `HomeCensusService` →
  `HomeDashboardController` (Inertia `Home/Census`) + `/api/home/census`.
  Slot grid + program-capacity metric wall + enrollment pipeline, on the
  design-system barrel (`Section`/`MetricGrid`/`metric`), demo rows carry the
  `ProvenanceBadge`. Earned urgency respected: slot states are grey/teal/amber
  informational; no coral anywhere in Phase 0.
- **Synthetic RPM pipeline:** `SyntheticRpmConnector` (full lifecycle copy of
  `SyntheticHealthcareConnector`: webhook/poll/backfill/replay, dead-letter,
  watermarks, encrypted payload store) + `RpmEventVocabulary`
  (`ObservationRecorded`, `DeviceStatusChanged`) + normalizer/mapper +
  `RpmProjectionHandler` (registered in `AppServiceProvider`) projecting into
  `prod.rpm_observations` and device state. Proven by Feature tests:
  breach-free ingest, idempotent replay, unknown-patient dead-letter.

## Decisions (with rationale)

1. **`rpm_observations` ships UNPARTITIONED** (build brief §13 Q5 default).
   No declarative partitioning convention exists in this repo and one must not
   arrive silently. Retention: >18-month observations are rollup-eligible;
   monthly range-partitioning is a proposed, separately-reviewed follow-up.
2. **The virtual ward is NOT in the HospitalManifest.** Manifest-derived
   physical denominators (licensed beds, staffed-bed totals, occupancy tuning
   — which whitelists `icu|step_down|med_surg`) must not absorb virtual slots.
   Instead `RtdcSeeder`'s manifest soft-trim now exempts `type = virtual_home`,
   and `HomeHospitalDemoSeeder` owns the ward. **Watch item:** house-wide
   rollups that enumerate all units will include the ward once the flag is on;
   physical-only surfaces should filter `type != 'virtual_home'` if that reads
   wrong in review.
3. **Slot states map onto the existing `prod.beds` CHECK** (`available |
   occupied | blocked | dirty`); on this unit `dirty` carries the
   "pending kit setup" meaning and is translated to `pending_setup` at the
   service boundary — no CHECK widening in Phase 0.
4. **`NavDomain.requiredFeature` added** (nav SSOT) — the leaf-level
   `requiredFeature` already existed; domain-level gating is additive and
   admin does NOT bypass it (tested).
5. **RpmProjectionHandler pulled forward from Phase 1** — without a projection
   owner, every connector message would dead-letter; a connector that cannot
   land an observation is not demonstrable. HEWS/alerting/FHIR storage remain
   Phase 1.
6. **Dev-DB debt cleared in passing:** `zephyrus_dev` was blocked on
   `2026_07_13_000400` (enterprise scope binding) because `hosp_org` was
   empty and the one active source pointed at `ZEPHYRUS-500` instead of a
   canonical facility key. Ran `SummitDeploymentSeeder`, remapped
   `synthetic-flow-ehr` → `SUMMIT_REGIONAL`/`SUMMIT_HEALTH`, migrated clean.

## Open questions (§13 — decided by default, awaiting product/clinical review)

| # | Question | Default taken |
|---|---|---|
| 1 | First condition set | HF, COPD, pneumonia/resp. infection, cellulitis, UTI (`config/home_hospital.php`) |
| 2 | Field staffing model | Board is staffing-model-agnostic (`home_visits.assigned_to` is an opaque ref) |
| 3 | Pilot census / radius | 12 slots, 3 zones (north/central/south) in the demo seed |
| 4 | First RPM vendor | `SyntheticRpmConnector` until chosen; `HealthcareConnector` keeps it vendor-agnostic |
| 5 | Observation partitioning | Unpartitioned + documented retention (see Decision 1) |
| 6 | Payer matrix | Demo `payer_rules` placeholder on the program row; harden before real referral screening |

## Verification

- `php artisan test tests/Feature/Home/` — 9 passed (53 assertions): gating
  404s, flag-off seeder no-op, Inertia census render, API payload,
  pseudonymity scan (no `mrn`/`address` in wire payload), seeder idempotency,
  RPM ingest end-to-end / idempotent replay / dead-letter.
- Full `vitest` suite 531 passed (nav roster tests updated: 9 workspace
  domains; new feature-gate visibility test).
- `npx tsc --noEmit` clean; `npx vite build` clean; `scripts/check-ui-canon.sh`
  passed (raw-palette ratchet unchanged at ≤76).
- Pint clean on all touched PHP.
- Demo seeder run twice on `zephyrus_dev`: counts stable
  (1 unit / 12 slots / 8 episodes / 6 referrals / 10 kits / 40 devices).
  `RtdcSeeder` re-run does not soft-delete the ward.

## Next (Phase 1 · Observability MVP)

HEWS + patient alerts with acknowledgement workflow, `/home/command` episode
tiles, `HomeMetrics` cockpit provider + seeded `home.*` definitions (Earned-Red
ration per §9), `?drill=home`, Eddy `home.` catalog route
(`propose_escalation_response`), ADT escalation-close loop, FHIR Observation
storage in `fhir.resource_versions`.
