# Hummingbird Research — 03 · Perioperative (OR / Surgical) Subsystem

> Inventory of the Zephyrus Perioperative subsystem (OR case scheduling, room status,
> block utilization, surgical analytics) to feed the **Hummingbird** mobile companion
> (Kotlin/Android + Swift/iOS). Field-level where possible. Source-of-truth = the
> Laravel 11 / React-Inertia / PostgreSQL codebase as of 2026-06-26.

## 0. Executive summary & architecture reality check

The Perioperative surface is the **most data-modeled but least wired** of the Zephyrus
workflows. There is a rich, well-normalized OR data model (15+ tables under the
`prod.*` schema) and a complete REST surface under `/api/cases`, `/api/blocks`,
`/api/analytics`, but **most front-end pages currently render from mock data**, not
those endpoints. Hummingbird should treat the **data model + REST contract** as the
integration target and treat the current screens as UX intent.

Three distinct "personalities" exist in this subsystem:

| Layer | What it is | Backing data | Mobile posture |
|---|---|---|---|
| **Operations** (Room Status, Case Management, Block Schedule) | Day-of-surgery live board + case tracking | Mostly mock today; REST contract exists (`/api/cases/*`) | **High-value mobile** (glance + notify + light action) |
| **Perioperative Analytics** (Block/OR/Primetime/Room-Running/Turnover) | Operational utilization analytics, recent-period | Mock-data JS + 1 real hook (`useORUtilizationData`) | Mostly DESKTOP; a few glanceable tiles |
| **Predictions** (Forecast/Demand/Resource) | Forward-looking planning | Placeholder ("Coming Soon") | DESKTOP-ONLY |

A separate **unified Analytics Workbench** (`Pages/Analytics.jsx`, `/analytics/*`,
powered by `OperationsAnalyticsService`) is RTDC/house-wide flavored but **pulls OR
signals into its hub** (OR cases today, block utilization, OR demand). It is exec/ops-leader
oriented and largely DESKTOP, but its OR-derived metric tiles are glanceable.

### Counts at a glance
- **Models (OR-relevant):** 24 (10 core + 9 reference + 5 patient-flow/shared touchpoints).
- **OR-relevant DB tables:** ~16 (`or_cases`, `orlog`, `rooms`, `room_utilization`,
  `providers`, `block_templates`, `block_utilization`, `case_metrics`, `case_timings`,
  `case_measurements`, `case_resources`, `case_safety_notes`, `care_journey_milestones`,
  `case_transport`, `case_staff`, `locations`, + 9 reference tables).
- **REST endpoints (periop):** 24 (6 cases, 5 blocks, 3 perf-analytics + 8 workbench + 3 reference; mutations = 3).
- **Web (Inertia) routes (periop):** 14 (3 operations, 5 analytics pages, 3 predictions, + legacy top-level Cases/RoomStatus/BlockSchedule).
- **React pages:** 14 (3 Operations + 9 Analytics + 3 Predictions + 3 legacy top-level + the unified Analytics hub).
- **OR case status values:** 5 canonical (Scheduled / In Progress / Delayed / Completed / Cancelled).
- **OR timing milestones (orlog):** 13 timestamp columns (periop arrival → PACU out).

---

## 1. Data elements (models, fields, relationships)

### 1.1 Core OR case model — `ORCase` → `prod.or_cases`
PK `case_id`. The scheduling + classification spine of a surgical case.

| Field | Type | Notes / mobile relevance |
|---|---|---|
| `case_id` | bigint PK | case identity (deep-link target) |
| `patient_id` | string | patient reference (no PHI name stored here; name comes from mock/EHR join) |
| `surgery_date` | date | day-of filter; "my cases today" |
| `room_id` | FK→rooms | assigned OR |
| `location_id` | FK→locations | facility/OR suite |
| `primary_surgeon_id` | FK→providers | **surgeon = "my cases" key** |
| `case_service_id` | FK→services | service line (Ortho, Cardiac…) |
| `scheduled_start_time` | datetime | board ordering; late-start calc |
| `scheduled_duration` | int (min) | est. case length |
| `record_create_date` | datetime | |
| `status_id` | FK→case_statuses | **live status** (see lifecycle §1.12) |
| `cancellation_reason_id` | FK→cancellation_reasons (nullable) | why cancelled |
| `asa_rating_id` | FK→asa_ratings | ASA physical status I–V |
| `case_type_id` | FK→case_types | Primary/Revision/Staged |
| `case_class_id` | FK→case_classes | Elective/Urgent/Emergency |
| `patient_class_id` | FK→patient_classes | Inpatient/Outpatient/Same-Day |
| `procedure_name` | string | display title |
| `pre_procedure_location` | string | e.g. Pre-Op holding |
| `post_procedure_location` | string | e.g. PACU |
| `safety_status` | string, default `Normal` | drives "requiring review" scope |
| `journey_progress` | int 0–100 | % milestones complete (computed if null) |
| `created_by`/`modified_by`/`is_deleted` | audit/soft-delete | |

