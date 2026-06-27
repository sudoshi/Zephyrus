# Zephyrus — Application Completeness Audit & Phased Implementation Plan

**Date:** 2026-06-26
**Goal:** Advance *every page in every section* to a state where it can plausibly demonstrate the
functionality of the application — no page blank, unavailable, or without plausible, actionable data —
creating demo data and backend support wherever needed.
**Method:** Parallel multi-agent audit (11 section auditors + 1 backend demo-data-gap auditor, ~1.2M
tokens) reading every route, controller, page component, API endpoint, migration, and seeder, followed
by a synthesis pass. Findings below were cross-checked against direct inspection of the route map,
`navigationConfig.ts`, the migration set, and the seeder chain.

---

## 1. Executive Summary

Zephyrus is a **polished but structurally bifurcated** command-center app. A genuinely live operational
spine coexists with large mock/stub/orphan zones:

- **LIVE today (the new spine):** Command Center (`/dashboard`), the entire **Transport** command center
  (11 pages), the **Operations Intelligence** analytics hub (`/analytics/*`), the **Ops Console**
  (Agent Inbox, Executive Brief), **Staffing Office**, the three live **RTDC** ops pages
  (Bed Placement, Global Huddle, Unit Huddle), and **Admin/Profile**.
- **Mock / stub / broken (the older sections):** all four secondary **role dashboards** (RTDC, Periop,
  ED, Improvement), the **entire ED section** (9 pages), the **entire RTDC Analytics/Predictions**
  section (6 nav pages + orphans), the **5 surgical-analytics** sub-pages, the **Periop
  Operations + Predictions** pages, and most of the **Improvement** module.

**The single deepest systemic problem is that demo provisioning is not self-contained in `db:seed`.**
`RtdcSeeder` — the *only* creator of `prod.units` and `prod.beds` — is **not** in `DatabaseSeeder`'s
run list (it is invoked only by the `rtdc:demo-reset` command), and `CommandCenterDemoSeeder` only
*reads/tunes* units, silently no-opping its per-unit loops when units are absent. Two rich features
(Patient Flow 4D Navigator, Facility Model) load **only** via standalone artisan import commands that
are outside the seed pipeline. So a bare `php artisan db:seed` on a pristine DB leaves the RTDC spine
empty and several flagship pages blank.

**Strategy: demo-data-first.** (P0) Make one idempotent command light up every *backed* page → (P1)
un-break the crash/orphan/dead-route pages → (P2–P4) wire the no-prop controllers and mock pages onto
the already-seeded `prod.*` tables and existing `/api` endpoints (mostly plumbing, not new
infrastructure) → (P5) build the few genuinely net-new domains (ED, ancillary, improvement
opportunities/library) and apply cross-cutting polish. **Most remaining work is wiring, not building** —
for the surgical analytics, Periop, and role-dashboard pages, fully-seeded tables and working endpoints
already exist; the pages just import static mock files instead.

### Headline numbers (≈90 product page-entries audited; Design/Examples/Auth excluded)

| Status | Count | Meaning |
|---|---:|---|
| **LIVE** | 29 | Renders plausible, DB-backed/computed, actionable data today |
| **STUB** | 22 | Propless placeholder ("coming soon" / skeletal layout) |
| **MOCK** | 19 | Rich hardcoded/client mock — looks demo-ready but not DB-backed |
| **BROKEN** | 14 | Crash, 404, unrouted orphan, or dead controller+page |
| **PARTIAL** | 6 | Some panels populated, others empty |
| **EMPTY** | 3 | Wired to API/props that return nothing on a fresh seed |

> Reassuring finding: **zero broken nav links.** Every `href` in `navigationConfig.ts` resolves to a
> registered route (commit `dad956e` already remediated the previously-broken set). The BROKEN bucket is
> crashes + *unrouted orphans* (dead code), not menu links that 404.

---

## 2. Full Page Inventory (by section)

Status legend: **LIVE / MOCK / PARTIAL / EMPTY / STUB / BROKEN**. `eff` = effort (S/M/L).

### Command Surfaces (role dashboards)
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Hospital Operations Command Center | `/dashboard` | inertia-db | S | Fully DB-backed via `CommandCenterDataService`; a few secondary panels zero out until P0 (units/beds) |
| MOCK | RTDC Dashboard | `/dashboard/rtdc` | client-mock | M | Controller passes no props; imports `mock-data/rtdc*` |
| MOCK | Perioperative Dashboard | `/dashboard/perioperative` | client-mock | M | `DashboardOverview` ← `mock-data/dashboard.js` |
| MOCK | Emergency Department Dashboard | `/dashboard/emergency` | client-mock | M | All panels ← `mock-data/ed.js` |
| MOCK | Improvement Dashboard | `/dashboard/improvement` | inertia-hardcoded | M | `getImprovementStats()` returns **all zeros** → card counts show 0; rest hardcoded |
| MOCK | Home / System Overview | `/home` | inertia-hardcoded | M | Inline closure; superuser bento wall gated off for this route; lower grid is in-file mock |

