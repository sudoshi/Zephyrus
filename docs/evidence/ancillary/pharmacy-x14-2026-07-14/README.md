# Pharmacy X-14 Phase Verification and Release-Gate Evidence

| Field | Evidence |
| --- | --- |
| Gate | X-14 — Pass the Pharmacy phase verification and release gate |
| Date | 2026-07-14 |
| Branch | `agent/ancillary-radiology-foundation` |
| Baseline commit | `9743977` (X-13) |
| Frozen demo anchor | `2026-07-14T14:32:00Z` (migration rehearsal); `2026-07-11T14:00:00Z` (deterministic service/safety fixtures) |
| Test database | `zephyrus_test` only; restored to zero owned ancillary/Pharmacy facts and zero integration sources after verification |
| Production | Not accessed, mutated, activated, migrated, or deployed |
| Result | PASS — no accepted functional, privacy, invariant, safety-boundary, or release failure |

## Release-gate outcome

X-14 closes the Pharmacy phase — and with it the full three-department ancillary
surface (Radiology, Laboratory/Pathology/Blood Bank, Pharmacy). The gate covers
schema, RDE/RDS/verification-queue ingest, ADC and warehouse/BCMA ingest, demo,
services, APIs, the five operational workspaces, the TAT Study, the Cockpit
Pharmacy Flow drill, the ED boarder medication lens, the complete RTDC readiness
vector (imaging + lab + medication), D11/D12 OCEL projection, a production-shaped
migration rehearsal, populated query plans, route/scheduler/source-activation
inventory, and the phase-wide individual-risk-field safety boundary.

The gate found and repaired one real correctness defect (flagged during X-5)
rather than recording it as a limitation:

- **SlaEvaluator late-stop constraint violation.** When a defensible late stop
  assertion arrived with an `occurred_at` earlier than a breach's materialized
  `breached_at` — the canonical case being a warehouse/BCMA administration whose
  physical instant precedes the moment the SLA nominally opened its breach —
  `SlaEvaluator::clearBreach` recorded `cleared_at = stop.occurred_at`, which
  violated the `ancillary_breaches_clear_time_check` (`cleared_at >= breached_at`).
  The failed UPDATE poisoned the evaluation transaction, `evaluateOrderSafely`
  caught and logged it, and the breach stayed permanently open — so the
  per-minute batch retried the same order forever. The fix clamps `cleared_at`
  to `breached_at` when the defensible stop precedes it, while preserving the
  true `stop_assertion_id` and the true pre-breach `elapsed_minutes_at_clear`
  for §6.4 defensible clock reconstruction, and recording a
  `late_stop_retraction` note in breach metadata. The append-only milestone
  ledger and the check constraint's intent are both preserved.

No connector, credential, endpoint, feature switch, scheduler change, queue
activation, external interface, production migration, clinical writeback, or
deployment was performed.

## Automated matrix

| Gate | Result |
| --- | --- |
| Dirty PHP Laravel Pint | PASS, 4 files, 1 style issue fixed |
| Focused SLA/safety/OCEL regression | PASS, 24 tests / 63,333 assertions |
| Full `--filter=Ancillary` regression | PASS, 306 passed / 74,901 assertions / 1,057.43 s |
| Full `--filter=Pharmacy` regression | PASS, 101 passed / 71,664 assertions / 626.85 s |
| Cockpit / ED-lens / AlertEngine | PASS, 22 passed / 247 assertions / 837.19 s |
| Vitest (focused ancillary/rtdc/cockpit) | PASS, 31 files / 118 tests |
| Vitest (full) | PASS, 113 files / 467 tests |
| TypeScript | PASS, `npx tsc --noEmit` |
| Production build | PASS, `npx vite build`, built in 1m 16s; accepted large-chunk warnings only |
| UI canon | PASS; 104 pre-existing arbitrary-line-height warnings only; raw-palette ratchet ≤ 76 holds |
| Route inventory | 439 routes; all five Pharmacy pages + TAT Study + six Pharmacy APIs present, session-authenticated |
| `git diff --check` | PASS |

