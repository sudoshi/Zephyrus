# DEVLOG — Home Hospital Module · Phases 0–1 (2026-07-17)

**Branch:** `feature/home-hospital-phase-0`
**Source of truth:** [ACUM-PRD-HAH-001](./home-hospital/Zephyrus_Hospital_at_Home_Strategy_and_Design.md) · [Build brief](./home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md)

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
