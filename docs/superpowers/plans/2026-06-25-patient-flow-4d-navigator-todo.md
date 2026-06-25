# Patient Flow 4D Navigator Implementation TODO

Date: 2026-06-25
Status: Detailed execution checklist
Companion plan: `docs/superpowers/plans/2026-06-25-patient-flow-4d-navigator-integration.md`
Target repo: `/home/smudoshi/Github/Zephyrus`
Demo source: `/home/smudoshi/Github/Zephyrus/patient-flow-4d-navigator`

## Outcome

Deliver the Patient Flow 4D Navigator as an authenticated Zephyrus feature backed by Laravel APIs, Zephyrus facility and integration schemas, imported synthetic flow events, and a React/Three.js Inertia page.

The implementation is complete when:

- The 500-bed GLB model loads from a stable Laravel-served asset path.
- Facility model metadata is imported into `hosp_ingest` and `hosp_space`.
- Synthetic HL7/message data can be imported idempotently into Zephyrus tables.
- `/api/patient-flow/*` endpoints replace the standalone Python server API.
- The 4D navigator runs inside Zephyrus without CDN import maps or direct DOM-only app bootstrapping.
- Authenticated users can reach the navigator from RTDC and/or ED navigation.
- Tests verify parsing, imports, API contracts, frontend state projection, build, and WebGL render smoke.

## Status Legend

- `[ ]` Not started
- `[~]` In progress
- `[x]` Complete
- `[!]` Blocked or needs decision

## Critical Constraints

- [ ] Do not revert the current root-level `hospital-cad-model/` deletions unless explicitly requested.
- [ ] Do not treat `patient-flow-4d-navigator/` as production-served web root.
- [ ] Do not expose raw HL7/FHIR payloads to the browser.
- [ ] Do not store production credentials directly in app config or DB rows.
- [ ] Do not run `500-bed-tier1-trauma1-academic-medical-center.ddl.sql` wholesale in the first slice.
- [ ] Preserve existing Zephyrus `hosp_ingest`, `hosp_space`, `raw`, `integration`, and `fhir` ownership boundaries.
- [ ] Keep changes scoped to patient-flow navigator integration and required supporting assets.

## Phase 0: Pre-Implementation Snapshot

### 0.1 Worktree And Baseline

- [ ] Run `git status --short`.
- [ ] Confirm known existing state:
  - [ ] root `hospital-cad-model/` paths show as deleted.
  - [ ] `patient-flow-4d-navigator/` is untracked.
  - [ ] integration plan file exists.
- [ ] Record whether user wants to keep the nested demo directory, relocate it under `docs/research`, or only extract runtime assets.
- [ ] Run `php artisan --version`.
- [ ] Run `php artisan migrate:status` or project-equivalent migration status command.
- [ ] Run `node --version`.
- [ ] Run `npm --version`.
- [ ] Run `composer --version`.
- [ ] Confirm `vendor/` and `node_modules/` are available.
- [ ] Confirm local database connection is available with `php artisan tinker` or `php artisan migrate:status`.

### 0.2 Demo Reproduction

- [ ] Run Python parser tests:

```bash
cd patient-flow-4d-navigator
python3 test_flow_engine.py
```

- [ ] Confirm expected result: `OK`.
- [ ] Run generator to reproduce current path issue:

```bash
cd patient-flow-4d-navigator
python3 generate_synthetic_flow.py
```

- [ ] Confirm current failure points to missing root-level `hospital-cad-model/data/model_catalog.json`.
- [ ] Start standalone server only for parity checks:

```bash
cd patient-flow-4d-navigator
python3 server.py --host 127.0.0.1 --port 8876
```

- [ ] In another shell, confirm summary works:

```bash
curl -sS http://127.0.0.1:8876/api/summary
```

- [ ] Confirm GLB path currently fails:

```bash
curl -i -sS http://127.0.0.1:8876/cad-model/model/hospital_model.glb
```

- [ ] Stop the Python server.

### 0.3 Existing Zephyrus Integration Points

- [ ] Re-read `routes/web.php`.
- [ ] Re-read `routes/api.php`.
- [ ] Re-read `app/Services/Facility/ModelCatalogImporter.php`.
- [ ] Re-read `app/Http/Controllers/Api/Facility/FacilityModelController.php`.
- [ ] Re-read `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php`.
- [ ] Re-read `database/migrations/2026_06_25_000030_create_healthcare_integration_foundation_tables.php`.
- [ ] Re-read `resources/js/Contexts/DashboardContext.tsx`.
- [ ] Re-read `resources/js/Pages/ED/Analytics/Flow.jsx`.
- [ ] Re-read `resources/js/Hooks/usePatientFlowData.js`.
- [ ] Re-read `vite.config.js`.
- [ ] Confirm current package dependencies do not include `three`.

## Phase 1: Artifact And Asset Strategy

### 1.1 Decide Canonical Artifact Layout

- [ ] Decide where research/demo source should live.
- [ ] Recommended option:
  - [ ] keep source under `patient-flow-4d-navigator/` temporarily.
  - [ ] copy runtime GLB/3D tiles assets to `public/vendor/zephyrus-facility-models/zep-500/`.
  - [ ] use `facility:import-catalog` to load catalog metadata into DB.
  - [ ] do not serve `patient-flow-4d-navigator/viewer/` in production.
- [ ] Alternative option:
  - [ ] relocate research source to `docs/research/patient-flow-4d-navigator/`.
  - [ ] relocate nested CAD research source to `docs/research/hospital-cad-model/`.
  - [ ] update README paths and verification paths.
- [ ] Document the chosen layout in the integration plan or this TODO if it changes.

### 1.2 Runtime Model Asset Path

- [ ] Create public asset directory:

```text
public/vendor/zephyrus-facility-models/zep-500/
```

- [ ] Copy or move `hospital_model.glb` into that directory.
- [ ] If using 3D Tiles later, copy `tileset.json` too.
- [ ] Preserve model source metadata in docs or import metadata.
- [ ] Confirm asset fetch:

```bash
php artisan serve --port=8001
curl -I http://127.0.0.1:8001/vendor/zephyrus-facility-models/zep-500/hospital_model.glb
```

- [ ] Confirm expected HTTP status is `200`.
- [ ] Confirm content length is nonzero.

### 1.3 Model Asset Configuration

- [ ] Add `config/facility_models.php`.
- [ ] Include `ZEPHYRUS-500` config entry.
- [ ] Include `model_url`.
- [ ] Include optional `tileset_url`.
- [ ] Include `source_catalog_path` or import source metadata if useful.
- [ ] Add config tests if config is consumed by controller.

Suggested config shape:

```php
return [
    'zep_500' => [
        'facility_code' => 'ZEPHYRUS-500',
        'facility_name' => '500-Bed Level I Trauma Academic Medical Center',
        'model_url' => '/vendor/zephyrus-facility-models/zep-500/hospital_model.glb',
        'tileset_url' => '/vendor/zephyrus-facility-models/zep-500/tileset.json',
    ],
];
```

### 1.4 Optional Demo Path Repair

- [ ] If keeping Python demo runnable, patch `generate_synthetic_flow.py`.
- [ ] If keeping Python demo runnable, patch `server.py`.
- [ ] Use path resolution that checks nested path first:
  - [ ] `ROOT / "hospital-cad-model"`
  - [ ] fallback to `ROOT.parent / "hospital-cad-model"`
- [ ] Update `README.md` commands if path changes.
- [ ] Re-run `python3 generate_synthetic_flow.py`.
- [ ] Re-run `python3 server.py --port 8876`.
- [ ] Confirm GLB now returns `200` from the standalone server if this path is repaired.

## Phase 2: Dependency And Build Setup

### 2.1 Add Three.js

- [ ] Add runtime dependency:

```bash
npm install three
```

- [ ] Add type dependency:

```bash
npm install -D @types/three
```

- [ ] Confirm `package.json` includes both entries.
- [ ] Confirm `package-lock.json` updates cleanly.
- [ ] Run:

```bash
npm run build
```

- [ ] If importing GLB through Vite, update `vite.config.js`:

```js
assetsInclude: ['**/*.svg', '**/*.glb']
```

- [ ] If serving GLB from `public/`, do not add GLB to `assetsInclude` unless another import path needs it.

### 2.2 Frontend Type Boundaries

- [ ] Confirm TypeScript strictness expectations in repo.
- [ ] Create feature directory:

```text
resources/js/features/patientFlowNavigator/
```

- [ ] Create `types.ts`.
- [ ] Create `api.ts`.
- [ ] Create `hooks.ts`.
- [ ] Avoid mixing navigator contracts into the existing mock `usePatientFlowData.js`.

## Phase 3: Facility Model Import

### 3.1 Migration Precheck

- [ ] Confirm `hosp_ref`, `hosp_ingest`, and `hosp_space` migrations exist.
- [ ] Confirm `prod.units` and `prod.beds` have nullable `facility_space_id` after migration.
- [ ] Confirm `FacilityImportCatalogCommand` is registered by Laravel auto-discovery.
- [ ] Run:

```bash
php artisan list | rg facility:import-catalog
```

### 3.2 Import Catalog

- [ ] Run:

```bash
php artisan facility:import-catalog patient-flow-4d-navigator/hospital-cad-model/data/model_catalog.json \
  --facility-code=ZEPHYRUS-500 \
  --facility-name="500-Bed Level I Trauma Academic Medical Center" \
  --source-name=patient-flow-4d-navigator-catalog \
  --map-operational
```

- [ ] Record output:
  - [ ] import ID
  - [ ] checksum
  - [ ] object count
  - [ ] facility-space count
  - [ ] operational units created
  - [ ] operational beds created
  - [ ] operational maps created
  - [ ] conflicts

### 3.3 Facility Summary Verification

- [ ] Start Laravel server if not running:

```bash
php artisan serve --port=8001
```

- [ ] Authenticate in browser if needed.
- [ ] Fetch:

```bash
curl -sS http://127.0.0.1:8001/api/facility/model/summary?facility_code=ZEPHYRUS-500
```

- [ ] If API requires session auth, verify through browser devtools or a feature test instead.
- [ ] Confirm latest import exists.
- [ ] Confirm object totals are close to 1,472.
- [ ] Confirm bed mappings are close to 500 if `--map-operational` is enabled.
- [ ] Confirm no unexpected conflicts.

### 3.4 Facility Import Tests

- [ ] Add or extend `tests/Feature/FacilityModelSchemaTest.php`.
- [ ] Add small catalog fixture if full catalog is too large for test.
- [ ] Test importer idempotency by importing same fixture twice.
- [ ] Test category creation for unknown categories if needed.
- [ ] Test facility summary includes latest import metadata.
- [ ] Test operational map target constraint remains valid.

## Phase 4: Flow Event Schema

### 4.1 Migration File

- [ ] Create migration:

```text
database/migrations/2026_06_25_000040_create_patient_flow_navigator_tables.php
```

- [ ] Use `SafeMigration` trait if matching local project style.
- [ ] Use `DB::unprepared` for cross-schema SQL where needed.
- [ ] Create schema if missing:

```sql
CREATE SCHEMA IF NOT EXISTS flow_core;
CREATE SCHEMA IF NOT EXISTS flow_realtime;
```

### 4.2 `flow_core.patient_identities`

- [ ] Create `flow_core.patient_identities`.
- [ ] Columns:
  - [ ] `patient_ref text primary key`
  - [ ] `patient_display_ref text not null`
  - [ ] `identifier_hash text not null`
  - [ ] `merged_into_patient_ref text null`
  - [ ] `deidentified boolean not null default true`
  - [ ] `metadata jsonb not null default '{}'::jsonb`
  - [ ] timestamps
- [ ] Add self-reference for merged patients if safe.
- [ ] Add index on `identifier_hash`.
- [ ] Add comment clarifying no MRN should be stored here.

### 4.3 `flow_core.encounters`

- [ ] Create `flow_core.encounters`.
- [ ] Columns:
  - [ ] `encounter_ref text primary key`
  - [ ] `patient_ref text not null references flow_core.patient_identities`
  - [ ] `patient_class text null`
  - [ ] `service_line text null`
  - [ ] `encounter_status text not null default 'in-progress'`
  - [ ] `started_at timestamptz null`
  - [ ] `ended_at timestamptz null`
  - [ ] `prod_encounter_id bigint null references prod.encounters`
  - [ ] `metadata jsonb not null default '{}'::jsonb`
  - [ ] timestamps
- [ ] Add index on `(patient_ref, started_at)`.
- [ ] Add index on `prod_encounter_id` where nonnull.

### 4.4 `flow_core.flow_events`