## Phase-wide individual-risk-field safety gate (§13 non-negotiable)

X-10 proved the controlled-substance surface carries no individual dimension.
X-14 broadens that guarantee across the ENTIRE Pharmacy phase in a new
`PharmacyPhaseSafetyGateTest` (5 tests). It builds every browser-facing pharmacy
payload — Flow Board, Discharge Meds, IV Room, Dispense, Controlled, TAT Study —
at MAXIMALLY elevated capabilities (patient-detail + barrier-annotation) and:

- Scans the operational data surface of each page for forbidden individual VALUE
  fragments (`user_id`, `staff_id`, `performed_by`, `verifier_ref`,
  `diversion_score`, `diversion_risk`, `risk_score`, `risk_rank`, `ranked_staff`,
  `outlier_user`, `staff_ranking`, …).
- Recursively guards every KEY under the data surface against individual-level
  fragments (`user`, `staff`, `person`, `employee`, `badge`, `actor`,
  `performed_by`, `verifier`, `pharmacist`, `technician`, `nurse`, `risk_score`,
  `riskRank`, `staffRank`, `userRank`, `ranked…`, `diversion`, `outlier`). Bare
  `rank` is deliberately allowed because `sourceRank` is the milestone
  source-precedence rank from §6.5, not a staff ranking.
- Proves the pseudonymous server-side `verifier_ref` (stored in verification
  metadata by the X-2 projector) never reaches ANY browser payload.
- Asserts each page carries its explicit no-user-level-dimension identifier
  policy, and the Controlled scope block declares `unit_and_station` aggregation.
- Statically scans the pharmacy service source for individual actor/score/rank
  column tokens.

The deliberate governance/disclaimer blocks (`privacy`, `scope`, `policy`) are
excluded from the forbidden-field scan because they intentionally NAME the
forbidden dimensions in prose ("no pharmacist, verifier, or user-level …") — the
same design X-10 uses when scanning only the `data` envelope.

## OCEL D11/D12 and command idempotency

D11 (medication order-to-administration) and D12 (discharge medication
readiness) are registered in `HospitalProcessCatalog` and their RX_* milestones
carry `process_ids` through the reference seeder. A new focused test proves the
projection emits real object/event lineage that the existing Arena consumers can
read, not merely a label in `process_models`:

- The `medication-ordered`, `order-verified`, `dose-prepared`, `dose-dispensed`,
  `dose-delivered`, and `dose-administered` events retain governed D11 lineage;
  the readiness-slice events retain D12 lineage.
- `ArenaQueryCatalog::activities_for_object_type('Medication Order')` returns the
  medication activities, and the existing conformance consumer accepts both D11
  and D12 against the projected OCEL document.
- No sensitive encounter reference leaks into the projected document.

`OcelAncillaryProjectionTest` (5 tests) also proves the full three-department
projection, scoped-backfill idempotency, D1/D5/D7 Arena consumption, and honest
partial-projection readiness for all six process families (D1/D5/D6/D7/D11/D12).

## Production-shaped disposable migration rehearsal

A clean migrated `zephyrus_test` schema was cloned into a disposable rehearsal
database (`zephyrus_x14_rehearsal`, ≈43 MB; clone time 10.2 s via
`CREATE DATABASE … TEMPLATE`). The canonical Pharmacy demo cohort was seeded into
the clone so retention and rollback could be proven on populated data rather than
assumed:

| Fact table | Rows |
| --- | ---: |
| `hosp_ref.rx_formulary` | 9 |
| `prod.rx_orders` | 24 |
| `prod.rx_verifications` | 24 |
| `prod.rx_preps` | 4 |
| `prod.rx_dispenses` | 12 |
| `prod.rx_administrations` | 4 |
| `prod.adc_stations` | 3 |
| `prod.adc_transactions` | 15 |
| `prod.rx_discharge_queue` | 2 |

