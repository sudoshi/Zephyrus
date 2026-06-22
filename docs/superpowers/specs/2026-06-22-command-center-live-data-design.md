# Command Center — Live Data Build (Design Spec)

**Date:** 2026-06-22
**Status:** Approved scope (user chose "Full build — all bands live")
**Branch:** `feature/command-center-live-data`
**Depends on:** the merged/deployed Command Center dashboard (spec `2026-06-22-command-center-dashboard-design.md`).

## Goal

Make `CommandCenterDataService::build()` compute **every band** (Capacity, Flow ED/IP/OR, Outcomes, Forecast, hero, strain, objectives, forecastDetail) from the live `zephyrus_dev` database instead of hardcoded synthesis. **The Zod payload contract (`resources/js/types/commandCenter.ts`) and all frontend code stay byte-for-byte unchanged** — this is a backend + DB-seed effort only. Where a metric needs source data that does not exist, create the table and seed it. Data remains representative (seeded), not a real clinical feed.

## Already done in this branch

- The 6 pending RTDC migrations were run (`php artisan migrate`): `rtdc_predictions`, `rtdc_plans`, `huddles`, `barriers`, `rtdc_reconciliations`, `bed_requests`, `bed_placement_decisions` now exist in the `prod` schema. Core RTDC data is already populated (`units` 12, `beds` 360, `encounters` 360, `census_snapshots` 432, `operational_events` 432).

## New tables to create (migrations)

All in the `prod` schema (the project sets `DB_SCHEMA=prod`; follow the column conventions of `2026_06_20_000010_create_rtdc_units_beds_tables.php` — `bigIncrements` PK named `<entity>_id`, `timestamps()`, `boolean is_deleted default false`). Place under `database/migrations/` with date prefix `2026_06_22_0000NN_…`.

### `prod.ed_visits` (powers the ED flow metrics)
- `ed_visit_id` bigint PK
- `patient_ref` varchar, required (pseudonymous, e.g. `sim-ed-0001`)
- `arrived_at` timestamp, required
- `triaged_at` timestamp, nullable
- `esi_level` tinyint unsigned, nullable (1–5)
- `provider_seen_at` timestamp, nullable
- `disposition` varchar, nullable — enum: `admitted`|`discharged`|`lwbs`|`transfer`|`eloped` (NULL = still in ED)
- `admit_decision_at` timestamp, nullable (boarding clock start)
- `bed_assigned_at` timestamp, nullable (boarding clock end; NULL + disposition=admitted ⇒ currently boarding)
- `departed_at` timestamp, nullable (left the ED)
- `unit_id` bigint FK→prod.units, nullable (admitting unit)
- `created_at`/`updated_at`, `is_deleted` bool default false
- index `(arrived_at)`, `(disposition)`

### `prod.gmlos_references` (powers LOS/GMLOS + excess bed-days)
- `gmlos_reference_id` bigint PK
- `unit_type` varchar, required — matches `units.type` (`med_surg`|`icu`|`step_down`|`ed`)
- `gmlos_days` decimal(5,2), required
- `effective_from` date, nullable
- `created_at`/`updated_at`
- unique `(unit_type)`

### `prod.diversion_events` (powers diversion hours)
- `diversion_event_id` bigint PK
- `scope` varchar default `ed` (`ed`|`hospital`)
- `unit_id` bigint FK→prod.units, nullable
- `started_at` timestamp, required
- `ended_at` timestamp, nullable (NULL = ongoing)
- `reason` varchar, nullable
- `created_at`/`updated_at`, `is_deleted` bool default false

### `prod.pdsa_cycles` (powers Active PDSA count)
- `pdsa_cycle_id` bigint PK
- `title` varchar, required
- `unit_id` bigint FK→prod.units, nullable
- `status` varchar default `active` — enum: `planned`|`active`|`completed`|`abandoned`
- `owner` varchar, nullable
- `objective` text, nullable
- `started_at` timestamp, nullable
- `completed_at` timestamp, nullable
- `created_at`/`updated_at`, `is_deleted` bool default false

Each new table gets an Eloquent model under `app/Models/` (PK `$primaryKey`, `$table='prod.<name>'` per existing model convention, `$guarded=[]` or fillable to match siblings).

## Seed plan

One idempotent orchestrator seeder **`Database\Seeders\CommandCenterDemoSeeder`** (re-runnable: truncate-or-upsert its own target rows; never touch `users`). It ensures/produces, deterministically (seed a fixed `mt_srand`):

