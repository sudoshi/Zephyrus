# 06 — Supporting Operational Domains & Full API Surface

> **Scope.** This document maps the *supporting* operational domains of Zephyrus —
> **Staffing**, **Transport**, **EVS** (environmental services / bed turns),
> **Facility modeling**, **PatientFlow + FHIR**, and **Integration/ingestion** — plus
> the **complete `routes/api.php` endpoint inventory** that a mobile companion
> ("Hummingbird", Kotlin/Android + Swift/iOS) would consume.
>
> **Stack.** Laravel 11 + React/Inertia, PostgreSQL (schema-qualified tables:
> `prod.*`, `flow_core.*`, `flow_realtime.*`, `hosp_space.*`, `hosp_ingest.*`,
> `integration.*`, `raw.*`, `fhir.*`, `ops.*`).
>
> **Auth.** Every domain API group is `['web', 'auth', 'throttle:60,1']` — i.e.
> **session-cookie auth via the `web` guard** (Laravel Sanctum SPA / Inertia model),
> *not* bearer tokens. See [§9 Mobile Auth Implications](#9-mobile-auth-implications).

---

## 1. Domain-by-domain data model

The three core **worker-workflow domains** (Staffing, Transport, EVS) share an
identical architectural shape, which is important for a mobile client:

> **Pattern: Request + Event + OperationsService.**
> Each domain has a **`*Request` aggregate** (the work item), an append-only
> **`*Event` log** (status-transition audit trail, one row per transition), and a
> single **`*OperationsService`** that owns all writes inside a DB transaction and
> emits an event on every state change. The controllers are thin; the service is the
> source of truth for lifecycle + serialization. All three use:
> - UUID business key (`request_uuid`) + integer surrogate PK.
> - `priority` ∈ `{routine, urgent, stat}`, sorted `stat → urgent → routine` then by due time.
> - Soft delete via `is_deleted` boolean.
> - `jsonb` columns for `risk_flags`, `metadata`, and a domain-specific resolution payload.
> - An **SLA block** computed on serialize: `{minutes_until_due, at_risk, label}`.
> - `created_by_user_id` / `updated_by_user_id` (and the event carries `actor_user_id`).

---

### 1.1 Staffing

Surfaces the "staffing office" — coverage gaps per unit/role/shift and the requests
to fill them. **Mobile-relevant for staffing coordinators & charge nurses.**

**Models** (`app/Models/Staffing/`)

#### `StaffingPlan` — `prod.staffing_plans`
The *demand/coverage* picture for one unit × role × shift × date.

| Field | Type | Notes |
|---|---|---|
| `staffing_plan_id` | int PK | |
| `plan_uuid` | string | business key |
| `unit_id` | int | nullable |
| `unit_label` | string(120) | required |
| `role` | string(40) | `rn, lpn, tech, charge, provider, respiratory, unit_secretary` |
| `shift_date` | date | |
| `shift` | string(20) | `day, evening, night` (default `day`) |
| `required_count` | int | target headcount |
| `scheduled_count` | int | on the schedule |
| `actual_count` | int | actually on the floor |
| `minimum_safe_count` | int | floor below which it's unsafe |
| `census` | int | current patient census |
| `ratio_target` | decimal(5,2) | nurse:patient target |
| `status` | string(40) | `balanced` (default), `gap`, `critical_gap` |
| `notes` | string(400) | |
| `constraints`, `metadata` | jsonb | |
| `is_deleted` | bool | |

Derived (computed in model/service, **not** stored):
- `gap()` = `max(0, required − max(scheduled, actual))`
- `belowMinimumSafe()` = `max(scheduled, actual) < minimum_safe_count`

Relationship: `hasMany StaffingRequest`. Indexes on `(shift_date,shift)`, `(status,shift_date)`, `(role,shift_date)`, `(unit_id,shift_date)`.

#### `StaffingRequest` — `prod.staffing_requests`
A *request to fill* a gap (the actionable unit).

| Field | Type | Notes |
|---|---|---|
| `staffing_request_id` | int PK | |
| `request_uuid` | string | |
| `unit_id`, `staffing_plan_id` | int | FK-ish (nullable) |
| `unit_label`, `role`, `shift_date`, `shift` | — | as above |
| `request_type` | string(40) | `fill_gap` (default), `float, overtime, agency, on_call, reassign` |
| `priority` | string(20) | `routine, urgent, stat` |
| `status` | string(40) | **lifecycle**, see §3.3 |
| `headcount_needed` | int | 1–50 |
| `hours_needed` | decimal(6,2) | 0–24 |
| `requested_by` | string(120) | |
| `needed_by` | timestamp | SLA target |
| `assigned_at`, `filled_at`, `completed_at` | timestamp | lifecycle stamps |
| `assigned_source` | string(60) | `float_pool, overtime, agency, on_call` |
| `assigned_staff_ref` | string(120) | who's filling it |
| `owner_name` | string(120) | coordinator owning the request |
| `risk_flags`, `resolution_payload`, `metadata` | jsonb | |
| `is_deleted` | bool | |

Relationships: `belongsTo StaffingPlan`, `hasMany StaffingEvent`. Scopes: `active()` (not completed/canceled), `forRole()`.

#### `StaffingEvent` — `prod.staffing_events`
Append-only transition log (`timestamps=false`, has its own `occurred_at`/`created_at`).

| Field | Notes |
|---|---|
| `staffing_event_id` PK · `event_uuid` · `staffing_request_id` FK |
| `event_type` (e.g. `staffing.requested`, `staffing.assigned`, `staffing.filled`) |
| `from_status` / `to_status` |
| `payload` jsonb · `source` (default `zephyrus`) · `actor_user_id` · `occurred_at` |

**Role labels** (`StaffingOperationsService::ROLE_LABELS`): rn→Registered Nurse, lpn→Licensed Practical Nurse, tech→Patient Care Tech, charge→Charge Nurse, provider→Provider, respiratory→Respiratory Therapist, unit_secretary→Unit Secretary.

**Resource options** (assignment sources surfaced to UI): `float_pool` (Float Pool), `overtime` (Overtime Offer), `agency` (Agency / Traveler), `on_call` (On-Call Activation).

---

### 1.2 Transport

Patient transport command center: inpatient moves, inter-facility transfers,
discharge rides, EMS inbound, care transitions. **The flagship mobile worker
workflow** (a porter/transporter with a phone). Has a **rich, multi-state lifecycle.**

**Models** (`app/Models/Transport/` + `app/Models/CaseTransport.php`)

#### `TransportRequest` — `prod.transport_requests`

| Field | Type | Notes |
|---|---|---|
| `transport_request_id` | int PK | |
| `request_uuid` | string | |
| `request_type` | string | `inpatient, transfer, discharge, ems, care_transition` |
| `priority` | string | `routine` (default), `urgent, stat` |
| `status` | string | **lifecycle**, default `requested`, see §3.1 |
| `patient_ref` | string | required |
| `encounter_ref` | string | nullable |
| `origin` | string(160) | required |
| `destination` | string(160) | required |
| `transport_mode` | string | `ambulatory, wheelchair, stretcher, bed, rideshare, nemt, bls, als, critical_care, ems, air, courier` (default `wheelchair`) |
| `clinical_service` | string(120) | |
| `requested_by` | string(120) | |
| `requested_at` | timestamp | useCurrent |
| `needed_at` | timestamp | SLA target |
| `assigned_at`, `dispatched_at`, `completed_at` | timestamp | lifecycle stamps |
| `assigned_team` | string | internal team |
| `assigned_vendor` | string | external vendor (see vendor catalog below) |
| `external_system`, `external_id` | string | vendor linkage |
| `segments` | jsonb | multi-leg trip legs |
| `risk_flags` | jsonb | |
| `handoff` | jsonb | populated at handoff: `{handoff_to, handoff_summary, documents[], outstanding_risks[], completed_at}` |
| `metadata` | jsonb | |
| `is_deleted` | bool | |

Indexes: `(status,needed_at)`, `(request_type,status)`, `(priority,needed_at)`, `patient_ref`, `encounter_ref`, `(external_system,external_id)`. Scopes: `active()` (not completed/canceled/failed), `forType()`. `hasMany TransportEvent`.

#### `TransportEvent` — `prod.transport_events`
Transition log (`UPDATED_AT = null`; uses `occurred_at`). Fields mirror StaffingEvent: `transport_event_id`, `event_uuid`, `transport_request_id`, `event_type` (e.g. `transport.requested`, `transport.assigned`, `transport.dispatched`, `transport.handoff_complete`), `from_status`, `to_status`, `payload`, `source`, `actor_user_id`, `occurred_at`.

#### `CaseTransport` — `case_transport` (legacy / perioperative)
A **separate, older** transport model tied to OR cases (`ORCase`), distinct from the
RTDC-grade `TransportRequest`. Status vocabulary is **different**: `Pending / In_Progress / Complete`. `transport_type` ∈ `Pre_Procedure / Post_Procedure`. Has rich helper methods (`start()`, `complete()`, `reassign()`, `reset()`, `getDurationAttribute`, `getDelayAttribute`, `isDelayed`). Belongs to `ORCase` + `User` (assigned). **Not exposed via the transport API routes** — it's part of the perioperative module. Mobile-relevant only if the perioperative transport board ships to mobile; otherwise treat as DESKTOP-ONLY/legacy.

**Vendor catalog** (`TransportOperationsService::vendorOptions()` — surfaced at `GET /transport/vendors`): `ride_health` (Ride Health — NEMT/wheelchair/stretcher/eligibility/webhooks), `uber_health`, `lyft_healthcare`, `contracted_ambulance` (BLS/ALS/critical_care), `careport` (CarePort/WellSky — post-acute referral/ADT), `aidin` (post-acute referral/authorization), `pulsara` (EMS ETA / prehospital handoff / team activation).

**Resource options** (`/transport/resources`): `porter_pool` (7), `discharge_lounge` (5), `wheelchair_bank` (18), `stretcher_pool` (9), `critical_care_team` (2).

> **Regional Transfer sub-domain.** `RegionalTransferController` + `RegionalTransferService` (45 KB) layer inter-facility *transfer decisioning* on top of `TransportRequest` (decision statuses `draft/accepted/redirected/deferred`, route simulation, and an AI "agent draft"). Surfaced at `/transport/regional-summary`, `/transport/regional-simulation`, and the per-request `regional-decision` / `regional-agent-draft` endpoints. This is **ops-leader / desktop** decisioning, not a frontline mobile flow.

---

### 1.3 EVS (Environmental Services / Bed Turns)

Bed-cleaning / room-turnover dispatch. **The second flagship mobile worker workflow**
(an EVS tech with a phone clearing dirty beds). Simpler lifecycle than transport.

**Models** (`app/Models/Evs/`)

#### `EvsRequest` — `prod.evs_requests`

| Field | Type | Notes |
|---|---|---|
| `evs_request_id` | int PK | |
| `request_uuid` | string | |
| `request_type` | string(80) | `bed_clean, room_clean, terminal_clean, isolation_clean, spill, discharge_turnover, procedure_turnover` |
| `priority` | string(40) | `routine` (default), `urgent, stat` |
| `status` | string(40) | **lifecycle**, default `requested`, see §3.2 |
| `room_id`, `bed_id`, `unit_id` | int | location FKs (nullable) |
| `patient_ref`, `encounter_ref` | string(120) | nullable |
| `location_label` | string(160) | **required** — human label (e.g. "5 West · 512-A") |
| `turn_type` | string(80) | `standard` (default), `terminal, isolation, stat, procedure, spill` |
| `isolation_required` | bool | default false — drives PPE/precautions |
| `requested_by` | string(120) | |
| `requested_at` | timestamp | useCurrent |
| `needed_at` | timestamp | SLA target |
| `assigned_at`, `started_at`, `completed_at` | timestamp | lifecycle stamps |
| `assigned_team` | string(120) | |
| `assigned_user_ref` | string(120) | the EVS tech |
| `external_system`, `external_id` | string | vendor linkage (e.g. bed-management platform) |
| `risk_flags`, `completion_payload`, `metadata` | jsonb | `completion_payload` captured on `completed` |
| `is_deleted` | bool | |

Indexes: `(status,needed_at)`, `(request_type,status)`, `(priority,needed_at)`, `(room_id,status)`, `(bed_id,status)`, `(unit_id,status)`, `(external_system,external_id)`. Scopes: `active()`, `forType()`. `hasMany EvsEvent`.

#### `EvsEvent` — `prod.evs_events`
Transition log (same shape; event types `evs.requested`, `evs.assigned`, `evs.in_progress`, `evs.completed`, …).

**Resource options** (`/evs/resources`): `evs_core_team` (8), `terminal_clean_team` (3), `isolation_clean_cart` (5), `spill_response` (2).

---

### 1.4 Facility Modeling (Digital Twin / Blueprint Import)

Imports facility blueprints/CAD into a canonical **spatial model**, then maps spaces
to operational entities (Location/Room/Unit/Bed). Powers the "4D navigator" and 3D
viewer. **Largely a back-office / desktop capability**; mobile would consume the
*resolved* space metadata, not the import pipeline.

**Models** (`app/Models/Facility/`)

#### `BlueprintImport` — `hosp_ingest.blueprint_imports`
One import job. Fields: `blueprint_import_id` PK, `import_uuid`, `source_name/type/uri/checksum`, `facility_code`, `facility_name`, `coordinate_units`, `coordinate_system`, `floor_height_ft` (decimal), `status`, `metadata` jsonb (carries `model_name`, `summary`), `imported_by`, `started_at`, `completed_at`. `hasMany BlueprintObject`.

#### `BlueprintObject` — `hosp_ingest.blueprint_objects`
A raw geometric object from the import (self-referential parent/child). Carries source identifiers (`source_object_id`, `source_global_id`, `source_layer`, `source_material`), geometry (`geometry_kind`, `position_ft`, `size_ft`, `bounds_ft`, `centroid_{x,y,z}_ft`, `gross/net_area_sqft`), `classification` jsonb, `extraction_confidence`, `review_status`, and **canonical linkage** (`canonical_schema/table/id`). `hasOne FacilitySpace`.

#### `FacilitySpace` — `hosp_space.facility_spaces`
The **canonical space** (room, unit, zone…). Self-referential (`parent_space_id`). Fields: `facility_space_id` PK, `blueprint_object_id` FK, `space_code`, `space_name`, `space_category`, `floor_label`, `floor_number`, `service_line_code`, `acuity_level`, `status`, `geometry` jsonb, `attributes` jsonb, `source_system`, `source_confidence`. Relationships fan out to operational entities: `hasMany Location / Room / Unit / Bed / OperationalSpaceMap`. **`space_code` is namespaced `"{facility_code}:..."`** (the controller filters with `like "{facility_code}:%"`).

#### `OperationalSpaceMap` — `hosp_space.operational_space_maps`
The **crosswalk** from a `FacilitySpace` to one operational entity. Fields: `facility_space_id`, plus exactly-one of `location_id/room_id/unit_id/bed_id`, `mapping_type`, `mapping_confidence`, `evidence` jsonb, `active` bool. `belongsTo FacilitySpace/Location/Room/Unit/Bed`.

**`GET /facility/model/summary`** returns aggregate counts only: imports by status, blueprint objects by category/review_status/floor, facility_spaces by category/status/floor, operational mappings (locations/rooms/units/beds mapped + unmapped_spaces), and prod-link counts. **No per-object geometry is exposed via API** — that lives in the dedicated 3D model/tileset URLs (`config('facility_models.zep_500.model_url' / 'tileset_url')`).

---

### 1.5 PatientFlow + FHIR Integration

The "4D patient-flow navigator": a real-time + replayable stream of patient movement
and clinical-context events across facility spaces, derived from HL7v2/FHIR/ambient
signals, projected into a patient-state + occupancy view, and exportable as FHIR.
**Read-heavy, visualization-oriented → mostly GLANCEABLE desktop; selected slices
(occupancy, my-unit movement) are mobile-glanceable.**

**Models** (`app/Models/PatientFlow/`, `app/Models/Fhir/`, `app/Models/Encounter.php`)

#### `PatientIdentity` — `flow_core.patient_identities`
String PK `patient_ref` (non-incrementing). `deidentified` bool, `metadata` jsonb. `hasMany FlowEncounter`, `hasMany FlowEvent`.

#### `FlowEncounter` — `flow_core.encounters`
String PK `encounter_ref`. Casts `started_at/ended_at/metadata`. `belongsTo PatientIdentity` (`patient_ref`), `belongsTo Encounter` (`prod_encounter_id → prod.encounters`), `hasMany FlowEvent`. (Bridges the flow stream to the canonical `prod.encounters`.)

#### `FlowEvent` — `flow_core.flow_events`
The core event. String PK `flow_event_id`. Rich casts: `occurred_at`, `recorded_at`, and clinical arrays `diagnosis_codes / order_codes / observation_codes / medication_codes`, `deidentified`, `metadata`. Has an `event_category` column (`movement` vs clinical-context). Relationships: `belongsTo Source`, `InboundMessage`, `CanonicalEventRecord`, `PatientIdentity`, `FlowEncounter`, and **two** FacilitySpace edges `fromFacilitySpace` / `toFacilitySpace`. Serialized event exposes (see `FlowEventRepository::serializeEvent`): `event_id, patient_id, patient_display_id, encounter_id, event_type, event_category, occurred_at, recorded_at, from_location, to_location, location_name, bed, service_line, floor, fhir_encounter_status, fhir_encounter_class`.

#### `OccupancySnapshot` — `flow_core.occupancy_snapshots`
Point-in-time occupancy per space. `snapshot_at`, `active_patient_count` int, `service_line_counts` jsonb, `acuity_counts` jsonb. `belongsTo FacilitySpace`, `belongsTo FlowEvent` (`generated_from_event_id`).

#### `AmbientSignalEvent` — `flow_realtime.ambient_signal_events`
Sensor/ambient-derived signal (RTLS, vision, etc.). `occurred_at`, `confidence_score` decimal, `normalized_payload`/`raw_payload` jsonb. `belongsTo AmbientSignalAdapterDefinition`, `FacilitySpace`, `FlowEvent` (`linked_flow_event_id`).

#### `AmbientSignalAdapterDefinition` — `flow_realtime.ambient_signal_adapters`
Adapter registry. `enabled` bool, `base_confidence`, `capability_payload` jsonb. `hasMany AmbientSignalEvent`.

#### `FhirBundleCache` — `flow_core.fhir_bundle_cache`
Cached FHIR Bundle for a flow event. `generated_at`, `bundle_json` jsonb. `belongsTo FlowEvent`.

#### `Encounter` — `prod.encounters` (canonical operational encounter)
`encounter_id` PK, `patient_ref`, `unit_id`, `bed_id`, `admitted_at`, `expected_discharge_date`, `acuity_tier` int, `status`, `discharged_at`, soft-delete. `belongsTo Unit/Bed`, scope `active()`.

#### FHIR resource store (`app/Models/Fhir/`)
- **`ResourceVersion` — `fhir.resource_versions`**: versioned raw FHIR resource. `last_updated`, `deleted_at`, `resource_data` jsonb. `belongsTo Source`, `IngestRun`.
- **`ResourceLink` — `fhir.resource_links`**: cross-references between FHIR resources. `metadata` jsonb. `belongsTo Source`.

**FHIR export shape** (`FhirBundleFactory::make`): a `Bundle` of `type:message` containing **Encounter + Patient + Location** resources, using `urn:zephyrus:*` identifier systems and HL7 v3 ActCode / location-physical-type code systems. The Encounter mirrors the flow event's `fhir_encounter_status`/`class` and to-location.

**Ingestion path (HL7v2 → FlowEvent).** `PatientFlowIngestController::hl7v2` accepts a raw HL7 ADT string (`raw_hl7` JSON field or raw body), runs `FlowEventNormalizer`, and upserts a normalized `FlowEvent` (HTTP **202**). The PatientFlow services include `Hl7V2Message`, `Hl7LocationData`, `FlowEventNormalizer`, `FlowEventRepository`, `PatientStateProjector` (reconstructs active-patient state + occupancy as-of a timestamp), `FacilitySpaceLocationResolver` (resolves locations against the facility model), `SyntheticFlowImporter` (demo data). Live source key = `synthetic-flow-ehr`.

---

### 1.6 Integration / Ingestion (the data backbone)

The canonical ingestion + provenance layer feeding everything above. **Operator/admin
back-office; mobile relevance is limited to a GLANCEABLE integration-health tile + a
NOTIFY on connector-down / dead-letter spikes.**

**Models** (`app/Models/Integration/`, `app/Models/Raw/`)

| Model | Table | Purpose / key fields |
|---|---|---|
| `Source` | `integration.sources` | The source-system registry. `source_key`, `source_name`, `vendor`, `system_class`, `interface_type`, `active_status`, `go_live_status`, `phi_allowed` bool, capability bools `smart_supported/bulk_supported/subscriptions_supported`, `metadata`. `hasMany IngestRun/InboundMessage/CanonicalEventRecord`. |
| `CanonicalEventRecord` | `integration.canonical_events` | Normalized canonical operational event. `occurred_at/received_at/projected_at`, `payload`/`metadata` jsonb, `projection_status` (pending/…). `belongsTo Source/IngestRun/InboundMessage`. |
| `ConnectorWatermark` | `integration.connector_watermarks` | Per-connector cursor/high-watermark. `last_success_at`, `metadata`. `belongsTo Source`. |
| `ProvenanceRecord` | `integration.provenance_records` | Lineage chain. `lineage` jsonb. `belongsTo Source/InboundMessage/CanonicalEventRecord`. |
| `InboundMessage` | `raw.inbound_messages` | Raw inbound payload + `normalized_payload`. `received_at`, `metadata`. `belongsTo Source/IngestRun`, `hasMany DeadLetter`. |
| `IngestRun` | `raw.ingest_runs` | One ingestion run with counters `messages_received/succeeded/failed/skipped`, `started_at/completed_at`, `status` (running/…). `belongsTo Source`, `hasMany InboundMessage`. |
| `DeadLetter` | `raw.dead_letters` | Failed messages. `context`/`metadata` jsonb, `status` (open/…), `resolved_at`, `replayed_at`. `belongsTo Source/IngestRun/InboundMessage`. |

**Healthcare connector framework** (`app/Integrations/Healthcare/`) — a vendor-pluggable connector abstraction:
- **Contracts:** `HealthcareConnector` (`sourceKey, capabilities, healthCheck, backfill, poll, handleWebhook, replay`), `CanonicalEventMapper`, `SourceMessageNormalizer`, `ProjectionHandler`.
- **DTOs:** `CanonicalOperationalEvent` (eventId, eventType, entityType/Ref, payload, occurredAt, idempotencyKey, correlation/causation IDs, sequenceKey, metadata), `ConnectorHealth` (status/message/metrics/metadata), `ConnectorCapabilities`, `SourceMessage`, `NormalizedPayload`, `WebhookEnvelope`, `PollRequest`, `BackfillRequest`, `ReplayRequest`.
- **Synthetic implementation:** `SyntheticHealthcareConnector` + mapper/normalizer (demo/sandbox).
- **Services:** `SourceRegistryService` (powers `/admin/integrations/health`), `EnterpriseConnectorControlService` (powers `/admin/integrations/enterprise`), `CanonicalEventWriter`, `RtdcProjectionHandler`.

**`GET /admin/integrations/health`** returns `{status: active|not_configured, sources:[{source_key, vendor, system_class, interface_type, active_status, go_live_status, phi_allowed, ingest_runs_count, inbound_messages_count, canonical_events_count, updated_at}], counts:{sources, active_sources, open_dead_letters, running_ingest_runs, pending_canonical_events}}`. → **This is the natural mobile integration-health glance + alert source.**

**`GET /admin/integrations/enterprise`** returns interface-engine / FHIR-connection / SMART-credential / playbook / coexistence-adapter counts and catalogs. Writeback drafts (`POST /admin/integrations/enterprise/writeback-drafts`) support resource types `Task, ServiceRequest, TransportRequest, EvsRequest, SecureMessage` → **confirms transport & EVS are first-class FHIR-writeback targets.**

---

## 2. Full API endpoint inventory (`routes/api.php`)

**Total endpoints: 106** (65 GET · 40 POST · 1 PUT — **41 mutations**). Every group
except `cases`, `blocks`, the second `analytics` block, the `improvement` map, and
reference-data is gated by **`['web', 'auth']`** (session-cookie / web guard).
**Every** group is rate-limited `throttle:60,1` (60 req/min). There is a single
unauthenticated `GET /health`.

> Mobile flag legend: **A**=Actionable (write/mutate worker flow) · **G**=Glanceable (read summary/list) · **N**=Notify-worthy · **D**=Desktop-only.

### 2.1 Health & cross-cutting
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/health` | closure | DB connectivity health probe | **none** | N (infra) |
| GET | `/command-center/drilldown` | `CommandCenterController@drilldown` | Command-center metric drill-downs | web+auth | G |

### 2.2 Facility
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/facility/model/summary` | `FacilityModelController@summary` | Digital-twin import/space/mapping counts | web+auth | D |

### 2.3 Patient Flow (4D navigator + ingest + stream)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/patient-flow/summary` | `PatientFlowController@summary` | Counts: messages, events, patients, locations, ambient, model URLs | web+auth | G |
| GET | `/patient-flow/locations` | `PatientFlowController@locations` | Navigator locations (+ops-graph nodes) | web+auth | D |
| GET | `/patient-flow/events` | `PatientFlowController@events` | Filtered flow events | web+auth | D |
| GET | `/patient-flow/tracks` | `PatientFlowController@tracks` | Events grouped per patient (tracks) | web+auth | D |
| GET | `/patient-flow/state` | `PatientFlowController@state` | Active-patient state + occupancy as-of `asOf` | web+auth | G |
| GET | `/patient-flow/ambient` | `PatientFlowController@ambient` | Ambient-signal summary | web+auth | G |
| GET | `/patient-flow/fhir/bundle` | `PatientFlowController@fhirBundle` | FHIR Bundle for one `event_id` | web+auth | D |
| GET | `/patient-flow/stream/adt` | `PatientFlowStreamController` (invokable) | **SSE** replay stream of ADT/flow events | web+auth | D (SSE) |
| POST | `/patient-flow/ingest/hl7v2` | `PatientFlowIngestController@hl7v2` | Ingest raw HL7v2 ADT → FlowEvent (202) | web+auth | D (system) |

### 2.4 RTDC — Real-Time Demand Capacity
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/rtdc/units` | `CensusController@units` | Unit census list | web+auth | G |
| GET | `/rtdc/units/{unitId}/prediction` | `PredictionController@show` | Capacity/demand prediction for a unit | web+auth | G |
| POST | `/rtdc/units/{unitId}/capacity` | `PredictionController@capacity` | Submit/update capacity inputs | web+auth | A |
| POST | `/rtdc/units/{unitId}/demand` | `PredictionController@demand` | Submit/update demand inputs | web+auth | A |
| POST | `/rtdc/units/{unitId}/plan` | `PredictionController@plan` | Save unit plan | web+auth | A |
| POST | `/rtdc/huddles` | `HuddleController@open` | Open a bed huddle | web+auth | A |
| POST | `/rtdc/huddles/{huddleId}/close` | `HuddleController@close` | Close a huddle | web+auth | A |
| GET | `/rtdc/bed-meeting` | `HuddleController@bedMeeting` | Bed-meeting view data | web+auth | G |
| GET | `/rtdc/barriers` | `BarrierController@index` | List discharge barriers | web+auth | G |
| POST | `/rtdc/barriers` | `BarrierController@store` | Create a barrier | web+auth | A |
| POST | `/rtdc/barriers/{barrierId}/resolve` | `BarrierController@resolve` | Resolve a barrier | web+auth | A·N |
| GET | `/rtdc/units/{unitId}/reliability` | `ReconciliationController@latest` | Latest reconciliation/reliability | web+auth | G |
| GET | `/rtdc/bed-requests` | `BedRequestController@index` | List bed requests | web+auth | G |
| POST | `/rtdc/bed-requests` | `BedRequestController@store` | Create bed request | web+auth | A |
| GET | `/rtdc/bed-requests/{id}/recommendations` | `BedRequestController@recommendations` | Bed placement recommendations | web+auth | G |
| POST | `/rtdc/bed-requests/{id}/decision` | `BedRequestController@decision` | Decide a bed request | web+auth | A·N |

### 2.5 Transport ⭐ (mobile worker workflow)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/transport/overview` | `TransportRequestController@overview` | KPI metrics + prioritized queue | web+auth | **G** |
| GET | `/transport/regional-summary` | `RegionalTransferController@summary` | Regional transfer dashboard | web+auth | D |
| POST | `/transport/regional-simulation` | `RegionalTransferController@simulate` | Run route simulation | web+auth | D |
| GET | `/transport/requests` | `TransportRequestController@index` | Paginated request list (filter type/status/priority) | web+auth | **G** |
| POST | `/transport/requests` | `TransportRequestController@store` | Create transport request (201) | web+auth | **A** |
| GET | `/transport/requests/{id}` | `TransportRequestController@show` | Request detail + event history | web+auth | **G** |
| POST | `/transport/requests/{id}/regional-decision` | `RegionalTransferController@decide` | Regional accept/redirect/defer | web+auth | D |
| POST | `/transport/requests/{id}/regional-agent-draft` | `RegionalTransferController@agentDraft` | AI-draft a regional transfer | web+auth | D |
| POST | `/transport/requests/{id}/assign` | `TransportRequestController@assign` | Assign team/vendor → `assigned` | web+auth | **A** |
| POST | `/transport/requests/{id}/status` | `TransportRequestController@status` | Advance lifecycle status | web+auth | **A** ⭐ |
| POST | `/transport/requests/{id}/cancel` | `TransportRequestController@cancel` | Cancel request | web+auth | **A** |
| POST | `/transport/requests/{id}/handoff` | `TransportRequestController@handoff` | Complete patient handoff | web+auth | **A·N** ⭐ |
| GET | `/transport/resources` | `TransportRequestController@resources` | Internal resource pool options | web+auth | G |
| GET | `/transport/vendors` | `TransportRequestController@vendors` | External vendor catalog | web+auth | G |

### 2.6 EVS ⭐ (mobile worker workflow)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/evs/overview` | `EvsRequestController@overview` | KPI metrics (dirty beds, isolation) + queue | web+auth | **G** |
| GET | `/evs/requests` | `EvsRequestController@index` | Paginated bed-turn list | web+auth | **G** |
| POST | `/evs/requests` | `EvsRequestController@store` | Create bed-turn request (201) | web+auth | **A** |
| GET | `/evs/requests/{id}` | `EvsRequestController@show` | Request detail + event history | web+auth | **G** |
| POST | `/evs/requests/{id}/assign` | `EvsRequestController@assign` | Assign team/tech → `assigned` | web+auth | **A** |
| POST | `/evs/requests/{id}/status` | `EvsRequestController@status` | Advance lifecycle (`in_progress`,`completed`,…) | web+auth | **A** ⭐ |
| POST | `/evs/requests/{id}/cancel` | `EvsRequestController@cancel` | Cancel request | web+auth | **A** |
| GET | `/evs/resources` | `EvsRequestController@resources` | EVS resource pool options | web+auth | G |

### 2.7 Staffing (mobile-relevant for coordinators)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/staffing/overview` | `StaffingController@overview` | KPI metrics + coverage + at-risk units + queue | web+auth | **G·N** |
| GET | `/staffing/plans` | `StaffingController@plans` | Today's plans + coverage + units_at_risk | web+auth | G |
| GET | `/staffing/requests` | `StaffingController@index` | Paginated request list (filter role/status/priority/unit) | web+auth | G |
| POST | `/staffing/requests` | `StaffingController@store` | Create staffing request (201) | web+auth | **A** |
| GET | `/staffing/requests/{id}` | `StaffingController@show` | Request detail + event history | web+auth | G |
| POST | `/staffing/requests/{id}/assign` | `StaffingController@assign` | Assign source/staff → `assigned` | web+auth | **A** |
| POST | `/staffing/requests/{id}/status` | `StaffingController@status` | Advance lifecycle (`filled`,`completed`,…) | web+auth | **A** |
| POST | `/staffing/requests/{id}/cancel` | `StaffingController@cancel` | Cancel request | web+auth | **A** |
| GET | `/staffing/resources` | `StaffingController@resources` | Fill-source options | web+auth | G |

### 2.8 Operations Graph / Agents / Actions
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/ops/graph/snapshot` | `OperationsGraphController@snapshot` | Operations-graph snapshot | web+auth | D |
| GET | `/ops/graph/nodes/{node}` | `OperationsGraphController@node` | Single graph node | web+auth | D |
| GET | `/ops/recommendations` | `OperationsGraphController@recommendations` | Recommendations | web+auth | G |
| GET | `/ops/agent-inbox` | `OperationsGraphController@agentInbox` | Agent inbox items | web+auth | G·N |
| GET | `/ops/agents/definitions` | `AgentController@definitions` | Available agent definitions | web+auth | D |
| POST | `/ops/agents/capacity-commander/run` | `AgentController@runCapacityCommander` | Run capacity-commander agent | web+auth | A |
| POST | `/ops/agents/data-quality/run` | `AgentController@runDataQuality` | Run data-quality agent | web+auth | A |
| POST | `/ops/agents/executive-briefing/run` | `AgentController@runExecutiveBriefing` | Run executive-briefing agent | web+auth | A |
| GET | `/ops/agents/runs/{run}` | `AgentController@show` | Agent run detail | web+auth | G |
| POST | `/ops/approvals/{approval}/decision` | `OperationalActionController@decideApproval` | Approve/deny an action | web+auth | **A·N** |
| POST | `/ops/actions/{action}/assign` | `OperationalActionController@assign` | Assign an operational action | web+auth | A |
| POST | `/ops/actions/{action}/start` | `OperationalActionController@start` | Start an action | web+auth | A |
| POST | `/ops/actions/{action}/complete` | `OperationalActionController@complete` | Complete an action | web+auth | A |
| POST | `/ops/actions/{action}/override` | `OperationalActionController@override` | Override an action | web+auth | A |
| POST | `/ops/actions/{action}/expire` | `OperationalActionController@expire` | Expire an action | web+auth | A |
| POST | `/ops/simulation-scenarios/{scenario}/promote` | `SimulationController@promote` | Promote a simulation scenario | web+auth | D |

### 2.9 Admin / Integrations
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/admin/integrations/health` | `IntegrationHealthController` (invokable) | Source health + dead-letter/run counts | web+auth | **G·N** |
| GET | `/admin/integrations/enterprise` | `EnterpriseConnectorController@summary` | Enterprise connector catalog/counts | web+auth | D |
| POST | `/admin/integrations/enterprise/fhir/capability-discovery` | `EnterpriseConnectorController@discoverFhir` | Probe FHIR capability statement | web+auth | D |
| POST | `/admin/integrations/enterprise/writeback-drafts` | `EnterpriseConnectorController@createWritebackDraft` | Draft a FHIR writeback (Task/ServiceRequest/Transport/EVS/SecureMessage) | web+auth | D |

### 2.10 Perioperative — OR Cases & Block Schedule (⚠ no `auth` middleware)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/cases` | `ORCaseController@index` | List OR cases | throttle only | G |
| POST | `/cases` | `ORCaseController@store` | Create OR case | throttle only | A |
| PUT | `/cases/{id}` | `ORCaseController@update` | Update OR case | throttle only | A |
| GET | `/cases/today` | `ORCaseController@todaysCases` | Today's cases | throttle only | G |
| GET | `/cases/metrics` | `ORCaseController@metrics` | OR case metrics | throttle only | G |
| GET | `/cases/room-status` | `ORCaseController@roomStatus` | OR room status board | throttle only | G |
| GET | `/blocks` | `BlockScheduleController@index` | List block schedule | throttle only | G |
| POST | `/blocks` | `BlockScheduleController@store` | Create block | throttle only | A |
| GET | `/blocks/utilization` | `BlockScheduleController@utilization` | Block utilization | throttle only | G |
| GET | `/blocks/service-utilization` | `BlockScheduleController@serviceUtilization` | Utilization by service | throttle only | G |
| GET | `/blocks/room-utilization` | `BlockScheduleController@roomUtilization` | Utilization by room | throttle only | G |

### 2.11 Analytics
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/analytics/overview` | `AnalyticsController@overview` | Analytics overview | web+auth | G |
| GET | `/analytics/live` | `AnalyticsController@live` | Live analytics | web+auth | G |
| GET | `/analytics/retrospective` | `AnalyticsController@retrospective` | Retrospective analytics | web+auth | D |
| GET | `/analytics/predictive` | `AnalyticsController@predictive` | Predictive analytics | web+auth | G |
| GET | `/analytics/process-intelligence` | `AnalyticsController@processIntelligence` | Process intelligence | web+auth | D |
| GET | `/analytics/opportunities` | `AnalyticsController@opportunities` | Improvement opportunities | web+auth | G |
| GET | `/analytics/workbench` | `AnalyticsController@workbench` | Analytics workbench | web+auth | D |
| GET | `/analytics/data-quality` | `AnalyticsController@dataQuality` | Data-quality analytics | web+auth | G·N |
| GET | `/analytics/metrics/{metricKey}/lineage` | `AnalyticsController@metricLineage` | Metric lineage (key-constrained) | web+auth | D |
| GET | `/analytics/service-performance` | `AnalyticsController@servicePerformance` | Service performance | throttle only | G |
| GET | `/analytics/provider-performance` | `AnalyticsController@providerPerformance` | Provider performance | throttle only | G |
| GET | `/analytics/historical-trends` | `AnalyticsController@historicalTrends` | Historical trends | throttle only | G |

### 2.12 Reference Data & Improvement (⚠ no `auth` middleware)
| Method | Path | Controller@method | Purpose | Auth | Mobile |
|---|---|---|---|---|---|
| GET | `/services` | `ServiceController@index` | Clinical services list | throttle only | G (ref) |
| GET | `/rooms` | `RoomController@index` | Rooms list | throttle only | G (ref) |
| GET | `/providers` | `ProviderController@index` | Providers list | throttle only | G (ref) |
| GET | `/improvement/api/nursing-operations` | closure | Bed-placement process map JSON (static fixture) | throttle only | D |

> **Counts by group:** health/cross-cutting 2 · facility 1 · patient-flow 9 · rtdc 16 ·
> transport 14 · evs 8 · staffing 9 · ops 16 · admin/integrations 4 · cases 6 ·
> blocks 5 · analytics 12 · reference 3 · improvement 1 = **106 distinct route
> registrations** (65 GET · 40 POST · 1 PUT → **41 mutations**; rest are reads).

---

## 3. Workflows (lifecycle state machines)

> The services don't enforce a strict state graph — `transition()` accepts any valid
> status string from the FormRequest enum and stamps the matching timestamp. So the
> sequences below are the **intended/expected** flows; a mobile client should drive
> them but tolerate out-of-order events from other actors (web/desktop/vendor webhooks).

### 3.1 Transport request lifecycle ⭐

**Full status vocabulary** (`TransportStatusUpdateRequest` enum):
`requested → accepted → queued → assigned → dispatched → arrived_pickup →
patient_ready | patient_not_ready → picked_up → en_route → arrived_destination →
handoff_started → handoff_complete → completed` · plus `canceled`, `escalated`,
`failed`.

```
                                   ┌─ patient_not_ready ─┐ (loop back)
requested → accepted → queued → assigned → dispatched → arrived_pickup → patient_ready
   → picked_up → en_route → arrived_destination → handoff_started → handoff_complete → completed
                         └──────────────────── escalated / canceled / failed ─────────────────────┘
```

| Step | Endpoint | Side effects (service) |
|---|---|---|
| Create | `POST /transport/requests` | status=`requested`, `requested_at` set, emits `transport.requested` |
| Assign | `POST …/{id}/assign` | status=`assigned`, sets `assigned_team`/`assigned_vendor`, `assigned_at`, emits `transport.assigned` (requires team **or** vendor) |
| Dispatch / progress | `POST …/{id}/status` | sets `dispatched_at` on first `dispatched`; emits `transport.{status}` |
| Handoff | `POST …/{id}/handoff` | status=`handoff_complete`, fills `handoff` jsonb `{handoff_to, summary, documents[], outstanding_risks[]}`, emits `transport.handoff_complete` (requires `handoff_to`) |
| Complete/Cancel/Fail | `POST …/{id}/status` or `/cancel` | sets `completed_at` once; terminal |

**At-risk rule** (drives queue ordering + the `at_risk` SLA flag): `priority == stat` **OR** (`needed_at` is past AND not terminal). Queue sorts `stat→urgent→routine`, then earliest `needed_at`.

### 3.2 EVS bed-turn lifecycle ⭐

**Status vocabulary** (`EvsStatusUpdateRequest`):
`requested → queued → assigned → in_progress → completed` · plus `canceled`,
`escalated`, `failed`.

```
requested → queued → assigned → in_progress → completed
                 └──────── escalated / canceled / failed ────────┘
```

| Step | Endpoint | Side effects |
|---|---|---|
| Create | `POST /evs/requests` | status=`requested`, `requested_at` set, emits `evs.requested` (requires `location_label`, `turn_type`) |
| Assign | `POST …/{id}/assign` | status=`assigned`, sets `assigned_team`/`assigned_user_ref`, `assigned_at`, emits `evs.assigned` (team **or** user_ref) |
| Start | `POST …/{id}/status` `{status:in_progress}` | sets `started_at` on first entry, emits `evs.in_progress` |
| Complete | `POST …/{id}/status` `{status:completed, payload:{…}}` | sets `completed_at`, stores `completion_payload`, emits `evs.completed` |
| Cancel/Fail | `…/cancel` or status | sets `completed_at` once; terminal |

**Special-case fields the mobile UI must surface:** `isolation_required` (PPE), `turn_type` (terminal/isolation/spill drive different SOPs), `room_id`/`bed_id`/`location_label`. **At-risk rule** identical to transport.

### 3.3 Staffing request lifecycle

**Status vocabulary** (`StaffingStatusUpdateRequest`):
`requested → open → sourcing → assigned → filled → completed` · plus `canceled`,
`escalated`, `unfilled`.

```
requested → open → sourcing → assigned → filled → completed
                 └────── escalated / canceled / unfilled ──────┘
```

| Step | Endpoint | Side effects |
|---|---|---|
| Create | `POST /staffing/requests` | status=`requested`, `shift_date` defaulted to today, emits `staffing.requested` |
| Assign | `POST …/{id}/assign` | status=`assigned`, sets `assigned_source` (**required** ∈ float_pool/overtime/agency/on_call), `assigned_staff_ref`, `owner_name`, `assigned_at`, emits `staffing.assigned` |
| Fill | `POST …/{id}/status` `{status:filled}` | sets `filled_at`, stores `resolution_payload`, emits `staffing.filled` |
| Complete / Unfilled | status | sets `completed_at` once; `unfilled` is a terminal "could not staff" |

**Demand signal (the "why"):** `StaffingPlan.gap()` and `belowMinimumSafe()` drive `units_at_risk` and `critical_gaps` on `/staffing/overview` and `/staffing/plans`. A coordinator's mobile push would fire on a unit dropping below `minimum_safe_count`.

### 3.4 Regional transfer decision (transport sub-flow, desktop)
`POST …/{id}/regional-decision` with `decision_status ∈ {draft, accepted, redirected, deferred}` + `selected_facility_code`; plus `regional-simulation` (route sim) and `regional-agent-draft` (AI-drafted recommendation). Ops-leader workflow.

---

## 4. Roles

There is **no per-endpoint RBAC** on the operational APIs — they authorize via session
auth only (`authorize(): true` on every FormRequest; the `assigned_*` fields are free
strings, not FK-validated user IDs). Admin endpoints are the exception:
`AuthProviderController` gates on `user.role ∈ {admin, superuser}`.

**Operational roles (implied by the data model & resource catalogs), relevant to mobile worker assignment:**

| Role | Domain | Where it appears | Mobile persona |
|---|---|---|---|
| **Transporter / Porter** | Transport | `assigned_team` (`porter_pool`), `critical_care_team`; drives the status lifecycle | ⭐ Frontline mobile worker |
| **EVS Tech** | EVS | `assigned_user_ref`, `assigned_team` (`evs_core_team`, `terminal_clean_team`, `spill_response`) | ⭐ Frontline mobile worker |
| **Staffing Coordinator** | Staffing | `owner_name`, request creator/assigner | Ops-leader mobile (assign/source) |
| **Charge Nurse** | Staffing | `role: charge`; the unit-level demand owner who raises requests | Frontline/ops mobile (raise + watch coverage) |
| **RN / LPN / Tech / Provider / Respiratory / Unit Secretary** | Staffing | `StaffingPlan.role` / `StaffingRequest.role` | Subjects of staffing (not app actors per se) |
| **Bed-flow / Capacity manager** | RTDC | bed-request decisions, huddles, barriers | Ops-leader mobile |
| **Dispatcher / Transfer-center agent** | Transport (regional) | regional-decision/simulation | Desktop ops-leader |
| **Integration operator / Admin** | Integration | `admin/integrations/*`, `AuthProviderController` (role-gated) | Glance + alert only |
| **Executive** | Analytics / ops agents | executive-briefing, analytics | Glanceable digest |

> **System auth model** (per `.claude/rules/auth-system.md`): app users carry
> `role` (default `user`), `is_active`, `must_change_password`. The 11 protected auth
> rules (temp-password + Resend email, forced change-password) apply to the mobile app
> too — Hummingbird login must honor `must_change_password` and won't have user-chosen
> passwords on registration.

---

## 5. Mobile relevance per domain

| Domain | Flag | Rationale & mobile surface |
|---|---|---|
| **Transport** | **ACTIONABLE** ⭐⭐ | Canonical frontline worker flow. A transporter's whole day is `assign → dispatch → arrived_pickup → picked_up → en_route → arrived_destination → handoff_complete`. Each status hop is a single tap. `/transport/overview` gives a glanceable queue; `/requests/{id}` gives the job card; handoff is a structured form. |
| **EVS** | **ACTIONABLE** ⭐⭐ | Second frontline worker flow. EVS tech: `assigned → in_progress → completed` on a dirty bed, with `isolation_required`/`turn_type` driving SOP. Tight loop, ideal for "claim next from queue". `/evs/overview` shows dirty-bed + isolation counts. |
| **Staffing** | **ACTIONABLE (coordinator) / NOTIFY (charge)** | Coordinators create + assign fill requests from a phone; charge nurses watch coverage and get pushed when a unit drops below `minimum_safe_count` or hits `critical_gap`. `/staffing/overview` + `/plans` are the glance. |
| **RTDC bed-requests / barriers** | **ACTIONABLE / NOTIFY** | Bed-request decisions and barrier-resolve are mobile-friendly approvals; barrier creation/resolution and bed decisions are notify-worthy. |
| **Ops actions / approvals** | **ACTIONABLE / NOTIFY** | `approvals/{id}/decision` and `actions/{id}/{start,complete}` are exactly mobile "approve/act on the go". Agent-inbox is a notify feed. |
| **PatientFlow** | **GLANCEABLE (slices) / DESKTOP-ONLY (navigator)** | `/state` (active patients + occupancy) and `/summary`/`/ambient` are glanceable; the full events/tracks/locations 4D navigator + SSE stream + FHIR bundle are desktop visualization. |
| **Integration health** | **GLANCEABLE / NOTIFY** | `/admin/integrations/health` → a status tile + push on connector-down, `open_dead_letters` spike, or `running_ingest_runs` stuck. Enterprise connector mgmt + writeback drafts are desktop. |
| **Facility modeling** | **DESKTOP-ONLY** | Blueprint import/space mapping is back-office; mobile only consumes resolved space labels indirectly (via EVS/transport location refs). |
| **Perioperative (cases/blocks)** | **GLANCEABLE / (legacy CaseTransport ACTIONABLE)** | Room-status + today's cases are glanceable; the legacy `CaseTransport` board *could* be a mobile worker flow but isn't wired to the new API. |
| **Analytics** | **GLANCEABLE (digests) / DESKTOP-ONLY (workbench)** | Overviews/opportunities/predictive are digest cards; workbench/lineage/process-intelligence are desktop. |

### 5.1 Top mobile-relevant worker workflows (priority order)
1. **Transport status progression** — `POST /transport/requests/{id}/status` (+`/assign`, `/handoff`). **ACTIONABLE.** The single highest-value mobile interaction. ~7 tap-states per trip.
2. **EVS bed-turn progression** — `POST /evs/requests/{id}/status` (`in_progress`→`completed`) + `/assign`. **ACTIONABLE.** "Claim → clean → done" loop.
3. **Staffing fill (coordinator)** — `POST /staffing/requests` + `/{id}/assign` + `/{id}/status`. **ACTIONABLE.** Raise/source/fill from the floor.
4. **RTDC bed-request decision & barrier resolve** — **ACTIONABLE/NOTIFY.**
5. **Ops approvals/actions** — `POST /ops/approvals/{id}/decision`, `/ops/actions/{id}/{start,complete}`. **ACTIONABLE.**

---

## 6. Notification opportunities (push to Hummingbird)

These derive directly from the data model — no new server state needed, just a
fan-out on the existing event logs / SLA computations:

| Trigger | Source signal | Audience | Priority |
|---|---|---|---|
| **New STAT/urgent transport** assigned to me | `TransportEvent transport.assigned` where `priority=stat\|urgent` | Transporter | High |
| **Transport SLA breach** (`needed_at` passed, not terminal) | `serializeRequest.sla.at_risk` | Transporter + dispatcher | High |
| **Patient ready / not ready** at pickup | `transport.patient_ready` / `patient_not_ready` | Transporter | Med |
| **Handoff completed / outstanding risks** | `transport.handoff_complete` (`handoff.outstanding_risks` non-empty) | Receiving unit, charge | Med·High |
| **New STAT bed-turn / isolation clean** | `EvsEvent evs.requested\|assigned` where `priority=stat` or `isolation_required=true` | EVS tech | High |
| **EVS bed-turn overdue** | EVS `sla.at_risk` | EVS lead + bed-flow | High |
| **Bed-turn completed** → bed clean & ready | `evs.completed` | Bed-flow / charge (unblocks placement) | High |
| **Unit below minimum-safe staffing** | `StaffingPlan.belowMinimumSafe()` / status `critical_gap` | Coordinator + charge | High |
| **Staffing request unfilled / escalated** | `staffing.unfilled` / `staffing.escalated` | Coordinator | High |
| **Bed-request decision needed / made** | `BedRequest` decision endpoints | Bed-flow manager | High |
| **Barrier raised / resolved** | RTDC `barriers` | Discharge team | Med |
| **Ops approval awaiting decision** | `ops/agent-inbox`, `approvals` | Approver | High |
| **Connector down / dead-letter spike** | `/admin/integrations/health` `open_dead_letters`, `active_sources` drop | Integration operator | Med |
| **Action assigned to me / expiring** | `OperationalAction` assign/expire | Action owner | Med |

> **Implementation note for push:** the API is **session-cookie based with no
> WebSocket/broadcast layer** today (the only realtime is the PatientFlow **SSE** replay
> endpoint, which is a demo stream, not a live event bus). To deliver these
> notifications Hummingbird will need either (a) a new push/broadcast service
> (FCM/APNs + Laravel Echo/Reverb on the event logs), or (b) periodic polling of the
> `*/overview` + `…/{id}` endpoints. The event tables (`*_events`) are the natural
> change-feed to drive (a).

---

## 7. Cross-domain data relationships (entity map)

```
PatientIdentity (flow_core) ──< FlowEncounter ──< FlowEvent >── Source (integration)
       │                              │                │            │
       │                              └─ prodEncounter │            ├──< IngestRun ──< InboundMessage ──< DeadLetter
       │                                 (prod.encounters)          ├──< CanonicalEventRecord ──< ProvenanceRecord
       │                                                            ├──< ConnectorWatermark
FlowEvent.from/toFacilitySpace ──> FacilitySpace (hosp_space)       └──  (fhir) ResourceVersion / ResourceLink
                                        │
   BlueprintImport ──< BlueprintObject ─┘ (1:1)
                                        │
                                        └──< OperationalSpaceMap ──> Location / Room / Unit / Bed (prod)
                                                                                     │
OccupancySnapshot ──> FacilitySpace                                                  │
AmbientSignalEvent ──> FacilitySpace, AmbientSignalAdapterDefinition, FlowEvent      │
                                                                                     │
Encounter (prod) ──> Unit, Bed ──────────────────────────────────────────────────────┘

── Worker domains (self-contained, weak refs via *_ref string columns) ──
TransportRequest ──< TransportEvent          (patient_ref / encounter_ref are STRINGS, not FKs)
EvsRequest       ──< EvsEvent                (room_id / bed_id / unit_id are loose ints)
StaffingPlan     ──< StaffingRequest ──< StaffingEvent   (unit_id loose)
CaseTransport ──> ORCase, User               (legacy perioperative, not API-exposed)
```

**Key insight for mobile data sync:** the worker domains (Transport/EVS/Staffing) are
**deliberately decoupled** — they reference patients/encounters/locations by *string
or loose-int ref*, not hard foreign keys. This makes them **independently cacheable
and offline-friendly** on a mobile client (a transport job card carries `patient_ref`,
`origin`, `destination` as denormalized strings; no join required to render it).
PatientFlow/Facility/Integration form the tightly-joined canonical backbone that the
worker domains float on top of.

---

## 8. Field-level reference: write payloads the mobile client must construct

| Action | Endpoint | Required fields | Optional fields |
|---|---|---|---|
| Create transport | `POST /transport/requests` | `request_type` (enum), `priority` (enum), `patient_ref`, `origin`, `destination`, `transport_mode` (enum) | `encounter_ref`, `clinical_service`, `requested_by`, `needed_at`, `assigned_team`, `assigned_vendor`, `external_system/id`, `segments[]`, `risk_flags[]`, `handoff{}`, `metadata{}` |
| Assign transport | `POST …/assign` | `assigned_team` **or** `assigned_vendor` | `note` |
| Transport status | `POST …/status` | `status` (enum) | `note`, `payload{}` |
| Transport handoff | `POST …/handoff` | `handoff_to` | `handoff_summary`, `documents[]`, `outstanding_risks[]` |
| Create EVS | `POST /evs/requests` | `request_type` (enum), `priority` (enum), `location_label`, `turn_type` (enum) | `room_id`, `bed_id`, `unit_id`, `patient_ref`, `encounter_ref`, `isolation_required`, `requested_by`, `needed_at`, `assigned_team`, `assigned_user_ref`, `external_system/id`, `risk_flags[]`, `completion_payload{}`, `metadata{}` |
| Assign EVS | `POST …/assign` | `assigned_team` **or** `assigned_user_ref` | `note` |
| EVS status | `POST …/status` | `status` (enum) | `note`, `payload{}` |
| Create staffing | `POST /staffing/requests` | `unit_label`, `role` (enum), `shift` (enum), `request_type` (enum), `priority` (enum), `headcount_needed` (1–50) | `unit_id`, `staffing_plan_id`, `shift_date`, `hours_needed` (0–24), `requested_by`, `needed_by`, `owner_name`, `risk_flags[]`, `metadata{}` |
| Assign staffing | `POST …/assign` | `assigned_source` (enum: float_pool/overtime/agency/on_call) | `assigned_staff_ref`, `owner_name` |
| Staffing status | `POST …/status` | `status` (enum) | `note`, `payload{}` |

All list endpoints return `{data:[…], meta:{current_page, last_page, total}}` (page size **50**). All single-request reads return `{data:{…serialized…, events:[…]}}`. Mutations return `{data:{…serialized request…}}`; create returns **201**.

---

## 9. Mobile auth implications

- **Session-cookie auth (web guard), not bearer tokens.** Every operational API group
  is `['web','auth']`. The browser SPA sends the session cookie + `X-XSRF-TOKEN`
  header (`bootstrap.js withXSRFToken=true`). A native mobile client must either:
  (a) implement the **Sanctum SPA cookie flow** (`/sanctum/csrf-cookie` → login →
  carry the `laravel_session` cookie + XSRF header on every write), or
  (b) introduce a **token guard** (Sanctum personal-access tokens / OAuth) — *not
  present today* and would be net-new server work.
- **CSRF** is required on all POST/PUT (auto-skipped only in the testing env).
- **Forced password change** (`must_change_password`) and the temp-password + Resend
  registration flow (see `.claude/rules/auth-system.md`) apply — Hummingbird login must
  redirect to change-password before granting app access; registration cannot accept a
  user-chosen password.
- **Authentik SSO** is configurable via `AuthProviderController` (`provider_type`,
  `is_enabled`, OIDC settings; `client_secret` lives in env only) — a likely path for
  enterprise mobile SSO.
- **Rate limit:** 60 requests/minute per route group (`throttle:60,1`) — mobile
  polling must stay under this.
- **⚠ Inconsistency to flag:** `cases`, `blocks`, `services`, `rooms`, `providers`,
  `analytics/{service,provider,historical}-*`, and `improvement/*` carry **only
  `throttle`, no `auth`** — they are effectively public read/write. If mobile uses
  these, note the gap (and that OR-case create/update is unauthenticated).

---

## 10. Summary counts

- **Domains mapped:** 6 in-scope supporting domains (Staffing, Transport, EVS,
  Facility, PatientFlow+FHIR, Integration) + adjacent RTDC/Ops/Analytics/Perioperative.
- **Models documented:** **30+** — Staffing (3), Transport (2)+CaseTransport, EVS (2),
  Facility (4), PatientFlow (7), FHIR (2)+Encounter, Integration (4), Raw (3).
- **Worker-domain lifecycles:** Transport (17 statuses), EVS (8), Staffing (9).
- **Total API endpoints:** **106** distinct route registrations in `routes/api.php`
  (65 GET · 40 POST · 1 PUT = 41 mutations). All operational groups web+auth+throttle;
  one public `/health`.
- **Flagship mobile workflows:** Transport status progression ⭐, EVS bed-turn ⭐,
  Staffing fill, RTDC bed/barrier decisions, Ops approvals/actions.
- **Notification opportunities:** ~14 high-value pushes derivable from existing event
  logs + SLA flags (no live event bus exists yet — SSE replay is demo-only).
