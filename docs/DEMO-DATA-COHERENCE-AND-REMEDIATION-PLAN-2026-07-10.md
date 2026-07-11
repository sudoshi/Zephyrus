# Zephyrus Demo Data Coherence and Application Remediation Plan

**Date:** 2026-07-10  
**Status:** Execution-ready remediation backlog  
**Environment:** `zephyrus.acumenus.net` working demonstration environment  
**Data policy:** Synthetic/seeded operational data is intentional. The goal is not to make the demo look like production data; the goal is to keep the synthetic story temporally current, internally coherent, visibly labeled, and safe to interpret.

## 1. Outcome

Zephyrus should present one continuously advancing synthetic hospital scenario across the command center, RTDC, ED, perioperative, staffing, transport, analytics, improvement, Arena, and administration surfaces.

At the end of this plan:

- every first-class route renders a useful page or an explicit, intentional unavailable state;
- no page white-screens, throws an uncaught runtime error, or displays `undefined` in its title;
- the central 48-hour operational window is always `[demo now - 24h, demo now + 24h]`;
- past events, current state, and future forecasts share one canonical clock and refresh batch;
- active waits and delays remain inside clinically and operationally plausible demo bounds;
- cross-page counts reconcile because every surface consumes the same canonical domain summaries;
- historical analytics remain available outside the 48-hour operational window, but are clearly labeled as historical synthetic cohorts;
- the global header never calls synthetic or stale data simply `LIVE`;
- demo refreshes never overwrite users, user-created records, approvals, or other non-seeder-owned data;
- a scheduled health check fails loudly before implausible values reach the UI.

## 2. Audit baseline

The July 10 browser audit covered all 67 first-class routes exposed through the command palette, workspace menus, and superuser menu. Most routes existed and many contained substantial seeded data, but the demo was not operationally coherent.

### 2.1 Highest-risk discrepancies

| Area               | Observed disagreement                                                                                                                                    | Required correction                                                                                                                                  |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| House capacity     | Command Center and RTDC Utilization showed `423 / 692 staffed`; Bed Tracking showed `423 / 544`; the facility is labeled `500 licensed beds`             | Separate inpatient licensed/staffed beds from ED treatment capacity and perioperative spaces; use a single inpatient denominator for house occupancy |
| Pending discharges | Command Center `0`; Bed Tracking `349`; Discharge Priorities `14`                                                                                        | Define one canonical pending-discharge cohort and reuse it everywhere                                                                                |
| ED census          | Command Center `0`; Triage `41`; Treatment `38`; ED Analytics `46 / 20`; ED Resource Management `0 / 46`                                                 | Use one ED state projection and one simultaneous-capacity definition                                                                                 |
| OR activity        | Command Center `0 cases today`; Case Management `20`; Room Status `4 / 4`; other resource views `15 / 20`                                                | Use the same service date, room scope, and case-state definitions                                                                                    |
| Staffing           | Command Center `0 open shifts`; Staffing and RTDC Resources showed five requests and a nine-person gap                                                   | Derive the cockpit tile from the staffing gap/request service                                                                                        |
| Freshness          | Command Center said `LIVE`; Transport was synthetic and about two hours old; Staffing was about eighteen hours old; Patient Flow was about 143 hours old | Compute freshness per source and aggregate it honestly                                                                                               |
| ED timers          | ESI-1/2 patients and active alerts displayed roughly 141–151 hour ages                                                                                   | Re-anchor or regenerate seeder-owned active records and enforce duration bounds                                                                      |

### 2.2 Broken or incomplete surfaces

- `/improvement/bottlenecks` rendered blank.
- `/analytics/turnover-times` threw `Cannot read properties of undefined (reading 'turnoverDistribution')`.
- `/analytics/arena` reported an unavailable OCEL registry and OCPM sidecar.
- `/admin/enterprise-setup` had no imported organizations.
- `/rtdc/predictions/discharge` produced an `undefined` document title.
- the Cockpit Service Line tab was disabled and marked `soon`.
- Retrospective Review, Process Intelligence, Opportunity Portfolio, Predictive Planning, and Scenario Workbench were presented as planned/reference surfaces rather than finished analytic workflows.
- raw formatting such as `56.400000000000006%` escaped to the UI.