- [ ] Create `flow_core.flow_events`.
- [ ] Columns:
  - [ ] `flow_event_id text primary key`
  - [ ] `source_id bigint null references integration.sources`
  - [ ] `inbound_message_id bigint null references raw.inbound_messages`
  - [ ] `canonical_event_id bigint null references integration.canonical_events`
  - [ ] `event_category text not null`
  - [ ] `event_type text not null`
  - [ ] `message_type text null`
  - [ ] `trigger_event text null`
  - [ ] `patient_ref text not null references flow_core.patient_identities`
  - [ ] `patient_display_ref text not null`
  - [ ] `encounter_ref text null references flow_core.encounters`
  - [ ] `occurred_at timestamptz not null`
  - [ ] `recorded_at timestamptz not null default now()`
  - [ ] `from_source_location_code text null`
  - [ ] `to_source_location_code text null`
  - [ ] `from_facility_space_id bigint null references hosp_space.facility_spaces`
  - [ ] `to_facility_space_id bigint null references hosp_space.facility_spaces`
  - [ ] `point_of_care text null`
  - [ ] `room text null`
  - [ ] `bed text null`
  - [ ] `patient_class text null`
  - [ ] `fhir_encounter_status text null`
  - [ ] `fhir_encounter_class text null`
  - [ ] `service_line text null`
  - [ ] `priority text null`
  - [ ] `diagnosis_codes text[] not null default ARRAY[]::text[]`
  - [ ] `order_codes text[] not null default ARRAY[]::text[]`
  - [ ] `observation_codes text[] not null default ARRAY[]::text[]`
  - [ ] `medication_codes text[] not null default ARRAY[]::text[]`
  - [ ] `cancellation_of_event_id text null`
  - [ ] `raw_message_hash text null`
  - [ ] `source_protocol text not null default 'hl7v2'`
  - [ ] `deidentified boolean not null default true`
  - [ ] `metadata jsonb not null default '{}'::jsonb`
  - [ ] timestamps
- [ ] Add FK for `cancellation_of_event_id` to `flow_core.flow_events`.
- [ ] Add indexes:
  - [ ] `occurred_at`
  - [ ] `(patient_ref, occurred_at)`
  - [ ] `(encounter_ref, occurred_at)`
  - [ ] `(to_facility_space_id, occurred_at)`
  - [ ] `(event_category, event_type)`
  - [ ] GIN `metadata`
  - [ ] unique or index on `inbound_message_id` if one inbound message maps to one flow event in this slice.
- [ ] Add comments clarifying this is the navigator projection, not raw source truth.

### 4.5 `flow_core.fhir_bundle_cache`

- [ ] Create cache table only if useful in first slice.
- [ ] Columns:
  - [ ] identity PK
  - [ ] `flow_event_id text references flow_core.flow_events on delete cascade`
  - [ ] `bundle_type text not null`
  - [ ] `generated_at timestamptz not null default now()`
  - [ ] `bundle_json jsonb not null`
- [ ] Unique index on `(flow_event_id, bundle_type)`.

### 4.6 `flow_core.occupancy_snapshots`

- [ ] Create occupancy snapshot table.
- [ ] Columns:
  - [ ] identity PK
  - [ ] `snapshot_at timestamptz not null`
  - [ ] `facility_space_id bigint references hosp_space.facility_spaces`
  - [ ] `active_patient_count integer not null`
  - [ ] `service_line_counts jsonb not null default '{}'::jsonb`
  - [ ] `acuity_counts jsonb not null default '{}'::jsonb`
  - [ ] `generated_from_event_id text null references flow_core.flow_events`
  - [ ] timestamps
- [ ] Unique index on `(snapshot_at, facility_space_id)`.
- [ ] Index on `(facility_space_id, snapshot_at)`.

### 4.7 `flow_realtime` Tables

- [ ] Create `flow_realtime.subscription_clients`.
- [ ] Create `flow_realtime.delivery_cursors`.
- [ ] Keep these minimal for first slice if SSE replay does not persist cursors.
- [ ] Include comments that WebSocket/Echo migration may supersede them.

### 4.8 Migration Down Method

- [ ] Down method should use safe drops only in local environment, matching repo convention.
- [ ] Drop dependent tables in correct order:
  - [ ] delivery cursors
  - [ ] subscription clients
  - [ ] occupancy snapshots
  - [ ] fhir bundle cache
  - [ ] flow events
  - [ ] encounters
  - [ ] patient identities
- [ ] Do not drop shared `raw`, `integration`, `hosp_space`, or `fhir` schemas.

### 4.9 Schema Tests

- [ ] Add `tests/Feature/PatientFlowSchemaTest.php`.
- [ ] Assert schemas exist.
- [ ] Assert core tables exist.
- [ ] Insert one patient identity.
- [ ] Insert one encounter.
- [ ] Insert one flow event.
- [ ] Assert event can reference a facility space if fixture is available.
- [ ] Assert array columns round-trip.
- [ ] Assert metadata JSON round-trips.

## Phase 5: Eloquent Models And DTOs

### 5.1 Models

- [ ] Create `app/Models/PatientFlow/PatientIdentity.php`.
- [ ] Create `app/Models/PatientFlow/FlowEncounter.php`.
- [ ] Create `app/Models/PatientFlow/FlowEvent.php`.
- [ ] Create `app/Models/PatientFlow/FhirBundleCache.php`.
- [ ] Create `app/Models/PatientFlow/OccupancySnapshot.php`.
- [ ] Set table names with schema prefixes.
- [ ] Set primary keys.
- [ ] Disable incrementing where PK is text.
- [ ] Define fillable or guarded consistently.
- [ ] Cast arrays/JSON/datetimes.
- [ ] Add relationships:
  - [ ] event -> patient identity
  - [ ] event -> encounter
  - [ ] event -> source
  - [ ] event -> inbound message
  - [ ] event -> canonical event
  - [ ] event -> from facility space
  - [ ] event -> to facility space

### 5.2 DTOs

- [ ] Create `app/Services/PatientFlow/Data/Hl7MessageData.php`.
- [ ] Create `app/Services/PatientFlow/Data/Hl7LocationData.php`.
- [ ] Create `app/Services/PatientFlow/Data/FlowEventData.php`.
- [ ] Create `app/Services/PatientFlow/Data/PatientStateData.php`.
- [ ] Use plain immutable classes or simple arrays depending on local style.
- [ ] Add `toArray()` for API and persistence conversion.

## Phase 6: PHP Flow Engine Port

### 6.1 Parser Service

- [ ] Create `app/Services/PatientFlow/Hl7V2MessageParser.php`.
- [ ] Implement raw cleanup:
  - [ ] strip MLLP wrapper characters.
  - [ ] split on `\r\n`, `\n`, or `\r`.
  - [ ] ignore empty lines.
- [ ] Implement segment lookup:
  - [ ] `first($segment)`
  - [ ] `all($segment)`
  - [ ] `field($segment, $fieldNumber, $component = null)`
- [ ] Implement MSH special field indexing matching Python behavior.
- [ ] Implement message type extraction.
- [ ] Implement trigger event extraction.
- [ ] Add unit tests for MSH indexing.

### 6.2 Timestamp Service

