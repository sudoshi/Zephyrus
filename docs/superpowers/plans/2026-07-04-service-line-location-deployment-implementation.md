# Service Line and Physical Location Deployment Taxonomy — Implementation Plan

**Date:** 2026-07-04
**Status:** Proposed implementation plan
**Scope:** Turn the research baseline in `docs/architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md` into a shippable, phased Zephyrus build: a normalized service-line registry, an IDN-geography + facility-service capability matrix, many-to-many service-line usage of physical spaces, room/bed capability tags, first-class interfacility transfer edges, per-facility manifest generation, a deployment console, a deployment-readiness scorecard, and a meticulous admin-level **Staffing Alignment Wizard** that integrates external staffing systems (HRIS / scheduling / credentialing / identity) to resolve which users belong to which service lines, roles, facilities, and units — with human-in-the-loop review, evidence, and governance.
**Source of truth (research):** `docs/architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md`
**Companion:** `docs/plans/HOSPITAL-1-SUMMIT-REGIONAL-PLAN.md`, `docs/superpowers/plans/2026-06-25-hospital-blueprint-ingestion-digital-twin.md`, `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`

---

## 1. Objective

Zephyrus today models exactly one hospital: Summit Regional, a synthetic 500-bed Level I academic center defined in `config/hospital/hospital-1.php` and read through `App\Support\Hospital\HospitalManifest`. The research report proves that real deployments are IDNs with heterogeneous facility roles (quaternary flagship, Level IV stabilization spoke, satellite ED, ASC, behavioral hospital, ambulatory campus) where a service line can be **definitive** at one site and **stabilize-and-transfer** at another.

This plan makes Zephyrus deployable into any hospital or IDN **without copying the Summit manifest**, by building the report's four canonical layers on top of the schema that already exists:

- **Layer 1 — IDN geography:** a new `hosp_org` schema (organizations, markets, facilities) with per-facility `idn_role` and regulated-designation fields.
- **Layer 2 — Service-line catalog:** a normalized `hosp_ref.service_lines` registry (+ programs, capability tags, and vocab lookups), authored in config and projected to the database so it is FK-able and queryable.
- **Layer 3 — Facility-service capability matrix:** `hosp_org.facility_service_capabilities` — one row per facility × service line × `capability_level` with evidence and transfer targets.
- **Layer 4 — Physical location mapping:** a `hosp_space.facility_space_service_lines` bridge so a CT scanner or hybrid OR can serve emergency + trauma + stroke + oncology at once, plus `capability_tags` on `prod.beds`/`prod.rooms` and `location_role` on facility spaces.

Plus two cross-cutting capabilities the report calls out: **first-class transfer edges** (`hosp_org.transfer_relationships`, optionally projected into the existing `ops` graph) and a **deployment-readiness scorecard** that mechanically checks the report's Acceptance Criteria.

Non-goals for this plan: replacing the blueprint ingestion pipeline (it stays as-is and feeds Layer 4), and building a patient-transfer pathfinding engine (we lay the transfer-edge substrate; weighted routing is a downstream plan).

Strongest ideas to preserve from the research report:
- `service_line != department != revenue_code != cost_center != physical_space` — store crosswalks, never collapse them.
- Absence is modeled as `capability_level = none/screen/stabilize`, never as a missing row.
- Regulated designations (trauma/stroke/perinatal/NICU/burn/transplant) require `state_designation` or `accreditation_body` evidence, not marketing pages.
- Summit Regional becomes a *reference* deployment, not the universal schema.

---

## 2. Current-State Findings (grounded in the repo)

### 2.1 What already exists — reuse, do not duplicate

| Concern | Existing object | File |
| --- | --- | --- |
| Blueprint intake | `hosp_ingest.blueprint_imports`, `hosp_ingest.blueprint_objects`, `hosp_ref.facility_object_categories` (12 seeded rows) | `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php` |
| Canonical spaces | `hosp_space.facility_spaces` (has free-text `service_line_code`, `acuity_level`, `attributes` jsonb GIN, `space_category` CHECK) | same migration |
| Space→prod bridge | `hosp_space.operational_space_maps` — FK to exactly one of `prod.locations/rooms/units/beds` (`num_nonnulls(...)=1` CHECK) | same migration |
| Prod link columns | nullable `facility_space_id` FK on `prod.locations/rooms/units/beds` (+ indexes) | same migration |
| Eloquent | `App\Models\Facility\FacilitySpace`, `App\Models\Facility\OperationalSpaceMap`; `App\Models\{Location,Room,Unit,Bed}` each with `facilitySpace(): BelongsTo` | `app/Models/**` |
| Source→space resolver | `App\Services\PatientFlow\FacilitySpaceLocationResolver` (resolves an HL7/CAD source code → facility-space payload; emits `service_line`) | `app/Services/PatientFlow/FacilitySpaceLocationResolver.php` |
| Space importer | `App\Services\Facility\ModelCatalogImporter` (writes `service_line_code`, derives unit type) | `app/Services/Facility/ModelCatalogImporter.php` |
| Ops graph | `ops.nodes` / `ops.edges` — **weighted (`weight decimal(8,4)`) and temporally-valid (`valid_from`/`valid_to`) edges already exist**; projector emits only containment edges (`located_in`, `contains_bed`, `assigned_to_unit`) | `database/migrations/2026_06_25_000020_create_ops_graph_tables.php`, `app/Services/Ops/OperationsGraphProjector.php` |
| Hospital manifest | `config/hospital/hospital-1.php` (16 service_lines, 25 units, per-unit `staffed_bed_count`) via `App\Support\Hospital\HospitalManifest` (20 injected consumers; static cache; `flush()`) | `app/Support/Hospital/HospitalManifest.php` |
| 3D asset config | `config/facility_models.php` keyed `zep_500` on immutable `ZEPHYRUS-500` CAD code | `config/facility_models.php` |
| Flow service-line | `flow_core.flow_events.service_line` (text), `flow_core.occupancy_snapshots.service_line_counts` (jsonb) | `database/migrations/2026_06_25_000040_*` |

Schemas confirmed present: `hosp_ref`, `hosp_ingest`, `hosp_space`, `prod`, `ops`, `flow_core`, `flow_realtime`, `integration`, `raw`, `fhir`, `star`, `stg`, `regional`, `eddy`.

### 2.2 What is missing — the build

- **`hosp_org` schema does not exist.** No organizations / markets / facilities / capability-matrix / transfer tables. Greenfield — no collision risk. (Layer 1 + Layer 3.)
- **No normalized `service_lines` table.** Service line lives only as (a) a config enum in `hospital-1.php`, (b) a free-text `hosp_space.facility_spaces.service_line_code`, (c) a per-event `flow_core.flow_events.service_line`, (d) a hardcoded FE constant `resources/js/constants/summitHospital.js`. Nothing is FK-able. (Layer 2.)
- **No `hosp_space.facility_space_service_lines` bridge.** A space can carry exactly one `service_line_code` today; shared spaces (CT, hybrid OR, ICU bed) cannot be represented. Greenfield. (Layer 4.)
- **No `capability_tags` on `prod.beds`/`prod.rooms`.** `prod.beds` has `isolation_capable`, `bed_type`, `status`; there is no structured tag vocabulary (`ventilator`, `crrt`, `negative_pressure`, `protective_environment`, `stroke_priority`, …). (Report §4.)
- **No first-class transfer relationships.** `ops.edges` can hold weighted edges but has no facility-level nodes and no `transfers_to` edge type. (Report §5.)
- **No `idn_role`, `location_role`, `capability_level`, or `evidence_class` vocabulary** anywhere as data.
- **`HospitalManifest` is hardwired to one file** (`require base_path('config/hospital/hospital-1.php')`) and one facility. No per-facility manifest generation.

### 2.3 Naming-reconciliation risk (must handle before any FK)

The existing manifest uses service-line codes that are a **subset with three variants** of the report's canonical taxonomy. Applying an FK on `facility_spaces.service_line_code` naively would break on these:

| Manifest code (in use) | Canonical code (report) | Resolution |
| --- | --- | --- |
| `trauma_surgery` | `trauma_acute_care_surgery` | alias → normalize existing rows |
| `medicine` | `hospital_medicine` | alias → normalize existing rows |
| `cardiology` | `cardiovascular` | alias → normalize existing rows |
| all other 13 (`critical_care`, `adult_med_surg`, `neurosciences`, `oncology`, `womens_health`, `pediatrics`, `neonatology`, `behavioral_health`, `rehabilitation`, `emergency`, `burn`, `perioperative`, `cardiovascular`) | identical | no change |

Only synthetic Summit data is affected, so normalization is safe — but it must run **before** the FK is validated (see §5 and Phase 2). This is why every new FK onto `service_lines` is added `NOT VALID`, backfilled, then `VALIDATE CONSTRAINT`.

---

## 3. Target Architecture

### 3.1 The report's four layers onto real schemas

```text
Layer 1  IDN geography          -> hosp_org.organizations / markets / facilities        (NEW schema)
Layer 2  Service-line catalog   -> hosp_ref.service_lines / programs / capability_tags  (NEW tables in existing schema)
                                   + hosp_ref.{idn_roles,location_roles,capability_levels,evidence_classes} lookups
Layer 3  Capability matrix      -> hosp_org.facility_service_capabilities               (NEW)
Layer 4  Physical mapping       -> hosp_space.facility_spaces (+location_role,+capability_tags,+facility_key)
                                   hosp_space.facility_space_service_lines (NEW bridge, many-to-many)
                                   prod.beds/prod.rooms.capability_tags (NEW columns)
Transfer graph                  -> hosp_org.transfer_relationships (NEW)
                                   -> projected as facility nodes + `transfers_to` edges into ops.nodes/ops.edges
```

### 3.2 Data flow