### RTDC — Operations
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Bed Placement | `/rtdc/bed-placement` | api-tanstack | S | `/api/rtdc/bed-requests` (needs units/beds → P0) |
| LIVE | Global Huddle (Bed Meeting) | `/rtdc/global-huddle` | api-tanstack | S | `/api/rtdc/bed-meeting` |
| LIVE | Unit Huddle | `/rtdc/unit-huddle` | api-tanstack | M | `/api/rtdc/units…`; ED unit prediction missing (P0) |
| PARTIAL | Patient Flow 4D Navigator | `/rtdc/patient-flow-navigator` | api-tanstack | S | Three.js viewer; **empty until `patient-flow:import-synthetic` runs** (P0) |
| STUB | Bed Tracking | `/rtdc/bed-tracking` | client-mock | M | Hardcoded `metrics` (500 beds) + "coming soon" bed-map |
| MOCK | Ancillary Services | `/rtdc/ancillary-services` | client-mock | L | 100% mock + 30s `Math.random` regenerator; **net-new domain** |
| MOCK | Service Huddle | `/rtdc/service-huddle` | client-mock | L | Mock roster; edits don't persist |
| MOCK | Discharge Priorities | `/rtdc/predictions/discharge` | client-mock | L | Inline closure → `generateMockDischargeData` |

### RTDC — Analytics & Predictions
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| STUB | Utilization & Capacity | `/rtdc/analytics/utilization` | inertia-noprops | L | Propless placeholder |
| STUB | Performance Metrics | `/rtdc/analytics/performance` | inertia-noprops | L | Propless placeholder |
| STUB | Resource Analytics | `/rtdc/analytics/resources` | inertia-noprops | L | Propless placeholder |
| STUB | Trends & Patterns | `/rtdc/analytics/trends` | inertia-noprops | L | Propless placeholder |
| STUB | Demand Forecast | `/rtdc/predictions/demand` | inertia-noprops | L | Propless placeholder |
| STUB | Resource Planning (RTDC) | `/rtdc/predictions/resources` | inertia-noprops | L | Propless placeholder |
| STUB | Risk Assessment | `/rtdc/predictions/risk` | inertia-noprops | M | Most-polished stub but **routed-without-nav**; shows only "—" |
| BROKEN | Department Census | `UNROUTED` | client-mock | M | Orphan: `departmentCensus()` has no route |
| BROKEN | Discharge Prediction (legacy) | `UNROUTED` | client-mock | M | Orphan: `dischargePrediction()` has no route |
| BROKEN | Discharge Predictions | `UNROUTED` | inertia-noprops | S | Orphan: `dischargePredictions()` has no route |

### Transport (the most complete section)
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Transport Command Center | `/dashboard/transport` | api-tanstack | S | `/api/transport/overview` |
| LIVE | Requests (worklist) | `/transport/requests` | api-tanstack | S | All-types worklist |
| LIVE | Dispatch Workbench | `/transport/dispatch` | api-tanstack | S | **Currently a duplicate of Requests** (no dispatch-specific UI) |
| LIVE | Inpatient | `/transport/inpatient` | api-tanstack | S | Filtered worklist |
| LIVE | Interfacility Transfers | `/transport/transfers` | api-tanstack | S | Richest: + RegionalTransferPanel (lazy-seeded) |
| LIVE | Discharge | `/transport/discharge` | api-tanstack | S | Seeder seeds 1 row |
| LIVE | EMS Handoff | `/transport/ems` | api-tanstack | M | Seeder seeds 1 row; generic list |
| LIVE | Resources | `/transport/resources` | api-tanstack | S | `/api/transport/resources` + `/vendors` |
| LIVE | Enterprise Integrations | `/transport/settings/integrations` | api-tanstack | S | Self-seeding catalog |
| PARTIAL | Transport Analytics | `/transport/analytics` | api-tanstack | M | Scorecard live; "Planned Measures" placeholder (needs `transport_events` → P0) |
| EMPTY | Care Transitions | `/transport/care-transitions` | api-tanstack | S | Worklist `request_type=care_transition` — **seeder seeds none** (P0 one-liner) |

### Emergency Department (entirely placeholder)
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| STUB | Triage | `/ed/operations/triage` | inertia-noprops | L | `<EDPlaceholder>` only |
| STUB | Treatment | `/ed/operations/treatment` | inertia-noprops | L | `<EDPlaceholder>` only |
| STUB | Resource Management | `/ed/operations/resources` | inertia-noprops | L | `<EDPlaceholder>` only |
| STUB | Wait Time | `/ed/analytics/wait-time` | inertia-noprops | M | `<EDPlaceholder>` only |
| STUB | Resource Analytics | `/ed/analytics/resources` | inertia-noprops | M | `<EDPlaceholder>` only |
| STUB | Arrival Prediction | `/ed/predictions/arrival` | inertia-noprops | M | `<EDPlaceholder>` only |
| STUB | Acuity Prediction | `/ed/predictions/acuity` | inertia-noprops | M | `<EDPlaceholder>` only |
| STUB | Resource Optimization | `/ed/predictions/resources` | inertia-noprops | M | `<EDPlaceholder>` only |
| EMPTY | Patient Flow (4D) | `/ed/analytics/flow` | api-tanstack | M | Only non-stub ED page; empty until patient-flow import (P0) |