## 3. Root causes found in the repository

### 3.1 There is no single demo refresh pipeline

`app/Console/Commands/DemoSeedCommand.php` provisions the database, facility catalog, and fixed synthetic patient-flow fixture, but it does not subsequently keep all time-sensitive domains aligned.

Useful pieces already exist:

- `patient-flow:rebase-synthetic --anchor=now` shifts the fixed `flow_core` fixture to wall-clock time;
- `rtdc:simulate --trailing` emits a trailing operational event story;
- `flow:snapshot` creates hourly checkpoints;
- `RefreshCockpitSnapshot` runs every minute;
- OCEL and Arena jobs already have scheduled cadences;
- `CommandCenterDemoSeeder` already generates most operational domains relative to `now()`.

These pieces currently run at different times, or only when a full seed is performed. They need one coordinator, one anchor, one batch identifier, and post-refresh validation.

### 3.2 Capacity concepts are mixed

`config/hospital/hospital-1.php` defines exactly 500 inpatient beds, plus:

- ED `staffed_bed_count = 148`, described as daily-throughput capacity, with separate `nedocs_bed_capacity = 50`;
- perioperative `staffed_bed_count = 44`, representing procedural capacity.

Adding all three produces 692, which is not a valid inpatient house denominator. `BedTrackingService` and census-snapshot consumers also use different inventories, producing 544, 692, or 500 depending on the page.

### 3.3 Current-state queries do not share one state projection

ED, RTDC, cockpit, staffing, and OR services independently query tables and apply different fallbacks, cutoffs, and capacity semantics. Examples include:

- `TriageService::anchorNow()` advancing to the latest future arrival when seed rows drift ahead;
- ED resource services alternating between census snapshots, physical beds, active visits, and hard fallbacks;
- Bed Tracking summing `prod.beds`, while RTDC Utilization sums `prod.census_snapshots`;
- cockpit metrics rebuilding from a separate command-center payload;
- page-level authored demo fallbacks remaining in several React components.

### 3.4 Active seeded rows age forever

Several seeders generate active records relative to the time they were last run. If they are not reseeded or rebased, open visits, transport requests, alerts, barriers, and flow events continue to accumulate duration. Queries correctly calculate elapsed time; the source rows are simply no longer a plausible current scenario.

### 3.5 Freshness is snapshot freshness, not source freshness

Refreshing `cockpit.snapshot` every minute can make the outer payload look current even when an included domain's newest source event is hours or days old. The command center must report the oldest or most consequential source freshness, not merely the time the aggregate was recomputed.

## 4. Target architecture: one rolling demo clock

### 4.1 Canonical time model

Create `App\Services\Demo\DemoClock` with these rules:

- `anchor()` returns one immutable timestamp for the entire refresh batch;
- the operational window is `anchor - 24 hours` through `anchor + 24 hours`;
- historical analytic cohorts may extend farther back, but cannot masquerade as current operational data;
- all timestamps in one refresh derive from the same `CarbonImmutable $anchor` passed explicitly to domain refreshers;
- tests use `Carbon::setTestNow()` and never depend on wall-clock execution speed;
- production/live connector mode bypasses `DemoClock` and uses source timestamps unchanged.

Do not let individual services invent their own synthetic `now`. Remove the need for workarounds such as advancing ED's clock to a future arrival.

### 4.2 Refresh ledger

Add `ops.demo_refresh_runs`:

```text
refresh_id UUID primary key
scenario_key text
seed_version text
anchor_at timestamptz
window_start_at timestamptz
window_end_at timestamptz
started_at timestamptz
completed_at timestamptz nullable
status text: running|passed|failed
domain_results jsonb
invariant_results jsonb
error_summary text nullable
```

