# Hummingbird Research 02 — RTDC (Real-Time Demand & Capacity) Subsystem Inventory

**Subsystem:** Real-Time Demand & Capacity — the house-wide bed / capacity / patient-flow command center.
**Stack:** Laravel 11 + React/Inertia (mixed `.jsx` legacy + `.tsx` modern), PostgreSQL (`prod.*` schema), Laravel Reverb (WebSockets) via `laravel-echo` + `pusher-js`, TanStack Query on the client.
**Date of inventory:** 2026-06-26.
**Purpose:** Feed the "Hummingbird" mobile companion (Kotlin/Android + Swift/iOS) implementation plan. Every data element, endpoint, page, and real-time channel is catalogued with a mobile-relevance flag.

> **Critical architectural note for mobile:** RTDC has **two parallel layers**.
> 1. **The "real" RTDC engine** (production-grade): the `.tsx` pages (`BedPlacement`, `UnitHuddle`, `GlobalHuddle`), the `/api/rtdc/*` JSON API, the `app/Rtdc/*` event-sourced census engine, the `prod.*` tables, and the **three Reverb broadcast channels**. This is the canonical, live, mobile-targetable surface.
> 2. **Legacy mock dashboards** (`.jsx` pages under `RTDC/Analytics`, `RTDC/Predictions`, `RTDC/Operations`, plus `BedTracking`, `DischargePrediction`, `ServiceHuddle`, `AncillaryServices`, the `Dashboard/RTDC` page): these render **hard-coded / `@/mock-data/rtdc*` data**, no API, mostly no real-time. They define *desired UX* (action items, owners, due dates, discharge readiness scoring, task matrices) that the backend does **not yet** implement. Hummingbird should map to the **engine** for live data and treat the mock pages as a **feature backlog / UX spec**.

---

## 1. Data Elements (Models, Fields, Relationships)

All tables live in the PostgreSQL `prod.` schema. Soft-delete via `is_deleted` boolean is near-universal. `patient_ref` is **pseudonymous (never MRN)** — payloads are deliberately PHI-light.

### 1.1 Core capacity entities

#### `Unit` — `prod.units` (`app/Models/Unit.php`)
A nursing unit / department; the unit of census and prediction.

| Field | Type | Notes |
|---|---|---|
| `unit_id` | bigint PK | |
| `name` | string | e.g. "Medical ICU" |
| `abbreviation` | string nullable | |
| `type` | string | **`ed` \| `med_surg` \| `icu` \| `step_down`** |
| `staffed_bed_count` | int (default 0) | nursing workload budget basis |
| `ratio_floor` | int (default 4) | max patients per nurse |
| `access_standard_minutes` | int (default 120) | target time-to-bed |
| `facility_space_id` | FK nullable | physical mapping |
| `created_by/modified_by/is_deleted` | audit | |

Relationships: `beds()` hasMany (non-deleted), `encounters()` hasMany, `facilitySpace()` belongsTo.

#### `Bed` — `prod.beds` (`app/Models/Bed.php`)
| Field | Type | Notes |
|---|---|---|
| `bed_id` | bigint PK | |
| `unit_id` | FK → units | |
| `label` | string | e.g. "MICU-04" |
| `status` | string | **DB CHECK constraint: `available` \| `occupied` \| `blocked` \| `dirty`** |
| `bed_type` | string (default `standard`) | |
| `isolation_capable` | bool | |
| `facility_space_id` | FK nullable | |
| audit | | `scopeAvailable()` = status=available & not deleted |

#### `CensusSnapshot` — `prod.census_snapshots` (`app/Models/CensusSnapshot.php`)
Materialized read-model row, recomputed and persisted on every operational event (and broadcast). Indexed `[unit_id, captured_at]`.

| Field | Type |
|---|---|
| `census_snapshot_id` PK, `unit_id` FK, `captured_at` timestamp | |
| `staffed_beds`, `occupied`, `available`, `blocked`, `acuity_adjusted_capacity` | all int |

> **This is the single most mobile-relevant "live" object** — it is exactly what is pushed over WebSocket (`census.updated`).

#### `Encounter` — `prod.encounters` (referenced throughout; model at `app/Models/Encounter.php`)
The active patient-in-bed record (projected from the event ledger; not in inventory scope list but load-bearing). Fields seen: `encounter_id`, `patient_ref`, `unit_id`, `bed_id`, `admitted_at`, `expected_discharge_date`, `acuity_tier` (1–4, DB CHECK), `status` (`active`|`discharged`), `discharged_at`. Indexed `[unit_id, status]`.

### 1.2 Bed-request / placement lifecycle

#### `BedRequest` — `prod.bed_requests` (`app/Models/BedRequest.php`)
| Field | Type | Allowed values (DB CHECK + validation) |
|---|---|---|
| `bed_request_id` | PK | |
| `patient_ref` | string | pseudonymous |
| `source` | string | **`ed` \| `transfer` \| `direct` \| `or`** |
| `sex` | string nullable | `M` \| `F` \| `other` |
| `service` | string nullable | e.g. "Cardiology" |
| `acuity_tier` | tinyint (default 2) | **1–4** |
| `isolation_required` | string (default none) | **`none` \| `contact` \| `droplet` \| `airborne`** |
| `required_unit_type` | string (default any) | **`any` \| `med_surg` \| `icu` \| `step_down`** |
| `status` | string (default pending) | **`pending` \| `placed` \| `cancelled`** |

`scopePending()` = status=pending & not deleted.

#### `BedPlacementDecision` — `prod.bed_placement_decisions` (`app/Models/BedPlacementDecision.php`)
The **audit + learning-loop** record for every recommend→decide cycle.

| Field | Type | Notes |
|---|---|---|
| `bed_placement_decision_id` | PK | |
| `bed_request_id` | FK | |
| `recommended_bed_id` | FK→beds nullable | what the optimizer ranked #1 |
| `chosen_bed_id` | FK→beds nullable | what the human picked |
| `action` | string | **`accepted` \| `edited` \| `rejected`** |
| `reason` | text nullable | free-text override reason |
| `score_snapshot` | jsonb | full `RankedRecommendations` at decision time (for weight tuning) |
| `decided_by` | FK→users nullable | |

### 1.3 IHI RTDC four-step prediction

#### `RtdcPrediction` — `prod.rtdc_predictions` (`app/Models/RtdcPrediction.php`)
Unique on `[unit_id, service_date, horizon]`. The heart of the huddle workflow.

