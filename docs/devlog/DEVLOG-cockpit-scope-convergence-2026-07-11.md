# Devlog — Mount-Anywhere Scope Convergence (2026-07-11)

## Problem

The cockpit scope picker (House / Units / Departments / Service lines) was fully
built (P8 WS-1/2/5) but on live data **21 of 25 unit mounts and all 16
service-line mounts rendered "No live census for this unit yet"**, and even a
working unit face showed only bed counts — no patient-care content.

Root cause: three disjoint unit taxonomies that never got reconciled.

| Source | Naming | Who wrote it |
|---|---|---|
| `HospitalManifest` (`config/hospital/hospital-1.php`) | `MICU`, `4W`, `7E` (branded) | hand-authored SSOT |
| `prod.units` + `hosp_space.facility_spaces` | `MICU3`, `MS4A`, `TEL7A` (CAD) | `facility:import-catalog --map-operational` |
| Legacy `prod.units` rows | `5E "5 East"`, `ICU`, `SD` | pre-Summit seeds (held most active encounters) |

`ScopedFaceBuilder` joined live census to the mount by manifest `abbr` — which
matched almost nothing. The bridge already existed in the manifest as
`cad_code` (`MICU↔MICU3`, `4W↔MS4A`, `7E↔TEL7A`, `OR↔PERIOP`); nothing used it.
Prod was already on the manifest taxonomy (seeded with `--skip-imports`); dev
had forked to the CAD taxonomy because the importer *created* parallel CAD
units instead of adopting the branded roster.

## Fixes

**Taxonomy convergence (the blocker)**
- `ModelCatalogImporter::mapOperationalUnitsAndBeds` — when a registered
  manifest claims the CAD facility code (`HospitalManifest::forCadFacilityCode`,
  new), CAD unit codes translate through `cad_code` and the import **adopts the
  branded unit** (linking `facility_space_id`) instead of minting a CAD twin.
  Beds are adopted in order (space-linked → CAD-labelled → next unlinked seeded
  bed → create), keeping friendly labels and stable bed counts.
- `RtdcSeeder` — `updateOrCreate` (syncs adopted legacy rows onto manifest
  branding/type/bed counts) + soft-trim of surplus *available* beds.
- `DemoTuningSeeder::dischargeOrphanedEncounters` (new step 1b) — discharges
  actives stranded on soft-deleted units and, per live unit, the oldest actives
  beyond its live bed count (a unit census must be physically possible).
- `CockpitScopeResolver` — unit tokens resolve through `abbr` **or** `cad_code`
  and canonicalize to the manifest abbr (`unit:MICU3` → `unit:MICU`); the
  catalog `assigned` flag matches through both keys.
- `ScopedFaceBuilder` — census joins through both keys; `unit:OR` (type
  `periop`, no census ward) delegates to the periop domain drill.

**Patient-care content at every altitude**
- Unit face: **Patient board** (bed / acuity tag / LOS / expected-DC —
  deliberately de-identified; PHI stays at A2P behind `EnforceFlowLens`) with
  every bed cell descending to the patient lens via `contextRefFor`, plus a
  `unit.dc_due` tile. Capacity board retained below.
- Service-line face: live `sl.census`, `sl.high_acuity` (tier ≥4), `sl.dc_due`,
  `sl.alos` tiles from the encounter spine (the ops MVs are house-level only),
  alongside the per-unit capacity board.
- `MobilePatientContextService` — `prod.encounters` (active) and
  `prod.or_cases` refs added to `candidatePatientRefs()`/`hasPatientContext()`
  so roster and PACU ptoks resolve; header location falls back to
  `Unit · BED` (encounter) or `PACU`/`OR` (or_logs).
- PACU bay board patient cell is now a drill affordance (same grammar as the
  ED track board). RTDC boards are unit-level — descent there is via the unit
  mounts.
- `prod.user_unit` demo assignments seeded (CCDS step 0c) — "My units" group +
  the charge-nurse default mount (`sanjay@example.com` → MICU) now live.
- `CockpitMenu` picker label fixed ("Service Line" → "Mount scope").

**Canon repair (pre-existing, arena merge)** — `ProcessModelLandscape` /
`ReferenceProcessMap` had `font-bold` ×2, sub-scale `text-[9-11px]` ×12 and 4
raw-palette classes that broke `check-ui-canon.sh` on main; snapped to
`font-semibold`/`text-xs` and `healthcare-info`/`healthcare-warning` tokens.

## Verification

- Dev re-seeded end-to-end (`php artisan zephyrus:demo-seed`, WITH imports):
  25 live branded units, actives on every unit, NEDOCS 150/severe.
- Probe: **44/44 mounts render live faces (0 empty), 477 patient drill cells**
  across the catalog; roster ptok → lens (`3 West — Medical ICU · MICU-14`),
  PACU ptok → lens (`PACU`); `unit:MICU3` canonicalizes to `unit:MICU`.
- Gates: Pint ✓, PHPUnit 864 ✓ (1 pre-existing skip), tsc ✓, Vitest 390 ✓,
  vite build ✓, `check-ui-canon.sh` ✓ (raw-palette back to ≤76).
- New tests: roster descent + de-identification, CAD join fallback, CAD token
  canonicalization, enriched-face table order.

## Deploy notes

Prod is already on the manifest taxonomy, so the join fixes are inert there;
the wins on prod are the enriched faces, lens resolution for inpatients, and
`user_unit` seeding. Standard `./deploy.sh` + `php artisan db:seed` refresh
(or `zephyrus:demo-seed --skip-imports`) + `php8.5-fpm` restart when approved.
