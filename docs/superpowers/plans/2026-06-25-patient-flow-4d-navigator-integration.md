# Patient Flow 4D Navigator Integration Plan

Date: 2026-06-25
Status: Proposed implementation plan
Scope: Integrate `patient-flow-4d-navigator/` demo artifacts into Zephyrus as a first-class Laravel/Inertia patient-flow digital-twin capability.

## 1. Objective

Integrate the Patient Flow 4D Navigator demo into Zephyrus so hospital operators can replay and monitor patient movement on the 500-bed digital-twin facility model from authenticated Zephyrus workflows.

The integrated system should preserve the demo's strongest ideas:

- 3D/4D patient location replay over a hospital GLB model.
- Historical event playback with time slider, speed controls, trails, census heat, filters, search, and inspector.
- Near-real-time event streaming for ADT movement updates.
- HL7 v2 ADT/order/result/medication message normalization.
- FHIR-shaped Encounter/Patient/Location bundle output.
- Deidentified synthetic demo data for development.
- A path to production raw-message governance, lineage, access control, and PHI minimization.

The implementation should not remain a standalone Python/static-HTML app. It should become a Zephyrus feature using Laravel routes, database migrations, Eloquent/query services, Inertia pages, Vite-bundled React/Three.js components, Zephyrus auth, and existing facility/integration foundations.

## 2. Directory Examined

Examined directory:

```text
/home/smudoshi/Github/Zephyrus/patient-flow-4d-navigator
```

Top-level demo files:

- `README.md`
- `flow_engine.py`
- `generate_synthetic_flow.py`
- `server.py`
- `test_flow_engine.py`
- `patient_flow_navigator_schema.sql`
- `500-bed-tier1-trauma1-academic-medical-center.ddl.sql`
- `data/hl7_messages.ndjson`
- `data/normalized_events.ndjson`
- `data/patient_tracks.json`
- `data/location_index.json`
- `data/summary.json`
- `viewer/index.html`
- `viewer/app.js`
- `viewer/styles.css`
- `verify_viewer.mjs`
- `verification/*.png`
- `verification/results.json`

Nested facility/CAD model files:

- `hospital-cad-model/README.md`
- `hospital-cad-model/generate_hospital_cad_model.py`
- `hospital-cad-model/data/model_catalog.json`
- `hospital-cad-model/model/hospital_model.glb`
- `hospital-cad-model/bim/hospital_model.ifc`
- `hospital-cad-model/cad/hospital_model.dxf`
- `hospital-cad-model/3dtiles/tileset.json`
- `hospital-cad-model/viewer/index.html`
- `hospital-cad-model/viewer/app.js`
- `hospital-cad-model/viewer/styles.css`
- `hospital-cad-model/verify_viewer.mjs`
- `hospital-cad-model/verification/*.png`
- `hospital-cad-model/verification/results.json`

Artifact sizes:

- GLB model: about 756 KB.
- IFC model: about 556 KB.
- DXF model: about 1.4 MB.
- `location_index.json`: about 576 KB.
- `patient_tracks.json`: about 1.7 MB.
- `hl7_messages.ndjson`: about 1.7 MB.
- `normalized_events.ndjson`: about 1.3 MB.

The assets are small enough for normal repo/build handling during the first implementation slice. Real production HL7/FHIR payloads are a governance problem, not a file-size problem.

## 3. Demo Runtime Findings

### 3.1 Synthetic Flow Dataset

The generated `data/summary.json` reports:

- 918 raw/synthetic messages.
- 918 normalized events.
- 90 synthetic patients.
- 716 location records used by flow playback.
- 552 movement events.
- 366 clinical-context events.
- Time range: `2026-06-23T21:23:00Z` to `2026-06-25T02:40:00Z`.

The active-state API reconstruction returns 74 active patients and 74 occupied locations at the latest state.

### 3.2 Flow Engine

`flow_engine.py` provides the core normalization behavior:

- Parses HL7 v2 messages with a lightweight standard-library parser.
- Supports common ADT movement triggers:
  - A01 admit
  - A02 transfer
  - A03 discharge
  - A04 registration
  - A05 preadmit
  - A06 outpatient to inpatient
  - A07 inpatient to outpatient
  - A08 update
  - A09 departing tracking
  - A10 arriving tracking
  - A11 cancel admit
  - A12 cancel transfer
  - A13 cancel discharge
  - A40 merge patient
- Categorizes non-ADT messages:
  - ORM/OML -> order
  - ORU -> observation
  - RDE/RAS -> medication
  - SIU -> schedule
  - DFT -> financial
  - MDM -> document
- Hashes patient IDs and encounter IDs.
- Extracts assigned/prior PV1 locations.
- Extracts service line, attending provider, patient class, diagnosis codes, order codes, observation codes, and medication codes.
- Maps event types to FHIR Encounter status/class concepts.
- Produces a small FHIR-style Bundle for an event.
- Reconstructs point-in-time active patient state.
- Computes occupancy by location.

The Python unit test currently passes:

```text
Ran 2 tests in 0.000s
OK
```

The implementation should port these contracts to PHP with fixture parity tests instead of shelling out to Python from Laravel.

### 3.3 Standalone Server

`server.py` exposes the demo API:

- `GET /api/summary`
- `GET /api/locations`
- `GET /api/events?from=&to=&patient=&category=&service_line=&floor=&limit=`
- `GET /api/tracks`
- `GET /api/state?asOf=`
- `GET /api/fhir/bundle?event_id=`
- `POST /api/hl7v2`
- `GET /stream/adt?replay=&interval=`

It also serves static demo files and an SSE replay stream.

Important local verification:

- `/api/summary` works.
- `/api/state` works.
- `/viewer/app.js` works.
- `/cad-model/model/hospital_model.glb` returns 404 in the current Zephyrus checkout.

The GLB 404 is caused by a path mismatch. `server.py` sets:

```python
CAD_ROOT = ROOT.parent / "hospital-cad-model"
```

But the current CAD directory is:

```text
patient-flow-4d-navigator/hospital-cad-model
```

not:

```text
Zephyrus/hospital-cad-model
```

`generate_synthetic_flow.py` has the same bug:

```python
CAD_ROOT = ROOT.parent / "hospital-cad-model"
CATALOG_PATH = CAD_ROOT / "data" / "model_catalog.json"
```

Running the generator fails with:

```text
FileNotFoundError: /home/smudoshi/Github/Zephyrus/hospital-cad-model/data/model_catalog.json
```

### 3.4 Standalone 4D Viewer

`viewer/app.js` is a vanilla Three.js application. It:

- Imports `three`, `GLTFLoader`, `OrbitControls`, and Lucide from CDN import maps.
- Creates a full-window WebGL canvas.
- Loads `/cad-model/model/hospital_model.glb`.
- Fetches `/api/summary`, `/api/locations`, and `/api/events?limit=20000`.
- Builds per-patient tracks in the browser.
- Reconstructs active patient states at slider time.
- Interpolates transfers over a 12-minute transition window.
- Renders:
  - base GLB model
  - patient token spheres
  - patient trails
  - occupancy/census cylinders
- Provides:
  - play/pause
  - live stream toggle
  - reset camera
  - focus active patients
  - floor, service, event-category, speed, and search filters
  - layer toggles
  - active/event/location metrics
  - patient/location inspector
  - live event feed

This should be converted into React components and hooks. Direct DOM querying and CDN import maps should be removed for the integrated app.

### 3.5 Standalone CAD Viewer

`hospital-cad-model/viewer/app.js` is a separate Three.js facility model explorer. It:

- Loads the same GLB.
- Supports orbit and pointer-lock walk mode.
- Filters by floor, service, category, trauma path, and search.
- Uses mesh `userData` from the GLB for inspector content.

The 4D navigator already uses the same model and is the better first integration target. The standalone CAD viewer can become a later "Facility Model Explorer" page or an admin/design route, but it should not block the patient-flow integration.

### 3.6 Verification Artifacts

The checked-in verification JSON shows prior successful render checks for:

- `desktop-1440x900`
- `mobile-390x844`

However, the screenshot paths in those JSON files point to `/Users/sudoshi/Github/Parthenon/...`, not this Zephyrus checkout. Treat them as historical evidence only. Re-run Playwright verification after the Zephyrus React integration.

## 4. Zephyrus Current-State Findings

### 4.1 Frontend Baseline

Live repo findings:

- Vite entrypoint is `resources/js/app.tsx`, not `resources/js/app.jsx`.
- Inertia resolves both `.tsx` and `.jsx` pages.
- `package.json` does not currently include `three` or `@types/three`.
- `lucide-react` is already available.
- `laravel-echo` and `pusher-js` are already available if the real-time path later moves from SSE to Echo/WebSockets.
- `vite.config.js` currently includes only SVG assets explicitly:

```js
assetsInclude: ['**/*.svg']
```

The integrated 3D feature either needs the GLB served from `public/` or Vite asset handling extended for `.glb`.

### 4.2 Existing Patient-Flow UI

Existing patient-flow frontend pieces:

- `resources/js/Components/Analytics/PatientFlow/PatientFlowDashboard.jsx`
- `resources/js/Hooks/usePatientFlowData.js`
- `resources/js/Pages/ED/Analytics/Flow.jsx`

Important limitation:

- `usePatientFlowData.js` is mock-data backed. It simulates a timeout and returns hard-coded process-map data.
- `ED/Analytics/Flow.jsx` is currently just an `EDPlaceholder`.

The 4D navigator should use a new dedicated hook/service first:

- `resources/js/features/patientFlowNavigator/api.ts`
- `resources/js/features/patientFlowNavigator/types.ts`
- `resources/js/features/patientFlowNavigator/hooks.ts`

After that lands, the existing analytics patient-flow dashboard can be refactored to consume the same backend projections where appropriate.

### 4.3 Routing Baseline

Relevant current routes:

- ED patient flow route exists:
  - `GET /ed/analytics/flow`