### Operations Intelligence Hub + Surgical Analytics
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Intelligence Hub | `/analytics` | api-tanstack | S | `/api/analytics/overview` |
| LIVE | Live Signals | `/analytics/live` | api-tanstack | S | computed from prod tables |
| LIVE | Predictive Planning | `/analytics/predictive` | api-tanstack | S | reads `rtdc_predictions` |
| LIVE | Opportunity Portfolio | `/analytics/opportunities` | api-tanstack | S | graph recommendations |
| LIVE | Scenario Workbench | `/analytics/workbench` | api-tanstack | S | simulation + attribution panels |
| LIVE | Data Quality | `/analytics/data-quality` | api-tanstack | S | thin until `ops.metric_*` seeded (P0) |
| PARTIAL | Retrospective Review | `/analytics/retrospective` | api-tanstack | M | 30-day window thin (OR span only 5 weekdays → P0) |
| PARTIAL | Process Intelligence | `/analytics/process-intelligence` | api-tanstack | M | needs `operational_events` (P0) |
| MOCK | Block Utilization | `/analytics/block-utilization` | client-mock | M | propless; imports mock — **data + endpoint already exist** |
| MOCK | OR Utilization | `/analytics/or-utilization` | client-mock | M | propless; imports mock |
| MOCK | Primetime Utilization | `/analytics/primetime-utilization` | client-mock | M | propless; imports mock |
| MOCK | Room Running | `/analytics/room-running` | client-mock | M | propless; imports mock |
| MOCK | Turnover Times | `/analytics/turnover-times` | client-mock | M | propless; imports mock |
| BROKEN | Provider Analytics | `UNROUTED` | client-mock | L | Dead controller+page; **live `/api/analytics/provider-performance` exists** |
| BROKEN | Service Analytics | `UNROUTED` | client-mock | L | Dead controller+page; live endpoint exists |
| BROKEN | Historical Trends | `UNROUTED` | client-mock | L | Dead controller+page; live endpoint exists |
| BROKEN | Patient Flow (Process Mining) | `UNROUTED` | client-mock | L | No controller, no route; child only via ServiceAnalytics |

### Perioperative — Operations & Predictions
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| MOCK | Room Status | `/operations/room-status` | client-mock | M | propless; `/api/cases/room-status` already exists |
| MOCK | Case Management | `/operations/cases` | inertia-hardcoded | M | props ← `App\Data\CaseManagementMockData` (PHP mock class) |
| STUB | Block Schedule | `/operations/block-schedule` | inertia-noprops | L | propless + "Calendar placeholder" |
| STUB | Utilization Forecast | `/predictions/forecast` | inertia-noprops | L | propless + "Chart placeholder" |
| STUB | Demand Analysis | `/predictions/demand` | inertia-noprops | L | propless + "Chart placeholder" |
| STUB | Resource Planning | `/predictions/resources` | inertia-noprops | L | propless + "Chart placeholder" |

### Improvement (Process Improvement)
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| EMPTY | Overview (redirect) | `/improvement/overview` | — | S | Pure redirect to dashboard.improvement (by design) |
| MOCK | Bottlenecks | `/improvement/bottlenecks` | client-mock | M | `getBottleneckStats()` hardcoded |
| MOCK | Process Analysis (OCEL) | `/improvement/process` | static-json | L | Reads static `bed_placement_process_map.json`; ignores requested workflow |
| MOCK | Root Cause | `/improvement/root-cause` | client-mock | L | **Regenerates 20 `Math.random` items per mount**; ignores prop |
| MOCK | Active Cycles | `/improvement/active` | client-mock | M | Ignores `cycles` prop; "View Details" href 404s |
| PARTIAL | Library | `/improvement/library` | inertia-hardcoded | M | One placeholder row; no `improvement_resources` table |
| PARTIAL | Opportunities | `/improvement/opportunities` | inertia-hardcoded | M | One placeholder row; no `improvement_opportunities` table |
| BROKEN | PDSA Index | `/improvement/pdsa` | client-mock | M | **White-screen crash** — `cycle.plan.objective` on mock lacking `.plan` |
| BROKEN | PDSA Show | `/improvement/pdsa/{id}` | inertia-hardcoded | M | **Crash** — controller stub returns `phases` not `plan/barriers/study` |
| STUB | PDSA Create | `/improvement/pdsa/create` | inertia-noprops | M | Form submits via `router.visit` → input discarded |
| BROKEN | PDSA Dashboard / Cycle Mgmt | `UNROUTED` | client-mock | S | Unrouted (verify import graph before deleting — see §7) |

### Ops Console & Staffing
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Agent Inbox | `/ops/agent-inbox` | api-tanstack | S | `/api/ops/*`; lazy-materialized agents |
| LIVE | Executive Operations Brief | `/ops/executive-brief` | api-tanstack | M | auto-runs briefing agent; measured impact zero until `ops.interventions` seeded (P0) |
| LIVE | Staffing Office | `/staffing` | api-tanstack | S | `/api/staffing/overview`; **date-scoped to today** (re-seed daily) |