```text
config/hospital/service-lines.php ─┐
config/hospital/capability-tags.php ├─ deployment:seed-registry ─> hosp_ref.service_lines / programs / capability_tags / *_lookups
config/hospital/taxonomy-vocab.php ─┘

client facility roster (CSV/JSON) ─ deployment:import-facilities ─> hosp_org.organizations / markets / facilities
client capability roster (CSV/JSON) ─ deployment:import-capabilities ─> hosp_org.facility_service_capabilities
                                                                        hosp_org.transfer_relationships

CAD/BIM/catalog ─ facility:import-catalog (existing, extended) ─> hosp_ingest.* ─> hosp_space.facility_spaces
                                                                  + facility_space_service_lines
                                                                  + operational_space_maps ─> prod.*.facility_space_id
                                                                  + prod.beds/rooms.capability_tags

hosp_org + hosp_ref + hosp_space ─ hospital:generate-manifest {facility_key} ─> config/hospital/<facility>.php (or DB-backed)
                                 ─ deployment:readiness {facility_key} ─> readiness scorecard (Acceptance Criteria)

hosp_org.* + ops projector (extended) ─> ops.nodes (facility) + ops.edges (transfers_to, weight=minutes)

/api/deployment/* ─> Pages/Deployment/DeploymentConsole (React/Inertia)
```

### 3.3 Ownership boundaries

- **`hosp_ref`** owns the enterprise vocabulary (service lines, programs, capability tags, roles, evidence classes). Authored in `config/hospital/*`, projected to DB, immutable at runtime.
- **`hosp_org`** owns *who and where*: the IDN graph and the per-facility capability matrix and transfer relationships. This is the deployment configuration layer.
- **`hosp_space`** continues to own *canonical physical spaces* and their many-to-many service-line usage. It references `hosp_ref` and `hosp_org` by code/key; it does not duplicate them.
- **`prod`** continues to own operational rows; it gains only `capability_tags`. It never learns about `idn_role` or capability matrices directly.
- **`ops`** stays the projection/graph layer; it gains facility nodes and transfer edges but remains rebuildable from source tables.
- **`flow_core` / `flow_realtime`** are unchanged consumers; their existing `service_line` text values get normalized to canonical codes (§5).

### 3.4 Non-negotiable constraints

1. **Additive and non-breaking.** New schema, new tables, and nullable columns only. No column drops, no type changes on existing columns, no hard cutover. Matches the `facility_space_id`-nullable-FK precedent.
2. **FKs onto `service_lines` are `NOT VALID` → backfill → `VALIDATE`.** Never add a validated FK before normalization runs.
3. **Idempotent seeders/importers**, keyed on natural keys (`service_line_code`, `facility_key`, `space_code`), using `upsert`/`firstOrCreate` — same discipline as `ModelCatalogImporter` and the Summit seeders.
4. **Config authors, DB projects.** Vocabulary is authored in `config/hospital/*` and seeded; the DB copy is the queryable/FK-able projection (same pattern as `hospital-1.php` → prod seeding).
5. **Summit stays green.** Every phase ends with `php artisan test` passing and the existing Patient Flow Navigator / Command Center / RTDC surfaces unchanged.
6. **`ZEPHYRUS-500` remains the immutable CAD join key**; `facility_key` (`SUMMIT_REGIONAL`) is the new business key. The two are linked by `hosp_org.facilities.cad_facility_code`.

---

## 4. Data Model

Follow the raw-SQL `DB::unprepared` idiom of `2026_06_25_000010_*` for the `hosp_ref`/`hosp_org`/`hosp_space` DDL (schema-qualified names, `CREATE SCHEMA IF NOT EXISTS`, `IF NOT EXISTS` on tables/columns, idempotent FK blocks). Use the Schema builder only for the simple `prod.*` column adds. All new migrations are timestamp-prefixed under `database/migrations/`.

### 4.1 `hosp_ref` — Layer 2 registry + vocabulary lookups

```sql
-- 2026_07_04_000110_create_service_line_registry_tables.php
CREATE SCHEMA IF NOT EXISTS hosp_ref;  -- already exists; idempotent

CREATE TABLE IF NOT EXISTS hosp_ref.service_lines (
  service_line_code            text PRIMARY KEY,
  display_name                 text NOT NULL,
  clinical_domain              text NOT NULL,               -- emergency|cardiovascular|neurosciences|oncology|...
  adult_or_pediatric           text NOT NULL DEFAULT 'adult',   -- adult|pediatric|both
  care_setting_default         text NOT NULL DEFAULT 'inpatient',-- inpatient|outpatient|procedural|virtual|support
  hcup_grouping                text,
  requires_24_7                boolean NOT NULL DEFAULT false,
  requires_inpatient_beds      boolean NOT NULL DEFAULT false,
  requires_procedure_platform  boolean NOT NULL DEFAULT false,
  requires_imaging             boolean NOT NULL DEFAULT false,
  requires_lab                 boolean NOT NULL DEFAULT false,
  requires_pharmacy            boolean NOT NULL DEFAULT false,
  requires_transport           boolean NOT NULL DEFAULT false,
  requires_transfer_agreements boolean NOT NULL DEFAULT false,
  certification_or_designation text[]  NOT NULL DEFAULT '{}',  -- {ACS,TJC,ACOG,ABA,OPTN,CMS}
  default_location_roles       text[]  NOT NULL DEFAULT '{}',
  default_workflow             text,                          -- rtdc|ed|periop|transport|command|none
  aliases                      text[]  NOT NULL DEFAULT '{}', -- legacy/synonym codes (see §5)
  sort_order                   integer NOT NULL DEFAULT 100,
  is_active                    boolean NOT NULL DEFAULT true,
  metadata                     jsonb   NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_service_lines_domain ON hosp_ref.service_lines(clinical_domain);
CREATE INDEX IF NOT EXISTS idx_service_lines_aliases ON hosp_ref.service_lines USING gin(aliases);

CREATE TABLE IF NOT EXISTS hosp_ref.programs (
  program_code       text PRIMARY KEY,
  service_line_code  text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
  display_name       text NOT NULL,
  designation_type   text,     -- state_designation|accreditation_body|internal
  designation_body   text,     -- ACS|TJC|ACOG|ABA|OPTN|CMS|state
  capability_level_implied text,-- min hosp_ref.capability_levels code
  adult_or_pediatric text NOT NULL DEFAULT 'adult',
  metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS hosp_ref.capability_tags (
  tag_code     text PRIMARY KEY,     -- ventilator|crrt|negative_pressure|protective_environment|stroke_priority|...
  tag_category text NOT NULL,        -- bed|room|isolation|procedure|monitoring|imaging
  display_name text NOT NULL,
  description  text,
  applies_to   text[] NOT NULL DEFAULT '{bed,room,facility_space}',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Small seeded vocab lookups (FK targets + FE dropdown sources)
CREATE TABLE IF NOT EXISTS hosp_ref.capability_levels (code text PRIMARY KEY, display_name text NOT NULL, rank int NOT NULL);
CREATE TABLE IF NOT EXISTS hosp_ref.idn_roles        (code text PRIMARY KEY, display_name text NOT NULL, sort_order int NOT NULL DEFAULT 100);
CREATE TABLE IF NOT EXISTS hosp_ref.location_roles   (code text PRIMARY KEY, display_name text NOT NULL, sort_order int NOT NULL DEFAULT 100);
CREATE TABLE IF NOT EXISTS hosp_ref.evidence_classes (code text PRIMARY KEY, display_name text NOT NULL, is_regulated boolean NOT NULL DEFAULT false);
```

Seeded lookup values:
- `capability_levels` (rank): `none`(0), `screen`(1), `stabilize`(2), `routine`(3), `advanced`(4), `definitive`(5), `quaternary`(6).
- `idn_roles`: the 14 from report §"IDN Geography Role" (`flagship_quaternary_hub` … `virtual_command_center`).
- `location_roles`: the 16 from report §"Location Role" (`arrival` … `transfer`).
- `evidence_classes`: the 9 from report §6 (`state_designation`, `accreditation_body`, `official_health_system_page`, `public_location_page`, `client_roster`, `EHR_location_master`, `facility_map`, `interview`, `assumption`); `is_regulated=true` for `state_designation` and `accreditation_body`.

### 4.2 `hosp_org` — Layer 1 geography + Layer 3 capability matrix + transfer graph