- RTDC bed-tracking route exists:
  - `GET /rtdc/bed-tracking`
- Facility model summary API exists:
  - `GET /api/facility/model/summary`
- Superuser navigation contains:
  - `/operations/patient-flow`

But there is no matching `GET /operations/patient-flow` route in `routes/web.php`. This is likely a stale navigation item and should be corrected during integration.

### 4.4 Auth Baseline

The repo currently redirects unauthenticated `/` requests to `login`, despite the supplied AGENTS note saying the root route auto-authenticates as a default admin. Plan against the live code:

- Inertia page routes should stay under `auth`.
- Browser API routes should use `web`, `auth`, and throttle middleware like the existing RTDC/facility APIs.
- External ingest endpoints should not rely on browser sessions; they need source credentials or a scoped token path when production ingestion begins.

### 4.5 Facility Model Foundation Already Exists

Zephyrus already has a facility blueprint/digital-twin data foundation:

- Migration:
  - `database/migrations/2026_06_25_000010_create_facility_blueprint_model_tables.php`
- Command:
  - `php artisan facility:import-catalog`
- Service:
  - `App\Services\Facility\ModelCatalogImporter`
- API:
  - `App\Http\Controllers\Api\Facility\FacilityModelController`
- Models:
  - `BlueprintImport`
  - `BlueprintObject`
  - `FacilitySpace`
  - `OperationalSpaceMap`

The importer reads `model_catalog.json`, stores imported objects, promotes them to canonical `hosp_space.facility_spaces`, and optionally maps care units/beds into `prod.units` and `prod.beds`.

This is the correct integration base for the CAD model metadata. Do not create a long-term duplicate location source from `data/location_index.json`.

### 4.6 Integration Foundation Already Exists

Zephyrus already has source/integration/raw/FHIR tables:

- `integration.sources`
- `integration.source_capabilities`
- `integration.source_endpoints`
- `integration.source_credentials`
- `raw.ingest_runs`
- `raw.inbound_messages`
- `integration.canonical_events`
- `raw.dead_letters`
- `integration.connector_watermarks`
- `fhir.resource_versions`
- `fhir.resource_links`
- `integration.identity_links`
- `integration.patient_merge_events`
- `integration.terminology_maps`
- `integration.provenance_records`
- `integration.event_projection_offsets`
- `integration.event_projection_errors`
- `integration.event_replay_jobs`

The flow integration should use these where possible instead of recreating all of `flow_ingest.source_system` and `flow_ingest.hl7_message_raw`.

## 5. Git/Artifact State To Handle Carefully

Current `git status --short` shows:

- The old root-level `hospital-cad-model/` files are deleted.
- The new `patient-flow-4d-navigator/` directory is untracked.

Do not "fix" this by blindly reverting or moving files. The integration implementation should explicitly choose the canonical artifact location and then stage only the intended files.

Recommended canonical structure:

```text
docs/research/patient-flow-4d-navigator/
  README.md
  flow_engine.py
  generate_synthetic_flow.py
  test_flow_engine.py
  patient_flow_navigator_schema.sql
  data/
  verification/

docs/research/hospital-cad-model/
  README.md
  generate_hospital_cad_model.py
  data/model_catalog.json
  bim/
  cad/
  3dtiles/
  verification/

public/vendor/zephyrus-facility-models/zep-500/
  hospital_model.glb
  tileset.json

resources/js/features/patientFlowNavigator/
  api.ts
  types.ts
  hooks.ts
  scene/
  components/
```

Alternate acceptable structure:

- Keep all research/demo source under `patient-flow-4d-navigator/`.
- Copy only runtime assets to `public/vendor/zephyrus-facility-models/zep-500/`.
- Import the catalog into DB through `facility:import-catalog`.

Avoid serving the untracked research directory directly in production.

## 6. Target Architecture

### 6.1 Data Flow

```text
Facility model catalog
  -> facility:import-catalog
  -> hosp_ingest.blueprint_objects
  -> hosp_space.facility_spaces
  -> prod.units/prod.beds mappings
  -> patient-flow location API

Synthetic or real HL7/FHIR movement messages
  -> raw.inbound_messages
  -> integration.canonical_events
  -> flow_core.flow_events
  -> occupancy/current-state projection
  -> /api/patient-flow/*
  -> React Three.js navigator
```

### 6.2 Ownership Boundaries

Use existing layers as source of truth:

- Facility geometry/metadata: `hosp_ingest` and `hosp_space`.
- Raw payload ledger: `raw.inbound_messages`.
- Source/capability registry: `integration.sources`.
- Canonical operational events: `integration.canonical_events`.
- Navigator-optimized replay/state: new `flow_core` tables or materialized views.
- Browser visualization: React/Vite/Three.js under `resources/js`.

### 6.3 Do Not Run The Large DDL Wholesale First

`500-bed-tier1-trauma1-academic-medical-center.ddl.sql` is valuable as a target ontology, but it creates a broad planning model with many schemas and tables. Zephyrus already introduced a narrower `hosp_ingest`/`hosp_space` bridge. The first integration should not run the full 2,167-line DDL wholesale.

