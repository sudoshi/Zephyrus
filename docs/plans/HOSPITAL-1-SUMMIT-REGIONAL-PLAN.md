# Hospital 1 — Summit Regional Medical Center: Synthetic Data Unification Plan

**Status:** Approved spec, pre-execution (Phase 0 complete)
**Owner:** Dr. Sanjay Udoshi
**Created:** 2026-06-27
**Scope:** Unify every Zephyrus surface — relational, 3D spatial, and 4D temporal — onto a
single, internally-consistent, fully-synthetic hospital grounded in real Atlantic Health
patient-journey distributions.

---

## 1. The core problem: four conflicting hospitals, no source of truth

Zephyrus currently models **four mutually inconsistent hospitals**, and the screens, seeders,
and digital twin each draw from a different one:

| # | Definition | Source | What it describes |
|---|---|---|---|
| 1 | **Operational reality** (all pages compute from this) | `database/seeders/RtdcSeeder.php` | 6 units / 180 beds — ED, 5E, 5W, 6E, ICU, SD |
| 2 | **Config label** | `config/facility_models.php` | "500-Bed Level I Trauma Academic Medical Center" (`ZEPHYRUS-500`) |
| 3 | **Frontend mock-data** | `resources/js/mock-data/rtdc.js`, `rtdc-service-huddle-constants.js` | 10–12 units incl. **phantom** 6W/7E-W/8E-W/9E-W/CCU that don't exist in the DB |
| 4 | **The 4D digital twin** | `patient-flow-4d-navigator/**` (Python generators) | **500 beds / 23 named units / Level I trauma AMC** — cryptic codes (`TEL7A`, `MS6B`, `BHU2`), `PARTHENON_AMC` |

Plus ~18 files with independently hardcoded provider/nurse/team names (`Dr. Smith`, `Dr. Sarah
Johnson`, `Porter Pool`, …).

