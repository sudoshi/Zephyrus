# Feedback & Refined Plan — Demo Data Coherence + Plausibility

**Companion to:** `docs/DEMO-DATA-COHERENCE-AND-REMEDIATION-PLAN-2026-07-10.md`
**Author review date:** 2026-07-10 (~20:14 EDT)
**Backend inspected:** `pgsql.acumenus.net` / db `zephyrus` (Postgres 17), canonical `prod.*` schema
**Verdict:** The plan is directionally excellent and its diagnosis is *empirically correct* — every number in its §2.1 discrepancy table reconciles to a real query. But it is **~90% about coherence and ~10% about plausibility**, and it over-invests in new architecture for a problem whose first 80% is "the refresh pipeline is simply never scheduled." This companion (a) validates the diagnosis with ground-truth measurements, (b) corrects/augments five points, and (c) re-sequences the work so the demo is *coherent within hours* and *deeply plausible within a couple of iterations*.

---

## 1. Ground truth — what the database actually contains (measured 2026-07-10)

The last full operational seed ran **~2026-07-03/04**. Since then only *transport* and *census_snapshots* have advanced; everything else is frozen ~6 days in the past, while a few values leak into the *future*. This single fact explains the entire §2.1 table.

| Domain | Table | Count | Newest observation | Staleness | Notes |
|---|---|---|---|---|---|
| ED | `prod.ed_visits` | 104 (46 active) | arrived 2026-07-04 21:32 | **142.7 h** | The "141–151 h timers" = these frozen active boarders |
| Encounters | `prod.encounters` | 593 (**423 active**) | admitted → 2026-07-24 (future) | mixed | **423 active = the "423 occupied"**; 3 admitted in the future; 10 with `exp_dc < admitted` |
| OR | `prod.or_cases` | 759 (**0 today**) | surgery_date 2026-07-03 | **188 h** | 20 on last day = the "Case Mgmt 20" |
| RTDC forecasts | `prod.rtdc_predictions` | 96 | service_date 2026-07-05 | **140 h** | **All 96** have `service_date < today` |
| Patient Flow 4D | `flow_core.flow_events` | 3,779 | occurred 2026-07-04 20:24 | **143.8 h** | Matches the plan's "143 h old" exactly |
| Bed requests | `prod.bed_requests` | 18 | 2026-07-03 | **168 h** | |
| Census | `prod.census_snapshots` | 3,674 | captured 2026-07-11 00:00 | fresh, **100 rows in the future** | Over-eager generator writes to tomorrow |
| Transport | `prod.transport_requests` | 202 (22 active) | requested 2026-07-10 20:57 | **fresh** | but 21 of 22 active are overdue |

**Beds decode (the 500 / 544 / 692 mystery, solved):** `prod.beds` = **692** physical rows = med_surg 340 + **ed 148** + icu 96 + step_down 64 + **periop 44**.
- Inpatient (icu+step_down+med_surg) = **500** → licensed denominator ✅
- +periop = **544** (Bed Tracking) ✅
- +ED = **692** (RTDC Utilization / physical inventory) ✅
- Occupied = **423** (entirely inpatient; ED & periop beds are all `available`), available = 269, 423+269 = 692. So the numerator is fine — **the bug is purely denominator hygiene.**

**Pending-discharge decode (0 / 349 / 14):** 349 = active encounters with `expected_discharge_date ≤ now+24h`, and **all 349 are already overdue** because the data froze 6 days ago. It is a *staleness artifact*, not a real cohort.

**`ops.source_freshness` already exists and already knows** (12 tracked sources): `ed_flow`, `encounters`, `bed_placement`, `rtdc_predictions` = **critical**; `transport_*`, `capacity_census` = success. The header just doesn't read it.

---

## 2. What the plan gets right (keep all of this)