**Appended accessor:** `statusCode` (lowercased status code).
**Computed:** `journey_progress` auto-derives from completed/total `milestones` if null.

**Relationships:** `provider` (surgeon), `room`, `service`, `status`, `staff`
(many-to-many via `prod.case_staff` pivot w/ `role`), `resources` (hasMany),
`measurements` (vitals), `milestones` (care-journey), `transports`, `timings`,
`safetyNotes`.

**Scopes (directly reusable as mobile API filters/notification triggers):**
`active` (SCHED/INPROG/DELAY), `today`, `inProgress`, `delayed`, `completed`,
`preOp` (phase), `inPhase($phase)`, `requiringReview` (safety_status != Normal),
`withPendingMilestones`, `withPendingTransport`, `withUnacknowledgedSafetyNotes`.

> NOTE: the model references a `phase` column in scopes (`preOp`, `inPhase`) but
> `prod.or_cases` does not have a `phase` column — phase lives on `case_timings.phase`
> and on the mock data. The canonical milestone clock is `orlog` (below).

### 1.2 OR timing milestones — `ORLog` → `prod.orlog`
PK `log_id`, FK `case_id` (1:1 in practice). **This is the wheels-in/out clock** — the
single richest source for live case tracking & turnover analytics. `timestamps=false`;
maintains `created_date`/`modified_date` via boot hooks.

13 milestone timestamps (chronological):
`periop_arrival_time` → `preop_in_time` → `preop_out_time` → **`or_in_time` (wheels-in)**
→ `anesthesia_start_time` → `procedure_start_time` → `procedure_closing_time` →
`procedure_end_time` → **`or_out_time` (wheels-out)** → `anesthesia_end_time` →
`pacu_in_time` → `pacu_out_time`. Plus `destination`, `number_of_panels`,
`primary_procedure`.

**Derived attributes (mobile-ready durations):** `preop_duration`,
`anesthesia_duration`, `procedure_duration`, `room_duration` (or_in→or_out),
`pacu_duration` — all minutes.

> Room status today is computed off `orlog` (see §2.1 `roomStatus()`):
> `or_in_time` set & `or_out_time` null ⇒ **In Progress**; `or_out_time` set ⇒
> **Turnover**; else **Available**.

### 1.3 Rooms — `Room` → `prod.rooms`
PK `room_id`. `location_id`, `name`, `type` (e.g. `OR`), `active_status`,
`facility_space_id`, audit/soft-delete. Scopes `active`, `operatingRooms` (type=OR).
Rel: `location`, `facilitySpace`, `cases`, `blockTemplates`, `utilization`.

### 1.4 Room utilization (daily rollup) — `RoomUtilization` → `prod.room_utilization`
Per room per `date`: `available_minutes`, `utilized_minutes`, `turnover_minutes`,
`utilization_percentage`, `cases_performed`, `avg_case_duration`. Formatted accessors
(H:MM), boolean flags `underutilized` (<75%), `overutilized` (>100%),
`highTurnover` (>30 min/case). Scopes for date/room/under/over/highTurnover.

### 1.5 Providers — `Provider` → `prod.providers`
PK `provider_id`. `npi`, `name`, `type` (`surgeon`/`anesthesiologist`), `specialty_id`,
`active_status`. Scopes `active`, `surgeons`, `anesthesiologists`. Rel: `specialty`,
`cases` (as primary surgeon), `blockTemplates` (as block owner).

### 1.6 Block templates — `BlockTemplate` → `prod.block_templates`
PK `block_id`. Allocated OR block time. Fields: `room_id`, `service_id`, `surgeon_id`,
`group_id`, `block_date`, `start_time`, `end_time`, `is_public`, `title`,
`abbreviation`. Accessors `formatted_time`, `duration_minutes`. Scopes `active`,
`upcoming`, forService/Surgeon/Room, public/private. Rel: room, service, surgeon,
`utilization`.