```sql
-- 2026_07_04_000120_create_idn_geography_capability_tables.php
CREATE SCHEMA IF NOT EXISTS hosp_org;

CREATE TABLE IF NOT EXISTS hosp_org.organizations (
  organization_id   bigserial PRIMARY KEY,
  organization_key  text UNIQUE NOT NULL,      -- SUMMIT_HEALTH
  name              text NOT NULL,
  short_name        text,
  kind              text NOT NULL DEFAULT 'idn',-- idn|single_hospital|specialty_network
  headquarters_state text,
  metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS hosp_org.markets (
  market_id       bigserial PRIMARY KEY,
  organization_id bigint NOT NULL REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
  market_key      text NOT NULL,
  name            text NOT NULL,
  region          text,
  state           text,
  metadata        jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (organization_id, market_key)
);

CREATE TABLE IF NOT EXISTS hosp_org.facilities (
  facility_id       bigserial PRIMARY KEY,
  organization_id   bigint NOT NULL REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
  market_id         bigint REFERENCES hosp_org.markets(market_id) ON DELETE SET NULL,
  facility_key      text UNIQUE NOT NULL,        -- SUMMIT_REGIONAL
  facility_name     text NOT NULL,
  short_name        text,
  parent_system     text,
  market            text,
  region            text,
  state             text,
  county            text,
  lat               numeric(9,6),
  lng               numeric(9,6),
  idn_role          text NOT NULL REFERENCES hosp_ref.idn_roles(code),
  campus_type       text,
  license_type      text,
  teaching_status   text,
  licensed_beds     integer,
  trauma_level_adult      text,
  trauma_level_pediatric  text,
  stroke_level            text,
  maternal_level          text,
  neonatal_level          text,
  burn_center_status      text,
  transplant_center_status text,
  transplant_programs     text[] NOT NULL DEFAULT '{}',
  pediatric_capability    text,
  behavioral_health_capability text,
  ambulatory_surgery_capability text,
  home_hospital_capability text,
  cad_facility_code text,                          -- 'ZEPHYRUS-500' for Summit; join to config/facility_models + space_code prefix
  review_status     text NOT NULL DEFAULT 'assumed',
  source_evidence   jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_active         boolean NOT NULL DEFAULT true,
  metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT facilities_review_status_chk CHECK (review_status IN ('source_verified','client_verified','assumed','unknown'))
);
CREATE INDEX IF NOT EXISTS idx_facilities_org_role ON hosp_org.facilities(organization_id, idn_role);
CREATE INDEX IF NOT EXISTS idx_facilities_state_county ON hosp_org.facilities(state, county);

CREATE TABLE IF NOT EXISTS hosp_org.facility_service_capabilities (
  facility_service_capability_id bigserial PRIMARY KEY,
  facility_id        bigint NOT NULL REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
  facility_key       text   NOT NULL,             -- denormalized for import/join convenience
  service_line_code  text   NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
  capability_level   text   NOT NULL REFERENCES hosp_ref.capability_levels(code),
  programs_present   text[] NOT NULL DEFAULT '{}',
  departments_present text[] NOT NULL DEFAULT '{}',
  coverage_model     text,                          -- in_house|tele|daytime|on_call|open
  hours              text,
  telehealth_support boolean NOT NULL DEFAULT false,
  transfer_out_targets text[] NOT NULL DEFAULT '{}',-- facility_keys or external names
  transfer_in_sources  text[] NOT NULL DEFAULT '{}',
  source_evidence_url  text,
  source_evidence_type text REFERENCES hosp_ref.evidence_classes(code),
  review_status      text NOT NULL DEFAULT 'assumed',
  notes              text,
  effective_start    date,
  effective_end      date,
  metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (facility_id, service_line_code),
  CONSTRAINT fsc_review_status_chk CHECK (review_status IN ('source_verified','client_verified','assumed','unknown'))
);
CREATE INDEX IF NOT EXISTS idx_fsc_service_capability ON hosp_org.facility_service_capabilities(service_line_code, capability_level);

CREATE TABLE IF NOT EXISTS hosp_org.transfer_relationships (
  transfer_relationship_id bigserial PRIMARY KEY,
  source_facility_id       bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
  source_facility_key      text,
  destination_facility_id  bigint REFERENCES hosp_org.facilities(facility_id) ON DELETE CASCADE,
  destination_facility_key text,
  destination_external_name text,                   -- e.g. 'Cooper University Hospital' (external Level I)
  service_line_code text REFERENCES hosp_ref.service_lines(service_line_code),
  program_code      text REFERENCES hosp_ref.programs(program_code),
  transfer_reason   text,
  transport_mode    text,                            -- ground_bls|ground_als|critical_care_transport|rotor|fixed_wing
  typical_minutes   integer,
  typical_miles     numeric(7,2),
  direction         text NOT NULL DEFAULT 'out',     -- out|in|bidirectional
  acceptance_constraints text,
  escalation_contact text,
  is_external_partner boolean NOT NULL DEFAULT false,
  review_status     text NOT NULL DEFAULT 'assumed',
  source_evidence   jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_active         boolean NOT NULL DEFAULT true,
  metadata          jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT transfer_endpoints_chk CHECK (source_facility_id IS NOT NULL OR destination_facility_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_transfer_service_line ON hosp_org.transfer_relationships(service_line_code, direction);
```

### 4.3 `hosp_space` — Layer 4 many-to-many + facility-space extensions

```sql
-- 2026_07_04_000130_create_facility_space_service_lines.php
ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS location_role   text;
ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS program_code    text;
ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';
ALTER TABLE hosp_space.facility_spaces ADD COLUMN IF NOT EXISTS facility_key    text;   -- Layer-1 tie; backfill from space_code prefix
CREATE INDEX IF NOT EXISTS idx_facility_spaces_facility_key ON hosp_space.facility_spaces(facility_key);
CREATE INDEX IF NOT EXISTS idx_facility_spaces_capability_tags ON hosp_space.facility_spaces USING gin(capability_tags);

CREATE TABLE IF NOT EXISTS hosp_space.facility_space_service_lines (
  facility_space_service_line_id bigserial PRIMARY KEY,
  facility_space_id bigint NOT NULL REFERENCES hosp_space.facility_spaces(facility_space_id) ON DELETE CASCADE,
  service_line_code text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
  program_code      text REFERENCES hosp_ref.programs(program_code),
  location_role     text REFERENCES hosp_ref.location_roles(code),
  primary_flag      boolean NOT NULL DEFAULT false,
  capability_tags   text[] NOT NULL DEFAULT '{}',
  effective_start   date,
  effective_end     date,
  evidence          jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (facility_space_id, service_line_code, COALESCE(program_code, ''))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_fssl_one_primary
  ON hosp_space.facility_space_service_lines(facility_space_id) WHERE primary_flag;
CREATE INDEX IF NOT EXISTS idx_fssl_service_line ON hosp_space.facility_space_service_lines(service_line_code);
```

The FK `hosp_space.facility_spaces.service_line_code -> hosp_ref.service_lines` and `.location_role -> hosp_ref.location_roles` are added in Phase 2 **after** normalization, via `ADD CONSTRAINT ... NOT VALID` then `VALIDATE CONSTRAINT` in a follow-up migration (`2026_07_04_000160_validate_facility_space_service_line_fk.php`).

### 4.4 `prod` — capability tags on beds and rooms

```sql
-- 2026_07_04_000140_add_capability_tags_to_prod_spaces.php  (Schema builder acceptable here)
ALTER TABLE prod.beds  ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';
ALTER TABLE prod.rooms ADD COLUMN IF NOT EXISTS capability_tags text[] NOT NULL DEFAULT '{}';
CREATE INDEX IF NOT EXISTS idx_prod_beds_capability_tags  ON prod.beds  USING gin(capability_tags);
CREATE INDEX IF NOT EXISTS idx_prod_rooms_capability_tags ON prod.rooms USING gin(capability_tags);
```

Tag values are validated against `hosp_ref.capability_tags` in the application layer (a nightly `deployment:audit-tags` integrity check flags orphans) rather than a hard FK, because arrays can't FK a lookup in Postgres without a trigger — kept intentionally lightweight.

### 4.5 Transfer graph projection (no schema change)

`ops.nodes`/`ops.edges` already support what we need. Phase 4 extends `App\Services\Ops\OperationsGraphProjector` to additionally:
- project `hosp_org.facilities` rows as nodes with `node_type='facility'`, `canonical_key='facility:{facility_key}'`;
- project `hosp_org.transfer_relationships` as edges with `edge_type='transfers_to'`, `from_node_id=facility:{source}`, `to_node_id=facility:{dest}`, `weight=typical_minutes`, `metadata={service_line_code, transport_mode, is_external_partner}`.

Guard with `Schema::hasTable('hosp_org.facilities')` so the projector degrades gracefully (same pattern as `PatientFlowController::attachOpsGraphNodes()`).

### 4.6 Migration + model inventory

| Migration file | Creates / alters |
| --- | --- |
| `2026_07_04_000110_create_service_line_registry_tables.php` | `hosp_ref.service_lines`, `.programs`, `.capability_tags`, `.capability_levels`, `.idn_roles`, `.location_roles`, `.evidence_classes` |
| `2026_07_04_000120_create_idn_geography_capability_tables.php` | `hosp_org.*` schema + 5 tables |
| `2026_07_04_000130_create_facility_space_service_lines.php` | `hosp_space.facility_space_service_lines` + facility_spaces columns |
| `2026_07_04_000140_add_capability_tags_to_prod_spaces.php` | `prod.beds/rooms.capability_tags` |
| `2026_07_04_000160_validate_facility_space_service_line_fk.php` | FK validate (runs after normalization) |

New Eloquent models:
- `App\Models\Reference\ServiceLine`, `App\Models\Reference\Program`, `App\Models\Reference\CapabilityTag` (`hosp_ref`).
- `App\Models\Org\Organization`, `App\Models\Org\Market`, `App\Models\Org\Facility`, `App\Models\Org\FacilityServiceCapability`, `App\Models\Org\TransferRelationship` (`hosp_org`).
- `App\Models\Facility\FacilitySpaceServiceLine` (`hosp_space`), plus a `serviceLines(): HasMany` relation added to `App\Models\Facility\FacilitySpace`.

### 4.7 `hosp_ref` roles + `hosp_org` staffing (feeds the Staffing Alignment Wizard, §11)

Staff → service-line/role assignment is modeled additively and **never touches the protected auth system** (`.claude/rules/auth-system.md`): `prod.users.role` and the temp-password/`must_change_password`/Resend login flow are untouched. This layer adds an *operational* assignment graph on top of the existing account, and links to `prod.users` by nullable FK when an app account exists.