This provides an auditable answer to “which synthetic moment produced this screen?” without attaching a new batch column to every existing table.

### 4.3 Ownership rules

The refresh pipeline may mutate only records it can prove are demo-owned:

- `patient_ref LIKE 'sim-%'`;
- request IDs/refs using `SYN-*` or `sim-*` conventions;
- `created_by = 'seeder'` or `created_by = 'demo-seeder'`;
- `requested_by = 'demo-seeder'`;
- event `source = 'demo-seeder'` or the registered synthetic source key;
- explicitly registered seed natural keys.

It must never delete or rewrite:

- users and credentials;
- user-created transport/staffing requests;
- approvals, audit history, or manually authored PDSA work;
- imported live connector data;
- facility configuration not owned by the Summit reference deployment.

## 5. P0 — Immediate demo safety and honesty

**Goal:** Stop misleading output before deeper reconciliation work.

- [ ] Add `DEMO_MODE=true` and `DEMO_SCENARIO=summit-reference` configuration; refuse all rolling-demo mutations unless demo mode is explicitly enabled.
- [ ] Replace the global `LIVE` badge with source-aware states:
    - `DEMO · CURRENT` when all required synthetic sources meet freshness thresholds;
    - `DEMO · AGING` when at least one source is nearing its threshold;
    - `DEMO · STALE` when any decision-critical source is expired;
    - `LIVE` only for connector-backed, non-synthetic sources.
- [ ] Add a global demo banner showing scenario name, refresh anchor, oldest critical source, and “Synthetic data — not for clinical use.”
- [ ] Change empty/no-observation metrics from reassuring zeroes to `—`/`No current observations` unless zero is an actual measured value.
- [ ] Add source provenance and `observedAt` to every cockpit domain section, drill, and analytics payload.
- [ ] Make stale domains incapable of producing green/normal operational statuses. Stale values should be neutral/unknown with an explicit warning.
- [ ] Rotate the predictable superuser password. Keep one-click demo access behind an environment flag or external access control, not a public `admin/password` credential.
- [ ] Add rate limiting, audit logging, and a visible demo-account marker to all superuser actions.

**Acceptance:** A reviewer can distinguish synthetic-current, synthetic-stale, and live data without opening a drill-down.

## 6. P1 — Build the canonical `zephyrus:demo-refresh` command

Create:

- `app/Console/Commands/DemoRefreshCommand.php`
- `app/Services/Demo/DemoRefreshCoordinator.php`
- `app/Services/Demo/DemoClock.php`
- `app/Services/Demo/DemoInvariantService.php`
- one small domain refresher per bounded data family.

Suggested signature:

```bash
php artisan zephyrus:demo-refresh \
  --anchor=now \
  --window-hours=48 \
  --scenario=summit-reference \
  --domains=all \
  --validate
```

Also support:

- `--dry-run` — show proposed counts/time shifts without writes;
- `--validate-only` — run invariants against the current database;
- `--domains=flow,ed,rtdc,...` — targeted repair/testing;
- `--force` — permitted only in demo mode and logged;
- `--json` — machine-readable scheduler/monitor output.

### 6.1 Execution order

1. Acquire a cache lock and PostgreSQL advisory lock.
2. Create the refresh ledger row and freeze one `anchor`.
3. Refresh reference deployment prerequisites.
4. Refresh the current operational domains.
5. Rebuild derived snapshots, projections, and materialized views.
6. Run invariants.
7. Publish the cockpit snapshot only if critical invariants pass.
8. Mark the refresh passed/failed and emit structured logs/metrics.

### 6.2 Failure behavior

- Never publish a partially refreshed cockpit snapshot as current.
- Preserve the last-known-good snapshot and mark it stale if a refresh fails.
- Record per-domain success/failure in `domain_results`.
- Return non-zero when any critical invariant fails.
- Alert after two consecutive refresh failures or when no successful refresh exists inside the source threshold.

## 7. P2 — Keep every 48-hour window current