### Admin & Profile (the most production-ready slice)
| Status | Page | Route | Source | eff | Note |
|---|---|---|---|---|---|
| LIVE | Users — Index | `/users` | inertia-db | S | `UserSeeder` seeds 5 real users |
| LIVE | Users — Create | `/users/create` | inertia-noprops | S | Working form; persists |
| LIVE | Users — Edit | `/users/{user}/edit` | inertia-db | S | Over-exposes full User model (polish) |
| LIVE | Profile — Edit | `/profile` | inertia-db | S | Three working partials; raw `shadow` → canon `shadow-sm` (polish) |

---

## 3. Cross-Cutting Findings (systemic)

1. **Demo-data not self-contained.** `DatabaseSeeder` runs only `User → CaseManagement → TestData →
   CommandCenterDemo`. `RtdcSeeder` (sole creator of `prod.units` + `prod.beds`) and
   `AuthProviderSettingSeeder` are **not** in the chain. `CommandCenterDemoSeeder` only reads/tunes
   units and **no-ops its per-unit loops if units are empty** — so a bare `db:seed` leaves the RTDC
   spine and Command Center secondary panels empty.
2. **Standalone import commands outside the seed pipeline.** Patient Flow Navigator (`flow_core.*`,
   `raw.inbound_messages`) loads only via `patient-flow:import-synthetic`; Facility Model
   (`hosp_space.*`, `hosp_ingest.*`) only via `facility:import-catalog`. Neither is in `db:seed` or
   `deploy.sh`, so `/rtdc/patient-flow-navigator`, `/ed/analytics/flow`, and Facility Model render empty.
3. **No-prop controller wave (~30 methods).** All 4 role dashboards, all 9 ED methods, all 10 RTDC
   Analytics/Predictions methods, the 4 Periop Predictions/Block-Schedule stubs, and Room Status call
   `Inertia::render(view)` with **no data props**. The React pages then import static `mock-data/*`,
   hardcode literals, or hit `/api` directly. **The fix pattern is uniform.**
4. **Two parallel universes (seeded DB vs disconnected mock).** For surgical analytics + several
   RTDC/Periop pages, fully-seeded `prod.*` tables *and* working `/api` endpoints already exist, but the
   pages import static mock instead. **Net-new backend is rarely needed; wiring is.**
5. **`DashboardService` is 100% hardcoded.** Every `getImprovementStats / getBottleneckStats /
   getRootCauses / getOpportunities / getLibraryResources / getActiveCycles / getPdsaCycle` returns
   static PHP arrays (mostly zeros/single placeholders) and never queries the DB — despite
   `prod.pdsa_cycles` being seeded.
6. **Two runtime crashes in Improvement PDSA.** `PDSA/Index.jsx` calls `cycle.plan.objective` on a mock
   lacking `.plan`; `PDSA/Show.jsx` receives a controller stub shaped `phases` not `plan/barriers/study`.
   Both white-screen and both ignore the already-seeded `prod.pdsa_cycles`.
7. **Orphan / dead-code pages (5 confirmed).** `RTDC/DischargePrediction.jsx`,
   `RTDC/Predictions/DischargePredictions.tsx`, `Analytics/{HistoricalTrends, ProviderAnalytics,
   ServiceAnalytics}.jsx` — each has a controller method that is never routed. Plus a 3-way
   discharge-page redundancy and an Analytics triplication where **live endpoints exist but nothing
   consumes them.** (PDSADashboard/CycleManagement + `Analytics/PatientFlow.jsx` flagged inconsistently
   by two auditors — **verify import graph before deleting**, see §7.)
8. **Broken link (verified).** Improvement "View Details" (`Active.jsx:286`) →
   `/improvement/active/{id}` has no route (real 404). *(Note: the audit also flagged a `/resources`
   route-name collision; direct verification disproved it — the six `name('resources')` entries live in
   distinct `ed.analytics.` / `ed.operations.` / `ed.predictions.` / `rtdc.analytics.` /
   `rtdc.predictions.` / `transport.` sub-groups, so all full names and URLs are distinct. No fix needed.)*
9. **Fallback-mock masking risk.** `Analytics.jsx` ships `fallbackIntelligenceMetrics/ActionQueue/
   SourceMap`, so an empty prod DB still *looks* populated — a demo-safety win but a credibility hazard
   if mistaken for live data. Gate behind a dev flag.

---

## 4. Demo-Data Architecture

The backend-gap auditor classified the **59 unseeded tables** into three categories — this is the key
to scoping seeders correctly (most "missing" tables do **not** need a seeder):

- **(A) Genuinely empty, no fallback → NEED a seeder.** `ops.metric_definitions / metric_lineage /
  metric_values / source_freshness / data_quality_findings` (Data Quality + metric trust + stale-source
  recommendations + agent freshness); `flow_core.*` + `flow_realtime.ambient_signal_*` (Patient Flow +
  ambient panel); `hosp_space.* / hosp_ingest.*` (Facility Model); `integration.sources` + `raw.*`
  (Integration Health); `prod.transport_events` (transport timelines — a one-line gap, since `evs_events`
  *is* seeded); `prod.operational_events` (process mining).
