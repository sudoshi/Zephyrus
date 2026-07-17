# DEVLOG — Home Hospital Module · Phase 0 Foundation (2026-07-17)

**Branch:** `feature/home-hospital-phase-0`
**Source of truth:** [ACUM-PRD-HAH-001](./home-hospital/Zephyrus_Hospital_at_Home_Strategy_and_Design.md) · [Build brief](./home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md)

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