- [ ] Create `app/Services/PatientFlow/Hl7TimestampParser.php` or methods on parser.
- [ ] Parse `YYYY`.
- [ ] Parse `YYYYMM`.
- [ ] Parse `YYYYMMDD`.
- [ ] Parse `YYYYMMDDHH`.
- [ ] Parse `YYYYMMDDHHMM`.
- [ ] Parse `YYYYMMDDHHMMSS`.
- [ ] Normalize to UTC ISO/datetimes.
- [ ] Decide handling for timezone offsets if present in real messages.
- [ ] Match Python fallback behavior for invalid values where appropriate.

### 6.3 Location Parser

- [ ] Create `Hl7LocationData`.
- [ ] Parse PL fields:
  - [ ] point of care
  - [ ] room
  - [ ] bed
  - [ ] facility
- [ ] Implement `locationCode()`:
  - [ ] bed if present
  - [ ] else room
  - [ ] else point of care
  - [ ] else `UNKNOWN`
- [ ] Implement `toHl7()`.
- [ ] Unit test assigned and prior location extraction.

### 6.4 Event Normalizer

- [ ] Create `FlowEventNormalizer`.
- [ ] Port event maps:
  - [ ] ADT movement types
  - [ ] message categories
  - [ ] FHIR Encounter status by event
  - [ ] patient class to FHIR class
- [ ] Implement stable hash with SHA-256 truncation.
- [ ] Implement event ID generation.
- [ ] Extract:
  - [ ] source system
  - [ ] message control ID
  - [ ] occurred timestamp
  - [ ] recorded timestamp
  - [ ] patient ref
  - [ ] patient display ref
  - [ ] encounter ref
  - [ ] assigned location
  - [ ] prior location
  - [ ] patient class
  - [ ] attending provider hash or source provider ref
  - [ ] service line
  - [ ] priority
  - [ ] diagnosis codes
  - [ ] order codes
  - [ ] observation codes
  - [ ] medication codes
  - [ ] raw message hash
  - [ ] metadata
- [ ] Ensure provider identifiers are hashed or excluded if needed.
- [ ] Add site/location enrichment hook.

### 6.5 Facility Location Resolver

- [ ] Create `FacilitySpaceLocationResolver`.
- [ ] Resolve source location code to `hosp_space.facility_spaces`.
- [ ] Matching priority:
  - [ ] exact `geometry->source_object_code`
  - [ ] exact `attributes->source_object_code`
  - [ ] facility-prefixed `space_code`
  - [ ] bed label through `prod.beds` if needed
  - [ ] room code fallback
- [ ] Return:
  - [ ] facility space ID
  - [ ] display name
  - [ ] category
  - [ ] floor
  - [ ] position feet
  - [ ] position meters
  - [ ] service line
  - [ ] unit code
  - [ ] metadata
- [ ] Cache location lookup per request/import run.
- [ ] Unit test exact bed code lookup.
- [ ] Unit test ED room code lookup.
- [ ] Unit test unknown location behavior.

### 6.6 FHIR Bundle Factory

- [ ] Create `FhirBundleFactory`.
- [ ] Generate Bundle type `message`.
- [ ] Generate Encounter resource.
- [ ] Generate Patient resource.
- [ ] Generate Location resource.
- [ ] Use hashed patient ref only.
- [ ] Use facility space source code for location ID where possible.
- [ ] Add test for resource types.
- [ ] Add test for status/class mapping.

### 6.7 State And Occupancy Projectors

- [ ] Create `PatientStateProjector`.
- [ ] Sort events by `occurred_at`.
- [ ] Remove active patient on `discharge`.
- [ ] Remove active patient on `cancel_admit`.
- [ ] Update patient location when event has `to_location`.
- [ ] Decide handling for clinical-context events that have same location.
- [ ] Match demo behavior initially:
  - [ ] movement, order, observation, medication, schedule can update active state if they have a location.
- [ ] Create `OccupancyProjector`.
- [ ] Count active patients by location.
- [ ] Optionally count by service line.
- [ ] Unit test admit -> active.
- [ ] Unit test admit -> discharge -> inactive.
- [ ] Unit test transfer changes location.

## Phase 7: Synthetic Import Command

### 7.1 Command Skeleton

- [ ] Create command:

```text
app/Console/Commands/PatientFlowImportSyntheticCommand.php
```

- [ ] Signature:

```text
patient-flow:import-synthetic
  {path : Path to hl7_messages.ndjson or normalized_events.ndjson}
  {--source-key=synthetic-flow-ehr}
  {--facility-code=ZEPHYRUS-500}
  {--from-normalized : Import normalized event NDJSON instead of raw HL7 message NDJSON}
  {--dry-run : Parse and report without writing}
```

- [ ] Description: import synthetic patient-flow navigator data.
- [ ] Register through auto-discovery or kernel if needed.

### 7.2 Source Setup

- [ ] Upsert `integration.sources`.
- [ ] Use:
  - [ ] `source_key = synthetic-flow-ehr`
  - [ ] `source_name = Synthetic Flow EHR`
  - [ ] `system_class = ehr`
  - [ ] `interface_type = hl7v2_file`
  - [ ] `environment = sandbox`
  - [ ] `phi_allowed = false`
  - [ ] `active_status = active`
  - [ ] `go_live_status = demo`
- [ ] Confirm source upsert is idempotent.

### 7.3 Ingest Run

- [ ] Create `raw.ingest_runs` at start.
- [ ] Set:
  - [ ] connector key
  - [ ] run type
  - [ ] status `running`
  - [ ] cursor metadata
- [ ] Increment counts:
  - [ ] received
  - [ ] succeeded
  - [ ] failed
  - [ ] skipped
- [ ] Mark completed or failed.
- [ ] Persist error summary if any.

### 7.4 Raw Message Import

- [ ] Read NDJSON line by line.
- [ ] For `hl7_messages.ndjson`, parse each row:
  - [ ] `message_id`
  - [ ] `occurred_at`
  - [ ] `message_type`
  - [ ] `trigger_event`
  - [ ] `raw_hl7`
- [ ] Create idempotency key:
  - [ ] source ID plus message control ID or raw hash.
- [ ] Insert `raw.inbound_messages`.
- [ ] On duplicate, skip or update parse status intentionally.
- [ ] Store raw payload only for synthetic/deidentified feed.
- [ ] Store payload hash.
- [ ] Store normalized payload after parser runs.

### 7.5 Canonical Event Import