- **(B) Derived / lazy → already non-empty.** `ops.nodes/edges/state_snapshots` rebuilt live by
  `OperationsGraphProjector::rebuild()` on every snapshot call; `regional.facilities +
  network_model_versions` lazy-seeded by `RegionalTransferService`; `integration.interface_engines /
  connector_playbooks / coexistence_adapters` lazy-seeded by `EnterpriseConnectorControlService::
  seedCatalog()`; `ops.agent_definitions` lazy-materialized from an in-code catalog. **This is why the
  new pages are LIVE without seeders.**
- **(C) Interactive-only → empty history/timelines until used.** `ops.actions/approvals/recommendations`,
  `ops.simulation_*`, `ops.interventions/*`, `ops.agent_runs/*`, `ops.writeback_drafts`,
  `regional.transfer_decisions/route_simulation_runs`. Seed a *small* sample so detail/history panels
  aren't bare on a fresh demo, but these are not page-blocking.

**Unifying goal:** a single `php artisan migrate --seed` (and an idempotent `db:seed` re-run) lights up
**every** page with plausible data — no multi-step runbook. Concretely:

1. **Register `RtdcSeeder` in `DatabaseSeeder` before `CommandCenterDemoSeeder`** (creates units + beds).
2. **`zephyrus:demo-seed` orchestrator command** that runs `db:seed` then `patient-flow:import-synthetic`
   (against a git-tracked NDJSON) and `facility:import-catalog`; idempotent (truncate-then-import per
   schema). Wire `./deploy.sh --db` to call it. *Alternatively* port both imports into
   `PatientFlowDemoSeeder` + `FacilityModelDemoSeeder` for true single-command `db:seed` coverage.
3. **Extend `CommandCenterDemoSeeder`:** `prod.transport_events` (mirror the `evs_events` pattern),
   1–2 `care_transition` requests, ED-unit `rtdc_predictions`, `prod.operational_events` (canonical log
   built from `or_cases/ed_visits/bed_requests` transitions), `flow_realtime.ambient_signal_*`, and
   **broaden OR-case generation from last-5-weekdays to 6–12 months** so retrospective/historical/trend
   views have variation.
4. **New `OpsMetricTrustSeeder`** (category A): metric definitions/lineage/values + source_freshness
   (mix fresh/warning/stale) + data_quality_findings.
5. **New `OpsIntelligenceSeeder`** (category C sample): 2–4 `interventions/intervention_metrics/
   outcome_attribution` rows tied to seeded recommendations (Executive Brief measured-impact) + a small
   `simulation_scenarios` library; a few blocked/dirty `prod.beds` and 2–3 open `prod.barriers` so the
   Ops Console recommendation branches fire.
6. **New domain seeders for the net-new surfaces** built later: ED tables, ancillary-services,
   `improvement_opportunities` + `improvement_resources`.

**Idempotency:** every seeder uses `updateOrCreate`/`firstOrCreate` on natural keys and clears
today-scoped rows before reseeding (the `staffing_plans` pattern). **Prod re-seed safety:** additive,
never `migrate:fresh` / destructive `--force` on prod; never delete `prod.users`; document
`zephyrus:demo-seed` as the canonical refresh. **Date fragility:** staffing + freshest census are scoped
to *today* — the demo MUST be re-seeded for the current day (bake into the runbook / scheduled refresh).

---

## 5. Phased Implementation Plan

Dependency order: **P0 (demo data) and P1 (un-break) are the demo-blocking critical path.** P2/P3/P4/P5
each depend on P0 and can run largely in parallel afterward.

### P0 — Self-Contained, Idempotent Demo-Data Foundation `[no deps]` — CRITICAL PATH
**Goal:** one `php artisan migrate --seed` (or `zephyrus:demo-seed`) makes every *currently-backed* page
render populated data, idempotently, with zero manual steps.
**Exit:** on a pristine DB, the single command alone makes every LIVE page populate; re-running is
idempotent; Command Center has no zeroed secondary panels; Patient Flow Navigator + Facility Model render
real data; `/transport/care-transitions` is non-empty; Executive Brief shows non-zero measured impact.

- **[S]** Register `RtdcSeeder::class` in `DatabaseSeeder` **before** `CommandCenterDemoSeeder`; verify
  `firstOrCreate` idempotency. *Lights up Bed Placement, Global/Unit Huddle, and Command Center secondary
  panels from a vanilla seed.* → `DatabaseSeeder.php`, `RtdcSeeder.php`
- **[M]** Build `zephyrus:demo-seed` orchestrator (db:seed → `patient-flow:import-synthetic` →
  `facility:import-catalog`); wire `deploy.sh --db`; confirm GLB/tileset assets served. *Or* port to
  `PatientFlowDemoSeeder` + `FacilityModelDemoSeeder`. → Patient Flow Navigator, ED Flow, Facility Model
- **[M]** Extend `CommandCenterDemoSeeder`: `transport_events`, 1–2 `care_transition` requests,
  `diversion_events`, ED-unit `rtdc_predictions`, `flow_realtime.ambient_signal_*`. → Command Center,
  Care Transitions, Transport Analytics, Unit Huddle
- **[M]** New `OpsMetricTrustSeeder`: `ops.metric_definitions/lineage/values/source_freshness/
  data_quality_findings`; register in `DatabaseSeeder`. → Data Quality, Process Intelligence, Ops Console