### 7.1 Patient Flow 4D / `flow_core`

- [ ] Invoke the existing `patient-flow:rebase-synthetic --anchor=<batch anchor>` from the coordinator.
- [ ] Extend the rebase command to cover every timestamp family used by the navigator, ambient signals, encounter state, and projected events—not just `flow_events`, `encounters`, and `occupancy_snapshots`.
- [ ] Rebuild trailing hourly checkpoints with `TimelineSnapshotService::rebuildTrailingWindow(24, $anchor)`.
- [ ] Rebuild forward projections through `anchor + 24h`.
- [ ] Run the OCEL incremental projection after rebasing so Arena and Flow never disagree about event time.
- [ ] Verify the web navigator and mobile Flow Window report the same window bounds and newest event.

### 7.2 RTDC

- [ ] Refactor the time-sensitive portions of `CommandCenterDemoSeeder` into `RtdcDemoRefresher`; do not run the full database seeder every fifteen minutes.
- [ ] Upsert today and tomorrow predictions using the batch anchor.
- [ ] Generate a deterministic trailing 24-hour operational event stream using stable natural event keys; do not append duplicates on each run.
- [ ] Rebuild current census snapshots and hourly history from the same unit state.
- [ ] Reconcile predictions nightly, but keep the forward prediction rows present across midnight.
- [ ] Remove expired seed-owned predictions and snapshots only after replacement rows pass validation.
- [ ] Ensure Global Huddle reads the same current prediction day used by Demand Forecast and Resource Planning.

### 7.3 Emergency Department

- [ ] Extract `seedEdVisits()` into `EdDemoRefresher` accepting the immutable anchor.
- [ ] Generate arrivals across the trailing 24 hours with deterministic 15-minute buckets.
- [ ] Create a bounded active cohort rather than leaving all unfinished rows open forever.
- [ ] Enforce temporal order:
      `arrived <= triaged <= provider_seen <= admit_decision <= bed_assigned <= departed`.
- [ ] Keep active clinical timers inside demo bounds:
    - ESI-1 provider contact: immediate/within 5 minutes;
    - ESI-2 door-to-provider: normally within 15 minutes;
    - waiting-room maximum: configurable, default 180 minutes;
    - treatment maximum: configurable, default 8 hours;
    - active ED boarder maximum: configurable, default 4 hours;
    - no active ED visit older than the operational window.
- [ ] Make exceptional outliers deliberate and labeled `scenario stressor`; never create 100+ hour ED waits accidentally.
- [ ] Remove `TriageService::anchorNow()`'s future-arrival workaround after seeds are guaranteed not to drift ahead.
- [ ] Derive Triage, Treatment, Resource Management, Wait Time, Resource Analytics, NEDOCS, and cockpit ED tiles from one `EdCurrentStateService`.

### 7.4 Transport

- [ ] Extract `seedTransportBacklog()` into `TransportDemoRefresher`.
- [ ] Re-anchor active requests to the current shift while preserving lifecycle order.
- [ ] Keep completed history in the trailing 24 hours and active due times near the anchor.
- [ ] Enforce priority-specific SLA bounds; create only a small, intentional overdue cohort.
- [ ] Preserve user-created requests and user-driven status transitions.
- [ ] Recompute `source_freshness` from the newest transport event, not the time the API response was assembled.

### 7.5 Staffing

- [ ] Refresh current and next shift plans, `needed_by`, and open demo requests from the same anchor.
- [ ] Cover midnight and shift changes by seeding today and tomorrow, not only the current calendar date.
- [ ] Keep the intended five-unit/nine-person shortage internally consistent with open request headcount.
- [ ] Derive the cockpit open-shifts tile from `StaffingOperationsService`/the canonical gap summary.
- [ ] Refresh synthetic source-freshness observations each cycle.

### 7.6 Perioperative