| Field | Type | Meaning |
|---|---|---|
| `rtdc_prediction_id` | PK | |
| `unit_id` | FK | |
| `service_date` | date | |
| `horizon` | string | **`by_2pm` \| `by_midnight`** (DB CHECK) |
| `discharges_definite` / `_probable` / `_possible` | int | clinician-entered Step-1 confidence tiers |
| `discharges_weighted` | decimal(6,2) | = 1.0·def + 0.6·prob + 0.3·poss |
| `demand_ed` / `_or` / `_transfer` / `_direct` | int | Step-2 demand by source |
| `demand_expected` | int | sum of the four |
| `capacity_now` | int | available beds at plan time |
| `bed_need` | int (signed) | **headline number** = demand_expected − (available + ⌊weighted discharges⌋) |
| `status` | string | `open` \| `closed` |

Relationship: `plans()` hasMany `RtdcPlan` (non-deleted).

#### `RtdcPlan` — `prod.rtdc_plans` (`app/Models/RtdcPlan.php`)
**The action-item table** for the huddle workflow (Step 3 "develop plan"). **Backend exists, but NO API/controller currently exposes create/read of these — they are write-modeled but UI-orphaned.**

| Field | Type |
|---|---|
| `rtdc_plan_id` PK, `rtdc_prediction_id` FK | |
| `action_text` | text |
| `owner` | string nullable |
| `due_at` | timestamp nullable |
| `status` | `open` \| `done` |

> **Mobile opportunity:** this is the canonical "huddle action item with owner + due date." The mock pages render rich action-item UIs against hard-coded data; this table is where real ones belong. A mobile action-item inbox would need a thin API added here.

#### `RtdcReconciliation` — `prod.rtdc_reconciliations` (`app/Models/RtdcReconciliation.php`)
RTDC Step 4 (evaluate). Unique on `[unit_id, service_date]`. Populated by a **daily scheduled job** (§4.4).

| Field | Type |
|---|---|
| `rtdc_reconciliation_id` PK, `unit_id` FK, `service_date` date | |
| `predicted_discharges` decimal(6,2), `actual_discharges` int | |
| `predicted_admissions` int, `actual_admissions` int | |
| `reliability_score` | decimal(5,4), 0..1, nullable (= 1 − |pred−actual|/max) |

### 1.4 Barriers & huddles

#### `Barrier` — `prod.barriers` (`app/Models/Barrier.php`)
Barriers to discharge / flow. Indexed `[unit_id, status]`.

| Field | Type | Notes |
|---|---|---|
| `barrier_id` | PK | |
| `encounter_id` | FK nullable | patient-level barrier |
| `unit_id` | FK nullable | unit-level barrier |
| `category` | string | **`medical` \| `logistical` \| `placement` \| `social`** (`Barrier::CATEGORIES`, DB CHECK) |
| `reason_code` | string nullable | |
| `description` | text nullable | |
| `owner` | string nullable | free-text owner |
| `status` | string | **`open` \| `resolved`** |
| `opened_at` | timestamp (default now) | |
| `resolved_at` | timestamp nullable | |

`scopeOpen()` = status=open & not deleted.

#### `Huddle` — `prod.huddles` (`app/Models/Huddle.php`)
| Field | Type | Notes |
|---|---|---|
| `huddle_id` PK | | |
| `type` | string | **`unit` \| `hospital`** (DB CHECK) |
| `unit_id` | FK nullable | null for hospital huddle |
| `service_date` | date | |
| `status` | `open` \| `closed` | |
| `facilitator_id` | FK→users nullable | |
| `closed_at` | timestamp nullable | |

> Note: A huddle here is a **session shell** (who facilitated, when closed). The *content* of a huddle is the per-unit `RtdcPrediction` + its `RtdcPlan` action items + `Barrier`s. There is no per-huddle membership/attendance model.

### 1.5 Supporting reference / event data

#### `GmlosReference` — `prod.gmlos_references` (`app/Models/GmlosReference.php`)
Geometric Mean Length of Stay benchmark per unit type. Unique on `unit_type`.
Fields: `gmlos_reference_id`, `unit_type` (`med_surg`|`icu`|`step_down`|`ed`), `gmlos_days` decimal(5,2), `effective_from` date. **Reference data only; no controller/endpoint reads it yet.**

#### `DiversionEvent` — `prod.diversion_events` (`app/Models/DiversionEvent.php`)
ED/hospital diversion (ambulance bypass) windows. `$guarded = []`.
Fields: `diversion_event_id`, `scope` (`ed`|`hospital`, default ed), `unit_id` FK nullable, `started_at`, `ended_at` (NULL = **ongoing**), `reason`. **No controller/endpoint or broadcast yet** — but a live "currently on diversion" state is a high-value mobile alert (see §8).

#### `Location` — `prod.locations` (`app/Models/Location.php`)
Generic location/place-of-service (OR-leaning: `scopeOperatingRooms()`). Fields: `location_id`, `name`, `abbreviation`, `type`, `pos_type`, `active_status` bool, `facility_space_id` FK. hasMany `rooms()`. **Peripheral to RTDC** (more Perioperative); included for completeness.

#### `CareJourneyMilestone` (`app/Models/CareJourneyMilestone.php`)
**Belongs to the Perioperative/`ORCase` domain, not RTDC's `prod.*` tables** (no `prod.` table prefix; FK `case_id` → `ORCase`). Models a per-case milestone with rich status lifecycle: `milestone_type`, `status` (`Pending`|`In_Progress`|`Completed`|`Verified`|`Action_Required`), `required` bool, `completed_at`, `completed_by`, `notes`. Helper methods: `complete()`, `verify()`, `requireAction()`, `reset()`, plus `is*()` predicates. Surfaces in RTDC **only** via the mock "Care Journey" UI components (`CareJourneyTimeline`, `PatientJourneyModal`). For Hummingbird this is the **conceptual model** the RTDC discharge-readiness / milestone UIs aspire to, but RTDC live data does not populate it.

#### `OperationalEvent` — `prod.operational_events` (the event ledger; `app/Models/OperationalEvent.php`)
Append-only canonical event log = source of truth for census. Fields: `operational_event_id`, `event_id` (UUID, **unique = idempotency key**), `type` (`EncounterStarted`|`EncounterTransferred`|`EncounterDischarged`|`BedStatusChanged`|`AcuityChanged`), `encounter_ref`, `payload` jsonb, `occurred_at`. Indexed `[type, occurred_at]` and `encounter_ref`.