Use the DDL as a reference for future expansion:

- route graph
- elevator classes
- trauma pathways
- infection-control/clean/soiled flow
- utility/downtime overlays
- quality/accreditation traceability
- optimization/simulation tables

For the patient-flow navigator, only implement the minimum event and location projections needed to power the viewer safely.

## 7. Backend Implementation Plan

### Phase 0: Stabilize Demo Artifacts

Tasks:

1. Decide canonical repo location for research/demo files.
2. Fix demo path references if keeping the Python demo runnable:
   - `generate_synthetic_flow.py`
   - `server.py`
   - `README.md`
3. Update stale README commands from `docs/research/...` if the final location differs.
4. Re-run:
   - `python3 test_flow_engine.py`
   - `python3 generate_synthetic_flow.py`
   - local server smoke check
5. Replace stale verification output paths by rerunning `verify_viewer.mjs` from this Zephyrus repo if preserving demo verification artifacts.

Acceptance:

- Python tests pass.
- Generator can find `model_catalog.json`.
- Standalone server can serve its GLB or the README states it is research-only and not the production serving path.

### Phase 1: Import Facility Model Into Existing Zephyrus Facility Tables

Use the existing command:

```bash
php artisan facility:import-catalog patient-flow-4d-navigator/hospital-cad-model/data/model_catalog.json \
  --facility-code=ZEPHYRUS-500 \
  --facility-name="500-Bed Level I Trauma Academic Medical Center" \
  --source-name=patient-flow-4d-navigator-catalog \
  --map-operational
```

If the artifact is relocated, update the path accordingly.

Tasks:

1. Verify migrations for `hosp_ingest`, `hosp_space`, `prod.units`, and `prod.beds` are applied.
2. Run/import the catalog in local/dev.
3. Verify `/api/facility/model/summary?facility_code=ZEPHYRUS-500`.
4. Add or extend tests for:
   - object count
   - facility-space count
   - bed/unit mapping coverage
   - no duplicate imports on same checksum/source/facility
5. Store public GLB asset path in one stable configuration:
   - `config/facility_models.php`, or
   - import metadata on `hosp_ingest.blueprint_imports`, or
   - a small `hosp_ingest.model_assets` table if multiple asset types need to be tracked.

Recommended first-slice config:

```php
return [
    'zep_500' => [
        'facility_code' => 'ZEPHYRUS-500',
        'model_url' => '/vendor/zephyrus-facility-models/zep-500/hospital_model.glb',
        'tileset_url' => '/vendor/zephyrus-facility-models/zep-500/tileset.json',
    ],
];
```

Acceptance:

- Facility model summary API reports latest import.
- `prod.units` and `prod.beds` can be linked to facility spaces when `--map-operational` is used.
- Browser can fetch the GLB from a Laravel-served public URL.

### Phase 2: Add Navigator Event Schema

Create a Laravel migration that adapts `patient_flow_navigator_schema.sql` into Zephyrus' existing integration architecture.

Recommended new schemas/tables:

- `flow_core.patient_identities`
- `flow_core.encounters`
- `flow_core.flow_events`
- `flow_core.fhir_bundle_cache`
- `flow_core.occupancy_snapshots`
- `flow_realtime.subscription_clients`
- `flow_realtime.delivery_cursors`

Recommended changes from the standalone SQL:

- Do not duplicate `flow_ingest.source_system`; reference `integration.sources.source_id`.
- Do not duplicate raw HL7 storage as the primary ledger; reference `raw.inbound_messages.inbound_message_id`.
- Add nullable `canonical_event_id` referencing `integration.canonical_events`.
- Add nullable `facility_space_id` referencing `hosp_space.facility_spaces`.
- Prefer `to_facility_space_id` and `from_facility_space_id` over standalone text-only location foreign keys.
- Keep source location codes for traceability:
  - `from_source_location_code`
  - `to_source_location_code`
- Keep hashed identifiers only:
  - `patient_ref`
  - `patient_display_ref`
  - `encounter_ref`
- Preserve JSON metadata for local HL7 site variance.

Suggested `flow_core.flow_events` shape:

```text
flow_event_id text primary key
source_id bigint null references integration.sources
inbound_message_id bigint null references raw.inbound_messages
canonical_event_id bigint null references integration.canonical_events
event_category text
event_type text
patient_ref text
patient_display_ref text
encounter_ref text
occurred_at timestamptz
recorded_at timestamptz
from_source_location_code text
to_source_location_code text
from_facility_space_id bigint null references hosp_space.facility_spaces
to_facility_space_id bigint null references hosp_space.facility_spaces
patient_class text
fhir_encounter_status text
fhir_encounter_class text
service_line text
priority text
diagnosis_codes text[]
order_codes text[]
observation_codes text[]
medication_codes text[]
cancellation_of_event_id text null
raw_message_hash text
source_protocol text
deidentified boolean
metadata jsonb
created_at/updated_at
```

Indexes:

- `occurred_at`
- `(patient_ref, occurred_at)`
- `(encounter_ref, occurred_at)`
- `(to_facility_space_id, occurred_at)`
- `(event_category, event_type)`
- GIN on metadata if heavily filtered.

Acceptance:

- Migration is idempotent/safe for existing Zephyrus schema style.
- Unit/feature tests verify schema exists and can insert one normalized event.
- Existing `raw` and `integration` tests still pass.

### Phase 3: Port The Flow Engine To PHP Services

Create services under:

```text
app/Services/PatientFlow/
```

Recommended classes:

- `Hl7V2MessageParser`
- `Hl7V2Location`
- `FlowEventData`
- `FlowEventNormalizer`
- `FlowEventRepository`
- `FhirBundleFactory`
- `PatientStateProjector`
- `OccupancyProjector`
- `SyntheticFlowImporter`

Responsibilities:

- `Hl7V2MessageParser`
  - Parse MSH/PID/PV1/EVN/DG1/OBR/OBX/ORC/RXE segments.
  - Match the Python fixture behavior.
- `FlowEventNormalizer`
  - Convert parsed messages into `FlowEventData`.
  - Apply event-category and event-type mappings.
  - Hash patient and encounter IDs.
  - Map source location code to `hosp_space.facility_spaces`.
- `FlowEventRepository`
  - Store/retrieve flow events.
  - Apply filters for from/to/patient/category/service/floor/limit.
- `FhirBundleFactory`
  - Return Encounter/Patient/Location bundle JSON for a flow event.
- `PatientStateProjector`
  - Reconstruct active patient state at `asOf`.
- `OccupancyProjector`
  - Compute active occupancy by location/unit/floor/service.
- `SyntheticFlowImporter`
  - Import `data/hl7_messages.ndjson` and/or `normalized_events.ndjson` into raw/canonical/flow tables for demo/dev.

Important parity fixtures:

- Port `test_flow_engine.py` transfer fixture to PHPUnit.
- Port admit/discharge/current-state fixture to PHPUnit.
- Add one fixture for:
  - ORU observation
  - RDE medication
  - A11/A12/A13 cancellation
  - A40 merge patient handling as a future-safe path.

Acceptance:

- PHP parser emits the same core normalized fields as the Python engine for fixtures.
- Event ID/idempotency behavior is deterministic.
- Synthetic import creates the expected 918 flow events from existing demo data.

### Phase 4: API Controllers And Routes

Create:

```text
app/Http/Controllers/Api/PatientFlow/PatientFlowController.php
app/Http/Controllers/Api/PatientFlow/PatientFlowIngestController.php
app/Http/Controllers/Api/PatientFlow/PatientFlowStreamController.php
```

Browser routes in `routes/api.php`:

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

External ingest route:

```php
Route::middleware(['throttle:120,1'])
    ->prefix('patient-flow/ingest')
    ->group(function () {
        Route::post('/hl7v2', [PatientFlowIngestController::class, 'hl7v2']);
    });
```

For the first demo slice, the ingest route can be disabled by config or guarded by a signed/testing token. For production, wire it to `integration.sources` and credential validation instead of session auth.

API response contracts should intentionally match the standalone demo enough for frontend migration:

- summary:
  - `messages`
  - `normalized_events`
  - `patients`
  - `locations`
  - `movement_events`
  - `clinical_context_events`
  - `min_occurred_at`
  - `max_occurred_at`
  - `live_events`
  - `model_url`
  - `facility_code`
- locations:
  - keyed by source/model location code
  - include `facility_space_id`, `name`, `category`, `floor`, `position_m`, `position_ft`, `metadata`
- events:
  - array of normalized event objects
- tracks:
  - keyed by patient ref
- state:
  - `asOf`
  - `activePatients`
  - `patients`
  - `occupancy`

Acceptance:

- API parity tests compare representative responses against current JSON demo shape.
- Throttle/auth behavior matches other Zephyrus browser APIs.
- Empty database returns coherent empty payloads, not 500s.

### Phase 5: Streaming Strategy

Initial integrated implementation:

- Use SSE for parity with the demo.
- Route: `GET /api/patient-flow/stream/adt?replay=180&interval=0.65`.
- Send events as:

```text
event: patient-flow
data: {...}
```

Implementation notes:

- Keep replay limits capped.
- Never stream raw HL7 payloads.
- Stream normalized/deidentified events only.
- Add heartbeat comments.
- Close cleanly when client disconnects.

Later production option:

- Move live updates to Laravel Echo/WebSockets using existing `laravel-echo` and `pusher-js` dependencies.
- Use queue-backed broadcasting when real ADT volume exists.
- Keep SSE as a simple fallback for local/dev and non-WebSocket environments.

Acceptance:

- Viewer receives replayed events.
- Browser disconnect does not leave PHP workers stuck indefinitely.
- Stream endpoint has auth and throttle protection.

## 8. Frontend Implementation Plan

### Phase 1: Add Dependencies And Assets

Add dependencies:

```bash
npm install three
npm install -D @types/three
```