- **[L]** New `OpsIntelligenceSeeder` + `operational_events` log + blocked beds/barriers. → Executive
  Brief, Agent Inbox, Workbench, Process Intelligence
- **[M]** Broaden OR-case + `case_metrics` seeding to 6–12 months (idempotent window delete). →
  Retrospective, Historical Trends, surgical analytics
- **[S]** Gate `Analytics.jsx` fallback mocks behind a dev flag.

### P1 — Un-break: Crashes, Orphans, Dead Routes, Broken Links `[P0]` — CRITICAL PATH
**Goal:** eliminate every BROKEN page. **Exit:** zero white-screen/404 pages; no click leads to a crash
or unreachable orphan; PDSA Index/Show render seeded cycles; the broken "View Details" link is fixed.

- **[M, urgent]** Fix PDSA Index + Show crashes against seeded `prod.pdsa_cycles`: point
  `pdsaIndex/pdsaShow` at the `PdsaCycle` model returning the shape the pages render; add null-guards
  (`cycle.plan?.objective`, `cycle.barriers ?? []`); drop stale mock import.
- **[S]** Resolve discharge triplication: delete `RTDC/DischargePrediction.jsx` +
  `RTDC/Predictions/DischargePredictions.tsx` + their dead controller methods (keep routed
  `DischargePriorities`). *(See §7 disposition.)*
- **[S]** Resolve 3 dead Analytics controllers/pages (HistoricalTrends/Provider/ServiceAnalytics) —
  **delete now, optionally rebuild in P3** since live `/api/analytics/{historical,provider,service}-
  performance` endpoints already exist. *(Product sign-off — see §7.)*
- **[S]** Resolve PDSA orphan components (PDSADashboard / CycleManagementPage / no-op CareIssuesModal) —
  **verify import graph first**, then delete or route.
- **[S]** Fix broken link (`Active.jsx:286` `/improvement/active/{id}` → `/improvement/pdsa/{id}`);
  **optional** quick win: route `DepartmentCensus` + add to nav.

### P2 — Wire Role Dashboards + RTDC Mock Pages to Live Data `[P0]`
**Goal:** convert the 4 role dashboards and RTDC mock/stub ops pages off static mock onto seeded
`prod.*`. **Exit:** all 4 dashboards render seeded numbers (Improvement counts non-zero); BedTracking
shows real totals; ServiceHuddle/DischargePriorities/AncillaryServices read the DB.

- **[L]** Wire the 4 role dashboards (RTDC, Periop, ED, Improvement + `/home`) to live Inertia props via
  a shared `CommandCenterDataService`-style service; swap the `mock-data/*` imports; add empty states;
  make `getImprovementStats` compute real counts.