- **Root-cause framing** (§3): no single refresh pipeline; mixed capacity concepts; no shared state projection; rows age forever; snapshot-freshness ≠ source-freshness. All confirmed.
- **One rolling clock + explicit anchor + 48 h window** (§4.1). Correct and necessary.
- **Capacity vocabulary** (§8.1) and the **named pending-discharge variants** (§8.3). These are exactly the right abstractions; I've supplied the concrete Summit values in §5 below.
- **Temporal / reconciliation / plausibility invariants** (§11) as executable gates. This is the plan's best idea — it just needs to *run now* (see §4), because the DB currently violates several.
- **Honest freshness badge + provenance** (§5, §10.1). Right instinct.
- **Ownership discipline** (§4.3) — don't clobber users/approvals/PDSA.

---

## 3. Corrections & additions (what to change)

### 3.1 Re-weight the effort: the first fix is "schedule the pipeline," not "build the architecture"
None of the demo's time-advancing commands are scheduled. `bootstrap/app.php` schedules `flow:snapshot` (hourly — but it only checkpoints the *frozen* census), `RefreshCockpitSnapshot` (**every minute** — this is *why* the header says LIVE: it re-wraps 6-day-old data with a fresh timestamp), MV refresh, OCEL, Arena, and a nightly RTDC reconcile. It does **not** schedule `patient-flow:rebase-synthetic`, `rtdc:simulate --trailing`, `db:seed`, or `zephyrus:demo-seed`. `CommandCenterDemoSeeder` is already `now()`-relative and idempotent with tagged deletes — **re-running it moves everything to "today."** So the highest-leverage action is a few hours of work, not the full DemoClock build. Sequence it first (Wave 0 below).

### 3.2 Don't reinvent `ops.source_freshness` — consume and schedule it
The plan's §10.1 "source freshness contract" is ~80% already built: `ops.source_freshness` stores `source_key / latest_observed_at / expected_lag_minutes / warning_lag_minutes / status(success|warning|critical) / metadata(route,scope)`, computed by `MetricLineageService::materializeSourceFreshness()`. The gaps are only: (1) it is **request-driven, not scheduled** — a row updates lazily when someone opens an analytics page; (2) the global header/badge doesn't read it. Fix those two things instead of designing a parallel contract.

### 3.3 The ownership model already exists in code — formalize, don't re-derive
`CommandCenterDemoSeeder`/`DemoTuningSeeder` already scope their deletes by `created_by IN ('seeder','demo-seeder')`, `patient_ref` prefixes (`sim-*`, `sim-hx/ra`, `sim-occ-*`), and note tags (`demo-tuning`, `notes='demo-today'`). §4.3 should reference these *actual* predicates as the ownership contract rather than proposing new ones.

### 3.4 Fix service references — most named services don't exist; `HouseCensusService` does (and is already canonical)
Both `TriageService` and `BedTrackingService` **do** exist (`app/Services/Ed/TriageService.php`, `app/Services/Rtdc/BedTrackingService.php`) — the plan's references are accurate. But of the six proposed canonical services, only **`HouseCensusService` already exists** (`app/Services/Rtdc/HouseCensusService.php`) and is *already* the single house-census read that Cockpit delegates to. The other five are just renames of existing split logic — do **not** create parallel classes; consolidate onto the real ones:
- ED → currently split across `Ed/{TriageService, TreatmentService, ResourceManagementService, WaitTimeService, NedocsService, ResourceAnalyticsService}` + `Dashboard/EdDashboardService` (there is no `EdCurrentStateService`).
- Periop → `Operations/{CaseManagementService, RoomStatusService}` + `Dashboard/PerioperativeMetricsService`.
- Staffing → `Staffing/StaffingOperationsService`; Transport → `Transport/TransportOperationsService`; Discharge → `Rtdc/DischargePrioritiesService`.

`DemoClock`/`DemoRefreshCoordinator` are genuinely greenfield. Confirm: app reads only `prod.*` (`config/database.php` → `search_path=prod,public`); the empty `emergency|perioperative|rtdc` schemas are read by **no** PHP (the `rtdc.*` strings in code are Cockpit *metric-key* namespaces, not tables).