### 1.6 Entity relationship summary

```
Unit 1──* Bed
Unit 1──* Encounter (active patient in bed)
Unit 1──* CensusSnapshot          (materialized, broadcast)
Unit 1──* RtdcPrediction 1──* RtdcPlan        (action items, owner, due_at)
Unit 1──* RtdcReconciliation      (Step 4, daily job)
Unit 1──* Barrier  (also Encounter 1──* Barrier)
Unit 1──* Huddle (+ hospital huddle has null unit)
Unit 1──* DiversionEvent
BedRequest 1──* BedPlacementDecision *──1 Bed (recommended & chosen)
OperationalEvent (ledger) ──projected──> Encounter + Bed.status + CensusSnapshot
GmlosReference keyed by unit.type
```

---

## 2. API & Web Endpoints

### 2.1 JSON API — `/api/rtdc/*` (the live, mobile-targetable API)
Defined in `routes/api.php`. **Middleware: `['web', 'auth', 'throttle:60,1']`** — i.e. **session-cookie auth via the web guard**, not token/Sanctum. CSRF via `X-XSRF-TOKEN`. Rate limit 60 req/min. All responses are `{ "data": ... }` envelopes (validated client-side by Zod in `resources/js/schemas/rtdc.ts`).

> **Mobile auth gap:** these endpoints assume a browser session cookie + XSRF token. A native app has no Inertia session bootstrap. **Hummingbird will need a token-based auth path (Sanctum personal-access tokens or OIDC bearer) added** — the OIDC infra already exists (`app/Services/Auth/Oidc/*`). This is a foundational mobile work item.

| Method | Path | Controller::method | Purpose | Params | Response |
|---|---|---|---|---|---|
| GET | `/api/rtdc/units` | `CensusController::units` | All units + live census (occupied/available/blocked + acuity-adjusted capacity) | — | `data: UnitCensus[]` |
| GET | `/api/rtdc/units/{unitId}/prediction` | `PredictionController::show` | One unit's RTDC prediction | query `service_date` (def today), `horizon` (def by_2pm) | `data: Prediction\|null` |
| POST | `/api/rtdc/units/{unitId}/capacity` | `PredictionController::capacity` | **Step 1** — save discharge tiers; computes weighted; **broadcasts `huddle.updated`** | body `service_date, horizon, definite, probable, possible` (0–200) | `data: Prediction` |
| POST | `/api/rtdc/units/{unitId}/demand` | `PredictionController::demand` | **Step 2** — save demand by source; **broadcasts `huddle.updated`** | body `service_date, horizon, ed, or, transfer, direct` (0–500) | `data: Prediction` |
| POST | `/api/rtdc/units/{unitId}/plan` | `PredictionController::plan` | **Step 3** — compute signed `bed_need`; **broadcasts `huddle.updated`** | body `service_date, horizon` | `data: Prediction` |
| POST | `/api/rtdc/huddles` | `HuddleController::open` | Open a unit or hospital huddle (firstOrCreate) | body `type(unit\|hospital), unit_id?, service_date` | `data: Huddle` |
| POST | `/api/rtdc/huddles/{huddleId}/close` | `HuddleController::close` | Close huddle (sets `closed_at`) | — | `data: Huddle` |
| GET | `/api/rtdc/bed-meeting` | `HuddleController::bedMeeting` | Hospital roll-up of all units' bed-need; **broadcasts `bedmeeting.updated`** | query `service_date, horizon` | `data: BedMeeting` |
| GET | `/api/rtdc/barriers` | `BarrierController::index` | Open barriers (optionally filtered by unit) | query `unit_id?` | `data: Barrier[]` |
| POST | `/api/rtdc/barriers` | `BarrierController::store` | **Log a barrier to discharge** | body `unit_id?, encounter_id?, category, reason_code?, description?, owner?` | `data: Barrier` |
| POST | `/api/rtdc/barriers/{barrierId}/resolve` | `BarrierController::resolve` | **Resolve a barrier** (sets resolved_at) | — | `data: Barrier` |
| GET | `/api/rtdc/units/{unitId}/reliability` | `ReconciliationController::latest` | Latest reconciliation/reliability for a unit | — | `data: Reconciliation\|null` |
| GET | `/api/rtdc/bed-requests` | `BedRequestController::index` | Pending bed requests (FIFO by created_at) | — | `data: BedRequest[]` |
| POST | `/api/rtdc/bed-requests` | `BedRequestController::store` | **Create a bed request** | body = `CreateBedRequestRequest` (patient_ref, source, sex?, service?, acuity_tier, isolation_required, required_unit_type) | `data: BedRequest` |
| GET | `/api/rtdc/bed-requests/{id}/recommendations` | `BedRequestController::recommendations` | Ranked bed recommendations + excluded beds w/ reasons | — | `data: RankedRecommendations` |
| POST | `/api/rtdc/bed-requests/{id}/decision` | `BedRequestController::decision` | **Accept/edit/reject a placement** → on accept dispatches `EncounterStarted`, occupies bed, marks request `placed` | body `action, chosen_bed_id?, reason?` | `data: BedPlacementDecision` (422 `UnsafePlacement`, 409 `BedUnavailable`) |

### 2.2 Web (Inertia) routes — `routes/web.php`
All under `auth` + workflow-scoped. Two `rtdc` prefix blocks.

| Method | Path (name) | Controller::method | Renders |
|---|---|---|---|
| GET | `/dashboard/rtdc` (`dashboard.rtdc`) | `RTDCDashboardController::index` | `Dashboard/RTDC` (mock) |
| GET | `/rtdc/global-huddle` (`rtdc.global-huddle`) | `RTDCController::globalHuddle`* / `RTDCDashboardController::globalHuddle` | `RTDC/GlobalHuddle` (**live**) |
| POST | `/rtdc/update-red-stretch-plan` (`rtdc.update-red-stretch-plan`) | `RTDCController::updateRedStretchPlan` | **Stub — only `Log::info`, no persistence** |
| GET | `/rtdc/bed-tracking` | `…::bedTracking` | `RTDC/BedTracking` (mock) |
| GET | `/rtdc/patient-flow-navigator` | `…::patientFlowNavigator` | `RTDC/PatientFlowNavigator` |
| GET | `/rtdc/ancillary-services` | `…::ancillaryServices` | `RTDC/AncillaryServices` (mock, 30s poll) |
| GET | `/rtdc/unit-huddle` | `…::unitHuddle` | `RTDC/UnitHuddle` (**live**) |
| GET | `/rtdc/service-huddle` | `…::serviceHuddle` | `RTDC/ServiceHuddle` (mock) |
| GET | `/rtdc/bed-placement` | `…::bedPlacement` | `RTDC/BedPlacement` (**live**) |
| GET | `/rtdc/analytics/{utilization,performance,resources,trends}` | analytics methods | mostly placeholder pages |
| GET | `/rtdc/predictions/{demand,resources,discharge,risk}` | prediction methods | mostly placeholder / mock pages |