- [ ] Insert `integration.canonical_events` for each normalized event.
- [ ] Use deterministic UUID or generated UUID plus unique idempotency key.
- [ ] Set:
  - [ ] event type
  - [ ] entity type `encounter`
  - [ ] entity ref
  - [ ] occurred at
  - [ ] received at
  - [ ] payload
  - [ ] payload hash
  - [ ] correlation ID
  - [ ] sequence key
  - [ ] projection status
- [ ] Handle duplicates idempotently.

### 7.6 Flow Projection Import

- [ ] Upsert patient identity.
- [ ] Upsert flow encounter.
- [ ] Upsert flow event.
- [ ] Resolve to/from facility spaces.
- [ ] Enrich event response fields through facility space metadata.
- [ ] Keep original source location code.
- [ ] Handle missing facility-space mapping without failing entire import.
- [ ] Track unmapped location count.
- [ ] Track duplicate event count.

### 7.7 Import Summary Output

- [ ] Print:
  - [ ] source ID
  - [ ] ingest run ID
  - [ ] rows read
  - [ ] raw messages inserted
  - [ ] raw messages skipped
  - [ ] canonical events inserted
  - [ ] flow events inserted
  - [ ] flow events skipped
  - [ ] patients
  - [ ] encounters
  - [ ] mapped locations
  - [ ] unmapped locations
  - [ ] min occurred at
  - [ ] max occurred at
- [ ] Exit nonzero on fatal parse/import failures.

### 7.8 Import Tests

- [ ] Add small `tests/Fixtures/patient_flow/hl7_messages.ndjson`.
- [ ] Add feature test for command success.
- [ ] Assert ingest run is completed.
- [ ] Assert raw message count.
- [ ] Assert canonical event count.
- [ ] Assert flow event count.
- [ ] Assert re-running command does not duplicate.
- [ ] Assert `--dry-run` writes nothing.
- [ ] Assert invalid path fails cleanly.

## Phase 8: Patient Flow API

### 8.1 Controller Structure

- [ ] Create namespace:

```text
app/Http/Controllers/Api/PatientFlow/
```

- [ ] Create `PatientFlowController`.
- [ ] Create `PatientFlowIngestController`.
- [ ] Create `PatientFlowStreamController`.
- [ ] Create request classes if filters need validation.

### 8.2 Route Registration

- [ ] Add browser API routes to `routes/api.php`:

```php
Route::middleware(['web', 'auth', 'throttle:60,1'])
    ->prefix('patient-flow')
    ->group(function () {
        Route::get('/summary', [PatientFlowController::class, 'summary']);
        Route::get('/locations', [PatientFlowController::class, 'locations']);
        Route::get('/events', [PatientFlowController::class, 'events']);
        Route::get('/tracks', [PatientFlowController::class, 'tracks']);
        Route::get('/state', [PatientFlowController::class, 'state']);
        Route::get('/fhir/bundle', [PatientFlowController::class, 'fhirBundle']);
        Route::get('/stream/adt', PatientFlowStreamController::class);
    });
```

- [ ] Add ingest route separately.
- [ ] Keep ingest disabled or token-protected for first slice if production auth is not ready.

### 8.3 Summary Endpoint

- [ ] Implement `GET /api/patient-flow/summary`.
- [ ] Return:
  - [ ] messages count
  - [ ] normalized events count
  - [ ] patient count
  - [ ] location count
  - [ ] movement event count
  - [ ] clinical context event count
  - [ ] min occurred at
  - [ ] max occurred at
  - [ ] live events count
  - [ ] facility code
  - [ ] model URL
  - [ ] data freshness timestamp
- [ ] Handle empty DB.
- [ ] Add feature test.

### 8.4 Locations Endpoint

- [ ] Implement `GET /api/patient-flow/locations`.
- [ ] Return object keyed by source location code.
- [ ] Include:
  - [ ] location code
  - [ ] facility space ID
  - [ ] name
  - [ ] category
  - [ ] floor
  - [ ] unit code
  - [ ] service line
  - [ ] position feet
  - [ ] position meters
  - [ ] metadata
- [ ] Source from `hosp_space.facility_spaces`.
- [ ] Include only active/relevant model locations by default:
  - [ ] bed
  - [ ] bay
  - [ ] room
  - [ ] procedure room
  - [ ] imaging
  - [ ] support if used by patient tracks
- [ ] Add query option for all spaces if useful.
- [ ] Add feature test.

### 8.5 Events Endpoint

- [ ] Implement `GET /api/patient-flow/events`.
- [ ] Support filters:
  - [ ] `from`
  - [ ] `to`
  - [ ] `patient`
  - [ ] `category`
  - [ ] `service_line`
  - [ ] `floor`
  - [ ] `limit`
- [ ] Cap `limit` to a safe max.
- [ ] Return sorted by occurred at.
- [ ] Include demo-compatible fields:
  - [ ] `event_id`
  - [ ] `event_category`
  - [ ] `event_type`
  - [ ] `patient_id`
  - [ ] `patient_display_id`
  - [ ] `encounter_id`
  - [ ] `occurred_at`
  - [ ] `recorded_at`
  - [ ] `from_location`
  - [ ] `to_location`
  - [ ] `location_name`
  - [ ] `location_category`
  - [ ] `location_floor`
  - [ ] `position_ft`
  - [ ] `position_m`
  - [ ] `service_line`
  - [ ] `unit_code`
  - [ ] codes arrays
  - [ ] metadata
- [ ] Add feature tests for each filter.

### 8.6 Tracks Endpoint

- [ ] Implement `GET /api/patient-flow/tracks`.
- [ ] Reuse event filters.
- [ ] Group events by patient ref.
- [ ] Sort each track.
- [ ] Cap total response size.
- [ ] Add feature test.

### 8.7 State Endpoint

- [ ] Implement `GET /api/patient-flow/state?asOf=`.
- [ ] Use `PatientStateProjector`.
- [ ] Return:
  - [ ] `asOf`
  - [ ] `activePatients`
  - [ ] `patients`
  - [ ] `occupancy`
- [ ] Add test for latest state.
- [ ] Add test for as-of before first event.
- [ ] Add test for after discharge.

### 8.8 FHIR Bundle Endpoint

- [ ] Implement `GET /api/patient-flow/fhir/bundle?event_id=`.
- [ ] Return 404 if event missing.
- [ ] Generate bundle from `FhirBundleFactory`.
- [ ] Optionally cache result.
- [ ] Add feature test.

### 8.9 Ingest Endpoint

- [ ] Implement `POST /api/patient-flow/ingest/hl7v2`.
- [ ] Accept raw text body.
- [ ] Accept JSON `{ "raw_hl7": "..." }`.
- [ ] Validate nonempty body.
- [ ] Authenticate with configured token or mark disabled in nonlocal.
- [ ] Normalize and persist through same service path as command.
- [ ] Return `202` with normalized event.
- [ ] Return `400` on parse/validation error.
- [ ] Add tests for raw text, JSON, empty body, duplicate, and auth failure.