```sql
-- 2026_07_04_000150_create_staffing_alignment_tables.php

-- Role taxonomy (reference; authored in config/hospital/staff-roles.php, projected here)
CREATE TABLE IF NOT EXISTS hosp_ref.staff_roles (
  role_code            text PRIMARY KEY,          -- intensivist|hospitalist|charge_nurse|staff_nurse|resp_therapist|pharmacist|case_manager|transport_tech|...
  display_name         text NOT NULL,
  role_category        text NOT NULL,             -- physician|apn_pa|nursing|allied_health|ancillary|support|leadership
  is_provider          boolean NOT NULL DEFAULT false,
  is_nursing           boolean NOT NULL DEFAULT false,
  is_clinical          boolean NOT NULL DEFAULT true,
  default_workflow     text,                       -- rtdc|ed|periop|emergency|improvement|command|none
  default_app_permissions text[] NOT NULL DEFAULT '{}',
  sort_order           integer NOT NULL DEFAULT 100,
  metadata             jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Connector configuration for external staffing systems (reuses integration.sources for transport/secrets)
CREATE TABLE IF NOT EXISTS hosp_org.staffing_sources (
  staffing_source_id bigserial PRIMARY KEY,
  organization_id    bigint REFERENCES hosp_org.organizations(organization_id) ON DELETE CASCADE,
  integration_source_id bigint,                    -- FK into integration.sources (do NOT duplicate secrets/transport)
  source_key         text UNIQUE NOT NULL,         -- WORKDAY_PROD|QGENDA|AMION|EPIC_PROVIDER|ENTRA_SCIM|CSV_UPLOAD
  connector_type     text NOT NULL,                -- hris|scheduling|credentialing|identity|ehr_master|on_call|manual
  transport          text NOT NULL,                -- rest_api|sftp|scim|hl7_mfn|fhir_practitioner|db_view|file_upload
  mapping_template   jsonb NOT NULL DEFAULT '{}'::jsonb, -- saved source-field -> canonical-field mapping (reused per sync)
  sync_schedule      text,                          -- cron expr; null = manual only
  is_active          boolean NOT NULL DEFAULT true,
  last_synced_at     timestamptz,
  metadata           jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- One wizard/import batch
CREATE TABLE IF NOT EXISTS hosp_org.staff_import_runs (
  staff_import_run_id bigserial PRIMARY KEY,
  staffing_source_id  bigint NOT NULL REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE,
  status              text NOT NULL DEFAULT 'staged', -- staged|resolved|in_review|committed|failed|cancelled
  mapping_snapshot    jsonb NOT NULL DEFAULT '{}'::jsonb,
  counts              jsonb NOT NULL DEFAULT '{}'::jsonb, -- {new,updated,departed,auto_approved,needs_review,conflicts,unmatched}
  dry_run             boolean NOT NULL DEFAULT true,
  initiated_by        bigint,                         -- prod.users.id (admin who ran the wizard)
  started_at          timestamptz NOT NULL DEFAULT now(),
  completed_at        timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT staff_import_runs_status_chk CHECK (status IN ('staged','resolved','in_review','committed','failed','cancelled'))
);

-- Canonical staff identity (PII-minimized; links to app account when one exists)
CREATE TABLE IF NOT EXISTS hosp_org.staff_members (
  staff_member_id  bigserial PRIMARY KEY,
  staff_key        text UNIQUE NOT NULL,            -- stable dedupe key (source_system + external_id)
  source_system    text NOT NULL,
  external_id      text NOT NULL,
  user_id          bigint,                          -- nullable FK -> prod.users.id (app account), never required
  npi              text,
  license_no       text,
  display_name     text,
  email            text,
  employee_type    text,                            -- employed|contracted|locum|resident|fellow|student|agency
  employment_status text,                           -- active|leave|terminated|pending
  is_active        boolean NOT NULL DEFAULT true,
  first_seen_at    timestamptz NOT NULL DEFAULT now(),
  last_seen_at     timestamptz NOT NULL DEFAULT now(),
  metadata         jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (source_system, external_id)
);
CREATE INDEX IF NOT EXISTS idx_staff_members_user ON hosp_org.staff_members(user_id);
CREATE INDEX IF NOT EXISTS idx_staff_members_npi  ON hosp_org.staff_members(npi);

-- The core output: staff -> facility x service line x role x unit (multi-membership, effective-dated)
CREATE TABLE IF NOT EXISTS hosp_org.staff_assignments (
  staff_assignment_id bigserial PRIMARY KEY,
  staff_member_id   bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
  facility_key      text NOT NULL,
  service_line_code text NOT NULL REFERENCES hosp_ref.service_lines(service_line_code),
  role_code         text NOT NULL REFERENCES hosp_ref.staff_roles(role_code),
  program_code      text REFERENCES hosp_ref.programs(program_code),
  unit_id           bigint REFERENCES prod.units(unit_id) ON DELETE SET NULL,
  primary_flag      boolean NOT NULL DEFAULT false,
  coverage_model    text,                            -- in_house|on_call|tele|daytime|float
  fte               numeric(4,2),
  confidence        numeric(5,4),                    -- resolution confidence [0,1]
  resolution_source text,                            -- override|rule|heuristic|imported
  review_status     text NOT NULL DEFAULT 'assumed', -- assumed|source_verified|client_verified|unknown
  evidence          jsonb NOT NULL DEFAULT '{}'::jsonb, -- {source_field, rule_id, matched_value}
  effective_start   date,
  effective_end     date,
  decided_by        bigint,                          -- prod.users.id (reviewer)
  decided_at        timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (staff_member_id, facility_key, service_line_code, role_code, COALESCE(unit_id, 0)),
  CONSTRAINT staff_assignments_review_chk CHECK (review_status IN ('assumed','source_verified','client_verified','unknown'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_one_primary
  ON hosp_org.staff_assignments(staff_member_id) WHERE primary_flag;
CREATE INDEX IF NOT EXISTS idx_staff_assignments_facility_sl ON hosp_org.staff_assignments(facility_key, service_line_code, role_code);

-- Deterministic crosswalk rules the resolver applies (learned from reviewer decisions)
CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_rules (
  staff_mapping_rule_id bigserial PRIMARY KEY,
  staffing_source_id bigint REFERENCES hosp_org.staffing_sources(staffing_source_id) ON DELETE CASCADE, -- null = global
  match_field        text NOT NULL,                 -- cost_center|department|specialty|job_code|job_title|home_unit
  match_operator     text NOT NULL DEFAULT 'equals',-- equals|prefix|contains|regex
  match_value        text NOT NULL,
  target_service_line_code text REFERENCES hosp_ref.service_lines(service_line_code),
  target_role_code   text REFERENCES hosp_ref.staff_roles(role_code),
  target_unit_hint   text,
  priority           integer NOT NULL DEFAULT 100,  -- lower runs first
  confidence         numeric(5,4) NOT NULL DEFAULT 0.90,
  is_active          boolean NOT NULL DEFAULT true,
  created_by         bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_staff_rules_lookup ON hosp_org.staff_mapping_rules(match_field, is_active, priority);

-- Human decision log (audit + rule promotion)
CREATE TABLE IF NOT EXISTS hosp_org.staff_mapping_reviews (
  staff_mapping_review_id bigserial PRIMARY KEY,
  staff_import_run_id bigint NOT NULL REFERENCES hosp_org.staff_import_runs(staff_import_run_id) ON DELETE CASCADE,
  staff_member_id     bigint NOT NULL REFERENCES hosp_org.staff_members(staff_member_id) ON DELETE CASCADE,
  proposed            jsonb NOT NULL DEFAULT '{}'::jsonb, -- resolver output
  final               jsonb NOT NULL DEFAULT '{}'::jsonb, -- reviewer's committed decision
  action              text NOT NULL,                 -- accept|edit|split|defer|reject|deactivate
  reviewer_id         bigint,                         -- prod.users.id
  note                text,
  promoted_to_rule_id bigint REFERENCES hosp_org.staff_mapping_rules(staff_mapping_rule_id),
  created_at timestamptz NOT NULL DEFAULT now()
);
```

New models: `App\Models\Reference\StaffRole`; `App\Models\Org\{StaffingSource,StaffImportRun,StaffMember,StaffAssignment,StaffMappingRule,StaffMappingReview}`; a `staffAssignments(): HasMany` relation on the existing `App\Models\User`. Secrets live in `integration.sources` (encrypted), never in `hosp_org.staffing_sources`, which stores only a reference.

---

## 5. Service-Line Registry Seed + Alias Crosswalk

Author the canonical registry as config, projected by `deployment:seed-registry`.

- **`config/hospital/service-lines.php`** — the full report taxonomy (28 enterprise lines from the Summary Matrix + `orthopedics_spine`, `geriatrics_palliative`, `infectious_disease_infection_prevention`, `renal_dialysis`, `gastroenterology`, `pulmonary_respiratory`, `imaging_diagnostics`, `laboratory_pathology`, `pharmacy_medication`, `primary_ambulatory`, `home_post_acute`, `logistics_support`, `quality_research_education`, `transplant`, `hospital_medicine`). Each row carries `requires_*`, `default_location_roles`, `default_workflow`, `certification_or_designation`, and `aliases`.
- **`config/hospital/programs.php`** — the program codes named across report §§1–23 (`adult_level_i_trauma`, `comprehensive_stroke`, `thrombectomy_capable`, `stemi_pci`, `cardiac_surgery`, `electrophysiology`, `nicu_level_iii`, `nicu_level_iv`, `regional_perinatal_center`, `mfm`, `bmt`, `cellular_therapy`, `kidney`, `liver`, `pancreas`, `heart`, `lung`, `burn_center`, `acute_inpatient_rehab`, `picu`, `pediatric_ed`, `adult_inpatient_psych`, `ed_crisis`, …), each tied to a `service_line_code` and a `designation_body`.
- **`config/hospital/capability-tags.php`** — the 25 tags from report §4 (`telemetry`, `ventilator`, `negative_pressure`, `protective_environment`, `bariatric`, `lift`, `medical_gas`, `hemodialysis`, `crrt`, `behavioral_safe`, `pediatric`, `neonatal`, `ob`, `trauma_resus`, `burn`, `chemo`, `transplant`, `neuro_monitoring`, `ecmo`, `hybrid_or`, `robotic`, `fluoro`, `mri`, `ct`, `stroke_priority`, `stemi_priority`).
- **`config/hospital/taxonomy-vocab.php`** — `capability_levels`, `idn_roles`, `location_roles`, `evidence_classes` seed values.

**Alias crosswalk (the only reconciliation the existing data needs):**

```php
// service_lines.php excerpt — aliases fold legacy Summit codes onto canonical
'trauma_acute_care_surgery' => ['aliases' => ['trauma_surgery'], ...],
'hospital_medicine'         => ['aliases' => ['medicine'], ...],
'cardiovascular'            => ['aliases' => ['cardiology'], ...],
```

`App\Services\Deployment\ServiceLineNormalizer::canonical(string $code): string` resolves any code (canonical or alias) to canonical. `deployment:normalize-service-lines` runs it as a one-time backfill over the three known stores that hold free-text service-line values today:
- `hosp_space.facility_spaces.service_line_code`
- `flow_core.flow_events.service_line`
- `flow_core.occupancy_snapshots.service_line_counts` (jsonb rekey)