### 1.7 Block utilization (daily rollup) — `BlockUtilization` → `prod.block_utilization`
Per block per `date`: `service_id`, `location_id`, `scheduled_minutes`,
`actual_minutes`, `utilization_percentage`, `cases_scheduled`, `cases_performed`,
`prime_time_percentage`, `non_prime_time_percentage`. Flags `underutilized`/`overutilized`.

### 1.8 Case metrics (per-case rollup) — `CaseMetrics` → `prod.case_metrics`
PK = `case_id` (1:1). `turnover_time`, `utilization_percentage`, `in_block_time`,
`out_of_block_time`, `prime_time_minutes`, `non_prime_time_minutes`,
`late_start_minutes`, `early_finish_minutes`. Flags `is_late_start`, `is_early_finish`,
`is_prime_time`; `prime_time_percentage`. **Primary feed for turnover/primetime
analytics and per-case "ran late" notifications.**

### 1.9 Case timings (phase-level) — `CaseTiming` → `case_timings`
Per case per `phase`: `planned_start`, `planned_duration`, `actual_start`,
`actual_duration`, `variance` (min). Phases: `Pre_Procedure`, `Procedure`, `Recovery`,
`Room_Turnover`. Live accessors: `planned_end`, `actual_end`, `is_delayed`,
`is_on_time`, `progress_percentage`, `remaining_time`. Helper methods `start()`,
`complete()`, `reset()`, `reschedule()`. **Real-time progress + delay engine.**

### 1.10 Case measurements (intra-op vitals) — `CaseMeasurement` → `case_measurements`
Per case timestamped vitals: `measured_at`, `hr`, `sbp`, `dbp`, `spo2`, `temp`,
`notes`. Computed `map` (MAP), `vitals_status` (normal/alert + alert list against
fixed ranges HR 60–100, SBP 90–140, DBP 60–90, SpO2 ≥95, Temp 36.5–37.5). Scope
`abnormal`. **Clinical alert source — NOTIFY candidate, but PHI-sensitive.**

### 1.11 Case touchpoints (operational)
- **`CaseResource`** → `case_resources`: `resource_name`, `status` (equipment/room readiness).
- **`CaseSafetyNote`** → `case_safety_notes`: `note_type` (`Safety_Alert`/`Barrier`/`General`),
  `content`, `severity` (`Low`/`Medium`/`High`/`Critical`), `created_by`,
  `acknowledged_by`, `acknowledged_at`. Rich behavior: `acknowledge()`, `escalate()`,
  `deescalate()`, `requiresImmediate()`, and **SLA `is_overdue`** (Critical 15m / High 30m /
  Medium 60m / Low 120m) + `time_to_acknowledgement`. **Top NOTIFY/ACTION source.**
- **`CareJourneyMilestone`** → `care_journey_milestones`: `milestone_type`, `status`
  (`Pending`/`In_Progress`/`Completed`/`Verified`/`Action_Required`), `required`,
  `completed_at`, `completed_by`, `notes`. Drives `journey_progress`.
- **`CaseTransport`** → `case_transport`: `transport_type` (`Pre_Procedure`/`Post_Procedure`),
  `status` (`Pending`/`In_Progress`/`Complete`), `location_from/to`, `assigned_to`,
  `planned_time`, `actual_start/end`. Methods start/complete/reassign; `is_delayed`,
  `delay`, `duration`. **ACTION + NOTIFY (transport ready / overdue).**
- **`case_staff`** pivot: `case_id`, `user_id`, `role` (Surgeon, Anesthesiologist,
  Scrub Nurse, Recovery Nurse, Charge RN…). Basis for "my cases" beyond surgeon_id.

### 1.12 Reference data (`Models/Reference/*`, all extend `BaseReference`)
| Model | Table | Key values / notes |
|---|---|---|
| `CaseStatus` | `case_statuses` | **Canonical lifecycle**: 1 SCHEDULED, 2 IN_PROGRESS, 3 DELAYED, 4 COMPLETED, 5 CANCELLED. Codes used in scopes: `SCHED`,`INPROG`,`DELAY`,`COMP`. Helpers `getStatusMap()`, `getActiveStatuses()` = [SCHED,INPROG,DELAY]. |
| `ASARating` | `asa_ratings` | name/code/description (ASA I–V) |
| `CaseClass` | `case_classes` | Elective / Urgent / Emergency |
| `CaseType` | `case_types` | Primary / Revision / Staged |
| `CancellationReason` | `cancellation_reasons` | reason lookup |
| `PatientClass` | `patient_classes` | Inpatient / Outpatient / Same-Day |
| `Service` | `services` | service line (also feeds ORCase, blocks) |
| `Specialty` | `specialties` | provider specialty |
| `Location` | `prod.locations` | `type` OR, `pos_type`, `abbreviation`; scope `operatingRooms` |