\* Two controllers both define `globalHuddle`; the named route `rtdc.global-huddle` in the first block points at `RTDCController` but that class only implements `updateRedStretchPlan` — the functional render is `RTDCDashboardController::globalHuddle` → `RTDC/GlobalHuddle`. Note `RTDCService.php`/`RtdcService.php` are the **same file** (case-insensitive macOS FS, identical md5); the class is `RtdcService` and only `activateWorkflow()` (sets session workflow=rtdc) is used by the dashboard controller.

---

## 3. Pages / Features

### 3.1 Live, engine-backed pages (`.tsx`) — **mobile-priority**

| Page | Route | Purpose | Key metrics / viz | User actions | Real-time |
|---|---|---|---|---|---|
| **BedPlacement** `Pages/RTDC/BedPlacement.tsx` | `/rtdc/bed-placement` | Prescriptive bed-assignment. Pick a pending request → see ranked recommendations → accept. | Pending-request list (patient_ref, source, tier, unit type, isolation); per-rec `RecommendationCard` (score, ok/not-ok chips, score breakdown, runner-up delta); "no safe bed" empty state w/ excluded count. | Select request; **Accept** a recommended bed (`postDecision accepted`). Invalidates `bed-requests`+`units` on success. | Via TanStack Query refetch; not directly socket-driven but census it feeds is. |
| **UnitHuddle** `Pages/RTDC/UnitHuddle.tsx` | `/rtdc/unit-huddle` | IHI RTDC Steps 1–3 for one unit + barriers + live census + reliability. | Live census tile (occupied/staffed, available, **safe additional capacity** = acuity-adjusted); `BedNeedReadout`; `ReliabilityTile`; `BarrierBoard`. | Enter discharge tiers → **Save capacity**; enter demand → **Save demand**; **Compute bed-need** (Step 3); **Resolve barrier** (`POST barriers/{id}/resolve`). | `useLiveCensus()` (Reverb): `census.updated` refetches units; `huddle.updated` refetches this unit's prediction. |
| **GlobalHuddle** `Pages/RTDC/GlobalHuddle.tsx` | `/rtdc/global-huddle` | Hospital Bed Meeting roll-up across all units. | **Net Bed Need**; **Total Deficit (units short)**; per-unit table (capacity, demand, bed_need color-coded ±). | Read-only board. | `useLiveCensus()` + `bedmeeting.updated` on `hospital.beds` refetches roll-up (multi-user huddle sync). |
| **PatientFlowNavigator** `Pages/RTDC/PatientFlowNavigator.tsx` | `/rtdc/patient-flow-navigator` | "4D" patient-flow navigator shell (delegates to `Components/PatientFlowNavigator`). Passes `facilityCode`. | (in sub-component) | (in sub-component) | (depends on sub-component) |

### 3.2 Legacy mock pages (`.jsx`) — **UX spec / backlog, not live**
All hard-coded or `@/mock-data/rtdc*`; no `/api/rtdc` calls. These define richer workflows than the backend supports.

| Page | Route | Purpose | Notable (aspirational) features | Real-time |
|---|---|---|---|---|
| `Dashboard/RTDC.jsx` | `/dashboard/rtdc` | House-wide RTDC dashboard | `CompactTabPanel` (alerts, bed types, staffing), `EnhancedDepartmentMetrics`, historical charts | none |
| `BedTracking.jsx` | `/rtdc/bed-tracking` | Bed status overview | Total/Occupied/Available/Cleaning; pending admits/discharges/transfers; bed-map placeholder | none |
| `DischargePrediction.jsx` | (predictions) | ML discharge forecast | Predicted/Completed/Pending/Accuracy; **discharge barriers by type w/ counts**; per-unit predicted-vs-completed | none |
| `DischargePriorities.jsx` | `/rtdc/predictions/discharge` | Ranked discharge candidates **P1–P4** | Per-patient LOS/expected LOS, unit capacity %, improvement rate, risk; filter Hospital/Service/Unit | none |
| `AncillaryServices.jsx` | `/rtdc/ancillary-services` | Imaging/lab/PT/RT wait times | Critical-delay count; unit×service wait-time matrix; trends modal | **setInterval 30s** (regenerates mock) |
| `ServiceHuddle.jsx` | `/rtdc/service-huddle` | Unit huddle board, per-patient | Care journey, clinical status, team; **StatusUpdateModal** (tasks, discharge plan, barriers, requirements); acuity/care-requirement counts | none (local state) |
| `ServicesHuddle.jsx` | (alt) | Service-line coordination | Active consults, pending orders, response time; **priority tasks list** | none |
| `Operations/GlobalHuddle.jsx` | (alt) | Exec hospital huddle | Census/ED-boarding/pending-discharge/critical-resource KPIs; **Action Items w/ priority + timestamp + "Take Action"** (stub) | none |
| `Analytics/{Performance,Resources,Trends,Utilization}.jsx` | analytics | **Placeholders** (text only) | — | none |
| `Analytics/DepartmentCensus.jsx` | analytics | Census by dept + staffing/capacity-planning | occupancy %, projected peak, additional beds for peak | none (DateRange no-op) |
| `Predictions/Discharge.jsx` | predictions | AI discharge prediction | Predicted/confidence/capacity-impact/risk; hourly discharge curve; **barriers**; model factors (LOS, care milestones) | none |
| `Predictions/{DemandForecast,ResourcePlanning}.jsx` | predictions | **Placeholders** | — | none |