## Phase 9: SSE Replay/Live Stream

### 9.1 Stream Endpoint

- [ ] Implement invokable `PatientFlowStreamController`.
- [ ] Accept query:
  - [ ] `replay`
  - [ ] `interval`
  - [ ] optional filters
- [ ] Cap replay count.
- [ ] Enforce minimum interval.
- [ ] Set headers:
  - [ ] `Content-Type: text/event-stream`
  - [ ] `Cache-Control: no-cache`
  - [ ] `Connection: keep-alive`
- [ ] Send initial heartbeat comment.
- [ ] Emit events as:

```text
id: <flow_event_id>
event: patient-flow
data: <json>
```

- [ ] Flush after each event.
- [ ] Stop loop on connection abort.
- [ ] Do not stream raw payloads.

### 9.2 Stream Tests

- [ ] Add feature test for response headers if practical.
- [ ] Add service-level test for payload serialization.
- [ ] Manually test with:

```bash
curl -N 'http://127.0.0.1:8001/api/patient-flow/stream/adt?replay=5&interval=0.05'
```

- [ ] Browser-test live toggle.

### 9.3 Future WebSocket Decision

- [ ] Document when to switch from SSE to Echo/WebSockets.
- [ ] Criteria:
  - [ ] real event volume exceeds simple replay needs.
  - [ ] bidirectional collaboration needed.
  - [ ] long-lived SSE worker cost becomes unacceptable.

## Phase 10: React Feature Client

### 10.1 Types

- [ ] Implement `resources/js/features/patientFlowNavigator/types.ts`.
- [ ] Define `PatientFlowSummary`.
- [ ] Define `PatientFlowLocation`.
- [ ] Define `PatientFlowEvent`.
- [ ] Define `PatientFlowTrackMap`.
- [ ] Define `PatientFlowState`.
- [ ] Define `PatientFlowFilters`.
- [ ] Define `PatientFlowLayerState`.
- [ ] Define `PatientFlowInspectorSelection`.

### 10.2 API Client

- [ ] Implement `api.ts` with axios.
- [ ] Functions:
  - [ ] `getPatientFlowSummary`
  - [ ] `getPatientFlowLocations`
  - [ ] `getPatientFlowEvents`
  - [ ] `getPatientFlowTracks`
  - [ ] `getPatientFlowState`
  - [ ] `getPatientFlowFhirBundle`
  - [ ] `createPatientFlowEventSource`
- [ ] Keep URL constants in one place.
- [ ] Serialize filters carefully.
- [ ] Add error normalization.

### 10.3 Hooks

- [ ] Implement `usePatientFlowNavigatorBootstrap`.
- [ ] Implement `usePatientFlowFilters`.
- [ ] Implement `usePatientFlowTimeline`.
- [ ] Implement `usePatientFlowStream`.
- [ ] Implement cleanup for EventSource.
- [ ] Add loading, error, and retry state.
- [ ] Add tests for hooks where practical.

## Phase 11: React/Three.js Scene Conversion

### 11.1 Component Structure

- [ ] Create directory:

```text
resources/js/Components/PatientFlowNavigator/
```

- [ ] Create `PatientFlowNavigator.tsx`.
- [ ] Create `PatientFlowScene.tsx`.
- [ ] Create `PatientFlowToolbar.tsx`.
- [ ] Create `PatientFlowInspector.tsx`.
- [ ] Create `PatientFlowEventFeed.tsx`.
- [ ] Create `PatientFlowStatusBar.tsx`.
- [ ] Create `PatientFlowMetricsStrip.tsx` if useful.
- [ ] Create `scene/stateProjection.ts`.
- [ ] Create `scene/materials.ts`.
- [ ] Create `scene/threeLifecycle.ts`.
- [ ] Create `scene/types.ts`.

### 11.2 Scene Lifecycle

- [ ] Initialize renderer from canvas ref.
- [ ] Set pixel ratio cap.
- [ ] Set renderer size.
- [ ] Set `outputColorSpace`.
- [ ] Create scene and background/fog.
- [ ] Create perspective camera.
- [ ] Create OrbitControls.
- [ ] Add hemisphere light.
- [ ] Add directional light.
- [ ] Add grid helper.
- [ ] Add groups:
  - [ ] heat layer
  - [ ] trail layer
  - [ ] patient layer
- [ ] Load GLB with `GLTFLoader`.
- [ ] Traverse meshes and store base object refs.
- [ ] Clone materials before changing opacity.
- [ ] Dispose geometry/materials on unmount.
- [ ] Dispose renderer on unmount.
- [ ] Cancel animation frame on unmount.
- [ ] Remove resize listeners on unmount.

### 11.3 State Projection Helpers

- [ ] Port `parseTime`.
- [ ] Port `fmtTime` or use date-fns/browser locale.
- [ ] Port `hashColor`.
- [ ] Port patient material cache.
- [ ] Port `positionFor`.
- [ ] Port `rebuildTracks`.
- [ ] Port filter matcher.
- [ ] Port `patientStatesAt`.
- [ ] Port transfer interpolation.
- [ ] Port occupancy calculation.
- [ ] Add unit tests for:
  - [ ] track rebuild
  - [ ] active state at time
  - [ ] discharge removal
  - [ ] floor filter
  - [ ] service filter
  - [ ] category filter
  - [ ] search filter

### 11.4 Rendering Features

- [ ] Render patient tokens.
- [ ] Render trails.
- [ ] Render occupancy/census markers.
- [ ] Toggle base model layer.
- [ ] Toggle patient token layer.
- [ ] Toggle trail layer.
- [ ] Toggle census heat layer.
- [ ] Update metrics:
  - [ ] active patients
  - [ ] events elapsed
  - [ ] occupied locations
- [ ] Add raycaster selection:
  - [ ] patient token
  - [ ] occupancy marker
  - [ ] base model mesh
- [ ] Populate inspector from selected object.
- [ ] Add focus active patients camera action.
- [ ] Add reset camera action.
- [ ] Add resize handling.

### 11.5 Toolbar And Controls

- [ ] Build compact fixed toolbar or app layout-integrated controls.
- [ ] Controls:
  - [ ] play/pause icon button
  - [ ] live stream icon button
  - [ ] reset camera icon button
  - [ ] focus active patients icon button
  - [ ] time slider
  - [ ] floor select
  - [ ] service select
  - [ ] category select
  - [ ] speed select
  - [ ] search input
  - [ ] model layer checkbox
  - [ ] patients layer checkbox
  - [ ] trails layer checkbox
  - [ ] census layer checkbox