### 1.13 Entity relationship sketch
```
Location 1─* Room 1─* ORCase *─1 Provider(surgeon)
   │                  │  │  │
   │                  │  │  └─1 Service, Status, ASARating, CaseClass, CaseType, PatientClass
   │                  │  └─1 ORLog (wheels in/out clock, 1:1)
   │                  │  └─1 CaseMetrics (per-case rollup, 1:1)
   │                  ├─* CaseTiming (phase), CaseMeasurement (vitals),
   │                  │    CareJourneyMilestone, CaseTransport, CaseSafetyNote,
   │                  │    CaseResource, case_staff(User)
Room 1─* RoomUtilization(daily)
BlockTemplate 1─* BlockUtilization(daily)   (block ── room/service/surgeon)
```

---

## 2. API & web endpoints

### 2.1 REST API — OR cases (`/api/cases`, throttle 60/min)
| Method | Path | Controller | Purpose | Params | Response shape | Mutation |
|---|---|---|---|---|---|---|
| GET | `/api/cases` | `Api\ORCaseController@index` | List cases w/ filters | `date`,`status`(id),`service`(id),`room`(id) | `[ORCase + surgeon,room,service,status]` ordered date desc / start asc | — |
| POST | `/api/cases` | `@store` | Create case | patient_name, mrn, procedure_name, service_id, room_id, primary_surgeon_id, surgery_date, scheduled_start_time(H:i), estimated_duration(≥15), case_class(Elective/Urgent/Emergency), notes | created `ORCase` (201) / 422 errors | **YES** |
| PUT | `/api/cases/{id}` | `@update` | Update case | same validation set | updated `ORCase` | **YES** |
| GET | `/api/cases/today` | `@todaysCases` | **Today's board** | — | `[ORCase + relations]` for today, start asc | — |
| GET | `/api/cases/metrics` | `@metrics` | 7-day util/turnover summary | — | `{utilization[surgery_date,utilization,avg_turnover,case_count], summary{avg_utilization,avg_turnover,total_cases}}` | — |
| GET | `/api/cases/room-status` | `@roomStatus` | **Live room board** | — | `[{room_id,room_name,case_id,procedure_name,surgeon_name,service_name,scheduled_duration,or_in_time,or_out_time,status}]` (status from orlog) | — |

### 2.2 REST API — blocks (`/api/blocks`, throttle 60/min)
| Method | Path | Controller | Purpose | Params | Response | Mut |
|---|---|---|---|---|---|---|
| GET | `/api/blocks` | `Api\BlockScheduleController@index` | List blocks (+room/service/surgeon names) | — | `[block + room_name,service_name,surgeon_name]` | — |
| POST | `/api/blocks` | `@store` | Create block | service_id, room_id, block_date, start_time(H:i), end_time(>start) | block (201) | **YES** |
| GET | `/api/blocks/utilization` | `@utilization` | 30-day block util + summary | — | `{utilization[...], summary{avg_utilization,avg_prime_time,total_blocks}}` | — |
| GET | `/api/blocks/service-utilization` | `@serviceUtilization` | Block util grouped by service (30d) | — | `[service_name, avg_utilization, avg_prime_time, block_count, total_cases]` | — |
| GET | `/api/blocks/room-utilization` | `@roomUtilization` | Room util grouped by room (30d) | — | `[room_name, avg_utilization, avg_turnover, total_cases, avg_case_duration]` | — |

### 2.3 REST API — analytics (`/api/analytics`)
**Workbench group** (`web,auth`, throttle 60/min) → `Api\AnalyticsController` →
`OperationsAnalyticsService`. All return `{data: {...}}` with `generatedAtIso`,
`section`, `metrics[]` (metric tiles), `sourceMap[]`:
`/overview`, `/live`, `/retrospective`, `/predictive`, `/process-intelligence`,
`/opportunities`, `/workbench`, `/data-quality`, `/metrics/{metricKey}/lineage`.
> OR-relevant content inside these: `retrospective` returns `orCasesByWeek`,
> `Block Utilization` tile, OR case count; `overview`/`live` action queue includes
> "{n} OR cases feeding today's demand" routed to `/analytics/or-utilization`;
> `predictive` demand sources include `or`.