- **RTDC core** — assume present; if `encounters` is empty, call the existing `RtdcSeeder` + synthetic source. (Idempotent guard.)
- **`rtdc_predictions`** — for each non-ED unit, today, both horizons (`by_2pm`,`by_midnight`): `discharges_definite/probable/possible`, `discharges_weighted`, `demand_ed/or/transfer/direct/expected`, `capacity_now` (= current available for the unit), `bed_need` (= `demand_expected − (capacity_now + discharges_weighted)`).
- **`rtdc_reconciliations`** — ~14 days history/unit with `predicted_*`, `actual_*`, `reliability_score` (0.7–0.95).
- **`bed_requests`** — ~18 rows, `source` mix (ed/transfer/direct/or), some `status=placed`, some `pending` (drives pending-admits). For placed ones create **`bed_placement_decisions`** with `created_at` 30–70 min after the matching encounter `admitted_at` (drives admit→bed latency).
- **`barriers`** — ~6 open across units (blocked-bed reasons).
- **`ed_visits`** — ~70 over the last 24h: `arrived_at` spread; `triaged_at` +3–12 min; `provider_seen_at` +8–35 min; disposition mix ≈ 70% discharged / 24% admitted / 2% lwbs / 4% transfer; `departed_at` set for completed; for admitted, `admit_decision_at` set and `bed_assigned_at` set for most but NULL for ~4 (currently boarding); `esi_level` weighted 2–4.
- **OR domain** — ensure reference rows (`services`, `rooms` (4 ORs), `providers`, `specialties`, `case_types`, `case_classes`, `patient_classes`, `asa_ratings`, `cancellation_reasons`, `case_statuses`), then `or_cases` for the last 5 weekdays × 4 rooms × ~5 cases with `scheduled_start_time`/`scheduled_duration`/`status_id`; `or_logs` with realistic `or_in_time`/`procedure_start_time`/`procedure_end_time`/`or_out_time`; a few `status=Cancelled` today. Seed `block_templates` + `block_utilization` rows and `case_metrics` (`turnover_time`,`late_start_minutes`,`utilization_percentage`) so OR metrics are directly queryable.
- **`gmlos_references`** — `med_surg` 4.20, `icu` 5.80, `step_down` 3.50, `ed` 0.40.
- **`diversion_events`** — 1–2 historical (ended) in the last week, 0 active (so current diversion hours ≈ 0).
- **`pdsa_cycles`** — 5 with `status=active`, 2 completed.

Wire `CommandCenterDemoSeeder` into `DatabaseSeeder` (append) AND make it runnable standalone: `php artisan db:seed --class=CommandCenterDemoSeeder`.

## Live computation definitions (the service rewrite)

Rewrite each private builder in `app/Services/CommandCenterDataService.php` to query the DB. `build()`'s returned array shape is unchanged. "Window" = last 24h unless noted. All percentages rounded to ints unless the contract shows decimals (readmission, LOS/GMLOS keep one decimal).

**unitCensus / capacity / hero / strain** (latest `census_snapshots` per unit via `DISTINCT ON (unit_id) … ORDER BY unit_id, captured_at DESC`, joined to `units`):
- per-unit `occupancyPct = round(occupied/NULLIF(staffed,0)*100)`; `acuityAdjustedPct = LEAST(100, round(occupied/NULLIF(acuity_adjusted_capacity,0)*100))`; status: ≥92 critical / ≥85 warning / else success.
- house `occupancy = round(sum(occupied)/sum(staffed)*100)`; `available = sum(available)`; `blocked = sum(blocked)`.
- `pendingAdmits = count(bed_requests where status='pending')`; `edBoarding = count(ed_visits where disposition='admitted' and bed_assigned_at is null)`.
- `netBeds = sum(available) − pendingAdmits`.
- `dischargesReady = count(encounters where status='active' and expected_discharge_date = current_date)`.
- strain `level`: occupancy ≥92→+2 / ≥85→+1; edBoarding ≥6→+1; pendingAdmits ≥10→+1; clamp 0–4; status ≥3 critical / ≥2 warning / else success. `previousLevel = max(0, level−1)`.

**Flow — ED** (`ed_visits`, window 24h):
- `door_to_provider = round(median(extract(epoch from provider_seen_at−arrived_at)/60))` over rows with `provider_seen_at`.
- `lwbs = round(100.0*count(disposition='lwbs')/NULLIF(count(*),0), 1)`.
- `ed_los = round(median(extract(epoch from departed_at−arrived_at)/60))` where `disposition='discharged'`.
- `ed_boarding = edBoarding` (above).

**Flow — Inpatient:**
- `adm_to_bed = round(median over placed requests of extract(epoch from bpd.created_at − br.created_at)/60))`.
- `dbn = round(100.0*count(discharged_at::time < '12:00')/NULLIF(count(discharged today),0))` over `encounters` discharged in window.

**Flow — OR** (`or_cases`/`or_logs`/`case_metrics`/`block_utilization`, most recent OR day):
- `fcots = round(100.0 * fraction of first-case-per-room where procedure_start_time ≤ scheduled_start_time + 15min)`.
- `block_util = round(avg(block_utilization.utilization_percentage) for the day)`.
- `turnover = round(avg(case_metrics.turnover_time))` (fallback: avg gap or_out→next or_in per room).
- `cancellations = count(or_cases where status=Cancelled and surgery_date = current_date)`.

