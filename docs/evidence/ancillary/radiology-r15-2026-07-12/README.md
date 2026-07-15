# Radiology R-15 Phase Verification and Release-Gate Evidence

| Field | Evidence |
| --- | --- |
| Gate | R-15 — Pass the Radiology phase verification and release gate |
| Date | 2026-07-12 |
| Branch | `agent/ancillary-radiology-foundation` |
| Baseline commit | `85d4cf972744f90bdb140ca437fed49b5e3bd779` |
| Test database | `zephyrus_test` only; reset to zero ancillary orders, milestones, exams, and owned transport rows after verification |
| Production | Not accessed, mutated, activated, migrated, or deployed |
| Result | PASS — no accepted test failure; only documented pre-existing UI-canon and build warnings |

## Release-gate outcome

R-15 closes the Radiology phase with a green backend, frontend, route, schema, demo, OCEL, query-plan, migration, and rendered-browser matrix. The gate also found and repaired release-blocking defects rather than documenting them away:

- Laravel's PostgreSQL `migrate:fresh` only cleared the configured `prod,public` search path, so prior test processes could leak rows from `ops`, `integration`, `hosp_org`, and other schemas. `tests/TestCase.php` now performs a database-name-guarded, test-environment-only reset of mutable non-system schemas while preserving migration-owned catalogs.
- The demo gate compared a date-valued expected discharge to a timestamp, emitted an evening census snapshot fixed at noon, randomized a deterministic transport SLA cohort during final tuning, and used an implausible 50/25/25 routine/urgent/STAT mix. Calendar-date comparison, current-minute census reuse, scenario-owned transport deadlines, and a deterministic 60/30/10 mix with exactly four of twenty active requests overdue now pass strict validation repeatedly.
- Empty SLA scopes serialized as JSON arrays on the Flow Board although the browser contract requires records. The HTTP serializer now preserves the promised JSON object shape, with unit and first-render/API parity coverage.
- Screen-reader-only classes applied directly to accessible Radiology chart tables allowed the table formatting context to widen a 768 px viewport. Screen-reader-only wrappers preserve the accessible tables without document overflow.

No connector, credential, endpoint, feature switch, scheduler change, queue activation, external interface, production migration, or application deployment was performed.

## Automated matrix

| Gate | Result |
| --- | --- |
| File-scoped and dirty Laravel Pint | PASS, 14 dirty PHP/test files |
| Focused demo temporal regression | PASS, 1 test / 4 assertions |
| Focused operational demo regression | PASS, 3 tests / 66 assertions |
| Focused ancillary serializer and Flow Board contract | PASS after JSON normalization guard |
| Full PHP suite | PASS, 1,031 passed / 16,664 assertions / 1 intentional skip / 198.22 s |
| Feature route smoke | PASS, 97 GET routes / 0 failures |
| Route inventory | 413 routes; all 13 Radiology page/API names present |
| Scheduler inventory | Ancillary SLA every minute; OCEL incremental every 15 minutes; full OCEL daily at 02:30; no new schedule activated |
| Vitest | PASS, 101 files / 421 tests |
| TypeScript | PASS, `npx tsc --noEmit` |
| Production build | PASS, 7,883 modules; only the existing stale Browserslist database and large-chunk warnings |
| UI canon | PASS; 104 pre-existing arbitrary-line-height warnings only |
| Playwright canonical matrix | PASS, 15 passed / 1 stale-fixture-only skip |
| Playwright stale fixture | PASS, 1 test |
| `git diff --check` | PASS |

The Playwright gate asserts the expected level-one heading, semantic main region, selected light/dark theme, zero document-level horizontal overflow, zero browser console errors, and zero uncaught page errors for every surface. It also proves keyboard activation of worklist details, visible focus, keyboard theme switching, a positive real breach count, the explicitly degraded read queue, the bounded empty Study state, and the stale-source qualification.

## Canonical demo and strict invariants

The final deterministic refresh used anchor `2026-07-12T21:12:00Z` against `zephyrus_test`:

```text
zephyrus:demo-refresh: PASS
domains: flow, operational, tuning, ancillary, ancillary_ocel, source_freshness
invariants: 35 total, 0 critical failed, 0 warning failed
published: true

zephyrus:demo-validate --strict: PASS
35 total, 0 critical failed, 0 warning failed
```

The owned ancillary cohort contained 26 orders and 140 milestones:

| Department | Orders | Milestones | Generated open breaches |
| --- | ---: | ---: | ---: |
| Radiology | 16 | 97 | 12 |
| Laboratory | 5 | 20 | 2 |
| Pharmacy | 5 | 23 | 2 |

Radiology satellites reconciled to 16 exams, 29 reads, six scanners, one scanner-downtime record, one critical-result loop, and two owned barriers. The rendered all-orders Flow Board showed 15 open orders, a positive open-breach cohort, one discharge blocker, and one explicitly degraded order.

Strict validation additionally proved the corrected operational scenario at 500 inpatient beds, matching active encounters and occupied beds, current decision sources, a 60% routine / 30% urgent / 10% STAT transport mix, and exactly 4/20 active requests overdue.

## Cross-module, Cockpit, and OCEL evidence

Before the final test-database reset, direct reconciliation queries proved:

- one live Radiology discharge blocker joined to a current encounter;
- ten Radiology ED-context exams joined to real non-deleted ED visits;
- one IR exam joined to a real OR case;
- one gated Cockpit snapshot containing Radiology data, with `published=true` from the zero-failure invariant gate; and
- 97 Radiology milestone events among 140 ancillary milestone events in OCEL.

The full 90-day OCEL projection and reconcile reported:

```text
events 717; objects 530; E2O 2,295; O2O 360; object changes 142
source prod.ancillary_milestones: 140 rows / 140 distinct refs / 140 projected events
source prod.transport_requests: 200 requests / 575 projected lifecycle events
source prod.barriers: 2 rows / 2 projected events
```

Two consecutive bounded `ocel:project-ancillary --json` runs returned identical results: 140 source rows, 140 events, 122 objects, 566 E2O links, 158 O2O links, and 140 object changes. This is the command-level idempotency and reconciliation evidence; the full PHP suite separately covers projection replay and cross-module imaging readiness.

## Populated query plans

All plans were captured on the canonical demo cohort with `enable_seqscan=off` only to make the small-fixture index path observable. Each plan used the intended governed index:

| Surface | Index path | Observed execution |
| --- | --- | ---: |
| Open Radiology worklist | `ancillary_orders_open_idx` index-only scan | 15 rows, 0.008 ms |
| Bounded Radiology TAT cohort | `ancillary_orders_department_ordered_idx` index-only scan | 16 rows, 0.008 ms |
| Bounded declared IR cohort | `rad_exams_ir_scheduled_idx` index-only scan plus incremental sort | 1 row, 0.011 ms |

These measurements are planner-path evidence on a small synthetic dataset, not production latency claims.

## Production-shaped disposable migration rehearsal

The clean, fully migrated `zephyrus_test` schema was cloned into `zephyrus_r15_rehearsal` (74,143,411 bytes; 95 applied migrations). The disposable database began with zero ancillary/Radiology facts.

The exact Radiology tail was rolled back in reverse order:

| Migration | Empty rollback |
| --- | ---: |
| `2026_07_12_000700_add_ir_study_index` | 36.09 ms |
| `2026_07_12_000600_add_ancillary_analytics_indexes` | 41.12 ms |
| `2026_07_11_000500_create_radiology_satellite_tables` | 29.77 ms |

Catalog inspection confirmed `prod.rad_exams` and the TAT/IR indexes were absent while the shared `prod.ancillary_orders` ledger remained present. The same three migrations then ran forward in 26.05 ms, 7.94 ms, and 10.66 ms respectively (0.20 s wall time; 71,308 KB maximum RSS).

After `AncillaryReferenceSeeder`, the rehearsal contained seven Radiology tables, 49 constraints with zero unvalidated constraints, seven required indexes, six modalities, nine subspecialties, all 95 migration rows, and the unchanged zero-row shared ledger. The disposable database was dropped and confirmed absent.