**Performance group** (throttle 60/min, no auth middleware) — heavier SQL,
date-range params `start_date`/`end_date`:
| Path | Method | Purpose | Returns |
|---|---|---|---|
| `/api/analytics/service-performance` | `@servicePerformance` | Per-service util/turnover/on-time/duration + trends + block util + day-of-week dist | `{utilization, trends, blockUtilization, dayDistribution, dateRange}` |
| `/api/analytics/provider-performance` | `@providerPerformance` | Per-surgeon util/turnover/on-time/overtime + trends | `{metrics, trends, dateRange}` |
| `/api/analytics/historical-trends` | `@historicalTrends` | Util/turnover/on-time by month/quarter/year + service growth | `{trends, serviceGrowth, dateRange}` |

> ⚠️ Data gap: these three queries reference `c.actual_start_time`, `c.actual_end_time`,
> `c.scheduled_end_time` on `prod.or_cases`, which **do not exist** in the migration
> (the case clock lives in `prod.orlog`). On-time/overtime/avg_duration columns will
> error or null against the current schema. Hummingbird should source on-time/late
> from `case_metrics.late_start_minutes` and durations from `orlog` instead.

### 2.4 REST API — reference (throttle 60/min)
`GET /api/services`, `GET /api/rooms`, `GET /api/providers` — simple active lists
(`Api\ServiceController/RoomController/ProviderController`). NOTE: these query
`is_active` while models use `active_status` — another wiring inconsistency.

### 2.5 Web (Inertia) routes — render pages only (auth/dashboard middleware)
Operations: `/operations/room-status`, `/operations/block-schedule`, `/operations/cases`.
Analytics pages: `/analytics/block-utilization`, `/analytics/or-utilization`,
`/analytics/primetime-utilization`, `/analytics/room-running`, `/analytics/turnover-times`.
Predictions: `/predictions/forecast`, `/predictions/demand`, `/predictions/resources`.
Unified hub: `/analytics`, `/analytics/{live|retrospective|predictive|process-intelligence|opportunities|workbench|data-quality}`.
Legacy top-level pages also exist (`Cases.jsx`, `RoomStatus.jsx`, `BlockSchedule.jsx`).
All Operations/Analytics-page/Predictions controllers are **thin** (`Inertia::render`
with no/mock props) except `Operations\CaseManagementController` which injects
`App\Data\CaseManagementMockData`.

---

## 3. Pages / features

### 3.1 Operations (day-of-surgery)
| Page | Route | Purpose | Key metrics / viz | User actions | Data |
|---|---|---|---|---|---|
| **Room Status** (`Pages/Operations/RoomStatus.jsx` + legacy `RoomStatusBoard`) | `/operations/room-status` | Live OR board | Tiles: Total Rooms, In Use, Available, Turnovers; per-room: status badge, current case (procedure/surgeon/service), **progress bar (elapsed/duration)**, case time, next case, today/week/month util; board metrics overall util %, avg turnover, on-time starts, delays today | Filter by location; refresh; open Room details modal (staff, resources, notes, **alerts**) | Mock (`mock-data/room-status`); REST `/api/cases/room-status` exists |
| **Case Management** (`Pages/Operations/CaseManagement.jsx` → `CaseTracker`) | `/operations/cases` | Track surgical cases in real time | Stats: totalPatients, inProgress, delayed, completed, preOp; banner "N procedures showing delays"; per-case phase, journey %, elapsed, resource status; **Care Journey** (pre-procedure milestones H&P/Consent/Labs/Transport, post-procedure PACU/recovery/discharge), vitals table | Filter by phase (Pre-Op/Procedure/Recovery/all); open Care Journey modal; Add Safety Note; Add Barrier; Cancel Procedure; acknowledge checklist items | `CaseManagementMockData.php` (28 mock procedures, 5 specialties) |
| **Block Schedule** (`Pages/Operations/BlockSchedule.jsx`; legacy `BlockScheduleManager`) | `/operations/block-schedule` | Manage OR block allocations | Tiles: Total Blocks, Utilization %, Released, Requests; **calendar (month/week/day)** — placeholder | Filter by service; switch month/week/day; **Add Block**; date range | Static placeholder; REST `/api/blocks` exists |
| **Cases (legacy)** (`Pages/Cases.jsx` → `CaseList`) | top-level | Tabular case list | Time, Patient+MRN, Procedure+Service, Surgeon, Room, **Status badge** | Filter date/status/service/room; **Add Case**; edit case (`CaseForm` w/ ASA, class, type, patient-class) | Mock (`mock-data/cases`) |