- [ ] Use `lucide-react` icons.
- [ ] Use accessible labels/titles.
- [ ] Keep button dimensions stable.
- [ ] Avoid nested cards.
- [ ] Ensure text fits on mobile.

### 11.6 Live Stream UI

- [ ] Connect EventSource on live toggle.
- [ ] Close EventSource on live toggle off.
- [ ] Close EventSource on component unmount.
- [ ] Add incoming event to event list.
- [ ] Sort events by occurred time.
- [ ] Rebuild tracks.
- [ ] Advance current time to max event time.
- [ ] Add feed entry.
- [ ] Show reconnecting/error status.
- [ ] Avoid duplicate stream events by event ID.

### 11.7 Loading And Error States

- [ ] Show loading status for:
  - [ ] summary
  - [ ] locations
  - [ ] events
  - [ ] model
- [ ] Show API error state.
- [ ] Show model load error state.
- [ ] Show WebGL unavailable message if renderer fails.
- [ ] Provide retry action for API bootstrap.
- [ ] Preserve page shell even if model fails.

### 11.8 Mobile Responsiveness

- [ ] Test at 390x844.
- [ ] Toolbar max-height below half viewport.
- [ ] Hide or compact event feed on mobile.
- [ ] Inspector occupies bottom region without covering all controls.
- [ ] Status bar does not overlap buttons.
- [ ] Canvas remains full bleed.
- [ ] No text overflow in controls.

## Phase 12: Inertia Pages And Navigation

### 12.1 RTDC Navigator Page

- [ ] Create page:

```text
resources/js/Pages/RTDC/PatientFlowNavigator.tsx
```

- [ ] Use `Head`.
- [ ] Render navigator component.
- [ ] Decide whether to include `DashboardLayout`.
- [ ] If full-bleed page conflicts with `DashboardLayout` padding, create a minimal route page with top nav and full-bleed canvas area.
- [ ] Pass `facilityCode` prop from controller.
- [ ] Pass optional initial filters.

### 12.2 Controller Route

- [ ] Add method to `RTDCDashboardController`:

```php
public function patientFlowNavigator()
{
    return Inertia::render('RTDC/PatientFlowNavigator', [
        'workflow' => 'rtdc',
        'facilityCode' => 'ZEPHYRUS-500',
    ]);
}
```

- [ ] Add route:

```php
Route::get('/rtdc/patient-flow-navigator', [RTDCDashboardController::class, 'patientFlowNavigator'])
    ->name('rtdc.patient-flow-navigator');
```

### 12.3 ED Flow Route

- [ ] Replace `resources/js/Pages/ED/Analytics/Flow.jsx` placeholder.
- [ ] Option A:
  - [ ] Render `PatientFlowNavigator` with ED-focused filters.
- [ ] Option B:
  - [ ] Render ED analytics page with launch/open navigator link.
- [ ] Recommended first slice: render the navigator with initial ED filter defaults.
- [ ] Set workflow prop to `emergency` in controller if needed.

### 12.4 Navigation Updates

- [ ] Update `resources/js/Contexts/DashboardContext.tsx`.
- [ ] Add RTDC operations item:
  - [ ] name: `Patient Flow Navigator`
  - [ ] href: `/rtdc/patient-flow-navigator`
  - [ ] description
  - [ ] icon
- [ ] Fix stale superuser `/operations/patient-flow`.
- [ ] Either:
  - [ ] add actual `/operations/patient-flow` route, or
  - [ ] point item to `/rtdc/patient-flow-navigator`.
- [ ] Confirm no new nav link 404s.

## Phase 13: Tests And Verification

### 13.1 PHP Unit Tests

- [ ] `Hl7V2MessageParserTest`
- [ ] `Hl7TimestampParserTest`
- [ ] `Hl7LocationDataTest`
- [ ] `FlowEventNormalizerTest`
- [ ] `FacilitySpaceLocationResolverTest`
- [ ] `FhirBundleFactoryTest`
- [ ] `PatientStateProjectorTest`
- [ ] `OccupancyProjectorTest`

### 13.2 PHP Feature Tests

- [ ] `PatientFlowSchemaTest`
- [ ] `PatientFlowImportSyntheticCommandTest`
- [ ] `PatientFlowSummaryApiTest`
- [ ] `PatientFlowLocationsApiTest`
- [ ] `PatientFlowEventsApiTest`
- [ ] `PatientFlowTracksApiTest`
- [ ] `PatientFlowStateApiTest`
- [ ] `PatientFlowFhirBundleApiTest`
- [ ] `PatientFlowIngestApiTest`
- [ ] `PatientFlowPageTest`

### 13.3 Frontend Unit Tests

- [ ] Add Vitest tests for `stateProjection.ts`.
- [ ] Test track grouping.
- [ ] Test patient state timeline.
- [ ] Test transfer interpolation.
- [ ] Test filters.
- [ ] Test occupancy map.
- [ ] Test API URL serialization.
- [ ] Test EventSource cleanup with mock.

### 13.4 Build Verification

- [ ] Run:

```bash
php artisan test
```

- [ ] Run:

```bash
npm run test
```

- [ ] Run:

```bash
npm run build
```

- [ ] Run:

```bash
git diff --check
```

### 13.5 Playwright Visual Verification

- [ ] Add or adapt Playwright test for `/rtdc/patient-flow-navigator`.
- [ ] Test desktop `1440x900`.
- [ ] Test mobile `390x844`.
- [ ] Assert page is authenticated or use test login helper.
- [ ] Assert canvas exists.
- [ ] Assert canvas has nonbackground pixels.
- [ ] Assert status reaches model loaded.
- [ ] Assert active metric is nonzero after synthetic import.
- [ ] Assert time slider interaction changes event count or time label.
- [ ] Assert floor filter does not crash.
- [ ] Assert live toggle connects and disconnects.
- [ ] Save screenshots under a Zephyrus verification path.

## Phase 14: Data Quality And Observability

### 14.1 Import Quality

- [ ] Track unmapped locations during import.
- [ ] Track duplicate messages.
- [ ] Track parse failures.
- [ ] Track event category counts.
- [ ] Track min/max occurred timestamps.
- [ ] Expose import summary in command output.
- [ ] Optionally persist summary in ingest run metadata.

### 14.2 API Quality

- [ ] Add response metadata:
  - [ ] generated at
  - [ ] facility code
  - [ ] source freshness
  - [ ] event count
- [ ] Add empty-state payloads.
- [ ] Add consistent error responses.
- [ ] Avoid leaking exception messages in production.

### 14.3 Runtime Logging