If using Vite imports for GLB:

```js
assetsInclude: ['**/*.svg', '**/*.glb']
```

Recommended first slice:

- Serve GLB from `public/vendor/zephyrus-facility-models/zep-500/hospital_model.glb`.
- Avoid bundling the binary model into JavaScript chunks.
- Keep `model_url` returned from `/api/patient-flow/summary` or config-injected page props.

### Phase 2: Create Typed Client Layer

Create:

```text
resources/js/features/patientFlowNavigator/types.ts
resources/js/features/patientFlowNavigator/api.ts
resources/js/features/patientFlowNavigator/hooks.ts
```

Types:

- `PatientFlowSummary`
- `PatientFlowLocation`
- `PatientFlowEvent`
- `PatientTrackMap`
- `PatientFlowState`
- `PatientFlowFilters`
- `PatientFlowLayerState`

API functions:

- `getSummary()`
- `getLocations()`
- `getEvents(filters)`
- `getTracks(filters)`
- `getState(asOf)`
- `getFhirBundle(eventId)`
- `openAdtStream(options)`

Hooks:

- `usePatientFlowNavigatorBootstrap`
- `usePatientFlowEvents`
- `usePatientFlowStream`
- `usePatientFlowFilters`

Do not reuse `usePatientFlowData` initially; it has a different mock process-map contract.

### Phase 3: Convert Three.js Viewer Into React Components

Create:

```text
resources/js/Components/PatientFlowNavigator/PatientFlowNavigator.tsx
resources/js/Components/PatientFlowNavigator/PatientFlowScene.tsx
resources/js/Components/PatientFlowNavigator/PatientFlowToolbar.tsx
resources/js/Components/PatientFlowNavigator/PatientFlowInspector.tsx
resources/js/Components/PatientFlowNavigator/PatientFlowEventFeed.tsx
resources/js/Components/PatientFlowNavigator/PatientFlowStatusBar.tsx
resources/js/Components/PatientFlowNavigator/scene/materials.ts
resources/js/Components/PatientFlowNavigator/scene/stateProjection.ts
resources/js/Components/PatientFlowNavigator/scene/threeLifecycle.ts
```

Conversion rules:

- Replace `document.querySelector` with React refs and state.
- Initialize renderer/scene/camera/orbit controls inside `useEffect`.
- Dispose renderer, geometries, materials, controls, EventSource, and animation frame on unmount.
- Use `three/examples/jsm/...` imports from npm.
- Use `lucide-react` icons instead of CDN Lucide.
- Keep the 3D canvas full-bleed/unframed.
- Keep control surfaces compact and work-focused.
- Avoid nesting the viewer inside cards.
- Preserve keyboard/mouse accessibility where possible:
  - button labels/tooltips
  - escape or route change closes stream
  - inspector updates through React state
- Guard for WebGL load errors and API failures.

Scene modules to preserve from demo:

- `hashColor`
- patient material cache
- `positionFor`
- track rebuild
- filter matching
- `patientStatesAt`
- token updates
- trail updates
- occupancy heat updates
- raycasting inspector
- frame active patients
- reset camera

Improvements during conversion:

- Move expensive `events.filter`/state reconstruction into memoized selectors.
- Avoid rebuilding trails/heat every animation frame when current time has not changed.
- Add model loading progress/error state.
- Add an explicit "demo data" or "live source" badge from API summary.
- Add floor/service/category options from API response, not from raw DOM mutation.

### Phase 4: Add Inertia Page And Navigation

Recommended page:

```text
resources/js/Pages/RTDC/PatientFlowNavigator.tsx
```

Recommended route:

```php
Route::get('/rtdc/patient-flow-navigator', [RTDCDashboardController::class, 'patientFlowNavigator'])
    ->name('rtdc.patient-flow-navigator');
```

Add controller method returning:

```php
return Inertia::render('RTDC/PatientFlowNavigator', [
    'workflow' => 'rtdc',
    'facilityCode' => 'ZEPHYRUS-500',
]);
```

Also replace the ED placeholder with a link/embed strategy:

- `/ed/analytics/flow` can render the same navigator with default ED floor/category/service filters.
- Or it can show an ED-focused analytics page with a prominent "Open 4D Navigator" panel.

Fix stale navigation:

- Replace superuser `/operations/patient-flow` with a real route.
- Add RTDC operations item:
  - `Patient Flow Navigator`
  - `/rtdc/patient-flow-navigator`
- Optionally add ED analytics item pointing to:
  - `/ed/analytics/flow`

Acceptance:

- Authenticated users can open the navigator from RTDC and/or ED nav.
- Existing ED flow placeholder is no longer the only patient-flow surface.
- No route in navigation points to a 404.

## 9. Synthetic Data Integration Plan

For local/dev:

1. Keep `data/hl7_messages.ndjson` as the primary synthetic source.
2. Add Artisan command:

```bash
php artisan patient-flow:import-synthetic patient-flow-4d-navigator/data/hl7_messages.ndjson \
  --source-key=synthetic-flow-ehr \
  --facility-code=ZEPHYRUS-500
```