**Outcomes** (`encounters`, `gmlos_references`, `diversion_events`, `pdsa_cycles`):
- `readmission = round(100.0 * (# discharged encounters followed by a same-`patient_ref` admission within 30 days) / NULLIF(# discharges in window,0), 1)` via self-join.
- per discharged encounter `los_days = extract(epoch from discharged_at−admitted_at)/86400`; `gmlos` = ref for `unit.type`. `los_gmlos = round(sum(los_days)/NULLIF(sum(gmlos),0), 2)`; `excess_days = round(sum(greatest(0, los_days−gmlos)))`.
- `diversion = round(sum hours of diversion_events overlapping the window)` (≈0).
- `pdsa_active = count(pdsa_cycles where status in ('active','planned'))`.

**Forecast** (`rtdc_predictions` today, `census_snapshots`):
- `pred_discharges = sum(discharges_definite + discharges_probable)`.
- `pred_arrivals = sum(demand_ed)`; `pred_admissions = sum(demand_expected)`.
- `net_beds (proj) = round(sum(available_now) + sum(discharges_weighted) − sum(demand_expected))`.
- `surge_prob` = heuristic: `clamp(round((occupancy−80)*4 + max(0,−netBeds)*5 + (1−avg_reliability)*20), 0, 95)`; documented as a heuristic, not a trained model.
- `forecastDetail.occupancyCurve` = deterministic 24h curve seeded from current house occupancy, distributing predicted admissions (inflow) and weighted discharges (outflow) across hours (e.g., discharges weighted to late morning, admissions to afternoon/evening); `lowerPct/upperPct = occ∓3`.
- `forecastDetail.netBedByUnit` = per unit `available_now − bed_need`.

**objectives** (OKR scoreboard): keep the three objectives, but compute `current` from the live metrics above (ED boarding minutes proxy from ed_visits, DBN%, FCOTS%, block util%, LOS/GMLOS) and keep `baseline`/`target` as configured constants; `progressPct = round(100*(baseline−current)/(baseline−target))` clamped 0–100 (direction-aware).

## Testing

- PHP feature test `tests/Feature/CommandCenterLiveDataTest.php`: seed via `CommandCenterDemoSeeder` (RefreshDatabase or seed into the test DB), call `app(CommandCenterDataService::class)->build()`, assert:
  - the four bands and hero/strain/forecast are present (existing structural test still passes);
  - values are **derived, not the old constants** (e.g., `heroMetrics` occupancy equals the computed census value; `flow` OR `cancellations` equals the seeded count; `outcomes` `pdsa_active` equals seeded active count; `forecast` `pred_discharges` equals the seeded sum). Use a handful of exact cross-checks against seeded fixtures.
- Existing `CommandCenterDataServiceTest` (structural) and `CommandCenterControllerTest` must still pass (shape unchanged). The frontend Vitest suite is untouched and must stay green.
- Run `vendor/bin/pint` on all touched PHP.

## Deploy

Frontend unchanged (no rebuild strictly required, but `./deploy.sh --frontend` is harmless). Prod needs the **schema + seed**:
1. `./deploy.sh --db` (runs migrations on prod — the 6 RTDC + 4 new).
2. Seed prod once: `php artisan db:seed --class=CommandCenterDemoSeeder --force` in `/var/www/Zephyrus`.
3. Smoke-check `/dashboard` shows live-derived numbers.
(Representative seed data in prod is acceptable — the whole RTDC dataset is already synthetic.)

## Task breakdown (for subagent execution)

1. **Migrations + models** — 4 create-table migrations + 4 Eloquent models; a feature test asserting the tables/columns exist.
2. **Seeder** — `CommandCenterDemoSeeder` (+ wire into `DatabaseSeeder`); run it; assert non-zero counts in every target table.
3. **Service rewrite** — replace the 9 builders with live queries per the definitions above; `CommandCenterLiveDataTest` for derived-value cross-checks; keep structural tests green; Pint.
4. **Verify + review + finish + deploy** — full PHP + JS suites, Pint, final review, merge, `deploy.sh --db` + prod seed + smoke.

## Risks

- **Median in Postgres** — use `percentile_cont(0.5) within group (order by …)`.
- **Empty windows** — guard every divisor with `NULLIF`; default to `neutral`/0 status, never divide-by-zero.
- **Status banding consistency** — centralize the `critical/warning/success` thresholds in one private helper reused by every metric.
- **Idempotent seeding** — the seeder must be safe to re-run (dev and prod) without duplicating rows.
- **Contract drift** — do NOT change any key, type, or nullability in the payload; the Zod schema is frozen. A `parseCommandCenterData`-equivalent shape check in the feature test guards this.
