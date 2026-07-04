# Hummingbird — RTDC Numbers Plausibility Report

**Generated:** 2026-06-28 · **Source:** live Postgres (`pgsql.acumenus.net` / `192.168.1.58`, db `zephyrus`, schema `prod`), read-only.
**Scope:** every number the mobile screens render comes from `GET /api/mobile/v1/rtdc/census`
(`RtdcController` + `AcuityService`) and `/for-you`. This report replays that exact math against
live data and flags values that read as implausible on screen.

## How each field is computed (so fixes land in the right place)

| Field shown | Source |
|---|---|
| `occupied` / `available` / `blocked/dirty` | counts of rows in `prod.beds` for the unit, by `status` |
| `staffed_bed_count` | `prod.units.staffed_bed_count` |
| `safe_capacity` | `AcuityService::adjustedCapacity` = `staffed_bed_count − Σ(acuity-tier weight of ACTIVE encounters)` → **remaining headroom**, floored at 0 |
| `bed_need` | `max(0, occupied − safe_capacity)` |
| `status` / `%` | `occupied / safe_capacity` → ≥100% critical, ≥90% warning, else success; `safe_capacity = 0` → "No data" |

Tier weights: T1 = 1.0, T2 = 1.3, T3 = 1.7, T4 = 2.2.

## Per-unit snapshot (active units)

`beds(rel)` = rows the census counts · `staffed` = `staffed_bed_count` · `safe` = headroom · `app_%` = what the gauge shows.

| unit | type | staffed | occ | avail | blk | beds | activeEnc | load | safe | app_% | flags |
|---|---|--:|--:|--:|--:|--:|--:|--:|--:|--:|---|
| Emergency Department | ed | 40 | 33 | **108** | 7 | **148** | 33 | 58.1 | 0 | — | ⚠️ avail>staffed, beds≠staffed, 0-headroom |
| 5 East | med_surg | 32 | 27 | 0 | 5 | 32 | 27 | 37.0 | 0 | — | headroom 0 → "No data" |
| 5 West | med_surg | 32 | 24 | 0 | 8 | 32 | 24 | 32.2 | 0 | — | headroom 0 → "No data" |
| 6 East | med_surg | 32 | 20 | 0 | 12 | 32 | 20 | 27.5 | 4 | **500%** | ⚠️ over-display |
| 3 West — MICU | icu | 24 | 0 | 24 | 0 | 24 | 1 | 1.3 | 22 | 0% | ok |
| 3 East — SICU | icu | 24 | 0 | 24 | 0 | 24 | 0 | 0.0 | 24 | 0% | ok |
| 3 South — NSICU | icu | 20 | 0 | 20 | 0 | 20 | 1 | 1.3 | 18 | 0% | stray enc |
| 3 North — CVICU | icu | 20 | 0 | 20 | 0 | 20 | 0 | 0.0 | 20 | 0% | ok |
| 3 Central — Burn ICU | icu | 8 | 0 | 8 | 0 | 8 | 1 | 1.7 | 6 | 0% | stray enc |
| 2 — Behavioral Health | med_surg | 24 | 0 | 24 | 0 | 24 | 0 | 0.0 | 24 | 0% | ok |
| 4 West — Med/Surg | med_surg | 28 | 0 | 28 | 0 | 28 | 1 | 1.7 | 26 | 0% | stray enc |
| 4 East — Med/Surg | med_surg | 28 | 0 | 28 | 0 | 28 | 1 | 2.2 | 25 | 0% | stray enc |
| 6 West — Med/Surg | med_surg | 24 | 0 | 24 | 0 | 24 | 1 | 2.2 | 21 | 0% | stray enc |
| 7 East — Telemetry | step_down | 32 | 0 | 32 | 0 | 32 | 0 | 0.0 | 32 | 0% | ok |
| 7 West — Stepdown | step_down | 32 | 0 | 32 | 0 | 32 | 0 | 0.0 | 32 | 0% | ok |
| 8 — Antepartum | med_surg | 12 | 0 | 12 | 0 | 12 | 1 | 1.3 | 10 | 0% | stray enc |
| 8 — Postpartum | med_surg | 28 | 0 | 28 | 0 | 28 | 1 | 1.3 | 26 | 0% | stray enc |
| 8 — Gyn Surgery | med_surg | 8 | 0 | 8 | 0 | 8 | 1 | 1.0 | 7 | 0% | stray enc |
| 9 — Pediatric Acute | med_surg | 24 | 0 | 24 | 0 | 24 | 0 | 0.0 | 24 | 0% | ok |
| 9 — PICU | med_surg | 12 | 0 | 12 | 0 | 12 | 1 | 1.7 | 10 | 0% | stray enc + type |
| 9 — NICU | med_surg | 12 | 0 | 12 | 0 | 12 | 0 | 0.0 | 12 | 0% | type |
| 10 — Oncology/Heme | med_surg | 24 | 0 | 24 | 0 | 24 | 0 | 0.0 | 24 | 0% | ok |
| 10 — BMT/Cellular | med_surg | 16 | 0 | 16 | 0 | 16 | 0 | 0.0 | 16 | 0% | ok |
| 11 — Acute Rehab | med_surg | 20 | 0 | 20 | 0 | 20 | 1 | 2.2 | 17 | 0% | stray enc |
| 2 — Perioperative | periop | 44 | 0 | 44 | 0 | 44 | 0 | 0.0 | 44 | 0% | ok |

House: **143** active encounters vs **132** occupied beds. Bed statuses present: `occupied` 132, `dirty` 48, `available` 1072.

---

## Findings