Catalog inspection found all nine satellite tables, 53 Pharmacy check
constraints, 68 Pharmacy indexes (including all eight named partial/unique
indexes), and both projection-guard functions
(`enforce_rx_order_department`, `enforce_rx_discharge_queue_order`). The Pharmacy
migration is recorded in `prod.migrations` at batch 1.

- **Populated rollback refused destructive removal** exactly as designed:
  `prod.adc_transactions contains facts; preserve the satellite data and use a
  forward-repair migration.` After the refusal all nine tables and all 15 ADC
  transactions remained intact.
- **Empty tail rolled down** in 71.63 ms (all nine tables dropped) and
  **reapplied additively** in 192.67 ms as migration **batch 2**, restoring all
  nine tables, 53 constraints, 68 indexes, and both guards.
- **Shared and prior-phase facts were untouched** by the Pharmacy tail cycle:
  66 ancillary orders, 328 milestones, 16 Radiology exams, 16 Laboratory
  specimens, 6 AP cases, and 6 Blood Bank requests were preserved unchanged.

The disposable rehearsal database was dropped; zero rehearsal databases remain.

Rollback posture is deliberately conservative: an empty Pharmacy tail can be
rolled down and forward; after facts exist, routes may be hidden while schema and
facts are retained, and correction must use a forward repair or verified restore
rather than destructive rollback (the migration `down()` throws outside
local/testing and refuses when facts exist).

## Populated query plans

Plans were captured on the seeded rehearsal cohort with `enable_seqscan=off` only
to expose the intended small-fixture planner path:

| Surface | Index path | Observed execution |
| --- | --- | ---: |
| Open STAT/sepsis worklist | Index Only Scan `rx_orders_open_stat_idx` | 0.019 ms |
| Shortage-open orders | Index Only Scan `rx_orders_shortage_open_idx` | 0.014 ms |
| Controlled orders | Index Only Scan `rx_orders_controlled_idx` | 0.027 ms |
| Open verification queue | Bitmap path via `rx_verifications_order_state_idx` | 0.019 ms |
| Open ADC discrepancy | Index Only Scan `adc_transactions_open_discrepancy_idx` | 0.031 ms |
| ADC stockout signal | Index Only Scan `adc_transactions_stockout_idx` | 0.026 ms |

These are index-selection observations on a small synthetic dataset, not
production latency claims.

## Route, scheduler, and activation inventory

All five Pharmacy page routes plus the TAT Study are `GET|HEAD` and
session-authenticated:

- `/pharmacy`
- `/pharmacy/discharge-meds`
- `/pharmacy/iv-room`
- `/pharmacy/dispense`
- `/pharmacy/controlled`
- `/analytics/pharmacy-tat`

The six private Pharmacy read APIs and the one governed barrier mutation
(`POST api/pharmacy/barriers`) are unchanged. The scheduler retains the
pre-existing per-minute ancillary SLA evaluation (`ancillary:evaluate-slas`),
the registered OCEL jobs (`RefreshOcelLog`, `ocel:project`), and the existing
demo refresh; X-14 adds no schedule entry.

The 15 synthetic ancillary source classes cataloged in the reference seeder
(`ehr_cpoe`, `pharmacy_queue`, `pharmacy`, `ivwms`, `adc`, `dose_tracking`,
`bcma_realtime`, `bcma_warehouse`, `ehr_workflow`, plus the Radiology/Lab/AP/
blood-bank feeds) remain synthetic. The FHIR ancillary ingress families
(`MedicationRequest`, `MedicationDispense`, and the rest) are gated behind
`INTEGRATION_ENABLE_ANCILLARY_FHIR`, which defaults to `false`. No live pharmacy,
ADC, warehouse/BCMA, or dose-tracking feed was activated: zero
`integration.sources` rows are live/production/PHI-approved.