- [ ] Maintain three cohorts: yesterday's completed cases, today's current schedule, and tomorrow's forecast/schedule.
- [ ] Use one service-date resolver for Room Status, Case Management, dashboard tiles, block schedule, and predictions.
- [ ] Ensure `cases today`, in-progress, completed, delayed, room counts, turnover, and PACU holds reconcile.
- [ ] Preserve the six-month historical analytic cohort; only rotate the operational 48-hour slice.
- [ ] Ensure scheduled times never drift into the past while cases remain marked pre-op.

### 7.7 Improvement, alerts, and agents

- [ ] Re-anchor only demo-owned PDSA `updated_at`/measurement windows; do not rewrite user-authored cycles.
- [ ] Close seed-owned alerts when their source condition clears.
- [ ] Derive alert `opened_at` from the current scenario episode; do not retain alert ages across full scenario resets.
- [ ] Keep inbox approvals, executive brief recommendations, and cockpit alerts on the same snapshot/refresh ID.
- [ ] Cap demo episode duration or deliberately rotate scenario phases so no active alert silently ages past 24 hours.

## 8. P3 — Reconcile capacity and counts

### 8.1 Capacity vocabulary

Adopt explicit measures:

| Measure                        | Summit demo value/meaning                                              | Included units                                                                |
| ------------------------------ | ---------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `licensed_inpatient_beds`      | 500 licensed inpatient beds                                            | ICU, step-down, med/surg, behavioral health, rehab, inpatient specialty units |
| `staffed_inpatient_beds`       | Current staffed subset of the 500                                      | Same inpatient units only                                                     |
| `ed_treatment_spaces`          | Simultaneous ED treatment capacity; use a clearly named manifest value | ED only                                                                       |
| `ed_daily_throughput_capacity` | Optional planning measure; not a bed denominator                       | ED only                                                                       |
| `periop_procedure_spaces`      | OR/procedure/recovery capacity                                         | Perioperative only                                                            |
| `physical_bed_inventory`       | Actual `prod.beds` rows by unit                                        | Display only within matching domain                                           |

- [ ] Do not sum ED throughput capacity or perioperative spaces into house inpatient occupancy.
- [ ] Add an explicit unit/reporting classification in the manifest or schema instead of inferring everything from `type`.
- [ ] Make `HouseCensusService` the canonical inpatient denominator provider.
- [ ] Make Bed Tracking and RTDC Utilization consume that canonical summary.
- [ ] Reconcile each unit: `occupied + available + dirty + blocked = staffed/physical inventory` under one documented model.
- [ ] Keep acuity-adjusted total capacity and remaining headroom as separate fields; never divide occupied beds by remaining headroom.

### 8.2 Canonical operational summaries

Create and reuse:

- `HouseCensusService` — inpatient capacity and census;
- `EdCurrentStateService` — ED cohort/capacity/timers;
- `PeriopCurrentStateService` — current service-date cases and rooms;
- `StaffingCurrentStateService` — gaps, requests, coverage;
- `TransportCurrentStateService` — active queue, SLA risk, durations;
- `DischargeCurrentStateService` — pending/ready/planned discharge cohorts.

Every cockpit tile, detailed page, agent tool, and executive brief must consume these summaries or an explicitly versioned derivative. Do not duplicate SQL definitions in presentation-specific services.

### 8.3 Pending discharge definition

Choose one contract and name its variants:

- `expected_discharge_24h`: active encounters with an expected discharge inside the next 24 hours;
- `discharge_ready_now`: clinically and operationally ready now;
- `priority_discharge_worklist`: the governed prioritized subset;
- `discharged_before_noon`: completed historical outcome, never a current pending count.

The dashboard label `Pending discharges` should use one of these named measures and link to the matching worklist.

## 9. P4 — Repair specific broken and unfinished pages

### 9.1 Demo blockers

- [ ] `/improvement/bottlenecks`
    - reproduce the production white-screen with browser console capture;
    - add an error boundary and honest empty state;
    - normalize `DashboardService::getBottleneckStats()` rows before rendering;
    - add a feature route test and React render test using empty and populated payloads.