### 🔴 1. Emergency Department bed inventory (DB fix)
ED has **148 bed rows** (33 occupied + 108 available + 7 dirty) but `staffed_bed_count = 40`. Every other
unit's bed rows equal its staffed count exactly — ED is the lone outlier. On screen this reads as
"**33 / 0 safe beds · 108 available**", which looks broken.
- **Likely cause:** ED was seeded with ~108 extra `available` bed rows, *or* `staffed_bed_count` (40) is too low for its real footprint.
- **Fix (pick one):** set `prod.units.staffed_bed_count` for ED to its true capacity, **or** prune the phantom `available` ED beds so `count(beds) ≈ staffed_bed_count`.

### 🟠 2. Active encounters without an occupied bed (DB fix)
**143** active encounters vs **132** occupied beds house-wide. ~11 units (MICU, NSICU, Burn ICU, 4 West/East,
6 West, Antepartum, Postpartum, Gyn, PICU, Rehab) each show **0 occupied beds but 1 active encounter**.
The encounter is counted toward acuity load (shrinking that unit's headroom) but no bed is marked occupied.
- **Likely cause:** encounters not reconciled with bed status (patient has an encounter but their bed row isn't `occupied`).
- **Fix:** reconcile `prod.encounters` (status `active`) against `prod.beds.status = 'occupied'` per unit — either mark the bed occupied or close the stale encounter.

### 🟠 3. `safe_capacity` is *headroom*, shown as a *total* (display semantics — code, not DB)
This is the root of the most jarring numbers:
- **6 East → 500%**: occupied 20, headroom 4 (it can admit ~4 more), so `20 / 4` renders as 500%.
- **ED / 5 East / 5 West → "0 safe beds / No data"**: they're at or over their nursing-workload budget, so headroom = 0, which the gauge interprets as "no capacity data".

`adjustedCapacity` returns *remaining* capacity, but the census labels it `safe_capacity` and the app divides
`occupied / safe_capacity` as if it were the unit total. **No DB edit fully fixes this** — it needs a small
code change. Options:
- **(a)** BFF returns a *total* acuity-adjusted safe capacity as the denominator and exposes headroom separately (`can_admit`), or
- **(b)** the app shows occupancy as `occupied / staffed_bed_count` and renders headroom as "can admit N more".
- **DB lever (partial):** if `prod.encounters.acuity_tier` values are inflated, the workload load is overstated and headroom collapses to 0 faster — verify tiers are realistic (ED load 58.1 on 33 patients ⇒ avg tier weight ~1.76 ≈ all tier-3, which is high for a full ED mix).

### 🟡 4. Unit `type` mislabels (DB fix, minor)
PICU and NICU are typed `med_surg` (should be `icu`); they won't appear in the Intensivist "Critical Care"
view. Behavioral Health is `med_surg` (often its own type). Adjust `prod.units.type` if you want them scoped correctly.

---

## Diagnostic queries (read-only; run before any change)

```sql
-- 1. Units where bed inventory != staffed_bed_count
SELECT u.unit_id, u.name, u.staffed_bed_count,
       count(b.*) FILTER (WHERE b.is_deleted = false) AS bed_rows
FROM prod.units u
LEFT JOIN prod.beds b ON b.unit_id = u.unit_id
WHERE u.is_deleted = false
GROUP BY u.unit_id, u.name, u.staffed_bed_count
HAVING count(b.*) FILTER (WHERE b.is_deleted = false) <> u.staffed_bed_count
ORDER BY abs(count(b.*) FILTER (WHERE b.is_deleted = false) - u.staffed_bed_count) DESC;

-- 2. Active encounters vs occupied beds, per unit
SELECT u.unit_id, u.name,
       count(DISTINCT e.encounter_id) FILTER (WHERE e.status='active' AND e.is_deleted=false) AS active_enc,
       count(b.*) FILTER (WHERE b.status='occupied' AND b.is_deleted=false)                   AS occupied_beds
FROM prod.units u
LEFT JOIN prod.encounters e ON e.unit_id = u.unit_id
LEFT JOIN prod.beds b       ON b.unit_id = u.unit_id
WHERE u.is_deleted = false
GROUP BY u.unit_id, u.name
HAVING count(DISTINCT e.encounter_id) FILTER (WHERE e.status='active' AND e.is_deleted=false)
     <> count(b.*) FILTER (WHERE b.status='occupied' AND b.is_deleted=false);

-- 3. Acuity tier distribution (sanity-check encounter tiers)
SELECT acuity_tier, count(*) FROM prod.encounters
WHERE status='active' AND is_deleted=false GROUP BY acuity_tier ORDER BY acuity_tier;
```

**Bottom line:** the only true *data* problems are **ED's bed inventory (#1)** and **stray active encounters (#2)**
(plus minor type labels #4). The 500% / "No data" gauges (#3) are a units-of-measure mismatch in the census
and want a small code change, not a DB edit — happy to implement option (a) or (b) on request.

## Flow Window spatial gate (added 2026-07-03, FLOW-WINDOW-PLAN W1)

Every **staffed bed must map to a facility space** (`prod.beds.facility_space_id`),
or it cannot render on the mobile floor plates. Check with:

```bash
php artisan facility:export-plates --check
```

```sql
-- Staffed beds with no mapped space (should return 0 rows)
SELECT b.bed_id, b.unit_id, b.label
FROM prod.beds b
WHERE b.is_deleted = false AND b.facility_space_id IS NULL;
```

Fix by re-running `facility:import-catalog … --map-operational` (or mapping the
stragglers through `hosp_space.operational_space_maps`).