## Complete readiness vector and honest freshness split

The gate proves the full ancillary readiness vector accretes across all three
departments — imaging (R-11), lab (L-11), and medication (X-7) axes are all
present in the RTDC surface and the ED boarder lens. The Pharmacy freshness split
is honest: the real-time order-to-dispense segments are cutoff-qualified against
the operational feeds, while the warehouse/BCMA-fed administration tail is always
labeled batch/cutoff-qualified and never presented as real-time (proven by
`test_administration_segments_are_batch_cutoff_qualified_and_never_real_time` and
the SlaEvaluator warehouse-clock coverage).

## Rendered-browser evidence and screenshots

`tests/e2e/pharmacy-phase-gate.spec.ts` mirrors `laboratory-phase-gate.spec.ts`.
It audits the five operational pages and the Pharmacy TAT Study in dark desktop
and representative light tablet/mobile viewports, asserting one level-one
heading, a semantic main region, the selected theme, a status region, document
containment, no console/page errors, keyboard focus/theme control, and the
absence of forbidden raw source/individual browser keys. It also reconciles the
ED medication lens, the RTDC full imaging+lab+medication vector, and the Cockpit
Pharmacy Flow drill (`/pharmacy?lens=stat|sepsis|shortage&source=cockpit`) to
their owned destinations, and captures normal/surge/shortage/discharge-blocked/
degraded/stale/empty screenshots into `screenshots/` when driven with
`X14_SCREENSHOT_DIR` and `X14_CAPTURE_STATES`.

**Harness caveat (honest limitation).** The Playwright specs target a live
authenticated `php artisan serve` instance at `:8084` seeded with the canonical
demo fixture. This sandbox terminates persistent dev servers (SIGKILL/exit 144),
so a live browser run and its full-page PNG capture could not be executed inside
this environment. The spec is committed as a durable, TypeScript-verified
artifact (it passes `npx tsc --noEmit` and `npx vite build`) to be run in a
harness that can serve the app; the `screenshots/` directory is created and
reserved for that run. The equivalent contract, privacy, freshness, and
reconciliation behavior the browser assertions cover is independently proven by
the passing PHP service/Inertia/API tests, the phase-wide safety gate, and the
Zod-schema vitest suites. This is the same session-driver caveat L-14 recorded.

## Accepted warnings, limitations, and activation boundary

- `scripts/check-ui-canon.sh` reports 104 pre-existing arbitrary-line-height
  warnings (Transport pages) and exits success; X-14 adds none.
- Vite reports chunks over 500 kB; the build exits success.
- The Playwright browser run and its screenshots are deferred to a harness that
  can serve the app (see the harness caveat above).
- The top-level `zephyrus:demo-refresh`/`zephyrus:demo-validate` commands drive
  the full Summit demo pipeline; the 42-invariant ancillary gate they enforce is
  proven at a frozen anchor by `AncillaryDemoScenarioTest`
  (`test_ancillary_invariants_and_json_validation_surface_are_explicit`,
  `test_forced_critical_invariant_failure_prevents_cockpit_publish`,
  `test_refresh_uses_the_explicit_anchor_for_sla_evaluation_and_restores_the_clock`,
  and the two-refresh idempotency tests), all green in the full regression above.
- The phase evidence uses deterministic synthetic data and reference
  distributions. It proves contract, reconciliation, safety, and rollback
  behavior — not production volume, production latency, or benchmark performance.
- Real pharmacy, ADC, warehouse/BCMA, dose-tracking, and eMAR feeds remain
  unconfigured and governance-gated. Source fidelity will remain coarse where
  those optional feeds are absent, and the warehouse-fed administration tail
  stays batch/cutoff-qualified by design.

None of these warnings or limitations is a Pharmacy correctness, freshness,
privacy, safety, reconciliation, or release failure. Production activation
remains a separately authorized action through `deploy.sh` plus any required
explicit migration command; X-14 performs neither.