- [ ] `/analytics/turnover-times`
    - remove the hardcoded `MARH OR` fallback in `TurnoverTimesDashboard.jsx`;
    - select the first actual site key or require an explicit selection;
    - guard every view against missing `locationData` and `turnoverDistribution`;
    - add a contract test for `TurnoverTimesService::build()` and a React test for empty, single-site, and multi-site payloads.
- [ ] `/rtdc/predictions/discharge`
    - provide a non-empty page title and route-level metadata;
    - test the generated document title.
- [ ] Format all percentages and durations through shared formatters; prohibit unrounded floating-point strings.

### 9.2 Arena

- [ ] Register/run `OcelProcessLandscapeSeeder` as part of initial demo provisioning.
- [ ] Enable and health-check the OCPM sidecar when Arena is included in the demo profile.
- [ ] Run `ocel:project` after every demo refresh and the full reconcile nightly.
- [ ] If Arena is deliberately excluded, remove it from primary navigation or show an intentional feature-disabled page—not a failed integration diagnostic.

### 9.3 Enterprise Setup

- [ ] Include `deployment:seed-registry` and the Summit facility import in `zephyrus:demo-seed`.
- [ ] Validate that organizations, facilities, spaces, capabilities, and transfer relationships exist before marking provisioning successful.
- [ ] Show a guided demo empty state only when the feature is intentionally unprovisioned.

### 9.4 Planned analytics

For each of Retrospective Review, Process Intelligence, Opportunity Portfolio, Predictive Planning, and Scenario Workbench, choose one state:

1. **Implemented:** real metrics, filters, evidence, empty/error states, and tests; or
2. **Preview:** visibly labeled preview/reference page with no implication that the engine is operating; or
3. **Hidden:** remove from primary demo navigation until ready.

Do not label an architectural description `active` merely because the page route loads.

### 9.5 Cockpit Service Line view

- [ ] Either implement the Service Line face using the existing service-line registry and scoped cockpit APIs, or remove/disable the visible tab with a clear preview label.
- [ ] Do not concatenate `Service Line` and `soon` into the accessible name.

## 10. P5 — Freshness, observability, and scheduler reliability

### 10.1 Source freshness contract

Every domain payload should carry:

```json
{
    "provenance": "synthetic|live|mixed",
    "sourceKey": "synthetic-flow-ehr",
    "observedAt": "ISO-8601",
    "refreshedAt": "ISO-8601",
    "refreshId": "UUID",
    "ageSeconds": 0,
    "freshness": "current|aging|stale|unavailable"
}
```

Recommended initial demo thresholds:

| Domain                |              Current |                      Aging |                                                 Stale |
| --------------------- | -------------------: | -------------------------: | ----------------------------------------------------: |
| Cockpit aggregate     |             <= 2 min |                    2–5 min |                                               > 5 min |
| ED/RTDC current state |             <= 5 min |                   5–15 min |                                              > 15 min |
| Transport             |            <= 10 min |                  10–30 min |                                              > 30 min |
| Staffing              |            <= 30 min |                  30–90 min |                                              > 90 min |
| Patient Flow events   |            <= 15 min |                  15–45 min |                                              > 45 min |
| Forecasts             | current service date | next service-date boundary |                                  expired service date |
| Historical analytics  |   labeled cohort end |                        n/a | end date unintentionally behind expected seed horizon |

### 10.2 Scheduling

Add to `bootstrap/app.php` in demo mode:

- `zephyrus:demo-refresh --window-hours=48 --validate` every 15 minutes;
- `withoutOverlapping()` and `onOneServer()`;
- hourly flow snapshot/materialized-view refresh after a successful demo refresh;
- nightly long-window reconciliation and retention;
- a one-minute cockpit refresh may remain, but it must not upgrade stale source data to current.

Production host requirements:

- one reliable `schedule:run` cron or supervised `schedule:work` process;
- queue workers for scheduled jobs;
- monitor both last scheduler heartbeat and last successful demo refresh;
- deploy only through `./deploy.sh`, per `AGENTS.md`;
- initial deploy sequence: migrations, `zephyrus:demo-seed`, `zephyrus:demo-refresh --validate`, build/cache refresh, route smoke, browser smoke.

### 10.3 Monitoring

- [ ] Emit `demo_refresh_success`, `demo_refresh_duration_seconds`, `demo_refresh_age_seconds`, and invariant-failure counts.
- [ ] Add `/up/demo` or an authenticated admin health endpoint returning last refresh, domain freshness, and invariant status.
- [ ] Add an admin refresh-history panel with the last ten batches.
- [ ] Alert on two failed refreshes, scheduler silence, stale critical data, or invariant failure.
- [ ] Log query/source failures without exposing PHI or credentials.

## 11. P6 — Automated invariants and test matrix

### 11.1 Temporal invariants

- [ ] No active operational event predates `anchor - 24h` unless explicitly marked a continuing encounter.
- [ ] No historical event occurs after the anchor.
- [ ] Only forecasts/plans may occur after the anchor, capped at `anchor + 24h` for the Flow Window.
- [ ] All lifecycle timestamps are monotonically ordered.
- [ ] All displayed durations are non-negative.
- [ ] Active timers satisfy configured demo bounds or carry an explicit scenario-outlier label.

### 11.2 Reconciliation invariants

- [ ] Sum of current inpatient unit census equals house census.
- [ ] Inpatient denominators exclude ED throughput and perioperative procedural capacity.
- [ ] Per-unit bed states reconcile to inventory.
- [ ] Active encounter count reconciles with occupied inpatient beds under documented exceptions.
- [ ] ED current census and acuity mix match across ED services.
- [ ] Pending admits/discharges/boarders match between cockpit and worklists.
- [ ] OR cases-today, room status, and throughput counts match.
- [ ] Staffing gap headcount equals open request headcount or documents the difference.
- [ ] Transport queue and at-risk counts match between cockpit and transport APIs.
- [ ] Agent Inbox and cockpit show the same pending-approval count for the same refresh ID.

### 11.3 Plausibility invariants

- [ ] Percentage values are finite and formatted to the defined precision.
- [ ] Occupancy over 100% is allowed only where the metric explicitly represents patients per staffed treatment space and is labeled over-capacity.
- [ ] NEDOCS cannot be zero when the ED is on diversion or has active boarders unless the metric is unavailable/stale.
- [ ] An OR utilization KPI cannot be populated from a period with zero cases without an explicit historical-period label.
- [ ] Green/normal status cannot be calculated from stale or missing sources.

### 11.4 Tests to add

- `tests/Feature/Demo/DemoRefreshCommandTest.php`
- `tests/Feature/Demo/DemoRefreshIdempotencyTest.php`
- `tests/Feature/Demo/DemoRefreshOwnershipTest.php`
- `tests/Feature/Demo/DemoTemporalInvariantTest.php`
- `tests/Feature/Demo/DemoCrossSurfaceParityTest.php`
- `tests/Feature/Demo/DemoFreshnessTest.php`
- ED current-state contract tests using a frozen anchor;
- RTDC capacity vocabulary/reconciliation tests;
- OR current-service-date parity tests;
- browser route smoke asserting no blank root, no `Something went wrong`, no endless loading, and no `undefined` title;
- React tests for all repaired error/empty states.

Run the idempotency suite twice against the same anchor and assert:

- counts do not grow;
- natural keys remain stable;
- no non-demo row changes;
- the second run produces the same domain payload;
- a later anchor advances timestamps without producing impossible lifecycle order.

## 12. P7 — Demo runbook

### 12.1 Initial provisioning

```bash
php artisan zephyrus:demo-seed --migrate
php artisan zephyrus:demo-refresh --anchor=now --window-hours=48 --validate
php artisan ocel:project --days=90 --reconcile
php artisan facility:export-plates --check
php artisan route:list
npm run build
php artisan test --testsuite=Feature
```