Rollback posture is intentionally conservative: empty Radiology tail objects can be rolled down and forward as rehearsed; the shared append-only ledger remains independent. Once facts exist, application rollback should hide affected routes while retaining schema and facts, and correction should use a forward repair (or verified restoration), not destructive table rollback. Automated migration tests enforce the shared-ledger delete guard and the empty down/up path.

## Screenshot index and design audit

All screenshots use pseudonymous, deterministic demo identifiers. No report narrative, direct identifier, credential, raw HL7, DICOM metadata, or other non-minimum-necessary clinical payload is present.

| File | Surface | Theme / viewport | State evidence |
| --- | --- | --- | --- |
| [flow-board-dark-desktop.png](screenshots/flow-board-dark-desktop.png) | Imaging Flow Board | dark, 1440 px | positive breach cohort, degraded evidence, discharge blocker |
| [flow-board-light-tablet.png](screenshots/flow-board-light-tablet.png) | Imaging Flow Board | light, 1024 px | same operational state at tablet width |
| [worklist-dark-desktop.png](screenshots/worklist-dark-desktop.png) | Order Worklist | dark, 1440 px | breach/warning/degraded rows and governed barriers |
| [worklist-light-mobile.png](screenshots/worklist-light-mobile.png) | Order Worklist | light, 390 px | stacked mobile cards and keyboard-capable details |
| [modality-utilization-dark-desktop.png](screenshots/modality-utilization-dark-desktop.png) | Modality Utilization | dark, 1440 px | normal complete-coverage state |
| [modality-utilization-light-tablet.png](screenshots/modality-utilization-light-tablet.png) | Modality Utilization | light, 1024 px | complete MPPS coverage and bounded chart layout |
| [reads-results-dark-desktop.png](screenshots/reads-results-dark-desktop.png) | Reads & Results | dark, 1440 px | degraded missing-timestamp qualification |
| [reads-results-light-tablet.png](screenshots/reads-results-light-tablet.png) | Reads & Results | light, 768 px | no document overflow after accessible-table repair |
| [reads-results-stale-light-tablet.png](screenshots/reads-results-stale-light-tablet.png) | Reads & Results | light, 768 px | registered stale source and cutoff-qualified queue |
| [tat-study-dark-desktop.png](screenshots/tat-study-dark-desktop.png) | Radiology TAT Study | dark, 1440 px | populated bounded Study with exclusions visible |
| [tat-study-light-tablet.png](screenshots/tat-study-light-tablet.png) | Radiology TAT Study | light, 1024 px | populated responsive Study |
| [tat-study-empty-light-tablet.png](screenshots/tat-study-empty-light-tablet.png) | Radiology TAT Study | light, 1024 px | honest empty bounded cohort |
| [ir-suite-study-dark-desktop.png](screenshots/ir-suite-study-dark-desktop.png) | IR Suite Study | dark, 1440 px | declared-room denominator and shared formulas |
| [ir-suite-study-light-mobile.png](screenshots/ir-suite-study-light-mobile.png) | IR Suite Study | light, 390 px | mobile Study tables/charts remain bounded |

Manual review covered title hierarchy, freshness/status placement, filter affordances, breakpoint stacking, horizontal containment, chart/table correspondence, semantic empty/degraded/stale messaging, dark/light contrast, synthetic-data labeling, and cross-link ownership. No clipped action, hidden status, illegible chart legend, or document-level overflow remained.

## Accepted warnings and activation boundary

- `scripts/check-ui-canon.sh` reports 104 existing arbitrary-line-height warnings and exits success; R-15 adds none.
- Vite reports the existing 18-month-old Browserslist database and a `NavigatorScene` chunk over 500 kB; the build exits success and R-15 does not expand that chunk.
- Playwright reports the existing `NO_COLOR`/`FORCE_COLOR` warning; all browser assertions pass.

No warning represents a failed Radiology behavior, data invariant, privacy boundary, or release check. Production activation remains a separate, explicitly governed action through `deploy.sh` plus any required explicit migration command.