and updates the config authoring sources (`config/hospital/hospital-1.php` `units[].service_line`, `resources/js/constants/summitHospital.js`) via a follow-up code edit tracked in Phase 2 tasks. Only synthetic Summit data is touched.

---

## 6. API Surface

All under `routes/api.php`, controllers in `App\Http\Controllers\Api\Deployment\`, middleware `['api','auth','throttle:60,1']`, gated by a `viewDeploymentConsole` ability (§12). JSON only; no raw PHI.

Phase 1 endpoints:
- `GET /api/deployment/service-lines` — the registry (+ programs, capability tags, vocab). Cacheable.
- `GET /api/deployment/organizations` / `GET /api/deployment/organizations/{key}` — IDN + markets + facilities tree.
- `GET /api/deployment/facilities` — facility inventory (filter `?state=&idn_role=&service_line=&capability_level=`).
- `GET /api/deployment/facilities/{facilityKey}` — one facility with its capability matrix, programs, transfer edges.
- `GET /api/deployment/capability-matrix?facility={key}` — Layer 3 grid (service line × capability level).
- `GET /api/deployment/facilities/{facilityKey}/spaces` — Layer 4 physical mapping (facility spaces + service-line usage + capability tags + operational-map targets).
- `GET /api/deployment/transfers?service_line={code}&facility={key}&direction=out` — transfer edges.
- `GET /api/deployment/readiness/{facilityKey}` — the Acceptance-Criteria scorecard (§15).

Future endpoints (write path; Phase 6+):
- `POST/PATCH /api/deployment/facilities`, `.../capability-matrix`, `.../transfers` — capability authoring with `review_status` transitions.
- `POST /api/deployment/facilities/{facilityKey}/generate-manifest`.

---

## 7. Backend Implementation Plan

### Phase 0: Service-Line Registry + Vocabulary (non-breaking foundation)

Tasks:
1. Add migration `2026_07_04_000110_create_service_line_registry_tables.php` (§4.1).
2. Author `config/hospital/service-lines.php`, `programs.php`, `capability-tags.php`, `taxonomy-vocab.php` (§5).
3. Add Eloquent models `App\Models\Reference\{ServiceLine,Program,CapabilityTag}` with `hosp_ref` table bindings, array casts on `text[]`/`jsonb` columns.
4. Add `App\Services\Deployment\ServiceLineRegistrar` + artisan `deployment:seed-registry` (idempotent `upsert` on `service_line_code`/`program_code`/`tag_code`/lookup `code`).
5. Add `App\Services\Deployment\ServiceLineNormalizer` (`canonical()`, `all()`, alias index built from the registry).
6. Extend `App\Support\Hospital\HospitalManifest::serviceLines()` to prefer the DB registry when seeded, falling back to config (keep the 20 consumers working unchanged).

Acceptance:
- `php artisan deployment:seed-registry` seeds ≥28 service lines, all programs, 25 capability tags, and the 4 vocab lookups; re-running is a no-op (row counts stable).
- `ServiceLineNormalizer::canonical('trauma_surgery') === 'trauma_acute_care_surgery'` (and `medicine`, `cardiology`).
- `php artisan test` green; no existing manifest consumer changes behavior.

### Phase 1: IDN Geography + Capability Matrix + Transfers (data layer)

Tasks:
1. Add migration `2026_07_04_000120_create_idn_geography_capability_tables.php` (§4.2).
2. Add models `App\Models\Org\{Organization,Market,Facility,FacilityServiceCapability,TransferRelationship}` with relationships (`facility->capabilities()`, `facility->market()`, `organization->facilities()`).
3. Add importers + artisan commands:
   - `deployment:import-facilities {path}` (CSV/JSON → organizations/markets/facilities; upsert on `facility_key`).
   - `deployment:import-capabilities {path}` (→ `facility_service_capabilities` + `transfer_relationships`; upsert on `(facility_key, service_line_code)`; normalize codes via `ServiceLineNormalizer`).
4. Seed **Summit Regional as a reference deployment**: `SummitDeploymentSeeder` builds org `SUMMIT_HEALTH`, market `Mid-Atlantic`, facility `SUMMIT_REGIONAL` (`idn_role=flagship_quaternary_hub`, `cad_facility_code=ZEPHYRUS-500`, `trauma_level_adult=Level I`), plus one `facility_service_capabilities` row per manifest service line at `capability_level=definitive` (except `emergency` 24/7). Also seed the four `network_facilities` stubs (`HAWTH`/`RIVCH`/`GLNMC`/`CASTG`) as `community_hospital` with `stabilize` trauma + transfer edges to `SUMMIT_REGIONAL`.
5. Ship **archetype fixture seeders** (test/demo only, clearly `review_status='assumed'`) for the report's worked IDNs — Geisinger (hub-and-spoke, Level I Danville + Level IV Muncy/Lewistown), Virtua (transplant at OLOL, RPC/NICU at Voorhees, external trauma edge to Cooper), Penn (multi-market, Level I at PPMC + Lancaster) — as regression fixtures proving the schema holds heterogeneous IDNs.

Acceptance:
- `deployment:import-facilities` + `deployment:import-capabilities` load the Geisinger fixture with `trauma_acute_care_surgery` = `definitive` at GMC/GWV, `advanced` at GCMC, `stabilize` at Muncy/Lewistown, and a `transfers_to` relationship from each Level IV site to a Level I hub.
- Virtua fixture has `transplant` only at `VIRTUA_OLOL`, `neonatology`=Level III only at `VIRTUA_VOORHEES`, and an `is_external_partner=true` transfer edge to `Cooper University Hospital` for trauma.
- `hosp_org.facilities` unique on `facility_key`; every row has a valid `idn_role`; capability rows unique on `(facility_id, service_line_code)`.
- `php artisan test --filter=DeploymentCapabilityMatrixTest` green.

### Phase 2: Many-to-Many Space ↔ Service Line + FK Hardening

Tasks:
1. Add migration `2026_07_04_000130_create_facility_space_service_lines.php` (§4.3).
2. Add model `App\Models\Facility\FacilitySpaceServiceLine`; add `serviceLines(): HasMany` + `primaryServiceLine()` helper to `FacilitySpace`.
3. Run `deployment:normalize-service-lines` (§5) over `facility_spaces`, `flow_events`, `occupancy_snapshots`; edit `config/hospital/hospital-1.php` unit codes + `resources/js/constants/summitHospital.js` to canonical.
4. Backfill `facility_space_service_lines` from each space's dominant `service_line_code` as `primary_flag=true` (one row per space), and backfill `facility_spaces.facility_key` from the `space_code` prefix mapped through `hosp_org.facilities.cad_facility_code`.
5. Extend `App\Services\Facility\ModelCatalogImporter` to write `facility_space_service_lines` (primary + any shared lines declared in the catalog) and `facility_spaces.location_role`/`capability_tags`.
6. Add migration `2026_07_04_000160_validate_facility_space_service_line_fk.php` — `ADD CONSTRAINT facility_spaces_service_line_fk ... NOT VALID` then `VALIDATE CONSTRAINT`; same for `location_role`.
7. Extend `FacilitySpaceLocationResolver::spaceRowToPayload()` to also emit `service_lines[]` (from the bridge) and `location_role`, keeping the existing single `service_line` for back-compat.

Acceptance:
- Every existing Summit facility space has exactly one `primary_flag=true` bridge row (`uq_fssl_one_primary` holds).
- A seeded shared CT space resolves to `service_lines = [emergency, trauma_acute_care_surgery, neurosciences, oncology, imaging_diagnostics]` via the resolver.
- The `service_line_code` FK validates with zero violations (proves normalization ran).
- Patient Flow Navigator API responses still return a `service_line` string (no FE break); `php artisan test --filter=PatientFlow` green.

### Phase 3: Capability Tags on Beds and Rooms

Tasks:
1. Add migration `2026_07_04_000140_add_capability_tags_to_prod_spaces.php` (§4.4).
2. Add `capability_tags` to `Bed`/`Room` `$fillable` + `array` cast.
3. Add `App\Services\Deployment\CapabilityTagBackfiller` mapping manifest `units[].acuity`/`type` → default bed tags (see table below); wire into `RtdcSeeder`/`facility:import-catalog --map-operational`.
4. Add artisan `deployment:audit-tags` — flags bed/room tags not present in `hosp_ref.capability_tags` and beds missing expected tags for their unit acuity.

Default acuity → bed tag seed heuristic (client roster overrides):

| Unit acuity | Default bed capability tags |
| --- | --- |
| `icu` | `ventilator, telemetry, medical_gas, negative_pressure(subset)` |
| `burn_icu` | `burn, ventilator, medical_gas` |
| `step_down` | `telemetry, medical_gas` |
| `behavioral` | `behavioral_safe` |
| `obstetrics` | `ob, medical_gas` |
| `neonatal` | `neonatal, medical_gas` |
| `pediatric` | `pediatric` |
| `med_surg` | `medical_gas` |

Acceptance:
- Every ICU bed seeds `ventilator`+`telemetry`; BHU beds seed `behavioral_safe`; `deployment:audit-tags` reports 0 orphan tags on Summit.
- `SELECT count(*) FROM prod.beds WHERE 'ventilator' = ANY(capability_tags)` returns the ICU/step-down bed count.
- `php artisan test --filter=CapabilityTag` green.

### Phase 4: Transfer Graph Projection

Tasks:
1. Extend `OperationsGraphProjector` to project `facility` nodes + `transfers_to` edges (§4.5), guarded by `Schema::hasTable('hosp_org.facilities')`.
2. Extend `OperationsGraphController` (or add `Api\Deployment\TransferRelationshipController`) to serve transfer subgraphs by service line / facility.
3. Add `weight`-aware read model so downstream routing can consume `typical_minutes` without recomputation.

Acceptance:
- After `php artisan ops:graph-rebuild` (existing projector entrypoint), `ops.nodes` contains one `facility:*` node per `hosp_org.facilities` row and `ops.edges` contains `transfers_to` edges with `weight = typical_minutes`.
- `GET /api/deployment/transfers?service_line=trauma_acute_care_surgery` returns the Geisinger Level IV → Level I edges.
- Re-running the projector is idempotent (no duplicate edges; partial-unique index holds).

### Phase 5: Per-Facility Manifest Generation

Tasks:
1. Add `App\Services\Deployment\ManifestGenerator` that assembles a manifest array (matching `hospital-1.php` shape: `facility`, `network_facilities`, `service_lines`, `units`, `census_demo_targets`, …) from `hosp_org` + `hosp_ref` + `hosp_space` + `prod` for a given `facility_key`.
2. Add artisan `hospital:generate-manifest {facilityKey} {--write=config/hospital/<key>.php}`.
3. Parameterize `HospitalManifest` to load by `facility_key` (default `SUMMIT_REGIONAL`) — replace the hardcoded `require config/hospital/hospital-1.php` with a resolver that maps `facility_key → config path` (registered in `config/hospital.php`), preserving the static cache keyed per facility and the `flush()` contract.

Acceptance:
- `hospital:generate-manifest SUMMIT_REGIONAL --write=/dev/stdout` reproduces the current Summit manifest's `facility`, `service_lines`, and unit `abbr/cad_code/staffed_bed_count` (round-trip diff limited to canonicalized service-line codes).
- Generating a manifest for a Geisinger-fixture spoke yields a smaller manifest (ED + med/surg + imaging + lab, no transplant/NICU) proving no Summit assumptions leak.
- `HospitalManifest::forFacility('SUMMIT_REGIONAL')` returns identical data to today's `app(HospitalManifest::class)`.

### Phase 6: Deployment Readiness Scorecard + API surface

Tasks:
1. Add `App\Services\Deployment\DeploymentReadinessService` implementing each Acceptance Criterion (§15) as a discrete check returning `{criterion, status, count, failures[]}`.
2. Add artisan `deployment:readiness {facilityKey} {--json}` and `Api\Deployment\DeploymentReadinessController`.
3. Add the read-side controllers for all Phase-1 endpoints (§6) with `App\Http\Resources\Deployment\*` transformers.
4. Register the `viewDeploymentConsole` ability/policy (§12).

Acceptance:
- `deployment:readiness SUMMIT_REGIONAL` reports pass on "every facility has an `idn_role`", "every service line has a `capability_level`", "every staffed bed maps to a facility space or is flagged unmapped-with-reason", and lists any low-confidence (`assumed`) rows.
- `GET /api/deployment/readiness/SUMMIT_REGIONAL` returns the same structure as JSON.
- `php artisan test --filter=DeploymentReadiness` green.

### Phase 7: Staffing Alignment (connectors, resolver, review, commit)

The meticulous staff → service-line/role layer. Full design in §11; the backend tasks:

Tasks:
1. Add migration `2026_07_04_000150_create_staffing_alignment_tables.php` (§4.7) + models (§4.7).
2. Author `config/hospital/staff-roles.php`; add `deployment:seed-staff-roles` (idempotent role taxonomy seed).
3. Add the `StaffingConnector` interface + implementations (`CsvUploadConnector`, `FhirPractitionerConnector`, `ScimConnector`, `Hl7StaffMasterConnector`, `RestApiConnector` base for Workday/UKG/QGenda/Amion/Epic) — ingest via `raw.inbound_messages` → `integration.canonical_events` (reuse; do not duplicate).
4. Add `App\Services\Staffing\StaffIdentityResolver` (dedupe + match to `prod.users` by email/NPI/employee_id) and `App\Services\Staffing\ServiceLineRoleResolver` (rule engine: override → `staff_mapping_rules` → heuristic, each with confidence + evidence).
5. Add `App\Services\Staffing\StaffImportOrchestrator` (stage → resolve → bucket → commit) writing `staff_import_runs`, `staff_members`, `staff_assignments`, `staff_mapping_reviews`.
6. Add `App\Services\Staffing\StaffProvisioningService` — on commit, set operational role/workflow + `is_active` on the linked account **additively** (never touches registration/login/`must_change_password`/temp-password flow; superuser `admin@acumenus.net` immutable).
7. Add `App\Services\Staffing\CoverageService` (staffed vs unstaffed units per service line) and `deployment:staffing-drift {source}` (source vs Zephyrus divergence + termination sweep with grace).
8. Add artisan `deployment:staffing-sync {source} {--commit}` for scheduled headless runs.

Acceptance:
- A CSV upload and a FHIR `Practitioner`+`PractitionerRole` bundle both stage into one `staff_import_run` with populated `counts`.
- ≥80% of a well-formed source resolves deterministically (`resolution_source='rule'`); the remainder route to `needs_review`/`unmatched`.
- Every committed `staff_assignment` has FK-valid `service_line_code` + `role_code`, a `facility_key`, a `confidence`, `review_status`, and `evidence`.
- Terminated staff (present in Zephyrus, absent from source) are soft-deactivated after grace; **auth tests green** (`php artisan test --testsuite=Feature --filter=Auth`).
- Re-running `deployment:staffing-sync` is idempotent (no duplicate assignments; `uq_staff_one_primary` holds).

---

## 8. Frontend Implementation Plan — Deployment Console

New Inertia surface under the superuser/ops-leader workflow. Respect the Token Canon in `CLAUDE.md`: Figtree via `font-sans`, weights 400/500/600 only (no `font-bold`), `healthcare-*` tokens with `dark:` pairs (no raw Tailwind palette), `Components/ui/Surface` (`Card`/`Panel`), `shadow-sm` resting, `tabular-nums` for all counts/levels, gold `:focus-visible`, dark-default. Status by icon+label, never color alone. Ration status color; reserve coral for real breaches (e.g. `unknown` evidence on a regulated designation).

### Phase F1: Typed client layer
- `resources/js/features/deployment/{types,api,hooks}.ts` — typed `ServiceLine`, `Facility`, `CapabilityMatrixCell`, `TransferEdge`, `ReadinessReport`; `useDataService()`-backed hooks; `dev` mock fixtures mirroring the archetype seeders.

### Phase F2: Components
- `FacilityNetworkMap` — markets → facilities tree/map; `idn_role` badges; regulated designations as labeled chips (state/accreditation evidence shows a verified icon; `assumed` shows a review flag).
- `CapabilityMatrixGrid` — facility × service-line grid; cell = `capability_level` (7-step ramp using sanctioned sequential palette, not status colors), `tabular-nums`, keyboard-navigable.
- `ServiceLinePresenceHeatmap` — the report's "Service-Line Presence by Hospital Type" as a live heatmap across the IDN.
- `TransferGraph` — ReactFlow (already a dependency) rendering `transfers_to` edges; edge label = `typical_minutes`; external partners visually distinct via icon+dash, not color alone.
- `ReadinessScorecard` — Acceptance-Criteria checklist with pass/fail counts and drill-down to failing rows.

### Phase F3: Page + navigation
- `resources/js/Pages/Deployment/DeploymentConsole.tsx` + Inertia route in `routes/web.php` (`Inertia::render('Deployment/DeploymentConsole', …)`, under `auth`).
- Register nav in `workflowNavigationConfig` (`Contexts/DashboardContext.jsx`) for superuser/ops-leader.

Acceptance:
- `npm run build` clean; `npx vitest run resources/js/features/deployment` green.
- Console renders Summit + all three archetype fixtures with correct capability grids and transfer edges; passes an impeccable-hook token check (`/impeccable audit`).

### Phase F4: Staffing Alignment Wizard (admin surface)

The admin-only wizard from §11. A `HeroUI`-stepper multi-step flow under `Pages/Deployment/StaffingWizard.tsx`, gated by `manageDeploymentConfig`.

- `resources/js/features/staffing/{types,api,hooks}.ts` — typed `StaffingSource`, `FieldMapping`, `StaffImportRun`, `ResolutionBucket`, `StaffAssignmentDraft`, `CoverageCell`.
- `SourceConnectStep` — connector picker + transport config + **Test Connection** (secrets never rendered back; write-only fields).
- `FieldMappingStep` — auto-detected source columns → canonical staff fields grid with confidence + saved template reuse.
- `ImportPreviewStep` — dry-run staging summary (new / updated / departed) with identity-match evidence.
- `ResolutionReviewStep` — the centerpiece **Review Queue**: buckets (Auto-approved / Needs review / Conflicts / Unmatched / Departed); per-row accept / edit / split (multi-assignment) / defer / reject / deactivate; "promote to rule" affordance; regulated roles require explicit confirm.
- `FacilityBindingStep` — bind facility_key + unit + coverage + FTE + effective window; capability-matrix validation warnings.
- `CommitStep` — final diff (assignments created/updated/deactivated, provisioning deltas) + explicit commit.
- `CoverageDashboard` + `ScheduleSyncPanel` — post-commit coverage-by-service-line/unit and recurring-sync config.
- Token canon: `tabular-nums` for counts/FTE/confidence; status by icon+label; ration color (coral only for regulated-role-without-evidence or a hard conflict); dark-default; no `font-bold`.

Acceptance:
- An admin can walk CSV upload → mapping → dry-run → review → commit end-to-end in `dev` mock mode and against a seeded FHIR fixture.
- The Review Queue correctly buckets a synthetic source (deterministic hits auto-approved, ambiguous specialty routed to review, unmatched job code surfaced).
- Promoting a decision to a rule reduces the review count on the next dry-run.
- `npx vitest run resources/js/features/staffing` green; `/impeccable audit` on `Pages/Deployment/StaffingWizard.tsx` clean.

---

## 9. Manifest Generation — retiring the Summit-only assumption

The report's Implementation Recommendation #7 ("keep Summit as a reference, not a universal model") is realized by Phase 5. After it lands:
- `config/hospital/hospital-1.php` remains as the Summit **reference** manifest and the regression baseline.
- New deployments are generated (`hospital:generate-manifest`) from `hosp_org` + capability matrix + facility-space import — never copied from Summit.
- `config/facility_models.php` gains per-facility keys as new facilities bring 3D assets; the `zep_500`/`ZEPHYRUS-500` entry is preserved as the Summit join key.
- `HospitalManifest` becomes facility-parameterized; all 20 existing consumers default to `SUMMIT_REGIONAL` and are unaffected.

---

## 10. Deployment Discovery Runbook (operationalizing the report's checklist)

Convert the report's Deployment Discovery Checklist into a repeatable per-client runbook, stored as `docs/deployment/RUNBOOK-<client>.md` generated from a template:

1. **Source harvest** (report Phase 0): facility list, licensed-bed roster, trauma/stroke/perinatal/NICU/burn/transplant designations, department + cost-center master, EHR/ADT/RTLS location masters, CAD/BIM/PDF floor plans, transfer agreements. Each artifact tagged with an `evidence_class`.
2. **Geography + capability** (Phase 1): `deployment:import-facilities` then `deployment:import-capabilities`; assign `idn_role`; set `capability_level` per facility × service line with `source_evidence_type`.
3. **Physical import** (Phase 2): `facility:import-catalog` → `hosp_ingest` → `hosp_space.facility_spaces` → `facility_space_service_lines`.
4. **Operational mapping** (Phase 3): `--map-operational` → `prod.*` + `operational_space_maps` + `capability_tags`.
5. **Transfer + route graph** (Phase 4): interfacility edges + `ops` projection.
6. **Workflow activation** (report Phase 5): RTDC/ED/periop/transport/command per activated space.
7. **Client review + governance** (report Phase 6): `deployment:readiness`; walk the low-confidence list; validate regulated designations against state/accreditation evidence; freeze the manifest.

The **Service-Line Interview** and **Physical Walkthrough** question sets from the report become structured intake forms whose answers populate `capability_model`, `hours`, `coverage_model`, `transfer_*`, `surge_role`, and `flow_restrictions`.

---

## 11. Staffing Integration and the Admin Assignment Wizard

The capability matrix (§4.2) says *what a facility can do*; this section answers *who does it, and where*. A meticulous, admin-only **Staffing Alignment Wizard** integrates external staffing systems and resolves every staff member to `facility × service line × role × unit` with human-in-the-loop review, confidence, and evidence — then provisions Zephyrus operational role/workflow **additively**, without ever modifying the protected auth system.

> **Full design lives in its own plan:** `docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md`. That standalone doc is authoritative for the staffing data model, connectors, resolution engine, wizard UX, API surface, testing, and governance. This section is the master-plan integration summary; the data model is §4.7 above, the backend work is Phase 7 (§7), and the frontend work is Phase F4 (§8). Keep the two in sync — the standalone doc leads.

**In one paragraph:** the wizard ingests staffing sources (HRIS / scheduling / credentialing / identity / EHR provider master) through pluggable `StaffingConnector` implementations (CSV upload is the always-available zero-integration path), stages them into a dry-run `staff_import_run`, resolves each person via a layered engine (override → deterministic `staff_mapping_rules` → heuristic → unmatched, each stamping confidence + evidence), and routes everything that is not high-confidence-deterministic to a **Review Queue** bucketed as Auto-approved / Needs review / Conflicts / Unmatched / Departed. An admin approves per row (accept / edit / split / defer / reject / deactivate, with "promote to rule"), binds facility + unit + coverage + FTE, and commits — writing `hosp_org.staff_assignments` + `staff_mapping_reviews` and provisioning operational role/workflow **additively** onto any linked `prod.users` account.

### 11.1 Non-negotiables carried from this master plan

- **Additive to auth (§13).** Per `.claude/rules/auth-system.md`, the wizard never touches registration, login, `/change-password`, the temp-password/Resend flow, `must_change_password`, or the `admin@acumenus.net` superuser. `StaffProvisioningService` is the only writer to `prod.users` and is additive-only (operational role/workflow + `is_active`), guarded by a `StaffingAuthInvariants` regression test written **first (red)**.
- **Data model (§4.7).** `hosp_ref.staff_roles` + `hosp_org.{staffing_sources,staff_import_runs,staff_members,staff_assignments,staff_mapping_rules,staff_mapping_reviews}`, with `uq_staff_one_primary` enforcing one primary membership per person.
- **Reuse the integration spine.** Transport + secrets live in `integration.sources` / `raw.inbound_messages` / `integration.canonical_events`; `hosp_org.staffing_sources` stores only a reference + reusable mapping template; connector secrets are never sent to the browser.
- **Admin-only + auditable.** `manageDeploymentConfig` ability (`role IN ('superuser','ops-leader')`); assignments are effective-dated, soft-deactivated (never hard-deleted), and every commit is an auditable `staff_import_run` + `staff_mapping_reviews` trail. Regulated roles require a `credentialing`/`ehr_master` evidence class to reach `source_verified`.

See the standalone plan for the connector table, the resolution-precedence ladder, the 8-step wizard flow (Scope → Connect → Map → Preview → Resolve → Review → Bind → Commit → Schedule), the admin-gated API surface, and the full risk register.

---

## 12. Testing and Validation Plan

Backend (`php artisan test`):
- `--filter=ServiceLineRegistry` — registry seed idempotency, alias resolution.
- `--filter=DeploymentCapabilityMatrix` — heterogeneous IDN fixtures (Geisinger/Virtua/Penn) load with correct `capability_level` per site.
- `--filter=FacilitySpaceServiceLine` — many-to-many, one-primary constraint, shared-space resolution.
- `--filter=CapabilityTag` — bed/room tag backfill + audit.
- `--filter=TransferGraphProjection` — facility nodes + `transfers_to` edges, idempotent rebuild.
- `--filter=DeploymentReadiness` — every Acceptance Criterion check.
- `--filter=StaffingResolver` — rules-engine precedence, confidence, multi-membership, unmatched bucketing.
- `--filter=StaffingImportCommit` — dry-run → review → commit; idempotent re-sync; termination sweep with grace.
- `--filter=StaffingAuthInvariants` — provisioning does NOT alter registration/login/change-password; `admin@acumenus.net.must_change_password` stays `false`.
- `--filter=PatientFlow` and `--filter=FlowWindow` — regression: existing flow surfaces unchanged after normalization.
- `--testsuite=Feature --filter=Auth` — the protected auth flow is untouched by staffing provisioning.

DB / migration:
- `php artisan migrate` then `php artisan migrate:rollback` on a scratch DB — verify each new migration's `down()` (guard `hosp_org`/bridge drops with `isLocalEnvironment()` like `2026_06_25_000010_*`).
- Post-migrate FK validation returns zero violations on `facility_spaces.service_line_code`.

Frontend:
- `npx vitest run resources/js/features/deployment resources/js/features/staffing`
- `npm run build`
- `/impeccable audit` on `Pages/Deployment/*` (incl. `StaffingWizard.tsx`) and `Components/Deployment/*`.

Lint / hygiene:
- `./vendor/bin/pint app/Models/Org app/Models/Reference app/Services/Deployment app/Services/Staffing app/Http/Controllers/Api/Deployment`
- `git diff --check`

Manual validation script (per phase):
- `php artisan deployment:seed-registry && php artisan deployment:import-facilities storage/fixtures/geisinger-facilities.json && php artisan deployment:import-capabilities storage/fixtures/geisinger-capabilities.json && php artisan deployment:readiness GEISINGER_DANVILLE --json`

---

## 13. Security, Privacy, and Governance

- **No PHI.** This entire layer is facility/organizational configuration. No patient identifiers enter `hosp_org`/`hosp_ref`.
- **RBAC.** Deployment console + write endpoints gated by a `viewDeploymentConsole` / `manageDeploymentConfig` ability mapped to `role IN ('superuser','ops-leader')` (reuse the existing `users.role` + Flow-Window lens RBAC pattern). Frontline roles do not see capability authoring.
- **Evidence provenance is first-class.** Every regulated designation (trauma/stroke/perinatal/NICU/burn/transplant) must carry `source_evidence_type IN ('state_designation','accreditation_body')` to reach `review_status='source_verified'`; marketing pages (`official_health_system_page`, `public_location_page`) can locate a service line but cannot verify a regulated designation. The readiness scorecard fails any `source_verified` designation lacking regulated evidence.
- **Review-status workflow.** `assumed → source_verified → client_verified`; write endpoints record the transitioning user + timestamp in `metadata`. Low-confidence rows are surfaced, never silently trusted.
- **Immutable join keys.** `cad_facility_code` (`ZEPHYRUS-500`) is never renamed by this layer.
- **Protected auth is inviolate.** The Staffing Alignment Wizard (§11) obeys `.claude/rules/auth-system.md`: it never modifies registration, login, `/change-password`, the temp-password/Resend flow, `must_change_password`, or the `admin@acumenus.net` superuser. Staff→role/service-line resolution is an *operational* layer; provisioning is additive (operational role/workflow + `is_active`) and is covered by the `StaffingAuthInvariants` regression test.
- **Staffing PII minimization.** Staff name/email are PII; stored minimally in `hosp_org.staff_members`, admin-only (`manageDeploymentConfig`), connector secrets encrypted in `integration.sources` and never sent to the browser.
- **Auditability.** `deployment:import-*` and `deployment:staffing-*` commands log a summary (rows upserted, codes normalized, unverified designations, review decisions) for the runbook record.

---

## 14. Rollout / Land Order

1. Phase 0 migration + registry seed (`deployment:seed-registry`) — inert until consumed; safe to deploy first.
2. Phase 1 `hosp_org` migration + `SummitDeploymentSeeder` — Summit represented as a facility; nothing else changes.
3. Phase 2 bridge migration → `deployment:normalize-service-lines` → backfill → **then** the FK-validate migration. Never validate before normalize.
4. Phase 3 `prod` tag columns + backfill.
5. Phase 4 projector extension + `ops:graph-rebuild`.
6. Phase 5 manifest generator + `HospitalManifest` parameterization (feature-flag `deployment.manifest_source=config|generated`, default `config`).
7. Phase 6 API + console + readiness.
8. Phase 7 staffing migration + role-taxonomy seed → connectors → wizard behind `deployment.staffing_wizard_enabled` (default off). CSV upload connector first; API connectors as client integrations are provisioned. Confirm `Auth` feature tests green before enabling.

Deploy via `./deploy.sh` only (no ad hoc SSH / prod `git pull`, per `AGENTS.md`). Post-deploy checklist: migrations applied (`php artisan migrate:status`), `deployment:seed-registry` idempotent, `/api/deployment/service-lines` returns 200, readiness scorecard renders for `SUMMIT_REGIONAL`.

---

## 15. Milestones and Deliverables

- **Milestone 1 — Registry live.** `hosp_ref.service_lines`/programs/tags/vocab seeded; `ServiceLineNormalizer` resolves aliases. *Deliverable:* a normalized, FK-able service-line ontology.
- **Milestone 2 — IDN modeled.** `hosp_org` populated for Summit + three archetype fixtures; capability matrix + transfer edges queryable. *Deliverable:* heterogeneous IDNs represented without false uniformity.
- **Milestone 3 — Spaces multiplexed.** Many-to-many space↔service-line + facility_key + FK-hardened. *Deliverable:* shared CT/hybrid-OR/ICU-bed correctly serve multiple lines.
- **Milestone 4 — Capabilities tagged + transferable.** Bed/room capability tags + `transfers_to` graph. *Deliverable:* vent/CRRT/stroke-priority beds and interfacility transfers are first-class data.
- **Milestone 5 — Any-facility deploy.** `hospital:generate-manifest` + parameterized `HospitalManifest`. *Deliverable:* a new client deploys from its capability matrix, not a Summit copy.
- **Milestone 6 — Deployment console + readiness.** `/api/deployment/*` + `Pages/Deployment/DeploymentConsole` + scorecard. *Deliverable:* the Acceptance Criteria are mechanically checkable in-product.
- **Milestone 7 — Staffing aligned.** Staffing Alignment Wizard connects a staffing source, resolves staff → service line/role/facility/unit with review + evidence, and provisions operational access additively. *Deliverable:* every user's service-line and role membership is authoritatively resolved and governed — with the protected auth flow untouched.

---

## 16. Acceptance Criteria (deployment-ready, made testable)

Each maps directly to the report's Acceptance Criteria and is implemented as a `DeploymentReadinessService` check:

1. Every `hosp_org.facilities` row has a non-null `idn_role` (FK-enforced) — **pass = 0 nulls**.
2. Every (facility × service line) present has a `capability_level` — **pass = every active facility has ≥1 capability row and every capability row has a valid level**.
3. Every regulated designation (trauma/stroke/perinatal/NICU/burn/transplant) with `review_status='source_verified'` has `source_evidence_type` in the regulated set — **pass = 0 violations**.
4. Every staffed inpatient bed maps to a facility space (`prod.beds.facility_space_id` not null) **or** carries `metadata.unmapped_reason` — **pass = 0 unexplained unmapped beds**.
5. Every ED/OR/ICU/imaging/cath-IR/L&D-NICU/behavioral/observation space has a `facility_spaces` record with a `location_role` — **pass = 0 missing roles for those categories**.
6. Every operationally important room/chair/bay maps to `prod.rooms` (or equivalent) via `operational_space_maps` — **report count of unmapped**.
7. Every staffed unit maps to `prod.units`; every operational bed to `prod.beds` — **pass = full coverage or explicit exceptions**.
8. Shared spaces have ≥1 non-primary `facility_space_service_lines` row where applicable — **report shared-space count**.
9. Transfer-out/in relationships exist for trauma/stroke/STEMI/OB-NICU/pediatrics/burn/transplant/complex-surgery where `capability_level < definitive` — **pass = no stabilize/absent line lacks a transfer edge**.
10. Internal route-graph coverage present for the key paths (ED→CT→OR/IR/ICU, OR→PACU/ICU/unit, L&D→OB-OR/NICU, inpatient→imaging, discharge→transport, clean/soiled/SPD) — **report path coverage** (best-effort; full routing is downstream).
11. Client stakeholders have reviewed low-confidence mappings — **pass = 0 rows remain `review_status='assumed'`** (or all listed for sign-off).
12. Every active staffed unit has ≥1 `staff_assignment` at an appropriate role (a unit with no charge/staff-nurse coverage is flagged) — **report unstaffed units per service line** (`CoverageService`).
13. Every committed `staff_assignment` has FK-valid `service_line_code` + `role_code`, a `facility_key`, and non-null `confidence` + `evidence` — **pass = 0 violations**; regulated roles at `source_verified` carry `credentialing`/`ehr_master` evidence.

The scorecard returns per-criterion `{status, count, failures[]}` and an overall `deployment_ready: bool`.

---

## 17. Risks and Controls

- **Risk:** Validating the `service_line_code` FK before normalization breaks Summit data. **Control:** FK added `NOT VALID`; `deployment:normalize-service-lines` runs in the same batch before `VALIDATE CONSTRAINT`; Phase 2 acceptance asserts zero violations.
- **Risk:** Semantic merge of `cardiology`→`cardiovascular` (etc.) loses granularity. **Control:** aliases preserved in `service_lines.aliases`; only synthetic Summit data is rekeyed; the decision is documented in §5 and recoverable from `metadata`.
- **Risk:** Array-typed `capability_tags` can't FK a lookup table. **Control:** app-layer validation + nightly `deployment:audit-tags` orphan check; GIN indexes keep membership queries fast.
- **Risk:** `HospitalManifest` parameterization regresses 20 consumers. **Control:** default `facility_key='SUMMIT_REGIONAL'`, `manifest_source` flag defaults to `config`, round-trip test asserts byte-for-byte-equivalent Summit data (modulo canonical codes).
- **Risk:** Archetype fixtures (Geisinger/Virtua/Penn) are mistaken for verified client data. **Control:** all fixture rows seeded `review_status='assumed'` and confined to test/demo seeders; readiness scorecard flags them.
- **Risk:** Ops-graph rebuild duplicates transfer edges. **Control:** reuse the existing partial-unique `ops_edges_active_unique_idx`; projector `upsert` semantics; idempotency asserted in Phase 4.
- **Risk:** Scope creep into patient-transfer pathfinding. **Control:** this plan delivers the transfer-edge *substrate* only; weighted routing is explicitly a downstream plan.
- **Risk:** Staffing provisioning inadvertently alters the protected auth flow (`.claude/rules/auth-system.md`). **Control:** `StaffProvisioningService` is additive-only (operational role/workflow + `is_active`); a `StaffingAuthInvariants` test asserts registration/login/change-password and superuser invariants; wizard ships behind `deployment.staffing_wizard_enabled` (default off).
- **Risk:** Connector credentials leak to the browser or logs. **Control:** secrets live encrypted in `integration.sources`; API fields are write-only; `hosp_org.staffing_sources` stores only a reference; admin-only RBAC (`manageDeploymentConfig`); no secrets in `read_network_requests`-visible payloads.
- **Risk:** Auto-resolution mis-assigns staff to the wrong service line/role at scale. **Control:** only high-confidence deterministic hits auto-approve; everything else routes to the review queue; regulated roles require explicit confirm; assignments are effective-dated and reversible; drift + coverage dashboards catch errors post-commit.

---

## 18. Immediate Next Work Items

1. Author `config/hospital/service-lines.php` (28+ lines with `aliases`) and `programs.php`, `capability-tags.php`, `taxonomy-vocab.php` from the report's Summary Matrix and §§1–23.
2. Write migration `2026_07_04_000110_create_service_line_registry_tables.php` and `App\Services\Deployment\ServiceLineRegistrar` + `deployment:seed-registry`.
3. Write `App\Services\Deployment\ServiceLineNormalizer` and unit-test the three alias mappings (`trauma_surgery`, `medicine`, `cardiology`).
4. Write migration `2026_07_04_000120_create_idn_geography_capability_tables.php` and the `App\Models\Org\*` models.
5. Build `SummitDeploymentSeeder` (Summit as `flagship_quaternary_hub` + 4 affiliate stubs) and the Geisinger archetype fixture as the first heterogeneous-IDN regression.
6. Land Phases 0–1 behind no flag (inert), then proceed to Phase 2 normalization + FK hardening.
7. Author `config/hospital/staff-roles.php` and scaffold the `StaffingConnector` interface + `CsvUploadConnector`; write the `StaffingAuthInvariants` test first (red) to lock the auth guardrail before building `StaffProvisioningService`.
8. Prototype the Staffing Alignment Wizard against a synthetic CSV of Summit providers/nurses (from the manifest's 30 providers + 36 nurses) to validate the resolver + review queue end-to-end before wiring real connectors.

## References

- `docs/architecture/SERVICE-LINE-LOCATION-DEPLOYMENT-TAXONOMY-2026-07-04.md` (research baseline)
- `docs/plans/HOSPITAL-1-SUMMIT-REGIONAL-PLAN.md`
- `docs/superpowers/plans/2026-06-25-hospital-blueprint-ingestion-digital-twin.md`
- `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`
- `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php`
- `database/migrations/2026_06_25_000020_create_ops_graph_tables.php`
- `app/Support/Hospital/HospitalManifest.php`, `config/hospital/hospital-1.php`, `config/facility_models.php`
- `app/Services/PatientFlow/FacilitySpaceLocationResolver.php`, `app/Services/Facility/ModelCatalogImporter.php`, `app/Services/Ops/OperationsGraphProjector.php`
- `docs/superpowers/plans/2026-06-24-transport-operations.md` (Connector Contract pattern reused for `StaffingConnector`)
- `AGENTS.md`, `CLAUDE.md`, `.claude/rules/auth-system.md` (protected auth — additive-only constraint on the staffing wizard)