Use the supported `./deploy.sh` path for the production demo host; do not use direct production `git pull` or ad hoc SSH deployment commands.

### 12.2 Pre-demo check

```bash
php artisan zephyrus:demo-refresh --validate
php artisan zephyrus:demo-refresh --validate-only --json
```

Then verify:

- global badge is `DEMO · CURRENT`;
- newest ED/RTDC/transport/flow observation is inside its freshness threshold;
- no active timer exceeds configured bounds;
- cockpit and detailed-page parity checks pass;
- Arena is healthy or intentionally hidden;
- enterprise registry is populated;
- all 67 command-palette/admin routes pass browser smoke.

### 12.3 Recovery

If validation fails:

1. retain the last-known-good cockpit snapshot;
2. show `DEMO · STALE`;
3. run `--validate-only --json` and inspect the failed domain/invariant;
4. refresh the bounded domain only;
5. rerun full validation;
6. do not clear user-created records or run `migrate:fresh` on the shared demo.

## 13. Delivery sequence

| Wave | Scope                                                       | Depends on | Exit gate                                          |
| ---- | ----------------------------------------------------------- | ---------- | -------------------------------------------------- |
| 0    | Demo label, stale semantics, credential hardening           | none       | Synthetic/stale data cannot appear as live/current |
| 1    | DemoClock, refresh ledger, coordinator, locks               | Wave 0     | Dry run + repeatable frozen-anchor refresh         |
| 2    | Flow, RTDC, ED, Transport, Staffing, OR refreshers          | Wave 1     | 48-hour invariants and ownership tests pass        |
| 3    | Canonical capacity/state summaries                          | Wave 1–2   | Cross-page parity suite passes                     |
| 4    | Blank/crash/title/Arena/enterprise/planned-page remediation | Wave 0     | All first-class routes render intentionally        |
| 5    | Scheduler, freshness contract, health/monitoring            | Wave 1–3   | Refresh remains current unattended for 72 hours    |
| 6    | Browser regression and release runbook                      | all        | Full browser audit has no critical discrepancy     |

## 14. Definition of done

- [ ] The demo runs unattended for 72 hours with a successful refresh at least every 15 minutes.
- [ ] Every rolling `[now-24h, now+24h]` window contains coherent past, current, and forecast data.
- [ ] No unintended active wait, delay, alert, or transport age exceeds 24 hours; tighter domain bounds pass.
- [ ] No current operational page is backed by an expired service date.
- [ ] House census, ED state, staffing gaps, transport queue, OR activity, and pending flow reconcile across all consuming surfaces.
- [ ] Synthetic provenance and source age are visible globally and in detail.
- [ ] All 67 audited routes render without blank pages, runtime errors, unresolved loading states, or undefined titles.
- [ ] The Turnover Times console error is gone.
- [ ] Bottlenecks renders populated or honest-empty content.
- [ ] Arena and Enterprise Setup are either fully provisioned or intentionally excluded/labeled.
- [ ] Planned analytics are implemented, clearly preview-labeled, or removed from primary demo navigation.
- [ ] Default public superuser credentials are removed.
- [ ] Refresh, parity, plausibility, ownership, and browser smoke tests run in CI.
- [ ] Deployment and pre-demo runbooks are validated on the hosted demo.

## 15. Recommended first implementation slice

Start with a small vertical slice that proves the architecture:

1. add `DemoClock`, the refresh ledger, and `zephyrus:demo-refresh` skeleton;
2. integrate `patient-flow:rebase-synthetic` and ED regeneration with a frozen anchor;
3. add temporal-order and maximum-active-age invariants;
4. publish source freshness to the cockpit and replace `LIVE` with `DEMO · CURRENT/STALE`;
5. run the command twice at one anchor, then once at `anchor + 15m`, and verify idempotency and advancing timers;
6. add the cross-surface ED parity test before expanding to RTDC, staffing, transport, and OR.

This slice directly eliminates the most visibly irrational timers while establishing the mechanism every remaining domain will reuse.