### Key insight (drives this whole plan)
**Definition #4 — the 4D CAD model — is already the hospital we want.** It is a complete,
geometrically-coherent, 500-bed Level I trauma AMC. The fix is **not** to build a roster from
scratch; it is to **promote the CAD model to the single source of truth**, brand it as Summit
Regional, give its units human-readable names, and force the operational layer (#1), config (#2),
and frontend (#3) to **reconcile down to it**. The temporal flow is then regenerated so patient
journeys match Atlantic Health's real distributions.

---

## 2. Locked decisions

- **Display name:** Summit Regional Medical Center
- **Internal facility code:** `HOSP1` (replaces `ZEPHYRUS-500` / `PARTHENON_AMC`; multi-hospital-ready)
- **Scale:** ~500 acute inpatient beds, 23 inpatient units + ED + periop — adopt the CAD model's existing roster verbatim
- **Character:** Pennsylvania / Mid-Atlantic, academic, geriatric-skewed high-acuity (per Atlantic Health)
- **Data:** fully synthetic; Atlantic Health used only as a **distribution source** (no patient rows copied)

---

## 3. Canonical roster — Summit Regional (adopted from the CAD model, renamed)

The 23 CAD units sum to **exactly 500 licensed beds**. Human-readable names respect the CAD model's
**real floor geography** so the 3D twin and the dashboards never disagree about where a unit lives.

| CAD code | Floor | Summit unit name | Abbr | Service line | Beds | Acuity |
|---|---|---|---|---|---|---|
| MICU3 | 3 | Medical ICU | MICU | Critical Care | 24 | icu |
| SICU3 | 3 | Surgical ICU | SICU | Surgical Critical Care | 24 | icu |
| CVICU3 | 3 | Cardiovascular ICU | CVICU | CV Critical Care | 20 | icu |
| NSICU3 | 3 | Neuro ICU | NSICU | Neurocritical Care | 20 | icu |
| BURN3 | 3 | Burn Center | BURN | Burn / Trauma | 8 | icu |
| BHU2 | 2 | Behavioral Health | BHU | Psychiatry | 24 | behavioral |
| MS4A | 4 | 4 East — Med/Surg | 4E | General Medicine | 28 | med_surg |
| MS4B | 4 | 4 West — Med/Surg | 4W | General Surgery | 28 | med_surg |
| MS5A | 5 | 5 East — Med/Surg | 5E | Nephrology / Renal | 28 | med_surg |
| MS5B | 5 | 5 West — Med/Surg | 5W | Hospitalist Medicine | 28 | med_surg |
| MS6A | 6 | 6 East — Med/Surg | 6E | Orthopedics | 24 | med_surg |
| MS6B | 6 | 6 West — Med/Surg | 6W | GI / Hepatology | 24 | med_surg |
| TEL7A | 7 | 7 East — Telemetry | 7E | Cardiology | 32 | step_down |
| TEL7B | 7 | 7 West — Telemetry | 7W | Cardiology / Pulmonary | 32 | step_down |
| ANT8 | 8 | Antepartum | ANT | Maternal-Fetal Medicine | 12 | obstetrics |
| PP8 | 8 | Mother-Baby | PP | Obstetrics | 28 | obstetrics |
| GYN8 | 8 | Gynecology | GYN | Gynecology | 8 | med_surg |
| PED9 | 9 | Pediatrics | PED | Pediatrics | 24 | pediatric |
| PICU9 | 9 | Pediatric ICU | PICU | Pediatric Critical Care | 12 | icu |
| NICU9 | 9 | Neonatal ICU | NICU | Neonatology | 12 | icu |
| ONC10 | 10 | Oncology | ONC | Oncology / Hematology | 24 | med_surg |
| BMT10 | 10 | Bone Marrow Transplant | BMT | Heme / Transplant | 16 | protective |
| AIR11 | 11 | Acute Inpatient Rehab | AIR | PM&R | 20 | rehab |
| **— total inpatient —** | | | | | **500** | |
| ED-* | 1 | Emergency Department | ED | Emergency Medicine | 148 pos | ed |
| GENOR/HYBOR/TROR/… | 2 | OR Suite (18 ORs) | OR | Surgery / Anesthesia | — | periop |
| PACU | 2 | PACU | PACU | Anesthesia | — | periop |
| IR / EP / ENDO | var | Interventional / Endoscopy | — | Procedural | — | periop |

### Two grounded additions to consider in Phase 4 (CAD generator extension)
- **Clinical Decision Unit (CDU)** — Atlantic's dominant pattern is the **observation↔inpatient
  bounce** (~10k transitions each way). The CAD model only has ED-OBS positions. Adding a
  first-class CDU (~16 beds, floor 1/2) lets the demo surface this real flow. **Recommended.**
- **LTACH** — Atlantic has long-term acute care (median LOS 22d, 75 pts). Rare; **optional**.

---

## 4. Target architecture — manifest as SSOT, CAD model as anchor

```
config/hospital/hospital-1.php   ◄── THE single source of truth (the manifest)
  ├ facility identity (HOSP1, Summit Regional, PA, Level I trauma)
  ├ unit roster (CAD code ↔ human name ↔ abbr ↔ floor ↔ service line ↔ beds ↔ acuity)
  ├ bed-labeling scheme
  ├ service lines / specialties
  ├ provider registry (~40 named) + nurse pool (~60)
  ├ ancillary teams, transport vendors, post-acute network (PA-flavored)
  └ census/occupancy demo targets
        │
        ├─► CAD generator ............ generate_hospital_cad_model.py reads manifest →
        │                              model_catalog.json + location_index.json
        │                              (Summit branding, human names, +CDU)
        ├─► facility:import-catalog .. model_catalog.json → hosp_space.facility_spaces
        │      --map-operational       → creates/attaches prod.units + prod.beds  ◄── reconciles #1 to #4
        ├─► RtdcSeeder ............... DERIVES from manifest (no more 6 hardcoded units)
        ├─► CommandCenterDemoSeeder .. census targets, OR, staffing — reads manifest
        ├─► ProviderRegistrySeeder ... named providers/nurses/teams (kills ~8 hardcoded arrays)
        └─► Flow generator ........... generate_synthetic_flow.py, parameterized by ▼
config/hospital/hospital-1-distributions.json
  (extracted READ-ONLY from atlantic_health: archetype probs, LOS percentiles,
   transition matrix, disposition mix, demographics, procedure clusters)
        │
        └─► patient-flow:import-synthetic → flow_core.flow_events + occupancy_snapshots
```

**Reconciliation direction:** the operational `prod.units`/`prod.beds` are **generated from the
CAD catalog** via `facility:import-catalog --map-operational`, so the toy 6-unit `RtdcSeeder`
roster is retired and the operational layer becomes a 1:1 projection of the 3D twin. Every
`prod.bed` links to a `hosp_space.facility_space` via `operational_space_maps`, bed-for-bed.

Because the completeness sweep already wired all ~47 frontend surfaces to `prod.*`, once the
seeders emit Summit Regional, **the screens inherit it automatically** — except the phantom-unit
mock-data files, which are deleted/aligned in Phase 6.

---

## 5. The 4D model regeneration (the explicit deliverable)

The 4D twin = **3D space** (`model_catalog.json`, `location_index.json`, `hosp_space.*`) +
**time** (`hl7_messages.ndjson` → `flow_core.flow_events`/`occupancy_snapshots`). Both halves
must be regenerated.

### 5a. Spatial (`generate_hospital_cad_model.py`, 70 KB)
- **Rebrand:** `model_name`, `sending_facility`, facility code → Summit Regional / `HOSP1`.
- **Human-readable names:** unit codes stay stable for geometry keys, but every space gets a
  `display_name`/`service_line` from the manifest (`TEL7A` → "7 East — Telemetry", Cardiology).
- **(Recommended) add CDU** unit on floor 1/2.
- Re-run → regenerates `model_catalog.json` + `location_index.json` + `3dtiles/tileset.json`.
- Re-import: `facility:import-catalog model_catalog.json --facility-code=HOSP1 --facility-name="Summit Regional Medical Center" --map-operational`.

### 5b. Importer defaults
- `FacilityImportCatalogCommand` + `PatientFlowImportSyntheticCommand`: change `ZEPHYRUS-500`
  default `--facility-code` → `HOSP1`; update `--facility-name` default.
- `config/facility_models.php`: retire `zep_500` / "500-Bed Level I Trauma AMC" → `hosp1` /
  "Summit Regional Medical Center" (keep env-overridable).

### 5c. Temporal (`generate_synthetic_flow.py` + `flow_engine.py`)
Current generator is a thin, ungrounded slice. Regenerate it parameterized by the Atlantic
distributions (§6):
- **Volume:** scale from 90 patients/30h → enough concurrent census to fill the 500-bed house to
  the demo target occupancy (~85%), consistent with `CommandCenterDemoSeeder` census snapshots.
- **Facility/IDs:** `PARTHENON_*` → `HOSP1`/Summit; keep synthetic MRNs.
- **Service-line weights:** replace the hand-tuned `services` list with Atlantic-derived weights
  mapped onto the 23-unit roster (critical-care-heavy, geriatric).
- **Demographics:** replace `19800101` DOB / `U` sex with the **65+ geriatric skew** and 56% F.
- **Journeys:** replace the single ED→treat→admit path with the real **archetype branch
  probabilities** (ED-only ~30%, ED→IP ~25%, ED→Obs→IP, direct, surgical, **obs↔IP bounce**),
  per-unit **LOS distributions**, and the **disposition mix** (home ~78%, SNF/HH, AMA, transfer,
  expired ~0.4%).
- **Diagnoses:** Atlantic diagnosis code↔label mappings are scrambled — **do not import them**.
  Use a curated, clinically-coherent condition set keyed to each service line.
- Re-run → regenerates `hl7_messages.ndjson`, `normalized_events.ndjson`, `patient_tracks.json`,
  `summary.json`. Re-import via `patient-flow:import-synthetic --facility-code=HOSP1`.

### 5d. Consistency guarantee
The flow generator's bed pool, the `CommandCenterDemoSeeder` census targets, and the
`facility:import-catalog` operational beds must all reference the **same 500-bed roster** so the
Patient Flow Navigator, the bed board, and the command center tell one story.

---

## 6. Atlantic Health → Summit journey mapping (flow-generator inputs)

Extracted READ-ONLY from `atlantic_health` schema (3,250 synthetic MIMIC-IV patients; `transfers`
table = 453K unit-level segments). Load-bearing, trustworthy signals only.

| Atlantic careunit | → Summit target | LOS seed (median) |
|---|---|---|
| EMERGENCY | ED | 5.4 h |
| INPATIENT | 8 med/surg floors (by service line) | 4.3 d (p90 11 d) |
| ICU | MICU/SICU/CVICU/NSICU/PICU/NICU | 4.1 d |
| OBSERVATION + "outpatient in a bed" | CDU (or ED-OBS) | 1.2 d |
| HOSP OP SURGERY + SURGERY ADMIT | OR → PACU → inpatient | 0.2–0.8 d |
| INPATIENT PSYCH | Behavioral Health | 5.5 d |
| INPATIENT REHAB | Acute Inpatient Rehab | 10 d |
| LTACH IP | LTACH (if added) | 22 d |
| MOBILE ICU (transport) | inter-facility transport leg | 0.36 d |

- **Archetypes (real probs):** ED-only ~30% · ED→Inpatient ~25% · ED→Obs→Inpatient · direct
  admit · surgical funnel · **obs↔inpatient bounce (~10k each way)**.
- **Disposition:** home ~78% · SNF/sub-acute rehab · home health · AMA · transfer-out · expired ~0.4%.
- **Demographics:** 56% F · **~65% aged 65+** · PA geography.
- **Acuity realism:** weight ICU/critical-care procedures (central lines, ventilation, dialysis, cardiac).
- ⚠️ **Diagnosis labels scrambled in source** → curate coherent conditions per service line; use Atlantic only for chronic/acute ratios and cardinality.

---

## 7. Phased execution

| Phase | Deliverable | Key files | Verify |
|---|---|---|---|
| **0 — Lock spec** ✅ | Identity, scale, roster confirmed | this doc | done |
| **1 — Extract evidence** | `config/hospital/hospital-1-distributions.json` from read-only `psql` against `atlantic_health` (aggregates only, no PHI) | new `scripts/extract-atlantic-distributions.sql` | JSON validates vs recon numbers |
| **2 — Author manifest** | `config/hospital/hospital-1.php` (SSOT) — CAD-aligned roster, providers, teams, post-acute net | new config | schema sanity |
| **3 — Reconcile operational layer** | RtdcSeeder derives from manifest (retire 6-unit roster); `--map-operational` becomes the bed source; `ProviderRegistrySeeder`; ~8 services read registry not literals | seeders + `app/Services/**` | `zephyrus:demo-seed` clean; prod.beds = 500 |
| **4 — Regenerate 3D blueprint** | CAD generator rebranded + human names (+CDU); re-import catalog | `generate_hospital_cad_model.py`, `model_catalog.json`, `FacilityImportCatalogCommand`, `config/facility_models.php` | catalog imports; every bed has a facility-space map |
| **5 — Regenerate 4D temporal flow** | Atlantic-grounded `generate_synthetic_flow.py`; re-import | flow generator, `hl7_messages.ndjson`, `PatientFlowImportSyntheticCommand` | flow occupancy ≈ census targets; geriatric mix; archetype dist matches |
| **6 — Purge frontend mock-data** | Delete/align `rtdc.js`, `rtdc-service-huddle-constants.js`, `cases.js`; fix phantom-unit refs; curated conditions per service line | `resources/js/mock-data/**` | no phantom units; `npx tsc --noEmit` + `npx vite build` |
| **7 — Verify & deploy** | RouteSmokeTest; consistency test ("no unit/provider/bed rendered that isn't in the manifest"); re-seed prod | tests + `deploy.sh` | 82/82 routes; visual QA; impeccable hook clean |

---

## 8. Files touched (inventory)

**New:** `config/hospital/hospital-1.php` · `config/hospital/hospital-1-distributions.json` ·
`scripts/extract-atlantic-distributions.sql` · `database/seeders/ProviderRegistrySeeder.php` ·
this doc.

**Modified (backend):** `RtdcSeeder.php` · `CommandCenterDemoSeeder.php` · `config/facility_models.php` ·
`FacilityImportCatalogCommand.php` · `PatientFlowImportSyntheticCommand.php` · ~8 services with
hardcoded names (`EdDashboardService`, `TreatmentService`, `ServiceHuddleService`,
`CaseManagementService`, `RoomStatusService`, `DischargePrioritiesService`, `RoomRunningService`,
`DashboardService`).

**Modified (4D twin / Python):** `patient-flow-4d-navigator/hospital-cad-model/generate_hospital_cad_model.py` ·
`patient-flow-4d-navigator/generate_synthetic_flow.py` · regenerated data artifacts
(`model_catalog.json`, `location_index.json`, `hl7_messages.ndjson`, `normalized_events.ndjson`,
`patient_tracks.json`, `summary.json`, `tileset.json`).

**Modified (frontend):** `resources/js/mock-data/rtdc.js`, `rtdc-service-huddle-constants.js`,
`cases.js` (delete/align).

---

## 9. Guardrails

- **Read-only on Parthenon.** Distribution extraction only; never copy patient rows. Output is
  aggregate JSON. Honors "fully synthetic."
- **Non-destructive / idempotent.** Manifest-driven `firstOrCreate`; no drops; soft-deletes only.
- **Token canon.** All UI work stays on `healthcare-*` tokens (impeccable hook on).
- **Diagnosis realism.** Curate coherent conditions; never import Atlantic's scrambled labels.
- **Multi-hospital-ready.** `HOSP1` + manifest pattern → Hospital 2 is a new config file later.
- **Verify before done.** RouteSmokeTest + manifest-consistency test + visual QA before deploy;
  re-seed prod with `zephyrus:demo-seed --migrate --skip-imports`.
</content>
</invoke>


---

## 10. Phase 1 outcome (VERIFIED) + revised scope

**Workflow `wf_b74378f9-d9b` — 10 Opus 4.8 agents, 783K tokens.** DB extraction **fully verified, 0 refuted / 39 load-bearing checks** (obs↔inpatient bounce OBS→INP 9695/0.942 & INP→OBS 9043/0.759; LOS percentiles; geriatric 64.74%; mortality 0.385%). Output: `config/hospital/hospital-1-distributions.json` + `docs/plans/HOSPITAL-1-EXECUTION-INTEL.md`.

### Adversarial completeness critic = FAIL → these MUST be eradicated (new workstream)
The first-pass recon missed **real branded names + cross-project provenance** that survive any frontend-only rename:
- **Virtua Health (real NJ hospitals)** — Marlton/Mount Holly/Our Lady of Lourdes/Voorhees/Willingboro across ~14 files, incl. a **LIVE controller default** (`ProcessAnalysisController.php:32,47`), a **DB migration column default** (`2025_02_17_195730_add_filters_to_process_layouts.php:15`), and **git-tracked `.bak`/`.backup`** stragglers (`ProcessSelector.jsx.bak/.backup`).
- **`resources/js/utils/generateMockDischargeData.js`** — missed entirely; live fallback carrying the 5 Virtua names + `CCU` phantom + surname pool.
- **CAD branding stragglers** (~7 files beyond the 3 generators): `hospital_model.ifc`, `viewer/index.html`, `model_catalog.json:3`, `tileset.json:30`, both READMEs, the `.ddl.sql` filename, `test_flow_engine.py`, and the **generated data** (`patient_tracks.json` 4500 PARTHENON hits, `*.ndjson`).
- **Leaked absolute paths** `/Users/sudoshi/Github/Parthenon/...` in `verification/results.json` (×2).
- Live phantom-unit components: `DischargeTracker.jsx:39,54`, `Home.jsx:14-18,27-30`, `StatusUpdateModal.jsx`, `DashboardService` (Med-Surg 3W etc.).

### Decisions locked by Phase 1
- **`ZEPHYRUS-500` is an immutable CAD join key** (model_catalog → location_index → FacilitySpaceLocationResolver/RTDC). **KEEP it**; overlay Summit/HOSP1 branding via the manifest + display layer. Do **not** rename the join key.
- **ICU LOS = `icustays.los` (median 4.07d)**, NOT the MICU careunit (0.358d transport/transition unit). Don't conflate.
- **PICU9/NICU9 latent bug**: acuity=`pediatrics` → `icu_capable`/`telemetry_capable` evaluate False; fix in CAD-gen if ICU flags needed.
- **Disposition** rollup splits on `' / '` (space-slash-space); Home 33695 / SNF 2866 / HH 2630 / Transfer 1084 / AMA 595 / Expired 174. Mortality = flag-based 162/42078 = 0.385%.
- **CDU2** add requires bumping the 500-bed assertion at `generate_hospital_cad_model.py:1845` → 516 and updating all downstream counts in lockstep.

### Revised phase order
- **Phase 2 (now):** author `config/hospital/hospital-1.php` manifest + `HospitalManifest` accessor + consistency test (the contract — owned in main loop).
- **Phase 3:** operational reconcile — expand RtdcSeeder to all 25 units from manifest, `--map-operational`, ProviderRegistrySeeder, gut ~9 hardcoded-name services.
- **Phase 3.5 (NEW): Branded-Literal Eradication** — Virtua cluster (incl. migration default via a NEW alter migration, controller default, `.bak`/`.backup` removal), `generateMockDischargeData.js`, leaked Parthenon paths, phantom live components.
- **Phase 4:** CAD generator rebrand + human names (+CDU2, fix PICU/NICU acuity), regenerate ALL artifacts (not just .py), re-import.
- **Phase 5:** Atlantic-grounded temporal flow regen + re-import.
- **Phase 6:** frontend purge; **Phase 7:** verify + gated prod re-seed.