3. Command should:
   - upsert `integration.sources` for the synthetic feed
   - create a `raw.ingest_runs` row
   - insert `raw.inbound_messages`
   - normalize messages into `integration.canonical_events`
   - project into `flow_core.flow_events`
   - optionally compute occupancy snapshots

4. Add `--from-normalized` option for faster import from `normalized_events.ndjson` during local-only development.

Acceptance:

- Command imports 918 events.
- Re-running command is idempotent.
- API summary reports the expected demo counts.
- Imported event IDs match deterministic expectations.

## 10. Production Ingestion Plan

Production source types:

- HL7 v2 ADT via interface engine or MLLP receiver.
- FHIR Encounter/Location/Patient subscriptions where available.
- FHIR Bulk Data for backfill.
- Vendor webhooks/API polling for transport, EVS, RTLS, or bed-management systems.

Production rules:

- Raw HL7/FHIR payloads are ePHI until proven otherwise.
- Do not expose raw payloads to the navigator.
- Store raw payload body in governed encrypted storage or `raw.inbound_messages.payload` only if local policy allows it.
- Store `payload_hash`, `storage_pointer`, source IDs, parser status, and lineage.
- Use hashed patient/encounter references in `flow_core`.
- Use RBAC for any drill-through that can identify a patient.
- Add audit logging for patient-level inspector access if real PHI fields are ever displayed.
- Keep source-specific terminology/location mapping configurable.

Minimum production ingest endpoint behavior:

- Validate source credential.
- Compute hash/idempotency key.
- Reject duplicates safely.
- Store raw envelope.
- Parse and normalize.
- Record canonical event.
- Project to flow event.
- Return ACK-style response.
- Dead-letter parse/mapping failures.

## 11. FHIR Bundle Strategy

The demo `flow_event_to_fhir_bundle` returns a small Bundle containing:

- Encounter
- Patient
- Location

Integrated approach:

- Keep `GET /api/patient-flow/fhir/bundle?event_id=...` for standards traceability and debugging.
- Generate Bundle from normalized event plus facility-space metadata.
- Do not pretend this is a full clinical FHIR server.
- Use Zephyrus `fhir.resource_versions` and `fhir.resource_links` when source FHIR resources are actually ingested.
- Cache generated bundles in `flow_core.fhir_bundle_cache` only if bundle generation becomes expensive or needs reproducibility.

## 12. Data Contract Mapping

Standalone event field to integrated source:

- `event_id`
  - `flow_core.flow_events.flow_event_id`
- `event_category`
  - `flow_core.flow_events.event_category`
- `event_type`
  - `flow_core.flow_events.event_type`
- `patient_id`
  - `flow_core.flow_events.patient_ref`
- `patient_display_id`
  - `flow_core.flow_events.patient_display_ref`
- `encounter_id`
  - `flow_core.flow_events.encounter_ref`
- `occurred_at`
  - `flow_core.flow_events.occurred_at`
- `recorded_at`
  - `flow_core.flow_events.recorded_at`
- `from_location`
  - `flow_core.flow_events.from_source_location_code`
- `to_location`
  - `flow_core.flow_events.to_source_location_code`
- `location_name`
  - from `hosp_space.facility_spaces.space_name` or metadata
- `location_floor`
  - from `hosp_space.facility_spaces.floor_number`
- `position_ft`
  - from `hosp_space.facility_spaces.geometry.position_ft`
- `position_m`
  - computed from feet or stored in response
- `service_line`
  - event service line, falling back to facility space service line
- `diagnosis_codes`, `order_codes`, `observation_codes`, `medication_codes`
  - flow event arrays
- `metadata`
  - flow event JSON metadata

Location response should use `hosp_space.facility_spaces` as source. For compatibility, key locations by the model/source code:

```text
attributes.source_object_code
geometry.source_object_code
space_code suffix after facility prefix
```

## 13. Testing And Validation Plan

Backend tests:

- Unit tests for HL7 timestamp parsing.
- Unit tests for HL7 segment/field/component parsing.
- Unit tests for ADT A01/A02/A03/A04/A08 normalization.
- Unit tests for non-ADT ORM/ORU/RDE categorization.
- Unit tests for current-state reconstruction.
- Unit tests for occupancy projection.
- Feature tests for:
  - `/api/patient-flow/summary`
  - `/api/patient-flow/locations`
  - `/api/patient-flow/events`
  - `/api/patient-flow/tracks`
  - `/api/patient-flow/state`
  - `/api/patient-flow/fhir/bundle`
- Feature tests for auth/throttle on browser APIs.
- Import command test with a small NDJSON fixture.
- Idempotency test for duplicate messages.
- Empty-state API tests.

Frontend tests:

- Vitest for pure state projection/filter helpers.
- React Testing Library for toolbar/filter controls.
- Mocked API bootstrap test.
- EventSource hook cleanup test.
- Canvas component smoke test with Three.js mocked if needed.

E2E/visual tests:

- Playwright desktop viewport `1440x900`.
- Playwright mobile viewport `390x844`.
- Assert:
  - page loads behind auth
  - canvas is nonblank
  - model loaded status appears
  - active metric appears
  - time slider changes state
  - floor/service/category filters do not crash
  - inspector opens on selectable token/location if practical
  - live stream button connects and can disconnect

Manual validation:

- `php artisan migrate`
- `php artisan facility:import-catalog ... --map-operational`
- `php artisan patient-flow:import-synthetic ...`
- `php artisan test`
- `npm run test`
- `npm run build`
- `git diff --check`

## 14. Security, Privacy, And Governance

Required safeguards before real feeds:

- No raw HL7/FHIR in browser responses.
- No MRN, name, DOB, address, phone, or unmasked identifiers in viewer payloads.
- Patient display IDs remain pseudonymous unless role-gated.
- Source credentials stored as secret references, not raw secrets.
- External ingest endpoints use source-scoped auth, not browser session auth.
- Raw payload storage uses encryption and retention controls.
- Parser errors go to dead letters without leaking PHI into logs.
- Event lineage from source -> raw -> canonical -> flow projection is queryable.
- Patient-level inspector fields are minimum-necessary.
- Real PHI drill-through requires RBAC and audit events.

## 15. Deployment Plan

First deployable slice:

1. Land migrations and PHP services.
2. Land import commands.
3. Land public GLB asset path.
4. Land API endpoints under auth.
5. Land React navigator page.
6. Import synthetic data in target environment.
7. Verify page and API health.

Operational deployment checklist:

- Confirm `APP_ENV` and auth are not in demo auto-login mode.
- Confirm migrations applied.
- Confirm `facility:import-catalog` succeeded.
- Confirm synthetic import succeeded.
- Confirm GLB URL returns `200`.
- Confirm `/api/patient-flow/summary` returns expected counts.
- Confirm browser loads page.
- Confirm build assets deployed.
- Confirm logs do not include raw HL7 payloads.

## 16. Recommended Phased Delivery

### Milestone 1: Repo and Asset Consolidation

- Choose final artifact locations.
- Fix demo path mismatch.
- Copy or relocate GLB to public runtime asset path.
- Document import commands.
- Add `three` dependency.

Deliverable:

- The checked-in demo is runnable or clearly marked research-only.
- The production runtime asset path is stable.

### Milestone 2: Facility Model Backing

- Import `model_catalog.json` through existing facility importer.
- Verify `hosp_space` coverage.
- Add model asset config.

Deliverable:

- Zephyrus can serve model metadata from DB and the GLB from public assets.

### Milestone 3: Flow Event Store And Synthetic Import

- Add `flow_core`/`flow_realtime` migrations.
- Port parser/normalizer.
- Add synthetic import command.
- Import 918 events.

Deliverable:

- Zephyrus DB contains patient-flow replay data with raw/canonical lineage.

### Milestone 4: Patient Flow APIs

- Add summary/location/event/track/state/FHIR endpoints.
- Add SSE replay endpoint.
- Add tests.

Deliverable:

- API parity with standalone demo without relying on Python server.

### Milestone 5: React 4D Navigator

- Convert vanilla viewer to React/Three.js.
- Add Inertia page and navigation.
- Replace ED placeholder or link to navigator.
- Add visual/E2E verification.

Deliverable:

- Authenticated Zephyrus users can run the 4D navigator inside the app.

### Milestone 6: Production Ingestion Readiness

- Add source credential validation.
- Add real HL7/FHIR ingestion adapters.
- Add dead-letter and health dashboards.
- Add RBAC/audit policy for any patient-level detail.

Deliverable:

- The navigator can accept governed live hospital feeds.

## 17. Key Implementation Decisions

Recommended decisions:

- Use `hosp_space` as the canonical location/facility source.
- Use `raw` and `integration` schemas for source ledger and canonical events.
- Use `flow_core` only for navigator-optimized movement projections.
- Serve GLB as a public versioned asset first.
- Port Python normalization behavior to PHP instead of invoking Python at runtime.
- Start with SSE for live/replay parity.
- Move to Echo/WebSockets only after real volume or bidirectional workflows need it.
- Keep the standalone CAD viewer as a later design/admin feature.
- Do not run the full 500-bed DDL wholesale during the first patient-flow integration.

## 18. Immediate Next Work Items

1. Normalize artifact placement and fix CAD path mismatch.
2. Add `three` and decide GLB serving path.
3. Import `hospital-cad-model/data/model_catalog.json` with `facility:import-catalog`.
4. Create `flow_core` migration adapted to Zephyrus' existing `raw`/`integration` schemas.
5. Port `flow_engine.py` parser/normalizer to PHP service classes.
6. Add `patient-flow:import-synthetic`.
7. Add `/api/patient-flow/*` endpoints.
8. Convert `viewer/app.js` into React/Three.js components.
9. Add `/rtdc/patient-flow-navigator` page and fix stale navigation.
10. Run PHPUnit, Vitest, build, and Playwright canvas verification.