### 3.2 Perioperative Analytics (operational utilization, recent-period)
All under `/analytics/*`, rendered by thin controllers, fed by `mock-data/*.js`
(one exception). Pattern: filter rail (Hospital/Location/Service/Surgeon + date range
30/90/180d + comparison toggle), metric cards, Nivo charts.

| Page | Headline metrics | Viz | Data | Tier |
|---|---|---|---|---|
| **Block Utilization** | In-Block %, Total Block %, Non-Prime % | Nivo bar/line, day-of-week heatmap, detail table | `mock-data/block-utilization` | Ops + exec review |
| **OR Utilization** | Utilization %, Efficiency Ratio, Cases/Day, Turnover, Avg Case Duration, Utilization Gap | line/bar, specialty donut, room cards, opportunity cards | **`useORUtilizationData()` (real hook)** + Analytics ctx | Ops + exec |
| **Primetime Utilization** | Primetime block util %, availability, scheduled vs actual % | bar/line, day-of-week heatmap | `mock-data/primetime-utilization` | Ops |
| **Room Running** | Rooms Running (count), Room Util %, Max/Ideal/Actual Staffing, Avg Rooms Running | line w/ markers, **hourly intraday** breakdown, location/service tables | `mock-data/room-running` | Ops (staffing) |
| **Turnover Times** | Median/Avg Turnover (min), Total Cases, Total Turnovers, distribution histogram | pie (distribution), bar (room/hourly) | `mock-data/turnover-times` + `useAnalyticsData` | Ops |
| **Provider Analytics** | Total Cases, Block Util %, Case-Length Accuracy % | placeholder charts | hardcoded scaffold | Exec |
| **Service Analytics** | Total Cases, On-Time Starts %, Block Util % | placeholder | hardcoded scaffold | Exec |
| **Historical Trends** | Avg/Max/Min %, trend ↗↘ (metric selectable) | placeholder (historical/seasonal/distribution) | scaffold | Exec |

### 3.3 Predictions (forward-looking) — all "Coming Soon" placeholders
`/predictions/forecast` (Utilization Forecast: predicted util %, confidence, range,
historical accuracy), `/predictions/demand` (Demand Analysis: predicted volume, growth %,
seasonality, model accuracy), `/predictions/resources` (Resource Planning: required staff,
equipment needs, room util %, cost impact $K). All scaffolds.

### 3.4 Unified Analytics Workbench (`Pages/Analytics.jsx`)
8 sections (hub / live / retrospective / predictive / process-intelligence /
opportunities / workbench / data-quality) with owners + cadence + a "Legacy Surgical
Pages" quick-link group (Block/OR/Primetime/Room-Running/Turnover). Falls back to a
"design shell" if the live API payload fails. Exec/ops-leader oriented; OR data is one
of several source domains ("Surgical throughput").

---

## 4. Real-time aspects

| Surface | Live mechanism today | Notes for mobile |
|---|---|---|
| **Room status board** | Client computes progress (`elapsed = now − or_in_time`) on render; **no WebSocket/Echo/polling** wired. REST `/api/cases/room-status` is request/response. | Add polling (e.g. 15–30s) or push; status derived from `orlog` wheels-in/out. |
| **Care Journey card** | `setInterval` 1s clock for "current time"; journey %/elapsed from props. | Per-case live progress + remaining-time is the strongest live mobile view. |
| **Case timing** | `CaseTiming` accessors (`progress_percentage`, `remaining_time`, `is_delayed`) compute against `now()` server-side. | Server can emit delay events. |
| **Vitals** | `CaseMeasurement.vitals_status` flags abnormal on read. | Real-time but PHI-heavy. |
| **Analytics Workbench** | `generatedAtIso` per payload; `sourceMap` freshness; designed for periodic refresh, not streaming. | Glance tiles only. |

**No realtime transport (Pusher/Echo/WebSocket) is currently configured** in the periop
front-end — the only timers are local `setInterval`. Hummingbird will need to define the
push/poll layer; the data model (orlog timestamps, status_id, safety-note SLA, transport
status) gives clean server-side trigger points.

---

## 5. Analytics depth & audience (exec-review vs operational)