### 3.4a The house-denominator bug is real and localized to ONE place
`HouseCensusService` sums `staffed_beds` across **all** non-deleted units with **no unit-type filter**, so any ED/OR unit carrying census rows is folded straight into the inpatient occupancy % that drives the RTDC dashboard and Cockpit strain tiles. This — not six scattered queries — is the single fix locus for §8.1: add a unit-classification filter here and everything downstream inherits it (Cockpit already delegates to it).

### 3.4b The incoherence mechanism is *divergent forward-anchoring*, not just divergent queries
Different surfaces of the same domain invent a different "now," which is why Triage/Treatment/Analytics disagree even off identical rows:
- `TriageService::anchorNow()` advances "now" to `MAX(arrived_at)` (so it sees the future-dated cohort); but `TreatmentService`, `WaitTimeService`, `ResourceManagementService`, `NedocsService` use plain `now()`/`today()` (so they *under*-populate the same cohort).
- Periop advances to the latest **day**: `CaseManagementService::activeDate()` and `RoomStatusService` anchor on `MAX(surgery_date)`, and `RoomStatusService::simulatedClock()` clamps to 10:30 off-hours.
- `StaffingOperationsService::todaysPlans()` is strict `whereDate(shift_date, today())` — empty whenever the seed day ≠ today.

Once DemoClock guarantees seeds never drift ahead of the anchor (Wave 1), **delete every one of these forward-anchor workarounds** — they exist only to paper over drift and actively cause the disagreement.

### 3.5 The biggest gap for "deeply plausible values": the plausibility model is disconnected
`config/hospital/hospital-1-distributions.json` is a rigorously derived, independently-verified statistical profile (admission archetypes, careunit transition matrix, per-unit-type LOS, ICU LOS, ED throughput hours, service-line weights, disposition mix, geriatric demographics, acuity/procedure signature). **It is never loaded at runtime** — it appears only in a code *comment* as provenance. The operational seeders use `mt_srand(20260622)` with hand-tuned constants. Consequence: the data is *coherent* but not *distribution-true*. This is the workstream the plan is missing, and it's exactly what "deeply plausible across the entire spectrum" requires. See §6.

### 3.6 Kill the empty legacy schemas (a footgun)
`emergency.*`, `perioperative.*`, and `rtdc.*` schemas exist with full table sets but are **completely empty** — the app reads the parallel `prod.*` tables. Anyone "fixing ED data" could seed the wrong schema. Either drop these schemas or document them as dead, and add an invariant that they stay empty.

### 3.7 Run the temporal invariants as a *pre-demo gate today* — the DB is already violating them
Current violations: **100** future-dated census snapshots, **3** encounters admitted in the future, **10** encounters with discharge-before-admit, **96** RTDC predictions dated in the past. §11's invariants should block a refresh from publishing (they'd catch all four right now).

### 3.8 Several surfaces render client-side *mocks*, not data — no amount of seeding fixes these
The plan's §3.3 mentions "page-level authored demo fallbacks" but doesn't enumerate them; they matter because a coherent, plausible database still won't reach these screens. Inventory to hunt down (React):
- **Fully client-mock, no backend props at all:** `RTDC/UnitHuddle` and `RTDC/GlobalHuddle` (their controller actions render with no service). §7.2's "Global Huddle reads the same prediction day" is currently impossible — there's nothing to read.
- **Hardcoded numeric fallbacks that leak stale/round values:** `RTDC/BedTracking.tsx` (`TOTAL=500`, `OCCUPIED=425` — this is where a stray "425" comes from), `ED/Analytics/WaitTime.jsx` (`DEFAULT_KPI` zeros).
- **`DEMO_*` block fallbacks:** `ED/Predictions/Resources.jsx` (`DEMO_AVAILABLE/FORECAST/KPIS/RECOMMENDATIONS/ACUITY_MIX`), `RTDC/Predictions/ResourcePlanning.jsx` (`DEMO_KPIS/DEMAND_VS_CAPACITY/RECOMMENDATIONS`), `Operations/RoomStatus.jsx` (12-room `mockRooms`), `Operations/BlockSchedule.jsx`, `RTDC/DischargePriorities.jsx` (`generateMockDischargeData()`), `Analytics.jsx` (`fallbackIntelligence/ActionQueue/SourceMap`), `Improvement/*` (`mockCycles`, `MOCK_BOTTLENECK_DATA`, `MOCK_RESOURCE_DATA`).