### 3.3 Notable RTDC components (UX spec for actionable mobile features)
- `BarrierBoard.tsx` — open barriers w/ category dots + **Resolve** button (live, props-driven).
- `RecommendationCard.tsx` — ranked bed w/ score/chips/breakdown + **Accept** (live).
- `BedNeedReadout.tsx`, `ReliabilityTile.tsx`, `DischargeTierEntry.tsx`, `DemandBySourceEntry.tsx` — live huddle primitives.
- **Mock-only but aspirational (no backend):** `DischargeReadinessScore.jsx` (computed % from clinical criteria/transport/instructions/alt-pathways incl. Hospital-at-Home), `InterventionsChecklist.jsx` (mark-complete/reschedule/add-note), `TaskPriorityMatrix.jsx` (urgency×importance quadrant, assignedTo, dueDate, completed), `DischargeChecklistTimeline.jsx` (milestones, add/edit), `CapacityTimeline/CapacityHuddleTracker.jsx` (red-stretch plans w/ responsible party + deadline), `CapacityTimeline/DischargeTracker.jsx` (discharge milestone chain: MD Order→Case Mgmt→Transport→Pharmacy→Final, statuses completed/in_progress/blocked/pending), `CapacityTimeline/DemandCapacityModel.jsx` (12h demand-vs-capacity line w/ >5-bed mismatch alerts).

---

## 4. Real-Time Mechanism — **KEY FOR MOBILE**

### 4.1 Transport: Laravel Reverb (WebSocket), public channels
- **Server config** `config/broadcasting.php`: default driver `BROADCAST_CONNECTION` (env; `reverb`/`pusher`/`null`). Reverb connection configured (`REVERB_*` env). Production likely `reverb`.
- **Client** `resources/js/lib/echo.ts`: `laravel-echo` + `pusher-js`, `broadcaster: 'reverb'`, `wsHost/wsPort` from `VITE_REVERB_*`, transports `['ws','wss']`. `window.Echo` global.
- **Channels are INTENTIONALLY PUBLIC** (`routes/channels.php` documents this explicitly): payloads are PHI-free aggregate operational counts, so no `Broadcast::channel()` auth callback exists. **If a future channel carries PHI it must switch to `PrivateChannel` + auth.** → For mobile, a native Reverb/Pusher-protocol client can subscribe to these public channels **without** the web-session auth that the REST API requires.

### 4.2 The three broadcast events (`app/Events/Rtdc/`)

| Event class | Channel | `broadcastAs()` | Payload (`broadcastWith`) | Fired by |
|---|---|---|---|---|
| `CensusUpdated` | `unit.{unitId}` | `census.updated` | `unit_id, captured_at, staffed_beds, occupied, available, blocked, acuity_adjusted_capacity` | `EventDispatcher::dispatch()` after any operational event projects (new admit/transfer/discharge/bed-status/acuity) |
| `HuddleUpdated` | `unit.{unitId}` | `huddle.updated` | `unit_id, prediction{…full RtdcPrediction…}` | `PredictionController::{capacity,demand,plan}` after each save (multi-user huddle sync) |
| `BedMeetingUpdated` | `hospital.beds` | `bedmeeting.updated` | the full bed-meeting roll-up array | `HuddleController::bedMeeting()` (when roll-up is fetched/recomputed) |