| Analytic | Source rollup | Cadence | Primary audience | Exec vs Operational |
|---|---|---|---|---|
| Live room status / running rooms | `orlog`, `room_utilization` (hourly) | Intraday | OR charge nurse / board runner | **Operational** |
| Turnover times | `case_metrics.turnover_time`, `room_utilization.turnover_minutes` | Daily / recent 30d | Charge nurse + periop ops leader | Operational (mgmt) |
| Block utilization / primetime | `block_utilization` (prime_time_%, util_%) | Daily, 30–180d | **Periop ops leader / OR committee** | Exec-review |
| OR utilization (efficiency, gap, cases/day) | `room_utilization`, `case_metrics` | 30–180d | Ops leader + exec | Both |
| Service / Provider analytics | `case_metrics` + service/provider joins | 30d–1y | **Executive / service-line chiefs** | Exec-review |
| Historical trends | `case_metrics` grouped month/qtr/yr | 1y | **Executive** | Exec-review |
| Predictions (forecast/demand/resource) | (placeholder) | Forward | Ops leader / exec planning | Exec/planning |
| Workbench (overview/opportunities/scenario) | `OperationsAnalyticsService` (house-wide + OR) | Continuous + weekly governance | Command center / exec | Exec-review |

Rule of thumb for Hummingbird: **anything keyed to a single day/shift and a room/case
is operational (mobile)**; anything keyed to a date *range* and aggregated by
service/provider/month is exec-review (desktop, or at most a read-only glance card).

---

## 6. Roles & access

The app uses a **role switcher** (`Components/CommandCenter/RoleSwitcher.tsx`) with
values `command`, `executive`, `service-line` (service-line currently disabled). The
DB `users.role` defaults to `user`. There is **no fine-grained periop RBAC** in code
today — pages are gated only by auth. For Hummingbird, map the de-facto periop personas
onto data scopes:

| Persona | Cares most about | Data scope | Mobile mode |
|---|---|---|---|
| **OR charge nurse / board runner** | Live room board, turnovers, delays, transport readiness, safety-note SLAs | `cases/room-status`, today's cases, `CaseTiming`, `CaseSafetyNote`, `CaseTransport` | Glance + Notify + light Action |
| **Surgeon** | My cases today, my next case start, am I delayed, my block | `or_cases` where primary_surgeon_id = me / `case_staff` role Surgeon; `block_templates` forSurgeon | Glance + Notify |
| **Perioperative ops leader** | Block/primetime/turnover utilization, running rooms vs staffing, on-time starts | block/room utilization, primetime, room-running | Read-only glance; deep work on desktop |
| **Executive** | Service/provider trends, historical utilization, forecasts, workbench | provider/service/historical analytics, Analytics Workbench | Glance tiles only |

---

## 7. Mobile relevance — per-feature flags (GLANCEABLE / ACTIONABLE / NOTIFY / DESKTOP-ONLY)

| Feature | Flag(s) | Rationale |
|---|---|---|
| **Live OR room status board** (`/api/cases/room-status`) | **GLANCEABLE + NOTIFY** | One-screen status of every OR; status derived from wheels-in/out. Notify on room→Available/Turnover and on Delayed. |
| **My cases today** (surgeon) / **Today's board** (`/api/cases/today`) | **GLANCEABLE + NOTIFY** | Highest-value personal view; filter by surgeon/`case_staff`. Notify on schedule change, "you're up next". |
| **Single-case live tracking** (CaseTiming progress/remaining, journey %) | **GLANCEABLE + NOTIFY** | Real-time progress + remaining time; notify when case runs past planned duration (`variance>0`). |
| **Case status changes** (POST/PUT case status: Scheduled→In Progress→Delayed→Completed/Cancelled) | **ACTIONABLE + NOTIFY** | Lightweight status advance is reasonable on mobile (board runner). Notify the surgeon/team on transition. |
| **Care-journey milestones** (acknowledge/complete H&P, Consent, Labs) | **ACTIONABLE + NOTIFY** | Tap-to-acknowledge checklist; notify on `Action_Required` / pending required milestone before wheels-in. |
| **Safety notes / barriers** (create + **acknowledge**, SLA overdue) | **ACTIONABLE + NOTIFY** | `CaseSafetyNote` has severity + acknowledgement + overdue SLA (Critical 15m). Prime push channel; ack from phone. |
| **Case transport** (pre/post-procedure ready/overdue, mark complete) | **ACTIONABLE + NOTIFY** | "Patient ready for transport to PACU/OR"; assignee can mark In_Progress/Complete. |
| **Turnover status / room ready** | **GLANCEABLE + NOTIFY** | "OR-4 ready in ~15 min" from turnover state; notify next surgeon/team. |
| **Block schedule (view today/this week)** | **GLANCEABLE** | View block allocation + release status; creating/editing blocks is desktop. |
| **Add/Edit case, Add block** (full forms) | **DESKTOP-ONLY** | Multi-field scheduling forms (ASA/class/type/patient-class/duration); not a phone task. |
| **Intra-op vitals** (HR/BP/SpO2/Temp, abnormal flags) | **NOTIFY (gated)** | Clinically valuable abnormal-vital push, but PHI-sensitive; behind role + auth, likely opt-in. |
| **Block / Primetime / OR / Room-Running / Turnover analytics** | **DESKTOP-ONLY** (top-line tile GLANCEABLE) | Heavy filter rails + Nivo charts; surface only a single util% / turnover tile on mobile. |
| **Provider / Service / Historical analytics** | **DESKTOP-ONLY** | Exec retrospective, date-range aggregations. |
| **Predictions (forecast/demand/resource)** | **DESKTOP-ONLY** | Placeholders; planning, not day-of. |
| **Unified Analytics Workbench** | **DESKTOP-ONLY** (OR action-queue item GLANCEABLE) | Command-center deep tool; the "N OR cases feeding demand" tile can glance. |