- **[M]** Wire RTDC Bed Tracking to a census endpoint + build a real unit/bed grid (replace "coming
  soon" + the contradictory 500-bed literal).
- **[L]** Wire Service Huddle (roster from `encounters`+`units`, persist status edits) + Discharge
  Priorities (priority tiers from `encounters`/`census`/`gmlos_references`; replace inline closure).
- **[L]** Wire Ancillary Services — **net-new domain**: migration + service + endpoint + seeder
  (PT/OT/ST/SW/RT/imaging/labs per unit); swap mock + `Math.random` for `useQuery`; snap token drift.

### P3 — Periop Operations + Surgical Analytics: Mock → Live `[P0]`
**Goal:** connect surgical analytics + Periop Operations to seeded tables/endpoints (mostly frontend
wiring + a few aggregation endpoints). **Exit:** 5 surgical-analytics pages + Room Status + Block
Schedule + Case Management render seeded data across tabs; 3 Periop Predictions show real charts.

- **[L]** Wire the 5 surgical-analytics sub-pages to seeded `block_utilization/or_cases/case_metrics/
  rooms` (add/extend `/api/blocks`, `/api/analytics` aggregations; refactor each dashboard's data source).
- **[L]** Wire Room Status (`/api/cases/room-status` exists), Block Schedule (`/api/blocks` + build
  calendar), Case Management (retire `App\Data\CaseManagementMockData` → seeded tables).
- **[L]** Build Periop Predictions backend + Recharts (Utilization/Demand/Resource) from 6–12mo seeded
  history (P0) — replace "Chart placeholder" boxes.

### P4 — RTDC Analytics/Predictions + ED Section: Build Real Pages `[P0, P1]`
**Goal:** replace the byte-identical RTDC Analytics/Predictions stubs and the 8 ED placeholders with real
components. **Exit:** no `<EDPlaceholder>` cards remain; 6 RTDC nav pages render real charts/KPIs; Risk
Assessment in nav and populated; ED renders functional boards/charts across 9 pages.

- **[L]** Build the 6 RTDC Analytics/Predictions nav stubs (Utilization/Performance/Resources/Trends +
  DemandForecast/ResourcePlanning) from seeded `census_snapshots/units/beds/rtdc_predictions`.
- **[M]** Build RTDC Risk Assessment + add to nav (readmission-risk endpoint; seed per-patient scores).
- **[L]** Build the ED domain data model + `EDDemoSeeder` — **net-new**: triage queue (ESI/chief
  complaint/wait timers), treatment tracking, arrival/triage/provider/disposition timestamps,
  resource inventory. *(Unblocks all 8 ED pages.)*
- **[L]** Build the 8 ED pages (Triage/Treatment/Resource Mgmt boards; WaitTime/Resource analytics;
  Arrival/Acuity/Resource-optimization predictions) fed by the ED model.

### P5 — Improvement Real Backend + Cross-Cutting Polish `[P0, P1]`
**Goal:** replace 100%-hardcoded `DashboardService` with DB-backed data; build missing improvement
tables; apply remaining token-canon/UX polish. **Exit:** Improvement fully DB-backed (no hardcoded
arrays, no random regeneration, Opportunities/Library/PDSA-Create persist); Transport Dispatch/EMS
differentiated + Analytics measures computed; Admin manages role/status; `check-ui-canon.sh` clean
(Auth untouched); Integration Health + Facility Model render seeded data.

- **[L]** Replace `DashboardService` hardcoded arrays with real queries (`pdsa_cycles` + new tables);
  make Bottlenecks/RootCause/Active consume props (RootCause: replace `Math.random` with persisted rows).
- **[L]** Build `improvement_opportunities` + `improvement_resources` tables/seeders; wire Opportunities
  CRUD + "Start PDSA", Library filters/Add; PDSA Create → real `POST /improvement/pdsa` store with
  `useForm`; wire Process.jsx to the API once `operational_events` exists.
- **[M]** Differentiate Transport Dispatch (filter to assignable/unassigned + team/vendor pickers) + EMS
  (ETA/receiving-readiness); compute the 6 Transport Analytics measures from `transport_events`.
- **[M]** Admin (role/is_active columns + role select + `Pick` prop trim) + Profile (`shadow`→`shadow-sm`)
  + token-canon nits sweep (indigo/slate/gray → `healthcare-*`). Run `scripts/check-ui-canon.sh`.
- **[M]** Verify Integration Health + Facility Model render with P0 seed; add small interactive-history
  samples (`ops.agent_runs`, `regional.*`) so detail panels aren't bare.

---

## 6. Quick Wins (do first — highest leverage / lowest effort)

1. **[S, foundational]** Register `RtdcSeeder` in `DatabaseSeeder` before `CommandCenterDemoSeeder` —
   lights up every RTDC LIVE page + Command Center secondary panels in one edit.
2. **[S]** Add 1–2 `care_transition` requests to `seedTransportBacklog()` — flips
   `/transport/care-transitions` EMPTY → LIVE.
3. **[S]** Add `prod.transport_events` writes (mirror `evs_events`) — fills every transport timeline +
   enriches Analytics measures.
4. **[S]** Seed `diversion_events` + ED-unit `rtdc_predictions` — clears Command Center zeroed cards.
5. **[M, urgent]** Fix the two PDSA crashes (the only white-screens in the app).
6. **[S]** Fix the `/improvement/active/{id}` broken href (`Active.jsx:286`).
7. **[S]** Resolve the 5 orphans (delete or route+wire — §7).
8. **[S–M]** Fold the patient-flow + facility imports into one `zephyrus:demo-seed` command.
9. **[M each, high payoff]** Wire the 4 role dashboards to props — they already look demo-ready.

---

## 7. Orphan / Dead-Code Disposition `[needs product sign-off]`

| File | Finding | Recommendation |
|---|---|---|
| `RTDC/DischargePrediction.jsx` + `dischargePrediction()` | Unrouted; superseded by `DischargePriorities` | **Delete** |
| `RTDC/Predictions/DischargePredictions.tsx` + `dischargePredictions()` | Unrouted defensive stub | **Delete** |
| `Analytics/HistoricalTrends.jsx` + controller | Unrouted; **live `/api/analytics/historical-performance` exists** | Delete now, rebuild cleanly in P3 if wanted |
| `Analytics/ProviderAnalytics.jsx` + controller | Unrouted; live endpoint exists | Delete now, rebuild in P3 if wanted |
| `Analytics/ServiceAnalytics.jsx` + controller | Unrouted; live endpoint exists; imports `PatientFlow.jsx` as child | Delete now, rebuild in P3 if wanted |
| `Analytics/PatientFlow.jsx` | **Child of ServiceAnalytics (NOT a separate orphan)** | Keep/remove with ServiceAnalytics |
| `Improvement/PDSADashboard.jsx`, `PDSA/PDSACycleManagementPage.jsx` | Flagged orphan by one auditor, "imported module" by another | **Verify import graph (`grep -r`) before deleting** |
| `RTDC/Analytics/DepartmentCensus.jsx` + `departmentCensus()` | Unrouted but rich mock | **Optional**: route + add to nav for an instant demo page, else delete |

> **Caution:** the dead Analytics controllers point at endpoints that already query seeded data, so
> deleting Provider/Service/Historical discards usable information architecture. Confirm whether product
> wants them rebuilt in P3 before deleting.

---

## 8. Risks & Guardrails

- **AUTH IS PROTECTED — DO NOT TOUCH.** The 7 Auth pages, `Components/Auth/*`, `GuestLayout`,
  `RegisteredUserController`, `ChangePasswordController`, `AuthenticatedSessionController`,
  `ChangePasswordModal`, the `must_change_password` flow, and the Resend temp-password paradigm are
  production-deployed and off-limits per `.claude/rules/auth-system.md`. The deliberate indigo/blue/cyan
  auth styling is a **sanctioned exception — do not recolor** during any token sweep. `admin@acumenus.net`
  must always exist with `must_change_password=false`.
- **Two-System color rule.** blue/slate `healthcare-*` governs operational surfaces; crimson `#9B1B30` +
  gold `#C9A227` is brand/heritage/focus **only**. Status uses only
  `healthcare-critical/warning/success/info` (teal/amber/coral/sky), always paired with arrow/icon/label,
  coral-red rationed for real breaches. New ED/RTDC/Improvement components must use the ONE `Surface`
  primitive (`Card`/`Panel`), `shadow-sm` for resting panels, no `bg-white`/`bg-gray-*`/glassmorphism,
  Figtree via `font-sans`, weights 400/500/600 only, Tailwind size scale, `tabular-nums` for metrics.
  Keep the impeccable hook on; run `scripts/check-ui-canon.sh` before committing.
- **Prod re-seed safety.** Never `migrate:fresh` / destructive `--force` on prod (catastrophic
  app-schema wipe history). Seeders additive/idempotent; never delete `prod.users` or real data.
  Document `zephyrus:demo-seed` as the canonical refresh; full `deploy.sh` skips migrations — use `--db`.
- **Worktree-sweep regression hazard.** P5's token polish is exactly the codebase-wide mechanical sweep
  that silently reverted Parthenon refactors 3×. Prefer sequential commits on `main`; if a worktree is
  used it MUST `git fetch` + `rebase origin/main` file-by-file (never `-X ours`), list all deletions,
  and run **both** `npx tsc --noEmit` AND `npx vite build` (vite is stricter) before returning.
- **Date-scoped seed fragility.** Staffing + freshest census are scoped to *today*; a stale demo DB
  collapses Staffing Office to its empty state. Re-seed for the current day — bake into the runbook.
- **Scope creep.** The only genuinely net-new infrastructure is ED, ancillary services, improvement
  opportunities/library, and Periop forecasting. Keep them minimal/demo-grade (tables + seeders + read
  endpoints; Form Requests, Eloquent scopes, eager loading; Pint via Docker; tsc + vite before commit).
  **Resist rebuilding what already works** (Transport, Ops Console, Command Center, Analytics hub).

---

## 9. Consolidated TODO Checklist

**P0 — Demo data (critical path)**
- [ ] Register `RtdcSeeder` before `CommandCenterDemoSeeder` in `DatabaseSeeder`; verify idempotency
- [ ] `zephyrus:demo-seed` orchestrator (db:seed + patient-flow + facility imports); wire `deploy.sh --db`
- [ ] Extend `CommandCenterDemoSeeder`: transport_events, care_transition, diversion_events, ED rtdc_predictions, ambient signals
- [ ] `OpsMetricTrustSeeder` (metric defs/lineage/values/source_freshness/data_quality_findings)
- [ ] `OpsIntelligenceSeeder` + operational_events + blocked beds/barriers
- [ ] Broaden OR-case seeding to 6–12 months
- [ ] Gate `Analytics.jsx` fallback mocks behind dev flag

**P1 — Un-break (critical path)**
- [ ] Fix PDSA Index + Show crashes against seeded `pdsa_cycles` + null-guards
- [ ] Delete discharge orphans (DischargePrediction, DischargePredictions) + dead methods
- [ ] Delete/defer 3 dead Analytics controllers/pages (sign-off)
- [ ] Resolve PDSA orphan components (verify imports first)
- [ ] Fix `/improvement/active/{id}` href (`Active.jsx:286`)

**P2 — Role dashboards + RTDC mock → live**
- [ ] Wire 4 role dashboards + `/home` to props; real Improvement counts
- [ ] Bed Tracking → census endpoint + bed grid
- [ ] Service Huddle + Discharge Priorities → live + persistence
- [ ] Ancillary Services net-new domain (migration + service + endpoint + seeder)

**P3 — Periop + surgical analytics**
- [ ] 5 surgical-analytics pages → seeded data/endpoints
- [ ] Room Status + Block Schedule + Case Management → live (retire `CaseManagementMockData`)
- [ ] Periop Predictions backend + charts

**P4 — RTDC analytics/predictions + ED**
- [ ] 6 RTDC Analytics/Predictions nav pages
- [ ] RTDC Risk Assessment + add to nav
- [ ] ED domain data model + `EDDemoSeeder`
- [ ] 8 ED pages (boards/charts/predictions)

**P5 — Improvement backend + polish**
- [ ] `DashboardService` real queries
- [ ] `improvement_opportunities` + `improvement_resources` tables/seeders + CRUD + PDSA Create persistence
- [ ] Transport Dispatch/EMS differentiation + Analytics measures
- [ ] Admin role/status + Profile/token-canon polish (`check-ui-canon.sh` clean)
- [ ] Integration Health + Facility Model verification + history samples

---

*Generated from a parallel multi-agent audit on 2026-06-26. Status counts and per-page findings reflect
the codebase at commit `dad956e`.*