### 4.3 Client subscription pattern (`features/rtdc/hooks.ts → useLiveCensus`)
For each known unit, subscribes `unit.{id}` and listens for **both** `.census.updated` (→ invalidate `['rtdc','units']`, after Zod-validating the wire payload via `censusUpdatedEventSchema`) and `.huddle.updated` (→ invalidate that unit's prediction). Also subscribes `hospital.beds` for `.bedmeeting.updated`. **Snapshot-on-(re)connect:** binds Pusher `connected` → refetch everything, because **Reverb/Pusher do NOT replay missed messages**. Cleanup leaves all channels on unmount. → **Mobile must replicate snapshot-on-reconnect**: on socket (re)connect, re-pull `/units`, `/bed-meeting`, and visible predictions; never assume gap-free delivery.

### 4.4 Server-side write/projection pipeline (`app/Rtdc/`)
1. **`EventDispatcher::dispatch(CanonicalEvent)`** — S2 synchronous: idempotent insert into `operational_events` ledger (unique `event_id`); if new, `CensusProjector::apply()` mutates `Encounter`/`Bed` rows; then recompute `CensusProjector::snapshot(unitId)` and `broadcast(CensusUpdated)`. Duplicates are skipped (no re-broadcast). Designed to be swapped for Redis Streams async (S1) without changing producers.
2. **`CensusProjector`** — applies the 5 `CanonicalEvent` types to the read model; `snapshot()` persists a `CensusSnapshot`.
3. **`CanonicalEvent`** (`app/Rtdc/Events/`) — immutable; the only shape the domain consumes (HL7v2/FHIR mapped in at the adapter). Types: `EncounterStarted`, `EncounterTransferred`, `EncounterDischarged`, `BedStatusChanged`, `AcuityChanged`.
4. **`EventSource` contract** + **`SyntheticEventSource`** (`app/Rtdc/Simulator/`) — deterministic seeded synthetic event stream for demo/CI; same interface future HL7v2/FHIR adapters implement. `CensusRebuilder` replays the whole ledger to rebuild census.
5. **Optimizer** (`app/Rtdc/Optimizer/`) — `HeuristicBedAssignmentOptimizer` (transparent weighted scoring; hard constraints prune, soft terms rank) behind `BedAssignmentOptimizer` contract (swappable for CP-SAT/OR-Tools). Weights: unit-type-match 10, acuity-headroom 20, occupancy-balance 15, isolation-fragmentation −25. `BedFeasibility` = shared hard-constraint check (capability, isolation, **nurse-safety-to-accept** via `AcuityService::canAccept`).
6. **Scheduled job** `bootstrap/app.php`: `ReconcileRtdcPredictions` runs **daily at 02:00** → `ReconciliationService::reconcile()` per unit for yesterday → writes `RtdcReconciliation` (predicted vs actual discharges/admissions + `reliability_score`). This is the Step-4 learning loop powering `ReliabilityTile`.
7. **Console commands:** `php artisan rtdc:simulate {--seed --ticks}` (drive live census w/ synthetic stream), `php artisan rtdc:demo-reset {--seed=42}` (seed deterministic E2E state).

### 4.5 Acuity engine (`app/Services/AcuityService.php`)
Tier weights `{1:1.0, 2:1.3, 3:1.7, 4:2.2}`. `adjustedCapacity(unit)` = how many more standard patients a unit can *safely* admit (nursing-workload-budgeted, never bed-count alone). `canAccept(unit, tier)` gates placement. This "safe additional capacity" number is broadcast in every census payload and shown on UnitHuddle — **a glanceable safety signal for mobile**.

### 4.6 Polling vs WebSocket summary
- **WebSocket (Reverb):** live census, huddle prediction edits, bed-meeting roll-up. The only true real-time path; public channels; no replay; snapshot-on-reconnect.
- **Polling:** only the **mock** `AncillaryServices.jsx` (`setInterval` 30s over fake data). Not a real data path.
- **Request/response (TanStack Query):** everything else (bed requests, recommendations, barriers, reliability), refetched on mutation success or socket invalidation.

**One-paragraph summary:** RTDC's real-time core is event-sourced. Operational events (synthetic now, HL7v2/FHIR later) land in an append-only `operational_events` ledger through `EventDispatcher`, which idempotently projects them into `Encounter`/`Bed`/`CensusSnapshot` read models and then broadcasts a PHI-free `CensusUpdated` aggregate on the public `unit.{id}` Reverb (WebSocket) channel; huddle edits broadcast `HuddleUpdated` on the same per-unit channel and the hospital roll-up broadcasts `BedMeetingUpdated` on `hospital.beds`. Clients subscribe via laravel-echo/pusher-js and, because Reverb does not replay missed frames, re-snapshot all tracked queries on every (re)connect. Everything else is plain `{data:…}` REST under `web`+`auth` session cookies with a 60/min throttle.

---

## 5. Huddle Workflow (collaborative — high mobile value)

The IHI **Real-Time Demand & Capacity (RTDC)** four-step method, per unit per service-date per horizon (`by_2pm` | `by_midnight`):

1. **Step 1 — Predict capacity** (clinician-entered discharge confidence tiers): `POST /units/{id}/capacity` (`definite`/`probable`/`possible`) → `discharges_weighted` = 1.0·def + 0.6·prob + 0.3·poss. Broadcasts `huddle.updated`.
2. **Step 2 — Predict demand by source**: `POST /units/{id}/demand` (`ed`/`or`/`transfer`/`direct`) → `demand_expected` = sum. Broadcasts `huddle.updated`.
3. **Step 3 — Develop plan / compute bed-need**: `POST /units/{id}/plan` → signed `bed_need` = demand_expected − (available beds + ⌊weighted discharges⌋); persists `capacity_now`. Broadcasts `huddle.updated`. **Action items** belong in `RtdcPlan` (`action_text`, `owner`, `due_at`, status open/done) — **table exists but is not yet exposed by any API/UI** (gap).
4. **Step 4 — Evaluate** (next day, automated): `ReconcileRtdcPredictions` job → `RtdcReconciliation` + `reliability_score`. Surfaced read-only via `/units/{id}/reliability` and `ReliabilityTile`.

**Hospital bed meeting:** `GET /bed-meeting` aggregates every unit's `bed_need` → `net_bed_need`, `total_positive_bed_need` (sum of deficits), per-unit rows. Broadcasts `bedmeeting.updated` so concurrent huddle edits sync across all viewers (the `GlobalHuddle.tsx` board).

**Huddle session lifecycle:** `POST /huddles` opens a `unit` or `hospital` huddle (firstOrCreate by type/unit/date), `POST /huddles/{id}/close` closes it (`closed_at`). The huddle row records facilitator + close time only; the working content is the predictions/barriers above. **No attendance/membership, no @mentions, no per-action-item assignment API yet.**

**Barriers within huddles:** `GET/POST /barriers`, `POST /barriers/{id}/resolve`. Categories `medical|logistical|placement|social`, optional `owner` (free text). Resolving is a one-tap state change → ideal mobile action.

> **Mobile-critical gaps to close for a real huddle companion:** (1) expose `RtdcPlan` CRUD (the action-item table with owner+due_at) via API + broadcast; (2) add notifications when a plan item is assigned to *you* or overdue; (3) barrier "owner" is free text — no user FK, so "barriers assigned to me" isn't queryable yet.

---

## 6. Bed-Management Workflow (request → placement → assignment)

```
[Create] BedRequest(status=pending)
   POST /bed-requests  (patient_ref, source, sex?, service?, acuity_tier 1-4,
                        isolation_required, required_unit_type)
        │
        ▼
[Recommend]  GET /bed-requests/{id}/recommendations
   HeuristicBedAssignmentOptimizer over Bed::available():
     hard constraints prune → ExcludedBed{bed_id, reason}
     soft scoring ranks     → Recommendation{bed_id,bed_label,unit,score,breakdown,chips}
     + runner_up_delta (confidence margin)
        │
        ▼
[Decide]  POST /bed-requests/{id}/decision  (action, chosen_bed_id?, reason?)
   action ∈ {accepted, edited, rejected}; always writes BedPlacementDecision (audit + score_snapshot)
   on accepted|edited w/ chosen_bed_id:
     - re-check server-side (chosen_bed_id NEVER trusted): bed must be 'available' (lockForUpdate)
       → 409 BedUnavailableException if claimed
     - BedFeasibility.violation() re-run → 422 UnsafePlacementException
     - dispatch CanonicalEvent::encounterStarted → bed.status='occupied', new Encounter, CensusUpdated broadcast
     - BedRequest.status='placed'
```

**Bed status lifecycle (`prod.beds.status`, DB CHECK):** `available → occupied` (on admit) → `dirty` (on transfer-out/discharge) → (EVS/turnover) → `available`; `blocked` for out-of-service. Census counts `blocked`+`dirty` together as "blocked." There is **no explicit EVS/turnover endpoint** in RTDC scope — bed status flips come from operational events (`BedStatusChanged`).

**Statuses summary:** BedRequest `pending|placed|cancelled`; BedPlacementDecision action `accepted|edited|rejected`; Bed `available|occupied|blocked|dirty`.

**Concurrency safety:** the `lockForUpdate` + server-side re-validation means two clients racing to claim the same bed cannot both win — important for a mobile client where stale lists are common. Mobile must handle 409/422 gracefully (re-fetch recommendations, show "bed just taken").

---

## 7. Roles

RTDC has **no fine-grained, code-enforced role gating** today. Findings:
- The app authorizes RTDC routes only with `auth` + a **workflow** concept. `RtdcService::activateWorkflow()` sets `session('workflow') = 'rtdc'`. `WorkflowSelector.jsx` lets users switch RTDC / OR / ED workflows (a **view switcher**, per PRODUCT.md's role switcher concept — frontline / ops-leaders / executives).
- `prod.users` carries a coarse `role` (`user` default, `admin` via OIDC group `Zephyrus Admins`) — **not** RTDC-specific personas.
- No `bed_manager` / `nursing_supervisor` / `charge_nurse` / `command_center` enum or middleware exists in code. The personas in the brief map to **intended audiences**, not enforced authz:
  - **Nursing supervisor / house supervisor** → GlobalHuddle (bed meeting), capacity alerts, diversion.
  - **Bed manager / placement coordinator** → BedPlacement (recommendations + decisions).
  - **Charge nurse** → UnitHuddle (their unit's Steps 1–3 + barriers + census).
  - **Command-center staff** → the house-wide RTDC dashboard + bed meeting.
  - **Executives** → roll-ups, reliability, trends (read-only, glanceable).
- `Huddle.facilitator_id`, `BedPlacementDecision.decided_by`, `CareJourneyMilestone.completed_by` are user FKs (attribution), but barrier/plan `owner` is free-text string.

> **Mobile implication:** persona-based home screens are a UX choice, not a server constraint. If Hummingbird wants "my unit" / "barriers assigned to me," the backend needs (a) a user↔unit association and (b) owner→user FKs on `Barrier`/`RtdcPlan`.

---

## 8. Mobile Relevance — per-feature flags

Flags: **GLANCEABLE** (read-only at-a-glance / widget / watch) · **ACTIONABLE** (tap to mutate on the go) · **NOTIFY** (push trigger) · **DESKTOP-ONLY** (too dense / authoring-heavy for phone).

### 8.1 Top mobile-relevant RTDC features (ranked)

| # | Feature | Backing data / endpoint | Real-time | Flags | Notes |
|---|---|---|---|---|---|
| 1 | **Live unit census** (occupied/available/blocked + **safe additional capacity**) | `GET /units`; `CensusUpdated` on `unit.{id}` | ✅ WS | **GLANCEABLE · NOTIFY** | Best widget/watch face. Acuity-adjusted "safe capacity" is the differentiator. NOTIFY when available→0 or blocked spikes. |
| 2 | **Hospital bed-need roll-up** (net bed need, total deficit, per-unit ±) | `GET /bed-meeting`; `BedMeetingUpdated` on `hospital.beds` | ✅ WS | **GLANCEABLE · NOTIFY** | Executive/house-supervisor home tile. NOTIFY when net_bed_need crosses a threshold or a unit goes deficit. |
| 3 | **Barriers to discharge** (list + **resolve**, log new) | `GET/POST /barriers`, `POST /barriers/{id}/resolve` | ➖ (refetch) | **ACTIONABLE · NOTIFY** | One-tap resolve; quick-add barrier from bedside. NOTIFY on new barrier in my unit / unresolved-aging. Add WS broadcast for live sync (gap). |
| 4 | **Bed requests** (pending list + create) | `GET/POST /bed-requests` | ➖ | **GLANCEABLE · ACTIONABLE · NOTIFY** | Create a request from anywhere (ED/transfer/direct/OR). NOTIFY bed managers on new pending request. |
| 5 | **Bed placement decision** (recommendations + **accept/edit/reject**) | `GET …/recommendations`, `POST …/decision` | ➖ | **ACTIONABLE** | Transparent score + chips render well on a card. Must handle 409/422 (bed taken / unsafe). High-value for roving bed managers. |
| 6 | **Unit huddle Steps 1–3** (enter discharges/demand, compute bed-need) | `POST /units/{id}/{capacity,demand,plan}`; `HuddleUpdated` | ✅ WS | **ACTIONABLE** (lightweight) / authoring leans DESKTOP | Numeric tier/demand entry is phone-friendly; full huddle facilitation is desktop. Live multi-user sync already built. |
| 7 | **Prediction reliability** (yesterday predicted vs actual) | `GET /units/{id}/reliability` | ➖ daily | **GLANCEABLE** | Trust/KPI tile; exec-facing. |
| 8 | **Capacity / demand mismatch alert** (bed_need > 0, deficit units) | derived from prediction/roll-up | ✅ via WS | **GLANCEABLE · NOTIFY** | "ICU short 3 beds by 2pm" is the canonical push. |
| 9 | **Diversion status** (ED/hospital on bypass, ongoing) | `DiversionEvent` (model only — **no endpoint/broadcast yet**) | ❌ (gap) | **GLANCEABLE · NOTIFY** | Highest-urgency signal; needs a thin read endpoint + broadcast to be mobile-usable. |
| 10 | **Discharge readiness / priorities** (P1–P4, readiness score, milestone chain) | **mock only** (`DischargePriorities`, `DischargeReadinessScore`, `DischargeTracker`) | ❌ | **GLANCEABLE · ACTIONABLE (future)** | Strong mobile fit (checklists, mark-complete, blocked-milestone alerts) but **backend not implemented** — depends on `CareJourneyMilestone`-style modeling for inpatient flow. |

### 8.2 Desktop-only / low mobile value
- Full **huddle facilitation** (authoring all units), the dense **bed-map** (placeholder anyway), **AncillaryServices** wait-time matrix (mock), **Analytics** placeholders, **PatientFlowNavigator** "4D" view (complex spatial UI), retrospective **trends** charts. → DESKTOP-ONLY (or read-only mini-cards at best).

### 8.3 Highest-value notification opportunities (ranked)
1. **Capacity breach / unit deficit** — `bed_need > 0` after Step-3, or `net_bed_need` threshold crossed on `bedmeeting.updated`. *"MICU short 3 beds by 2pm."*
2. **Available beds → 0 / safe-capacity exhausted** on a watched unit (`census.updated` → available==0 or acuity_adjusted_capacity==0). *"5 West is full; 0 safe admissions."*
3. **New pending bed request** (to bed managers) on `POST /bed-requests`. *"New ED bed request, tier 4, ICU."*
4. **Bed placement contention** (409/422 on decide, or a high-priority request unplaced > N min). *"No safe bed for request #1234 (all candidates failed safety)."*
5. **New / aging unresolved barrier** in my unit (esp. `placement`/`social` that stall discharge). *"Placement barrier on 4 West open 6h."*
6. **Action item assigned to me / overdue** — *requires exposing `RtdcPlan` + owner→user FK first* (current gap; highest-value once built).
7. **Diversion started/ended** — *requires `DiversionEvent` endpoint + broadcast first*. *"ED on diversion."*
8. **Daily reliability digest** (exec) after the 02:00 reconcile job. *"Yesterday's discharge prediction reliability: 0.83."*

> Notification delivery does **not exist** for RTDC yet — there is **no FCM/APNs/push infrastructure** in the codebase (only email via Resend for auth). Hummingbird must add a push pipeline. The cleanest server hook is to **fan out from the existing broadcast events** (`CensusUpdated`/`HuddleUpdated`/`BedMeetingUpdated`) plus new triggers on barrier/bed-request creation and the reconcile job — i.e. a server-side rules engine that, on those events, evaluates thresholds and enqueues pushes to subscribed devices/units.

---

## 9. Key Gaps & Risks for Hummingbird (consolidated)

1. **Auth:** `/api/rtdc/*` is **web-session + XSRF only**; native apps need token/OIDC bearer auth added (OIDC infra exists). Foundational.
2. **Push:** no FCM/APNs/notification infra anywhere; must be built; best driven off broadcast events + new triggers.
3. **Action items orphaned:** `RtdcPlan` (owner/due_at) has no API/UI — the single biggest "huddle companion" gap.
4. **Owner is free text:** `Barrier.owner` / `RtdcPlan.owner` are strings, not user FKs → "assigned to me" queries impossible without schema change.
5. **No user↔unit association:** persona/"my unit" home screens need it.
6. **Diversion & GMLOS are data-only:** models exist, no endpoints/broadcasts → not consumable by mobile yet.
7. **Barriers don't broadcast:** unlike census/huddle, barrier create/resolve has no WS event → mobile would poll; add a `BarrierUpdated` event for parity.
8. **Mock vs real divergence:** the richest UX (discharge readiness scoring, task matrices, milestone chains, red-stretch plans, action items with timestamps) is **frontend mock only**; Hummingbird should mine these `.jsx` components/`@/mock-data/rtdc*` as the **product spec** while building the missing backend, not assume the data exists.
9. **No replay on reconnect:** mobile WS client must implement snapshot-on-(re)connect exactly like `useLiveCensus` (re-pull units/bed-meeting/predictions).

---

## 10. File Index (absolute paths)

**Controllers:** `/Users/sudoshi/Github/Zephyrus/app/Http/Controllers/RTDCDashboardController.php`, `…/RTDCController.php`, `…/Api/Rtdc/{BarrierController,BedRequestController,CensusController,HuddleController,PredictionController,ReconciliationController}.php`
**Engine:** `/Users/sudoshi/Github/Zephyrus/app/Rtdc/{EventDispatcher,CensusProjector,CensusRebuilder}.php`, `…/Rtdc/Events/CanonicalEvent.php`, `…/Rtdc/Contracts/EventSource.php`, `…/Rtdc/Simulator/{SyntheticEventSource,SimulatorConfig}.php`, `…/Rtdc/Optimizer/{HeuristicBedAssignmentOptimizer,BedFeasibility,Recommendation,RankedRecommendations,ExcludedBed}.php`, `…/Rtdc/Optimizer/Contracts/BedAssignmentOptimizer.php`
**Broadcast events:** `/Users/sudoshi/Github/Zephyrus/app/Events/Rtdc/{CensusUpdated,HuddleUpdated,BedMeetingUpdated}.php`
**Services:** `/Users/sudoshi/Github/Zephyrus/app/Services/{RtdcService,BedPlacementService,BarrierService,HuddleService,AcuityService,ReconciliationService}.php`
**Job/commands:** `/Users/sudoshi/Github/Zephyrus/app/Jobs/ReconcileRtdcPredictions.php`, `…/app/Console/Commands/{RtdcSimulateCommand,RtdcDemoResetCommand}.php`, scheduled in `…/bootstrap/app.php`
**Models:** `/Users/sudoshi/Github/Zephyrus/app/Models/{Unit,Bed,Encounter,CensusSnapshot,BedRequest,BedPlacementDecision,RtdcPrediction,RtdcPlan,RtdcReconciliation,Barrier,Huddle,DiversionEvent,GmlosReference,Location,CareJourneyMilestone,OperationalEvent}.php`
**Requests:** `/Users/sudoshi/Github/Zephyrus/app/Http/Requests/Rtdc/{CreateBedRequestRequest,BedPlacementDecisionRequest,UpsertBarrierRequest,UpsertCapacityRequest,UpsertDemandRequest}.php`
**Migrations:** `/Users/sudoshi/Github/Zephyrus/database/migrations/2026_06_20_0000{10,20,30,40,50,60}_*.php`, `…/2026_06_21_0000{10,20}_*.php`, `…/2026_06_22_0000{20,30}_*.php`
**Routes/channels:** `/Users/sudoshi/Github/Zephyrus/routes/{web.php,api.php,channels.php}`, config `/Users/sudoshi/Github/Zephyrus/config/broadcasting.php`
**Frontend (live):** `/Users/sudoshi/Github/Zephyrus/resources/js/lib/echo.ts`, `…/resources/js/schemas/rtdc.ts`, `…/resources/js/features/rtdc/{api,bedPlacement,hooks}.ts`, `…/resources/js/Pages/RTDC/{BedPlacement,GlobalHuddle,UnitHuddle,PatientFlowNavigator}.tsx`, `…/resources/js/Components/RTDC/{BarrierBoard,RecommendationCard,BedNeedReadout,ReliabilityTile,DischargeTierEntry,DemandBySourceEntry}.tsx`
**Frontend (mock/spec):** `…/resources/js/Pages/RTDC/{BedTracking,DischargePrediction,DischargePriorities,AncillaryServices,ServiceHuddle,ServicesHuddle}.jsx`, `…/Pages/RTDC/{Analytics,Predictions,Operations}/*.jsx`, `…/Pages/Dashboard/RTDC.jsx`, `…/Components/RTDC/{DischargeReadinessScore,InterventionsChecklist,TaskPriorityMatrix,DischargeChecklistTimeline}.jsx`, `…/Components/RTDC/CapacityTimeline/*.jsx`