---

## 8. Notification opportunities (server-side triggers already modeled)

Ranked by value × how cleanly the data model supports the trigger:

1. **Case delay / running long** — `CaseTiming.variance > 0` / `is_delayed`, or status→DELAYED. Notify surgeon + charge + downstream (RTDC demand). Strongest signal.
2. **Room ready / turnover complete** — `orlog.or_out_time` set then turnover done / next case `or_in_time`. "OR-X ready in ~N min" to next team.
3. **Safety note unacknowledged past SLA** — `CaseSafetyNote.is_overdue` (Critical 15m / High 30m / Medium 60m / Low 120m) + `requiresImmediate()`. Escalation push.
4. **Transport ready / overdue** — `CaseTransport` status `Pending` past `planned_time` (`is_delayed`), pre- or post-procedure. To assigned porter + charge.
5. **Required pre-procedure milestone incomplete before wheels-in** — `CareJourneyMilestone` `required & status≠Completed` while case approaching `scheduled_start_time` (H&P/Consent/Labs gate). To surgeon + pre-op nurse.
6. **"You're up next" / schedule change** — surgeon's next case start approaching, or `scheduled_start_time`/room reassignment changed. To surgeon.
7. **Late start** — `case_metrics.late_start_minutes > 0` at first-case-of-day. To ops leader (on-time-start KPI).
8. **Abnormal intra-op vitals** — `CaseMeasurement.vitals_status = alert` (PHI-gated, opt-in). To anesthesia/charge.
9. **Case cancelled** — status→CANCELLED with `cancellation_reason`. Frees room/block; notify scheduling + downstream demand.

---

## 9. Integration notes & caveats for Hummingbird

- **Mock vs live:** Operations and Analytics pages render mock data; the **REST contract
  is the real target**. Validate each endpoint against live `prod.*` before depending on it.
- **Schema gaps to route around:** (a) `or_cases` has **no** `actual_start_time/actual_end_time/scheduled_end_time` — use `orlog` for the clock and `case_metrics` for late/early; (b) reference endpoints filter `is_active` but the column is `active_status`; (c) `ORCase` `phase` scopes reference a non-existent column — use `case_timings.phase`. (d) `ORCaseController@store` sets `$case->status = 'Scheduled'` (string) but the column is `status_id` (FK int) — creation path is currently buggy.
- **Canonical status model** (use these IDs/codes): 1 Scheduled (SCHED) · 2 In Progress (INPROG) · 3 Delayed (DELAY) · 4 Completed (COMP) · 5 Cancelled. Active = {1,2,3}.
- **Status never by color alone** (project rule): mobile must pair OR status with icon/label (board already pairs StatusDot + text).
- **PHI:** patient name is not in `or_cases` (only `patient_id`); mock data injects names. Vitals & safety-note content are sensitive — gate behind auth + role.
- **Two-system color rule:** OR status colors map to `healthcare-critical/warning/success/info` (teal/amber/coral/sky); reserve coral-red for true breaches (delay/critical safety), not routine turnover.