- [ ] Log ingest failures without raw payload bodies.
- [ ] Log API errors with request ID.
- [ ] Log model config missing errors.
- [ ] Log stream disconnects only at debug level.

## Phase 15: Security And Privacy Hardening

### 15.1 Browser API Safety

- [ ] Browser APIs use `web`, `auth`, and throttle middleware.
- [ ] Responses contain hashed patient refs only.
- [ ] Responses do not include raw HL7.
- [ ] Responses do not include MRN.
- [ ] Responses do not include patient name.
- [ ] Responses do not include DOB.
- [ ] Responses do not include address or phone.
- [ ] Inspector is minimum-necessary.

### 15.2 Ingest Safety

- [ ] Disable external ingest by default outside local/demo until auth is ready.
- [ ] Add source-scoped token config for demo ingest if needed.
- [ ] Validate content length.
- [ ] Validate content type.
- [ ] Reject empty body.
- [ ] Dead-letter parse failures.
- [ ] Do not log raw payload.

### 15.3 Production PHI Readiness

- [ ] Add RBAC policy for patient-level details.
- [ ] Add audit event for patient token inspector if real patient details appear.
- [ ] Add retention policy for raw messages.
- [ ] Add encryption policy for payload storage.
- [ ] Add source credential secret-reference model.
- [ ] Add BAA/contract readiness checks from `integration.sources`.

## Phase 16: Deployment Steps

### 16.1 Local Deployment

- [ ] Apply migrations:

```bash
php artisan migrate
```

- [ ] Import facility catalog.
- [ ] Import synthetic flow data.
- [ ] Start dev server:

```bash
./start-dev.sh
```

- [ ] Open `/rtdc/patient-flow-navigator`.
- [ ] Verify model and patient tokens render.

### 16.2 Staging Deployment

- [ ] Build frontend:

```bash
npm run build
```

- [ ] Deploy assets including GLB.
- [ ] Apply migrations.
- [ ] Run facility import.
- [ ] Run synthetic import if staging demo data is desired.
- [ ] Confirm API health.
- [ ] Confirm page renders with authenticated user.
- [ ] Confirm no raw payloads in logs.

### 16.3 Production Deployment

- [ ] Decide whether feature is behind a flag.
- [ ] Add config:
  - [ ] model URL
  - [ ] facility code
  - [ ] ingest enabled false by default
  - [ ] synthetic demo enabled false by default unless explicitly desired
- [ ] Apply migrations.
- [ ] Deploy frontend and model assets.
- [ ] Import facility model if production demo is enabled.
- [ ] Do not import synthetic patient tracks unless desired for demo environment.
- [ ] Confirm route access is role-appropriate.
- [ ] Monitor logs after release.

## Phase 17: Documentation Updates

- [ ] Update `db/README.md` with flow schemas if new schemas are added.
- [ ] Add `docs/superpowers/specs/2026-06-25-patient-flow-4d-navigator-design.md` if design spec is needed.
- [ ] Update integration plan if final asset layout differs.
- [ ] Add developer runbook:
  - [ ] install dependencies
  - [ ] migrate
  - [ ] import facility catalog
  - [ ] import synthetic data
  - [ ] run page
  - [ ] run tests
- [ ] Add security note for raw payload handling.
- [ ] Add production feature flag note.

## Phase 18: Risk Register

### 18.1 Path Drift

- [ ] Risk: demo paths reference deleted root `hospital-cad-model/`.
- [ ] Mitigation: stable public model asset path plus DB import path.
- [ ] Validation: curl GLB URL and run import command.

### 18.2 Duplicate Facility Location Sources

- [ ] Risk: long-term split between `location_index.json` and `hosp_space`.
- [ ] Mitigation: API locations come from `hosp_space`; JSON only for fixture/backfill.
- [ ] Validation: location endpoint uses DB query, not static JSON.

### 18.3 Parser Mismatch

- [ ] Risk: PHP parser differs from Python demo behavior.
- [ ] Mitigation: parity fixtures and tests.
- [ ] Validation: expected normalized fields match for fixture messages.

### 18.4 PHI Leakage

- [ ] Risk: raw payload or identifiers leak to browser or logs.
- [ ] Mitigation: hashed refs, no raw payload API fields, log redaction.
- [ ] Validation: inspect API payloads and logs.

### 18.5 WebGL Blank Canvas

- [ ] Risk: GLB loads but scene is blank due to camera, path, material, or disposal errors.
- [ ] Mitigation: Playwright pixel checks on desktop/mobile.
- [ ] Validation: nonbackground pixel ratio and model loaded status.

### 18.6 API Payload Size

- [ ] Risk: large event/track payloads slow initial load.
- [ ] Mitigation: limits, compression, filters, pagination or time-window loading.
- [ ] Validation: bootstrap time and payload size measurements.

### 18.7 SSE Worker Cost

- [ ] Risk: long-lived PHP requests consume workers.
- [ ] Mitigation: replay caps, disconnect handling, future Echo/WebSocket migration.
- [ ] Validation: manual disconnect and worker process checks.

## Phase 19: Definition Of Done

- [ ] `three` dependency is installed and build succeeds.
- [ ] GLB is served from stable Laravel public path.
- [ ] Facility catalog is imported with expected object/space counts.
- [ ] Flow schema migration exists and passes tests.
- [ ] PHP parser/normalizer has parity tests.
- [ ] Synthetic import command imports 918 demo events idempotently.
- [ ] Patient-flow API endpoints return expected contracts.
- [ ] SSE endpoint streams normalized events.
- [ ] React navigator renders the model and patient tokens.
- [ ] RTDC navigator route exists and is linked from navigation.
- [ ] ED patient-flow placeholder is replaced or links to the navigator.
- [ ] Backend tests pass.
- [ ] Frontend tests pass.
- [ ] Production build passes.
- [ ] `git diff --check` passes.
- [ ] Playwright desktop and mobile canvas verification passes.
- [ ] No browser API response includes raw HL7 or direct patient identifiers.
- [ ] Documentation/runbook is updated.

## Recommended Implementation Order

1. Resolve asset layout and GLB serving.
2. Add `three` dependency.
3. Import facility catalog and verify existing facility model APIs.
4. Add `flow_core` schema and model tests.
5. Port parser/normalizer and parity tests.
6. Add synthetic import command.
7. Add patient-flow API endpoints.
8. Add SSE stream.
9. Build frontend API/types/hooks.
10. Convert Three.js viewer to React components.
11. Add Inertia route/page/navigation.
12. Replace ED flow placeholder or link to navigator.
13. Run full backend/frontend/build validation.
14. Run Playwright visual verification.
15. Update docs and prepare deploy notes.