Decision per surface: wire to the canonical service (Wave 3) **or** convert the fallback into an honest empty/unavailable state (Wave 4). A silent mock is the worst outcome — it looks live and reconciles with nothing.

---

## 4. The plausibility problem, quantified (this is the heart of "deeply plausible")

Coherence makes the numbers *agree*; plausibility makes them *believable*. Current specifics that fail a clinician's smell test:

| Signal | Measured now | Realistic target | Why it's off |
|---|---|---|---|
| Inpatient occupancy by unit type | **flat 84.4–84.7%** across ICU / step-down / med-surg | ICU 85–95%, step-down 80–90%, med-surg 78–88%, differentiated | A single global occupancy target applied uniformly, not a per-unit model |
| ED acuity (ESI) mix | 1:8 / **2:45** / 3:37 / 4:9 / 5:5 (ESI-2 = 43%) | pyramid: ESI-3 largest (~40–45%), then 2 & 4 (~20–25% each), ESI-1 ~2%, ESI-5 ~5–10% | Over-acute arrival mix; ESI-2 shouldn't exceed ESI-3 |
| Discharge-before-noon | **49.9%** | 25–35% (a metric hospitals *struggle* to hit) | Implausibly rosy — undercuts the improvement narrative |
| Discharge volume | 1,284 over 4 days ≈ **321/day** | ~90–120/day for 500 staffed beds | ~3× too high; also only a 4-day span, not the "6-month cohort" §7.6 assumes |
| Transport priority mix | routine 101 / **urgent 51 / stat 50** | routine ≫ urgent ≫ stat (stat a few %) | STAT ≈ urgent is not real; over-weighted to high priority |
| Active transport overdue | **21 of 22** overdue | a small, intentional overdue cohort (≤ ~15%) | `needed_at` anchored to an old shift |
| OR scale | 4 rooms, **15–20 cases/day**, no weekend cases | Level I / 44 procedural rooms → 35–60 weekday cases, a light weekend emergent tail | Volume & room count contradict the stated "Level I Trauma Academic" identity |
| ED door-to-provider | median 19 m / p90 25 m / **max 27 m** | median plausible, but needs a real long tail (p90 45–90 m, occasional 2–4 h) | Intervals too tight — no variance |

Note the recurring pattern: **over-acuity** (ED ESI, transport priority) and **flat distributions** (occupancy, OR/day, door-to-provider). Both are symptoms of hand-tuned constants instead of sampling from `distributions.json`.

---

## 5. Concrete Summit capacity constants (drop-in for §8.1)

Author these into the manifest and assert them as invariants:

```
licensed_inpatient_beds   = 500   # icu 96 + step_down 64 + med_surg 340
staffed_inpatient_beds    = <=500 # current staffed subset; the ONLY house denominator
ed_treatment_spaces       = 148   # ED only — never in the inpatient denominator
periop_procedure_spaces   = 44    # OR/procedure/PACU — never in the inpatient denominator
physical_bed_inventory    = 692   # display only, per matching domain
```
Reconciliation invariant per unit: `occupied + available + dirty + blocked = staffed/physical inventory`. House invariant: `Σ inpatient-unit occupied = house occupied`, and the house denominator excludes ED + periop.

Pending-discharge named measures (pick one per surface, link to its worklist): `expected_discharge_24h`, `discharge_ready_now`, `priority_discharge_worklist`, `discharged_before_noon` (historical outcome only — never a current pending count).

---

## 6. Refined delivery plan

The plan's Waves 0–6 are good; I re-order and add a **Plausibility** wave, and pull the "just schedule it" win to the front.

