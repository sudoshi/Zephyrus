# Laboratory L-14 Phase Verification and Release-Gate Evidence

| Field | Evidence |
| --- | --- |
| Gate | L-14 — Pass the Laboratory phase verification and release gate |
| Date | 2026-07-13 |
| Branch | `agent/ancillary-radiology-foundation` |
| Baseline commit | `c6c19a1` (L-13) |
| Frozen demo anchor | `2026-07-13T14:32:00Z` |
| Test database | `zephyrus_test` only; reset after verification with zero owned ancillary/Laboratory/AP/Blood Bank demo facts |
| Production | Not accessed, mutated, activated, migrated, or deployed |
| Result | PASS — no accepted functional, privacy, invariant, accessibility, or release failure |

## Release-gate outcome

L-14 closes the Laboratory phase with a green schema, ingest, projection, demo, service, API, cross-domain, Cockpit, Study, OCEL, migration, query-plan, frontend, and rendered-browser matrix. The gate found and repaired three correctness defects rather than recording them as limitations:

- Explicit demo anchors were not frozen while the ancillary SLA evaluator ran, so wall-clock time could create a mathematically invalid open breach. The scenario coordinator now freezes the supplied clock for generation/projection and restores the caller's prior clock in `finally`.
- A same-day scheduled refresh could reuse canonical rows with stale receipt times, leaving superseded owned provenance and OCEL rows. Exact-owner synthetic events now replace their canonical assertion in place, deterministic demo order UUIDs survive the projection path, superseded owned Laboratory provenance is removed, and ancillary OCEL rows are rebuilt without deleting shared context.
- A generated discharge blocker could reference an eligible encounter that fell outside the capped Discharge Priorities cohort. The generator now selects from the actual visible service cohort while retaining the same current/non-ED/expected-discharge eligibility rules.

No connector, credential, endpoint, feature switch, scheduler change, queue activation, external interface, production migration, clinical writeback, or deployment was performed.

## Automated matrix

| Gate | Result |
| --- | --- |
| Dirty PHP Laravel Pint | PASS, 7 files |
| Focused demo/Laboratory/OCEL regression | PASS, 14 tests / 226 assertions |
| Full PHP suite | PASS, 1,096 passed / 18,043 assertions / 1 intentional fixture-regeneration skip / 545.95 s |
| Registered GET route smoke | PASS, 103 GET routes / 0 failures, inside the full suite |
| Route inventory | 426 routes; all six Laboratory pages present with session authentication |
| Vitest | PASS, 107 files / 443 tests |
| TypeScript | PASS, `npx tsc --noEmit` |
| Production build | PASS, 7,895 modules / 43.09 s; accepted warnings listed below |
| UI canon | PASS; 104 pre-existing arbitrary-line-height warnings only |
| Playwright canonical matrix | PASS, 49 passed / 1 isolated stale-fixture skip |
| Playwright stale fixture | PASS, 1 test |
| Screenshot evidence | PASS, 16 full-page images |
| `git diff --check` | PASS after evidence/checklist finalization |

The Playwright phase specification covers `/lab`, `/lab/specimens`, `/lab/pending-decisions`, `/lab/blood-bank`, `/lab/anatomic-path`, and `/analytics/lab-tat` in dark desktop and representative light tablet/mobile viewports. It asserts one level-one heading, a semantic main region, selected theme, document containment, no console/page errors, visible keyboard focus, keyboard details/theme controls, non-color state semantics, preserved filter provenance, and absence of forbidden browser fields.

The broader browser run also reconciles ED Treatment, RTDC Discharge Priorities, Perioperative case management, RTDC Ancillary Services, and Cockpit Flow to their owned Laboratory destinations. The final targeted readiness test proves the generated discharge blocker is present in the actual capped Discharge Priorities cohort and its exact Decision-Pending drill resolves to one item.

## Canonical demo and strict invariants

The final deterministic refresh and independent validation used the same frozen anchor:

```text
APP_ENV=testing DEMO_MODE=true php artisan zephyrus:demo-refresh \
  --anchor=2026-07-13T14:32:00Z --validate --force --json
PASS: 42 invariants, 0 critical failures, 0 warning failures, published=true

APP_ENV=testing DEMO_MODE=true php artisan zephyrus:demo-validate \
  --anchor=2026-07-13T14:32:00Z --strict --json
PASS: 42 invariants, 0 critical failures, 0 warning failures
```

The exact-owner cohort contains 47 shared orders, 246 milestones, and 20 generated open breaches:

| Department | Orders | Milestones | Open breaches | Satellites |
| --- | ---: | ---: | ---: | --- |
| Radiology | 16 | 97 | 12 | 16 exams, 29 reads, 6 scanners, 1 downtime, 1 critical loop, 2 barriers |
| Laboratory | 14 | 84 | 4 | 16 specimens, 14 result versions, 2 critical callbacks, 3 pending decisions |
| Pathology | 6 | 29 | 2 | 6 AP cases, 2 frozen sections |
| Blood Bank | 6 | 13 | 0 | 6 OR-linked readiness requests, 4 pending gates, 1 active MTP |
| Pharmacy precursor | 5 | 23 | 2 | Shared-spine milestones only; Pharmacy phase remains unimplemented |

Direct reconciliation found zero orphan or mismatched milestones, satellite links, recollect chains, callbacks, decision destinations, OR links, provenance rows, or OCEL source references. The three live clinical-Laboratory decisions resolve separately to a real ED visit, visible discharge encounter, and OR case. All AP and Blood Bank rows resolve to non-deleted OR cases. Cockpit publication remains gated on the same 42-invariant result.

Two same-day refreshes retained exactly 246 canonical owned events, 47 orders, 246 milestones, and 20 breaches while advancing the 15-minute cutoffs. The second refresh left zero superseded Laboratory provenance or ancillary OCEL rows, and the caller's pre-existing Carbon clock was restored after the refresh.

## OCEL D1/D7 and command idempotency

The Laboratory release advances only the process families supported by observed facts:

- D1 Laboratory result verification contains 56 dedicated D1 event rows plus 13 governed D1/D2 joins.
- D7 Pathology specimen-to-diagnosis contains 13 dedicated D7 event rows plus 3 governed D7/C11 joins.
- D5 Radiology remains present and unchanged.

The current owned ancillary window reconciles to 224 OCEL events and 224 distinct source references. Two consecutive bounded `ocel:project-ancillary --json` runs returned the same result: 224 events, 191 objects, 963 E2O links, 268 O2O links, and 224 object changes. Source-reference uniqueness, event/object links, D1/D7 activity names, registered process IDs, replay idempotency, and the existing Arena query paths are covered by the focused OCEL feature tests and the full suite.

## Populated query plans

Plans were captured on the canonical populated cohort with `enable_seqscan=off` only to expose the intended small-fixture planner path:

| Surface | Index path | Rows | Observed execution |
| --- | --- | ---: | ---: |
| Specimen Tracker in-transit queue | `lab_specimens_status_collection_idx` | 1 | 0.011 ms |
| Decision-Pending Results | `lab_results_pending_decision_idx` bitmap path | 14 fact / 8 decision rows | 0.071 ms |
| AP stage aging | `ap_cases_stage_aging_idx` | 1 | 0.017 ms |
| Blood Bank pending OR readiness | `bb_readiness_pending_or_idx` | 5 | 0.014 ms |

These are index-selection observations on a small synthetic dataset, not production latency claims.

## Production-shaped disposable migration rehearsal

A clean migrated test schema was cloned into a disposable rehearsal database (approximately 88 MB; clone time 0.32 s). Governed shared and Radiology facts were loaded before the Laboratory tail so retention could be proven rather than assumed.

The populated Laboratory migration correctly refused destructive rollback and documented forward repair as the safe posture. The same tail rolled back on an empty database in 0.15 s and reapplied additively in 37.74 ms as migration batch 2. Catalog inspection found all six Laboratory/AP/Blood Bank satellite tables, six governing constraints, eight required indexes, nine Laboratory catalog rows, and a complete migration-history row.

`AncillaryReferenceSeeder` plus the deterministic backfill completed in 6.48 s and passed all 42 invariants. The final clone contained 16 Laboratory specimens, 14 results, 2 critical callbacks, 6 AP cases, 6 Blood Bank requests, 16 retained Radiology exams, and 29 retained reads. Row/count fingerprints for the shared and Radiology order, exam, and read facts were unchanged across the Laboratory migration/backfill. The clone contained zero provenance or OCEL orphans and was dropped; no rehearsal database remained.

Rollback posture is deliberately conservative: an empty Laboratory tail can be rolled down and forward; after facts exist, routes may be hidden while schema and facts are retained, and correction must use a forward repair or verified restore rather than destructive rollback.

## Route, scheduler, and activation inventory

All six Laboratory page routes are `GET|HEAD`, session-authenticated, and owned once:

- `/lab`
- `/lab/specimens`
- `/lab/pending-decisions`
- `/lab/blood-bank`
- `/lab/anatomic-path`
- `/analytics/lab-tat`

The existing private Laboratory reads and the one governed barrier mutation are unchanged. The scheduler retains the pre-existing per-minute ancillary SLA evaluation, the registered OCEL jobs, integration health/FHIR maintenance, and the existing six-hour demo refresh in demo mode. L-14 adds no schedule entry. All 15 ancillary integration-source records remain synthetic, inactive, or sandboxed; no LIS, AP-LIS, or blood-bank feed was activated.

## Screenshot index and privacy/design audit

All images use deterministic pseudonymous demo context. Manual inspection covered heading hierarchy, freshness/status placement, filter affordances, breakpoint stacking, chart/table correspondence, theme contrast, keyboard-visible affordances, semantic normal/rework/breach/degraded/stale/empty messaging, and document containment. No direct identifier, result value, report narrative, raw HL7/FHIR payload, source credential, clipped control, or document-level overflow is present.

| File | Surface | Theme / viewport | State evidence |
| --- | --- | --- | --- |
| [flow-board-dark-desktop.png](screenshots/flow-board-dark-desktop.png) | Laboratory Flow Board | dark desktop | populated flow and degraded branch |
| [flow-board-light-tablet.png](screenshots/flow-board-light-tablet.png) | Laboratory Flow Board | light tablet | responsive populated flow |
| [flow-board-stale-light-tablet.png](screenshots/flow-board-stale-light-tablet.png) | Laboratory Flow Board | light tablet | registered stale-source qualification |
| [specimens-dark-desktop.png](screenshots/specimens-dark-desktop.png) | Specimen Tracker | dark desktop | current specimen chains |
| [specimens-light-mobile.png](screenshots/specimens-light-mobile.png) | Specimen Tracker | light mobile | contained responsive tracker |
| [specimens-rework-light-tablet.png](screenshots/specimens-rework-light-tablet.png) | Specimen Tracker | light tablet | rejection/recollect lineage |
| [decision-pending-dark-desktop.png](screenshots/decision-pending-dark-desktop.png) | Decision-Pending Results | dark desktop | three explicit destination classes |
| [decision-pending-light-tablet.png](screenshots/decision-pending-light-tablet.png) | Decision-Pending Results | light tablet | responsive decision queue |
| [decision-pending-breach-light-tablet.png](screenshots/decision-pending-breach-light-tablet.png) | Decision-Pending Results | light tablet | breached downstream gate |
| [blood-bank-dark-desktop.png](screenshots/blood-bank-dark-desktop.png) | Blood Bank Readiness | dark desktop | OR gates and MTP evidence |
| [blood-bank-light-tablet.png](screenshots/blood-bank-light-tablet.png) | Blood Bank Readiness | light tablet | responsive readiness matrix |
| [anatomic-pathology-dark-desktop.png](screenshots/anatomic-pathology-dark-desktop.png) | Anatomic Pathology | dark desktop | stage aging and frozen section |
| [anatomic-pathology-light-mobile.png](screenshots/anatomic-pathology-light-mobile.png) | Anatomic Pathology | light mobile | contained mobile aging view |
| [tat-study-dark-desktop.png](screenshots/tat-study-dark-desktop.png) | Laboratory TAT Study | dark desktop | populated five-clock study |
| [tat-study-light-tablet.png](screenshots/tat-study-light-tablet.png) | Laboratory TAT Study | light tablet | responsive populated study |
| [tat-study-empty-light-tablet.png](screenshots/tat-study-empty-light-tablet.png) | Laboratory TAT Study | light tablet | honest bounded empty state |

## Accepted warnings, limitations, and activation boundary

- `scripts/check-ui-canon.sh` reports 104 existing arbitrary-line-height warnings and exits success; L-14 adds none.
- Vite reports the existing 18-month-old Browserslist database and chunks over 500 kB; the build exits success.
- Playwright reports the existing `NO_COLOR`/`FORCE_COLOR` warning; all assertions pass.
- The phase evidence uses deterministic synthetic data and reference distributions. It proves contract/reconciliation behavior, not production volume, production latency, or local benchmark performance.
- Real LIS, AP-LIS, blood-bank, analyzer, and courier feeds remain unconfigured and governance-gated. Source fidelity will remain coarse where those optional feeds are absent.

None of these warnings or limitations is a Laboratory correctness, freshness, privacy, accessibility, reconciliation, or release failure. Production activation remains a separately authorized action through `deploy.sh` plus any required explicit migration command; L-14 performs neither.