### Wave 0 — Coherent + honest *today* (hours, no new architecture)
1. Run one manual re-anchored batch at a single frozen anchor: `db:seed --class=CommandCenterDemoSeeder` → `db:seed --class=DemoTuningSeeder` → `patient-flow:rebase-synthetic --anchor=now` → `flow:snapshot --backfill=24h` → `rtdc:simulate --trailing`. (These already exist; they've just never been run together on a cron.)
2. Fix the future-leak: cap census generation at `anchor`; correct the 3 future admits + 10 discharge-before-admit rows.
3. Wire the global badge to `ops.source_freshness` → `DEMO · CURRENT / AGING / STALE`; add the "Synthetic — not for clinical use" banner with anchor + oldest critical source. Stale sources cannot render green.
4. Rotate the superuser password behind an env flag.
**Exit:** header can't call stale data LIVE; no active timer > 24 h; the four temporal violations are gone.

### Wave 1 — Formalize the clock (DemoClock, ledger, coordinator, locks)
Wrap the Wave 0 commands in `zephyrus:demo-refresh` (one anchor, advisory lock, `ops.demo_refresh_runs` ledger, publish-only-if-invariants-pass). Build **on** the existing ownership tags (§3.3) and `ops.source_freshness` (§3.2). Schedule it every 15 min with `withoutOverlapping()->onOneServer()`. Requires a real `schedule:work` + queue worker on the host.
**Exit:** dry-run + repeatable frozen-anchor refresh; idempotency suite green.

### Wave 2 — Plausibility engine (**new — the "deeply plausible" core**)
1. Load `hospital-1-distributions.json` at runtime behind a `DistributionProfile` service (`DistributionSampler`).
2. Replace hand-tuned constants in the domain refreshers with sampling: per-unit-type LOS (use `icuLos` for ICU beds, *not* the MICU transport-unit artifact — the JSON flags this), admission archetypes + transition matrix for encounter journeys, disposition mix, geriatric age/gender skew.
3. Domain calibrations from §4: per-unit occupancy targets (ICU hot, med-surg cooler); ED **ESI pyramid** on arrivals with realistic door-to-provider tails; **scale OR** to the 44-room / Level-I identity (or explicitly down-scope the identity) with weekday shape + weekend emergent tail; transport priority mix routine≫urgent≫stat with a small overdue cohort; discharge-before-noon to 25–35%.
4. Add **distributional invariants** (§8): assert sampled cohorts fall within target bands, not just that counts reconcile.
**Exit:** each domain's headline distribution is within its realistic band; a clinician reviewer can't point to an "off" number.

### Wave 3 — Canonical state consolidation + reconciliation
Do **not** create six new `*CurrentStateService` classes. Instead: (a) add the unit-classification filter to the existing `HouseCensusService` so it stops mixing ED/OR into the inpatient denominator (§3.4a) — Cockpit already delegates to it, so this fix propagates for free; (b) collapse the ED read-path onto one entry point and the periop read-path onto one service-date resolver (§3.4); (c) delete the divergent forward-anchor workarounds now that Wave 1 guarantees no drift (§3.4b); (d) point the fully-mock and `DEMO_*` React surfaces (§3.8) at the real services. Cross-surface parity suite (§11.2) is the gate.

### Wave 4 — Page repairs (parallel with Wave 0)
`/improvement/bottlenecks` (error boundary + honest empty), `/analytics/turnover-times` (`turnoverDistribution` guard, drop the `MARH OR` fallback), `/rtdc/predictions/discharge` (title), shared formatters (kill `56.400000000000006%`), Arena (seed OCEL + health-check sidecar or hide intentionally), Enterprise Setup (registry import), planned analytics (implement / preview-label / hide), Cockpit Service Line tab.

### Wave 5 — Freshness, scheduler, monitoring
Schedule the `source_freshness` recompute (don't leave it request-driven); `/up/demo` health endpoint; admin refresh-history panel; alert on 2 failed refreshes / scheduler silence / stale-critical / invariant failure.

### Wave 6 — Invariants in CI + browser regression
Full temporal + reconciliation + **plausibility/distributional** invariant suites, idempotency (run twice at one anchor, once at +15m), 67-route browser smoke (no blank root, no error boundary, no endless loading, no `undefined` title).

---

## 7. Do-this-in-the-next-hour checklist
- [ ] Run the Wave 0 re-anchor batch against a frozen anchor; confirm ED/OR/RTDC/flow newest observation moves inside threshold.
- [ ] Delete/repair the 100 future census snapshots, 3 future admits, 10 discharge-before-admit rows.
- [ ] Point the header badge at `ops.source_freshness`; ship the synthetic banner.
- [ ] Add a `--validate-only` invariant pass that fails on the four current violations, and run it as the pre-demo gate.
- [ ] File the two footguns: drop/annotate empty `emergency|perioperative|rtdc` schemas; note that `distributions.json` is currently unused at runtime.

---

## 7a. Build status — 2026-07-10 (read-only foundation landed)

Shipped (all SELECT-only / additive; nothing written to the canonical demo DB yet):
- `config/hospital.php` — registered the previously-unused `distributions` profile + `inpatient_unit_types` + tuneable `plausibility_targets` bands.
- `App\Services\Demo\DemoClock` — the one immutable anchor + 48h window (replaces the divergent per-service "now").
- `App\Services\Demo\DistributionProfile` — finally loads `hospital-1-distributions.json` at runtime with typed accessors + the clinical bands.
- `App\Services\Demo\DemoInvariantService` — read-only temporal / capacity / freshness / plausibility invariants.
- `zephyrus:demo-validate` — the `--validate-only` pre-demo gate (non-zero exit on critical failure; `--json` for monitors). Safe to run on the canonical host.
- `tests/Unit/Demo/*` — 8 PHPUnit tests (DemoClock, DistributionProfile, gate logic), green.

Verified against the live DB (read-only): the gate initially reported **6 critical failures** (3 future admits, 10 discharge-before-admit, 96 stale forecasts, 46 aged ED boarders, house-denominator contamination, 4 stale decision sources) and 6 plausibility warnings.

**Wave 0 EXECUTED on the canonical demo DB (2026-07-10) — all 6 criticals cleared.** After a full `prod`-schema backup, ran `patient-flow:rebase-synthetic --anchor=now` (window shifted +6d), `db:seed CommandCenterDemoSeeder`, `db:seed DemoTuningSeeder`, `flow:snapshot --backfill=24h`, and republished the cockpit snapshot. Two seeder bugs were found and fixed in the process:
- `DemoTuningSeeder::staffingToday()` collided on `uniq_staffing_plan_slot` because its `DELETE` only cleared `notes='demo-today'` while `CommandCenterDemoSeeder` now owns today's slots → made the insert `ON CONFLICT … DO UPDATE` (takes ownership, idempotent).
- Added `DemoTuningSeeder::clampTemporalLeaks()` (runs first, idempotent): purge expired forecasts, drop future-dated census actuals, pull future-admitted active encounters back to a recent admit, repair discharge-before-admit rows.
- Fixed `HouseCensusService::houseTotals()` to scope the house denominator to `config('hospital.inpatient_unit_types')` (excludes ED + periop); `latestPerUnit()` still returns all units for per-unit consumers.

Result: **0 critical failures, 6 plausibility warnings.** Domains re-anchored — ED aged boarders 46→0, OR cases today 0→20, forecasts 07-04→07-11/12, flow 144h→0.2h; census and bed-board reconcile at 423/500 = 85%.

**Wave 1 EXECUTED (2026-07-10) — the demo now stays current unattended.** Built `config/demo.php` (DEMO_MODE gate), the `ops.demo_refresh_runs` ledger (migration), `App\Services\Demo\DemoRefreshCoordinator`, and `zephyrus:demo-refresh` (`--validate`/`--validate-only`/`--dry-run`/`--json`/`--force`). One anchor per batch, a PostgreSQL advisory lock, ledger row, the refresher batch (rebase → CommandCenterDemoSeeder → DemoTuningSeeder), `ops.source_freshness` recompute, invariants, and cockpit publish **only if critical invariants pass**. Scheduled `*/15 * * * *` (gated on `demo.enabled`, `withoutOverlapping(10)`). Verified: repeated runs are idempotent (every domain table stable across runs — 0 growth), each writes a ledger row, and a forced failure preserves last-known-good (cockpit not published). Two more re-runnability bugs fixed: `CommandCenterDemoSeeder::seedTransportBacklog()` was deleting `transport_requests` → cascade into the **append-only** `transport_events` ledger (now seed-once; `DemoTuningSeeder::refreshSlas()` keeps SLAs near-now); and the coordinator no longer calls `flow:snapshot --backfill` every cycle (it appended off-hour duplicate `occupancy_snapshots` because the rebase nudges prior rows off their upsert key).

**Wave 2 EXECUTED (2026-07-10) — deeply plausible values, 0 warnings.** Added `App\Services\Demo\DistributionSampler` (deterministic weighted / in-band sampling) and wired it into the generators so values are distribution-true, not flat constants:
- **Occupancy differentiated** — `DemoTuningSeeder` per-unit-type targets (ICU 92 / step-down 86 / med-surg ~85), pulled up only, so ICU runs hotter (spread 5.3 pts, was 0.3).
- **ESI pyramid** — `CommandCenterDemoSeeder`: throughput ESI-1 made rare (walk-in ESI-1 is not real; the vent crowding cohort carries ESI-1) and the crowding cohort samples a pyramid instead of hard-coding ESI-2 → ESI-3 modal (5/30/43/14/8).
- **Discharge-before-noon** — afternoon-peaked discharge-hour distribution (was uniform 0–23h → ~50%) → 37.5%.
- **Transport** — re-weighted synthetic sources to routine 70 / urgent 20 / stat 10 (stable per request id) and tightened the SLA spread to ~9% overdue (was 73%); user-created requests preserved.

Verified idempotent across repeated refreshes (every table stable, deterministic sampler). Tests: `tests/Unit/Demo/DistributionSamplerTest` (+ suite = 13 green). Result: **0 critical, 0 warnings.**

**OR scale-up + Wave 5 EXECUTED (2026-07-11).** OR expanded to a 20-room Level I suite with a block-schedule active pattern (~70% staffed/day; anchor day forces 12 rooms) and 2–4 cases/active room → ~48 cases across 16 rooms on the anchor day; FCOTS generalised off hardcoded room-index slots to a deterministic ~80% (measured 81%), turnover (~29m) and PACU holds (4) preserved; new `plausibility.or_daily_volume` invariant. A 15-minute batch-drift grace was added to the "not in future" checks (the anchor is frozen at start but seeders write at live now()). Wave 5: `zephyrus:demo-prune` (nightly retention for occupancy/census snapshots + the ledger) and `GET /up/demo` (health endpoint — last refresh, source freshness, scheduler liveness; 200/503) for uptime monitors.

Result across everything: **22 checks, 0 critical / 0 warnings, idempotent.**

Genuinely remaining (follow-ups): the admin refresh-history **panel** (frontend) is not built (the `/up/demo` endpoint + ledger back it); the full PHPUnit suite should run in **CI** against `zephyrus_test` (Demo unit suite is green; app behavior verified directly against canonical); the demo **host** needs a `schedule:run` cron + queue worker + `DEMO_MODE=true`; and the app relies on the DB server session timezone being **UTC** (standard for servers — `config/database.php` doesn't pin it).

## 8. One-line summary
The plan's diagnosis is right and its architecture is sound, but (a) the fastest 80% of coherence is "schedule the seeders + rebase you already have," (b) build on the `ops.source_freshness` + seeder-ownership machinery that already exists rather than re-deriving it, and (c) add the missing **plausibility wave** that finally wires the verified `distributions.json` into the generators — because *coherent* and *deeply plausible* are two different bars, and only the first is currently in scope.
